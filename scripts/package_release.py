#!/usr/bin/env python3
"""Build a deterministic WordPress install ZIP from an explicit SHA-256 manifest.

Only manifest-listed plugin files are packaged, plus integrity.json.
Use --refresh-manifest intentionally after reviewing source changes.
No dependencies beyond Python 3's standard library; no network or WP writes.
"""
import argparse
import hashlib
import json
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

ROOT = Path(__file__).resolve().parents[1]
MANIFEST_PATH = ROOT / "integrity.json"
ZIP_PREFIX = "mg-saoluu/"
ZIP_TIME = (2026, 10, 24, 12, 0, 0)


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def manifest_file(name):
    if not isinstance(name, str) or not name or "\\" in name:
        raise ValueError("Unsafe manifest name")
    rel = Path(name)
    if rel.is_absolute() or any(part in ("", ".", "..") for part in rel.parts):
        raise ValueError("Unsafe manifest path: " + name)
    path = ROOT / rel
    if not path.is_file() or path.is_symlink() or not path.resolve().is_relative_to(ROOT.resolve()):
        raise ValueError("Missing/unsafe manifest file: " + name)
    return path


def read_manifest():
    data = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
    if data.get("format") != "mg-saoluu-integrity" or not isinstance(data.get("files"), dict):
        raise ValueError("Wrong integrity manifest format")
    if not data["files"]:
        raise ValueError("Empty manifest")
    return data


def build(output, refresh=False):
    manifest = read_manifest()
    # Detect forgotten new plugin files: a build must never silently omit code.
    excluded = {".git", ".github", "tests", "docs", "scripts", "dist"}
    actual_plugin_files = {
        file.relative_to(ROOT).as_posix()
        for file in ROOT.rglob("*")
        if file.is_file() and not file.is_symlink()
        and file.relative_to(ROOT).parts[0] not in excluded
        and file.name not in {".gitignore", "integrity.json"}
    }
    if actual_plugin_files != set(manifest["files"]):
        missing = sorted(actual_plugin_files - set(manifest["files"]))
        extra = sorted(set(manifest["files"]) - actual_plugin_files)
        raise ValueError(f"Plugin file allowlist drift; missing-from-manifest={missing}, absent-from-disk={extra}")
    if "mg-saoluu.php" not in manifest["files"]:
        raise ValueError("Missing main WordPress plugin file")
    files = {}
    for name, old_hash in sorted(manifest["files"].items()):
        data = manifest_file(name).read_bytes()
        actual = sha256(data)
        if not refresh and actual != old_hash:
            raise ValueError("Manifest mismatch for " + name)
        files[name] = data
        if refresh:
            manifest["files"][name] = actual
    if refresh:
        MANIFEST_PATH.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    data_manifest = MANIFEST_PATH.read_bytes()
    if output.resolve().is_relative_to(ROOT.resolve()):
        raise ValueError("Output ZIP must be outside source directory")
    output.parent.mkdir(parents=True, exist_ok=True)
    with ZipFile(output, "w", compression=ZIP_DEFLATED, compresslevel=9) as archive:
        for name, content in [*files.items(), ("integrity.json", data_manifest)]:
            info = ZipInfo(ZIP_PREFIX + name, date_time=ZIP_TIME)
            info.compress_type = ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            archive.writestr(info, content, compress_type=ZIP_DEFLATED, compresslevel=9)
    with ZipFile(output) as archive:
        if archive.testzip() is not None:
            raise ValueError("ZIP CRC verification failed")
        actual_names = set(archive.namelist())
        expected_names = {ZIP_PREFIX + name for name in files} | {ZIP_PREFIX + "integrity.json"}
        if actual_names != expected_names:
            raise ValueError("ZIP file allowlist changed")
        for name, data in [*files.items(), ("integrity.json", data_manifest)]:
            if archive.read(ZIP_PREFIX + name) != data:
                raise ValueError("ZIP payload mismatch: " + name)
    print("PASS", len(files), "manifest-protected files; ZIP CRC/payload exact")
    print("SHA256", sha256(output.read_bytes()))
    print("ZIP", output)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--out", type=Path, required=True)
    parser.add_argument("--refresh-manifest", action="store_true", help="Explicitly rehash reviewed files")
    args = parser.parse_args()
    build(args.out, args.refresh_manifest)
