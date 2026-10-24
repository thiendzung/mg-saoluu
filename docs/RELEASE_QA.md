# MG Sao Lưu v1.2.1-rc3 — Clone trial gate

**State:** Agent Local reports the clone restore and Issue #8 safety drill PASS at exact HEAD `19035d9af2a81bc97ffb0b7e3c6d892151a8f384`; this evidence does not automatically cover later review fixes. Production RESTORE remains BLOCKED in RC.

## New first-trial functions
- Encrypted `local_copy` Host-to-Local package from Admin Backup.
- Nonproduction UI form to import uploaded `.mgbackup` / `.zip` archive on separate clone.
- Private structured JSONL: default on Local/Staging, off on Production (unless explicit `MG_SAOLUU_DEBUG` true), outside webroot, capped and rolled. Log stores allowlisted stage names and counts, not credentials.
- Reject duplicate ZIP paths and incomplete extraction streams. Existing safety gates (prefix, prebackup, RC production restore lock) preserved.

## Final review / release acceptance
- Run `sh scripts/verify_rc3_release.sh` from a clean disposable checkout at the **latest exact HEAD**. It executes PHP lint, integrity and contracts on source and again against the **extracted final ZIP** (including the 4 unsafe-config tests). Store the command exit status, final ZIP SHA256 and sanitized output.
- The RC user interface must state **production restore locked** without offering a misleading Local-approval workflow. Direct/stale `security_*restore*` POST requests cannot unlock it. The underlying restore hard-block must remain.
- The maintenance marker `.maintenance` must fail closed on write failure and report cleanup failure instead of falsely confirming restore success. Validate a negative test on a disposable clone.
- **Network isolation is a separate runtime gate:** do not accept a copied Woo DB until outbound SMTP, payment gateways, webhooks and Action Scheduler/cron have been disabled or blocked externally and verified by a controlled test. `pre_wp_mail` alone does **not** prove isolation.
- Physical disk exhaustion was not performed; **simulated low-disk preflight is not equivalent**. Keep this limitation in the release decision and avoid claims of crash-atomic rollback.
- For debugging, use structured plugin JSONL and WordPress PHP logs **outside webroot** on clones; verify redaction. Do not enable production debug/restore.
- All evidence must cite the exact source SHA and extracted ZIP SHA; previous SHA reports are not transferable.

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
