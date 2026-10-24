# MG Sao Lưu v1.2.1-rc2 — Release QA

**Status:** Source/contract PASS. **Production restore BLOCKED in RC.** Full Herd/DBngin/cPanel backup-restore drill NOT YET PROVEN.

## Change set

- Focus Backup → Verify → Store → Restore; inactive Data Transfer/Deploy PHP files removed, WordPress settings preserved.
- WordPress 7.1+, PHP 8.3+ requirements; site PHP must provide ZipArchive and OpenSSL; `mysqldump` + `mysql` CLI required.
- Atomic non-blocking `flock` in private off-webroot storage protects simultaneous manual/scheduled work; no sleep/polling.
- Core no longer ships dormant PHP SQL importer/exporter fallback.
- WordPress asset enqueue for admin CSS/JS; README shortened.
- Restore on production is fail-closed in RC; backup and verification may still be tested separately under authorization.

## Evidence (PHP 8.4 CLI, isolated test harness)

- PHP lint: **19/19 PASS**.
- `php tests/contract.php`: **53/53 PASS**.
- `php tests/contract.php --unsafe-config`: **4/4 PASS**.
- File manifest SHA and final ZIP CRC: **PASS**.

## Not proven

- Full WordPress UI runtime, PHP 8.3/8.5 actual compatibility, ZipArchive in PHP CLI used for real ZIP restore, DBngin MariaDB/MySQL dump/import, Google Drive round-trip, cPanel Cron, Woo HPOS restore, remote disaster recovery.
- Concurrent two PHP-FPM processes on Herd/hosting, production permissions, visual QA and immutable offsite storage.

## Exit criteria for stable

Independent WordPress+DB clone: encrypted DB/full backup → verify → offsite retrieval → restore fresh DB/files/uploads → compare Woo HPOS/content → wrong-key, missing CLI, mismatch, disk-full, rollback negative paths. Do not restore into canonical `motgu.test` or `motgu.com` during RC testing.

Reference: `RECOVERY_RUNBOOK.md`.

## Reproducible source → ZIP boundary

- Build from the reviewed Git branch, not from an old ZIP.
- `python3 scripts/package_release.py --out ../mg-saoluu-v1.2.1-rc2.zip` validates SHA manifest, fails if any plugin file is unlisted, and checks archive byte identity.
- After an intentional source edit, re-review it first; then run the same command with `--refresh-manifest`, commit the manifest change and rerun tests **on the extracted ZIP**.
- Never merge/release when either the source or ZIP manifest differs; GitHub `main` remains docs-only until Founder merge of the RC PR.
