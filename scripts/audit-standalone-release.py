#!/usr/bin/env python3
"""Fail closed on credential-like material in the deployable tree and snapshot."""
from __future__ import annotations

import base64
import io
from pathlib import Path
import re
import tarfile

ROOT = Path(__file__).resolve().parents[1]
RELEASE = ROOT / "release"
FORBIDDEN_NAMES = {".env", "id_rsa", "id_ed25519", "credentials.json", "secrets.json"}
FORBIDDEN_SUFFIXES = {".pem", ".key", ".p12", ".pfx", ".sqlite", ".db", ".session", ".madeline", ".log"}
FORBIDDEN_DIRS = {"storage", "vendor", ".git", "backup", "__pycache__"}
PATTERNS = {
    "private_key": rb"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----",
    "google_api_key": rb"AIza[0-9A-Za-z_-]{32,}",
    "telegram_bot_token": rb"(?<![0-9])[0-9]{8,12}:[A-Za-z0-9_-]{30,}",
    "github_personal_token": rb"gh[pousr]_[A-Za-z0-9]{25,}",
    "github_fine_grained_token": rb"github_pat_[A-Za-z0-9_]{30,}",
    "cloudflare_user_token": rb"cf" + rb"ut_[A-Za-z0-9_-]{30,}",
    "aws_access_key": rb"AKIA[0-9A-Z]{16}",
    "slack_token": rb"xox[baprs]-[0-9A-Za-z-]{25,}",
}
SENSITIVE_KEYS = {
    "APP_KEY", "DB_PASS", "PANEL_PASSWORD_HASH", "TELEGRAM_API_HASH",
    "TELEGRAM_SESSION", "GEMINI_API_KEY", "WORKERS_AI_API_TOKEN",
    "AZURE_TRANSLATOR_KEY", "GOOGLE_CLOUD_CREDENTIALS",
}

issues: list[tuple[str, str]] = []


def scan(name: str, contents: bytes, *, archived_template: bool = False) -> None:
    rel = Path(name)
    if (
        rel.name in FORBIDDEN_NAMES
        or rel.suffix.lower() in FORBIDDEN_SUFFIXES
        or any(part in FORBIDDEN_DIRS for part in rel.parts)
    ):
        issues.append((name, "forbidden_path"))
        return
    if b"\x00" in contents[:4096]:
        return
    for label, regex in PATTERNS.items():
        if re.search(regex, contents):
            issues.append((name, label))
    if rel.name == ".env.example":
        for line in contents.decode("utf-8").splitlines():
            if not line.strip() or line.lstrip().startswith("#"):
                continue
            key, separator, value = line.partition("=")
            if not separator:
                issues.append((name, "invalid_example_line"))
            elif key in SENSITIVE_KEYS and value.strip():
                placeholder = value.strip().lower()
                if not (archived_template and placeholder.startswith(("gere-", "troque-", "example-", "placeholder-"))):
                    issues.append((name, "credential_in_example"))
    if rel.name == "config.php":
        # Exclude compiled application config with literal credential assignments.
        for key in SENSITIVE_KEYS:
            needle = (key + "=").encode()
            if needle in contents:
                issues.append((name, "literal_sensitive_config"))
                break


if not RELEASE.is_dir():
    raise SystemExit("RELEASE_TREE_MISSING")
for file in RELEASE.rglob("*"):
    if file.is_file():
        scan(file.relative_to(RELEASE).as_posix(), file.read_bytes())
parts = sorted((ROOT / "backup").glob("production-source-2026-09-18.part*.b64"))
if len(parts) != 9:
    raise SystemExit("SOURCE_BACKUP_PARTS_MISSING")
archive = base64.b64decode(b"".join(part.read_bytes().strip() for part in parts))
archive_file_count = 0
with tarfile.open(fileobj=io.BytesIO(archive), mode="r:gz") as tar:
    for member in tar:
        archive_file_count += int(member.isfile())
        if member.isfile():
            stream = tar.extractfile(member)
            if stream:
                scan(member.name.removeprefix("./"), stream.read(), archived_template=True)
if issues:
    for filename, reason in issues:
        print(f"SECRET_AUDIT_BLOCKED file={filename} reason={reason}")
    raise SystemExit("SECRET_AUDIT_FAILED")
print(f"SECRET_AUDIT_PASSED release_files={sum(p.is_file() for p in RELEASE.rglob('*'))} archive_files={archive_file_count}")
