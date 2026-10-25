#!/bin/sh
# Verify source and extracted RC ZIP with the same contract/integrity gates.
# Run only from an isolated checkout. Does not touch WordPress or production.
set -eu

if [ "$#" -gt 1 ]; then
  echo "Usage: sh scripts/verify_rc3_release.sh [absolute-output-zip]" >&2
  exit 2
fi
if [ "$#" -eq 1 ] && { [ -e "$1" ] || [ -L "$1" ]; }; then
  echo "Refusing to overwrite an existing output ZIP" >&2
  exit 2
fi

ROOT=$(CDPATH= cd "$(dirname "$0")/.." && pwd)
TMP=$(mktemp -d "${TMPDIR:-/tmp}/mgsl-v130rc13-verify.XXXXXX")
trap 'rm -rf "$TMP"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

cd "$ROOT"
echo "=== SOURCE: PHP lint ==="
find . -type f -name '*.php' ! -path './.git/*' -print0 | xargs -0 -n 1 php -l

echo "=== SOURCE: integrity, contracts and unsafe config ==="
php tests/integrity_manifest.php
php tests/contract.php
php tests/contract.php --unsafe-config
php tests/schedule_health.php
php tests/packs_contract.php
php tests/pack_worker_contract.php
php tests/admin_ui_contract.php

ZIP="$TMP/mg-saoluu-v1.3.0-rc13.zip"
echo "=== PACKAGE: deterministic ZIP + CRC/payload ==="
python3 scripts/package_release.py --out "$ZIP"
unzip -t "$ZIP"
unzip -q "$ZIP" -d "$TMP/extracted"

echo "=== EXTRACTED ZIP: integrity, contracts and unsafe config ==="
export MGSL_TEST_PLUGIN_DIR="$TMP/extracted/mg-saoluu"
test -f "$MGSL_TEST_PLUGIN_DIR/mg-saoluu.php"
php tests/integrity_manifest.php
php tests/contract.php
php tests/contract.php --unsafe-config
php tests/schedule_health.php
php tests/packs_contract.php
php tests/pack_worker_contract.php
php tests/admin_ui_contract.php

echo "=== FINAL ZIP SHA-256 ==="
python3 - "$ZIP" <<'PY'
import hashlib
import sys
from pathlib import Path
p = Path(sys.argv[1])
print(hashlib.sha256(p.read_bytes()).hexdigest(), p.name)
PY
if [ "$#" -eq 1 ]; then
  case "$1" in
    /*) ;;
    *) echo "Output ZIP must have an absolute path outside source" >&2; exit 2 ;;
  esac
  cp "$ZIP" "$1"
  echo "Final verified archive saved: $1"
fi
echo "PASS: source, packaged payload, extracted integrity and contracts"
