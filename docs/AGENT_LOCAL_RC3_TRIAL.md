# AGENT LOCAL — MG Sao Lưu RC3 clone verification (Task Packet)

**Repository:** `thiendzung/mg-saoluu`  
**PR:** https://github.com/thiendzung/mg-saoluu/pull/2  
**Base for this packet:** `927f2ed0dda803d2b89a5389fe0f1d6f56c5c37c`. **Refresh exact PR HEAD immediately before executing; this packet's SHA is an observed baseline, not authority to test stale code.**  
**Owner:** Agent Local (Mac/Herd/DBngin). **Founder:** approves merge, any production, account, payment or hosting changes.  
**Goal:** prove encrypted Host → Local backup/restore on independent clones, retain safe diagnostics, report exact evidence. No production restore or two-way live sync.

## STOP / safety envelope

- No modification/install/debug/cron on `motgu.com` or AZDIGI production; no canonical `motgu.test` overwrite. Staging/hosting source needs Founder authorization if it changes an existing server.
- Use two *disposable* WordPress installations with **distinct DB names, DB users and directories**, not aliases of live development. Check/record actual `WP_ENVIRONMENT_TYPE`; ensure plugin shows Local/Staging rather than Production.
- Isolate outbound SMTP, gateways, webhooks, Woo/Action Scheduler and scheduled tasks at **network/provider level** before any copied database is brought online; plugin's `pre_wp_mail` filter is not sufficient.
- Do not put `wp-config.php`, database dumps, user/order PII, API credentials, Recovery Key, private logs or backups into GitHub/PR/chat.
- **No force-push, rebase, reset --hard, merge, public release, production restore, or blanket git clean.** Retain backups and teardown only disposable paths created for this task.

## Stage 1 — exact-ref source/ZIP gate

From a clean disposable checkout (do not alter Founder's checkout):

```sh
git clone https://github.com/thiendzung/mg-saoluu.git /tmp/mgsl-rc3-verify
cd /tmp/mgsl-rc3-verify
git fetch origin
git checkout --detach 927f2ed0dda803d2b89a5389fe0f1d6f56c5c37c
git rev-parse HEAD
git status --porcelain
php -v
php -m
find . -type f -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
php tests/contract.php
php tests/contract.php --unsafe-config
python3 scripts/package_release.py --out /tmp/mg-saoluu-v1.2.1-rc3-verify.zip
unzip -t /tmp/mg-saoluu-v1.2.1-rc3-verify.zip
mkdir -p /tmp/mgsl-rc3-unpacked
unzip -q /tmp/mg-saoluu-v1.2.1-rc3-verify.zip -d /tmp/mgsl-rc3-unpacked
MGSL_TEST_PLUGIN_DIR=/tmp/mgsl-rc3-unpacked/mg-saoluu php tests/contract.php
MGSL_TEST_PLUGIN_DIR=/tmp/mgsl-rc3-unpacked/mg-saoluu php tests/contract.php --unsafe-config
```

Use a uniquely named disposable directory if the example paths already exist. Never refresh `integrity.json` as a workaround for an unexplained mismatch. Record total PHP lint, tests, checksum and SHA of final ZIP. Confirm PHP CLI, WordPress PHP-FPM, WordPress, MySQL/MariaDB and native `mysql` / `mysqldump` versions on both environments. Inspect native `exec`, ZipArchive, OpenSSL and off-webroot storage permissions.

## Stage 2 — debug on CLONES only

- Plugin event logging is already default ON for Local/Staging and OFF for Production. Verify that the displayed classification is correct before using it. Events are in private `mg-saoluu-debug.jsonl` under the plugin's configured **outside-webroot** backup directory; admin export is nonce-protected.
- For PHP errors set the following once in **clone-only** `wp-config.php`, above `/* That's all, stop editing! */`. If constants already exist, **edit existing definitions**, never define twice. Replace absolute path with a private outside-webroot path writable by PHP:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', '/absolute/private/path/wp-errors.log');
define('WP_DEBUG_DISPLAY', false);
@ini_set('display_errors', 0);
```

- Use `WP_ENVIRONMENT_TYPE=local` on Mac clones, `staging` on authorized staging; never relabel production as nonproduction to bypass guards.
- Logs must include timestamps, event, request_id, stage/result/error **code** without paths/secrets. Check absence of PII, credentials and Recovery Key. If failures are invisible before restore-start/preflight, identify a bounded diagnostic fix.
- Do **not** switch on PHP debug in live production. Confirm PHP logs are outside webroot, permissions restrictive; disable/delete sensitive test logs during cleanup.

## Stage 3 — independent end-to-end drill

1. Make source clone with a small reproducible fixture: posts, artwork CPT, media images, serialized options, Woo test products + HPOS orders, plugin custom tables. Record table prefix and baseline counts, URL and representative checksums/hashes (no customer data).
2. Install ZIP on source and target clones; confirm admin UI, `local_copy` choice, encrypted archive download, and `.mgbackup/.zip` upload. Prefer test encryption with explicit exportable Recovery Key retained locally in password manager.
3. Create encrypted `local_copy` backup, verify file with site tool and offline `tools/recovery-verify.php`, transfer source → target, restore **DB + files** on target with matching prefix, change URL. Do not overwrite an existing unrelated clone.
4. Compare source/target post counts and representative fixtures; imported assets, active theme/plugins, custom tables, WP home/siteurl, **GUID unchanged**, Woo HPOS rows, admin login, front pages. Search/replace dry-run on target (read-only) may use `wp search-replace OLD NEW --skip-columns=guid --dry-run --all-tables-with-prefix` to identify residual source URLs; assess serialized objects separately.
5. Confirm `backup_start`, `backup_archive_ready`, `backup_verified`, `restore_started`, `restore_complete` in private JSONL where paths are valid, and compare against PHP warning/error log. Debug must not expose secrets.

## Stage 4 — negative tests on disposable clones ONLY

- Wrong key; corrupt and truncated `.mgbackup`; mutated ZIP/hash; duplicate and symlink ZIP entries; prefix mismatch; missing `mysql` or `mysqldump` binary; file upload limit; permission/storage preflight; bad metadata; simultaneous nonblocking `flock`.
- Database import fail mid-way, file-copy failure, disk quota/full **only in disposable constrained storage**; assert rollback snapshot was taken and contents restored (or report truthful failure, do not call PASS).
- Production-mode **read-only** guard test on an expendable fixture (no live domain) to prove actual restore returns `rc_production_restore_disabled`. Never spoof a live site into local.
- If plugin lacks clear error-code diagnostics for a reproduced failure, note exact code path and add narrowly scoped logging + regression test in a *separate remediation commit/PR* after tests. Known UI issue for review: Production Restore panel still offers approval flow although RC3 hard-blocks restore; hide/label clearly before release.
- No sleep/polling loops for status checks; prefer process exit codes, callbacks and file capture.

## Deliverables / decision

Return one evidence report:
- `PASS`, `FAIL` or `BLOCKED` for **source/ZIP, source backup, restore, integrity, HPOS/media, URL/GUID, negative tests, rollback and logs**.
- Exact repo HEAD, PR HEAD, ZIP SHA256, WordPress/PHP/DB versions, staging/local identifiers with paths/PII redacted; exact commands and exit codes.
- Sanitized log events (not raw PHP logs), screenshots where helpful, minimal reproduction of each defect, affected files/lines and suggested fix.
- If fixing defects: create minimal scope branch/PR from *fresh* verified RC3 HEAD, add regression, refresh manifest **only after reviewed plugin source edits**, rerun all gates on source + extracted ZIP, report new exact SHA. Do not merge.
- **Stop after evidence and propose next action.** Production restore and live Local → Production remain **NO-GO**, regardless of a successful clone drill.

References: `docs/RELEASE_QA.md`, `RECOVERY_RUNBOOK.md`, `scripts/package_release.py` and the PR #2 diff.
