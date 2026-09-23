#!/usr/bin/env python3
"""Build a self-contained, credential-free deploy tree from the public snapshot.

The production Railway instance is not contacted or modified.
"""
from __future__ import annotations

import base64
import io
import os
from pathlib import Path, PurePosixPath
import shutil
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[1]
TARGET = ROOT / "release"
PARTS = sorted((ROOT / "backup").glob("production-source-2026-09-18.part*.b64"))
ENTRYPOINTS_FROM_SNAPSHOT = {"index.php", "connect.php", "login.php", "install.php"}
FORBIDDEN_PARTS = {"storage", "vendor", ".git", "backup", "__pycache__", ".env"}
FORBIDDEN_SUFFIXES = {".key", ".pem", ".sqlite", ".db", ".session", ".madeline", ".log"}


def safe_name(name: str) -> Path:
    normalized = name.removeprefix("./")
    p = PurePosixPath(normalized)
    if not normalized or p.is_absolute() or ".." in p.parts:
        raise ValueError("UNSAFE_SNAPSHOT_PATH")
    if any(part in FORBIDDEN_PARTS or (part.startswith(".env") and part != ".env.example") for part in p.parts):
        raise ValueError("FORBIDDEN_SNAPSHOT_PATH")
    if p.suffix.lower() in FORBIDDEN_SUFFIXES:
        raise ValueError("FORBIDDEN_SNAPSHOT_FILE")
    return Path(*p.parts)


def run(*args: str, env: dict[str, str] | None = None) -> None:
    proc = subprocess.run(args, cwd=TARGET, env=env, capture_output=True, text=True)
    if proc.returncode:
        # Do not expose source, runtime messages, environment values or logs in CI.
        print(f"RELEASE_STEP_FAILED: {Path(args[1]).name if len(args)>1 else args[0]}")
        raise SystemExit(1)
    print(f"RELEASE_STEP_OK: {Path(args[1]).name if len(args)>1 else args[0]}")


def main() -> None:
    if len(PARTS) != 9:
        raise SystemExit("SANITIZED_SNAPSHOT_NOT_FOUND")
    if TARGET.exists():
        shutil.rmtree(TARGET)
    TARGET.mkdir(mode=0o755)
    archive = base64.b64decode(b"".join(p.read_bytes().strip() for p in PARTS), validate=False)
    with tarfile.open(fileobj=io.BytesIO(archive), mode="r:gz") as tar:
        for member in tar.getmembers():
            if member.isdir():
                continue
            if not member.isfile():
                raise SystemExit("SNAPSHOT_LINK_OR_SPECIAL_FILE_BLOCKED")
            relative = safe_name(member.name)
            destination = TARGET / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            stream = tar.extractfile(member)
            if stream is None:
                raise SystemExit("SNAPSHOT_CONTENT_MISSING")
            destination.write_bytes(stream.read())

    # The newer tracked modules and visual styles take precedence, but the
    # operational entrypoints must start from the sanitized runtime snapshot:
    # the opt-in formatter installer relies on those already integrated anchors.
    tracked = subprocess.check_output(["git", "ls-files", "-z"], cwd=ROOT).decode().split("\0")
    for name in tracked:
        if not name or name in ENTRYPOINTS_FROM_SNAPSHOT:
            continue
        source = ROOT / name
        if not source.is_file():
            continue
        if name.startswith(("backup/", ".github/", "docs/")):
            continue
        if name.startswith("scripts/") and name in {
            "scripts/assemble-standalone-release.py",
            "scripts/inspect-public-snapshot.py",
            "scripts/audit-standalone-release.py",
        }:
            continue
        if not (
            name.startswith(("app/", "assets/", "config/", "database/", "scripts/", "tests/"))
            or name.startswith("runtime-")
            or name in {
                ".htaccess", ".env.example", "bootstrap.php", "worker.php",
                "reset.php", "ai-learning.php", "composer.json", "composer.lock",
            }
        ):
            continue
        destination = TARGET / name
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, destination)

    env = dict(os.environ, SMART_FORMAT_TEST_ONLY="1", WORKERS_AI_TEST_ONLY="1",
               AI_LEARNING_ENABLED="0")
    for installer in [
        "runtime-integrations-ui.php",
        "runtime-brand.php",
        "runtime-db-reconnect.php",
        "runtime-mobile-bottom-nav.php",
        "runtime-mobile-app-header.php",
        "runtime-panel-greeting.php",
        "runtime-caption-footer.php",
        "runtime-smart-format.php",
        "runtime-ai-learning.php",
    ]:
        if (TARGET / installer).is_file():
            run("php", installer, env=env)

    for template in (ROOT / "deploy" / "standalone").iterdir():
        if not template.is_file():
            continue
        name = template.name
        if name == "env.example":
            name = ".env.example"
        shutil.copyfile(template, TARGET / name)

    if not (TARGET / "app/TelegramRouter.php").is_file():
        raise SystemExit("TELEGRAM_ROUTER_MISSING")
    if not (TARGET / "config/config.php").is_file():
        raise SystemExit("APPLICATION_CONFIG_MISSING")
    router = (TARGET / "app/TelegramRouter.php").read_text()
    panel = (TARGET / "index.php").read_text()
    if "TMR_SMART_FORMAT_V1" not in router or "tmr-smart-format-choice" not in panel:
        raise SystemExit("SMART_FORMAT_NOT_ASSEMBLED")
    if "/assets/brand/connect-responsive.css?v=2" not in panel:
        # connect page receives this stylesheet, while the panel may include it
        # by the shared branding generator; require the actual CSS in either case.
        if not (TARGET / "assets/brand/connect-responsive.css").is_file():
            raise SystemExit("TABLET_CSS_MISSING")
    print(f"STANDALONE_SOURCE_ASSEMBLED files={sum(p.is_file() for p in TARGET.rglob('*'))}")


if __name__ == "__main__":
    main()
