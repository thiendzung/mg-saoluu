# Agent Local — MG Sao Luu v1.3.0-rc3 migration acceptance

## Goal
Validate the migration feature only, while re-confirming the Stable Backup/Restore base is not regressed.

## Hard boundaries
- RC3 must never write to real Production.
- Do not touch motgu.com, AZDIGI, DNS, live cron, payment, SMTP or production Drive.
- Use one Local source clone and one isolated Staging target with separate DB/user/root.
- Staging target must have outbound SMTP/payment/webhook/outbound HTTP isolated outside imported WordPress data.
- No merge/tag/release.
- No polling loops, sleep, ps or pgrep.

## Exact package gate
Use the exact PR HEAD supplied in Issue handoff.
Run:
`sh scripts/verify_rc3_release.sh /ABSOLUTE/PRIVATE/NEW/mg-saoluu-v1.3.0-rc3.zip`

Require source + extracted ZIP:
- integrity PASS;
- Stable core contracts PASS;
- unsafe config PASS;
- schedule health PASS;
- migration contract PASS;
- staging migration QA gate PASS;
- performance contract PASS;
- ZIP CRC/payload PASS;
- local ZIP SHA exactly matches the issue handoff.

Any mismatch: STOP.

## Source Local flow
1. Install exact RC3 on an isolated Local source.
2. Confirm active version and self-integrity.
3. Create encrypted migration local_copy from the Migration panel.
4. Verify the archive normally; record SHA-256.
5. Pair Local public key with isolated Staging target if not already paired.
6. Enter the exact HTTPS Staging target URL and generate one signed migration grant.
7. Confirm grant expires within 10 minutes and is bound to exact archive SHA/source/target.

## Staging target preflight
Use an isolated Staging hostname such as staging-*.example.com, separate DB/user/root.
Required runtime config:
- WP_ENVIRONMENT_TYPE=staging
- MG_SAOLUU_MIGRATION_QA_MODE=true
- DISABLE_WP_CRON=true
- MG_SAOLUU_MIGRATION_EXTERNAL_IO_ISOLATED=true
- HTTPS actually active

Before migration record:
- target DB hash/invariants;
- users/posts/media/comments/products/orders/HPOS counts;
- prefix;
- home/siteurl;
- active theme;
- MG Sao Luu version + SHA of mg-saoluu.php and includes/restore.php;
- outside-root sentinel SHA.

Target freshness must prove exactly one administrator and zero real content/media/comments/legacy orders/HPOS orders.

## Positive Stage -> Apply
1. Stage the encrypted .mgbackup.
2. Confirm Stage does not mutate DB.
3. Apply with:
   - exact grant;
   - Recovery Key;
   - admin password;
   - exact target host;
   - exact MIGRATE phrase;
   - explicit confirmation.
4. Require:
   - source -> target URL replacement;
   - GUID unchanged;
   - expected posts/media/products/Artwork/HPOS imported;
   - matching DB prefix;
   - active MG Sao Luu remains RC3 with unchanged runtime-plugin SHA;
   - health PASS;
   - maintenance marker absent;
   - restore journal completed, operation=migration;
   - pre-restore checkpoint exists and SHA is recorded;
   - external I/O remains isolated.

## Rollback
Use the exact linked pre-migration checkpoint.
Require:
- rolled_back;
- rollback_attempt_status=succeeded;
- baseline DB/files/invariants restored;
- files introduced by migration cleaned;
- target MG Sao Luu remains RC3;
- sentinel unchanged;
- maintenance absent.

## Negative runtime gates
Run on disposable Staging only:
1. RC real-production gate -> blocked.
2. Host-header mismatch -> blocked.
3. external I/O isolation constant missing -> blocked.
4. WP-Cron not disabled -> blocked.
5. invalid/non-HTTPS/IP/.test target -> blocked.
6. DB prefix mismatch -> blocked.
7. target not fresh: extra user/content/media/comment/order/HPOS -> blocked.
8. wrong archive SHA grant -> blocked.
9. wrong target domain grant -> blocked.
10. unpaired signer -> blocked.
11. expired grant -> blocked.
12. replay consumed grant -> blocked.
13. corrupt encrypted archive -> blocked.
14. symlink/outside-root sentinel protection -> PASS.
15. interrupted import only if a deterministic safe fault-injection hook exists; otherwise NOT TESTED. Do not kill DB/PHP arbitrarily.

## Performance observation
On Local Backup tab:
- no full archive directory scan should be triggered merely to render migration picker;
- only catalog-backed migration candidates should be listed;
- no archive decrypt/hash operation should happen until explicit Sign.

Record page render observation if practical; code-level performance contract is authoritative for this RC.

## Report
Return:
`EXACT_SHA | ZIP_SHA256 | CI | SOURCE_VERSION_INTEGRITY | MIGRATION_ARCHIVE_SHA | GRANT_BINDING | TARGET_PREFLIGHT | STAGE_DB_UNCHANGED | APPLY_STATUS | URL_GUID | DATA_COUNTS_HPOS | RUNTIME_PLUGIN_SHA | CHECKPOINT_SHA | ROLLBACK_STATUS | BASELINE_RESTORED | CLEANUP | PRODUCTION_BLOCK | HOST_SPOOF | EXTERNAL_IO_GATE | CRON_GATE | TARGET_URL_GATE | PREFIX_GATE | FRESHNESS_GATES | WRONG_SHA | WRONG_TARGET | UNPAIRED | EXPIRED | REPLAY | CORRUPT | SYMLINK_SENTINEL | INTERRUPTED_IMPORT | PERFORMANCE | DEBUG_REDACTION | BLOCKERS | FINAL_RECOMMENDATION`

## Decision
Any real-Production mutation, false success, runtime-plugin overwrite, outside-root write, replay acceptance, target-freshness bypass or rollback mismatch is NO-GO.
