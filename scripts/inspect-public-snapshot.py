#!/usr/bin/env python3
"""Inspect the public, sanitized application snapshot without exporting its contents."""
from __future__ import annotations

import base64
import hashlib
import io
import json
from pathlib import Path
import tarfile

ROOT = Path(__file__).resolve().parents[1]
parts = sorted((ROOT / "backup").glob("production-source-2026-09-18.part*.b64"))
if len(parts) != 9:
    raise SystemExit("BACKUP_PART_COUNT_MISMATCH")
raw = base64.b64decode(b"".join(part.read_bytes().strip() for part in parts))
with tarfile.open(fileobj=io.BytesIO(raw), mode="r:gz") as tar:
    entries = [m for m in tar.getmembers() if m.isfile()]
    risky = []
    files = {}
    for m in entries:
        rel = Path(m.name.removeprefix("./"))
        if m.name.startswith("/") or ".." in rel.parts:
            risky.append("UNSAFE_PATH")
            continue
        name = rel.as_posix()
        if rel.name == ".env" or name.startswith(("storage/", "vendor/", ".git/")):
            risky.append("FORBIDDEN_PATH")
            continue
        if rel.suffix.lower() in {".sqlite", ".db", ".pem", ".key", ".session", ".madeline"}:
            risky.append("FORBIDDEN_EXTENSION")
            continue
        f = tar.extractfile(m)
        files[name] = f.read() if f else b""
print("BACKUP_INSPECT", json.dumps({
    "entry_count": len(entries),
    "source_count": len(files),
    "blocked_entry_count": len(risky),
    "missing_in_git": sorted(name for name in files if not (ROOT / name).is_file()),
    "same_in_git": sum((ROOT / name).is_file() and (ROOT / name).read_bytes() == data for name, data in files.items()),
    "different_in_git": sorted(name for name, data in files.items() if (ROOT / name).is_file() and (ROOT / name).read_bytes() != data),
    "archive_sha256_prefix": hashlib.sha256(raw).hexdigest()[:12],
}, ensure_ascii=False))
if risky:
    raise SystemExit("BACKUP_HAS_FORBIDDEN_ENTRIES")
