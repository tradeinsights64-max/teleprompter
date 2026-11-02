#!/usr/bin/env python3
"""Refresh scripts/index.json version stamps from the .txt files.

Usage: python3 tools/update_index.py [--open <id>]
Every entry's "v" is a short hash of its text, so the teleprompter page
re-downloads only the scripts that changed.
"""
import hashlib, json, sys, datetime, pathlib

root = pathlib.Path(__file__).resolve().parent.parent
idx_path = root / "scripts" / "index.json"
idx = json.loads(idx_path.read_text(encoding="utf-8"))
if "--open" in sys.argv:
    idx["open"] = sys.argv[sys.argv.index("--open") + 1]
for s in idx["scripts"]:
    text = (root / "scripts" / s["file"]).read_text(encoding="utf-8")
    s["v"] = hashlib.sha1(text.encode("utf-8")).hexdigest()[:10]
idx["updated"] = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
idx_path.write_text(json.dumps(idx, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps(idx, ensure_ascii=False, indent=2))
