# MG Sao Lưu v1.2.1-rc3 — Clone trial gate

**State:** Implementation committed on a review branch; runtime PHP/WordPress acceptance still pending. Production RESTORE remains BLOCKED in RC.

## New first-trial functions
- Encrypted `local_copy` Host-to-Local package from Admin Backup.
- Nonproduction UI form to import uploaded `.mgbackup` / `.zip` archive on separate clone.
- Private structured JSONL: default on Local/Staging, off on Production (unless explicit `MG_SAOLUU_DEBUG` true), outside webroot, capped and rolled. Log stores allowlisted stage names and counts, not credentials.
- Reject duplicate ZIP paths and incomplete extraction streams. Existing safety gates (prefix, prebackup, RC production restore lock) preserved.

## Source and packaged checks
Run on exact branch SHA, and repeat on extracted ZIP:
```sh
find . -type f -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
php tests/contract.php
php tests/contract.php --unsafe-config
python3 scripts/package_release.py --out ../mg-saoluu-v1.2.1-rc3.zip
```
The integrity manifest must be refreshed for reviewed new source before packaging, without introducing unexpected files.

## Runtime acceptance — Agent Local (required)
1. Isolated WordPress 7.1+, PHP 8.3+, MariaDB/MySQL, mysql/mysqldump, OpenSSL and ZipArchive. Two separate disposable WP clones and database users.
2. On source clone, create encrypted local_copy, verify, download; retain Recovery Key.
3. On second clone, import with source key + URL migration; verify GUID unchanged, Woo HPOS, CPT/metadata, uploads and theme/plugin files.
4. Failure drills: wrong key, corrupt/duplicate ZIP, wrong prefix, missing binary, disk full, interrupted SQL import/rollback; parallel lock.
5. Collect sanitized plugin diagnostic log and WP native PHP log only if enabled in private clone config.
6. Optional Drive manual roundtrip compare SHA256; direct Drive download/restore not built.

Do not touch canonical motgu.test or motgu.com. No production restore, merge or live cron until reviewed evidence and Founder approval.
