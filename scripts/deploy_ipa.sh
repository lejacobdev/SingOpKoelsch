#!/usr/bin/env bash
# Called by GitHub Actions after a successful build
# Usage: deploy_ipa.sh <ipa_size_bytes>
set -euo pipefail

IPA_SIZE="${1:-0}"
IPA_PATH="/var/www/html8/app/SingOpKoelsch.ipa"
ALTSTORE="/var/www/html8/app/altstore.json"
ALTSTORE_PAL="/var/www/html8/app/altstore-pal.json"

# Update IPA size in both JSON files using Python (safe JSON parse/write)
python3 - "$IPA_SIZE" "$ALTSTORE" "$ALTSTORE_PAL" << 'PYTHON'
import sys, json

size = int(sys.argv[1])
files = sys.argv[2:]

for path in files:
    with open(path) as f:
        data = json.load(f)
    app = data["apps"][0]
    app["size"] = size
    if app.get("versions"):
        app["versions"][0]["size"] = size
    with open(path, "w") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    print(f"Updated {path}: size={size}")
PYTHON

echo "Deploy complete: $(du -sh "$IPA_PATH" | cut -f1) IPA"
