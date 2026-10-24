# Agent Local — MG Sao Luu v1.3.0-rc5 exact DB rollback acceptance

## Goal
Prove the RC5 fix for the RC4 blocker: after signed migration and explicit rollback, the database returns to the exact baseline stable hash while replay protection and audit evidence survive outside the DB.

## Hard boundaries
- No motgu.com / Production writes.
- No motgu.test changes.
- Local source + isolated Staging target only.
- Separate WP root, DB and DB user.
- External SMTP/payment/webhook/outbound HTTP isolated.
- No merge/tag/release.
- Do not publish Recovery Key, migration grant, credentials or raw logs.

## Exact package gate
Use the exact RC5 HEAD and ZIP SHA from the issue handoff.
Run:
`sh scripts/verify_rc3_release.sh /ABSOLUTE/PRIVATE/NEW/mg-saoluu-v1.3.0-rc5.zip`

Require source + extracted ZIP tests PASS and exact ZIP SHA match.

## Baseline evidence
Before Stage/Apply record:
- raw DB dump SHA if useful;
- stable/normalized DB hash used for equality;
- exact SHOW FULL TABLES inventory + table/view types;
- schema count + schema fingerprint;
- exact normalized dump/diff of `mg_options` suitable to identify any mismatch without exposing secrets;
- data counts, URL/prefix/theme/GUID;
- RC5 runtime plugin SHA;
- outside-root sentinel SHA.

## Positive migration
Repeat the signed Local -> isolated Staging migration:
1. exact RC5 + self-integrity;
2. encrypted migration local_copy;
3. archive verify + SHA;
4. paired signer + 10-minute exact archive/target grant;
5. fresh-target preflight;
6. Stage and prove DB unchanged;
7. Apply;
8. verify URL/GUID/data/HPOS/plugin SHA/health/checkpoint;
9. record post-migration schema count.

## Explicit rollback PASS requires ALL
- journal `rolled_back`;
- `rollback_attempt_status=succeeded`;
- schema reconcile returns exact baseline inventory;
- final SHOW FULL TABLES names/types exactly equal baseline;
- final stable DB hash EXACTLY equals baseline stable DB hash;
- normalized `mg_options` EXACTLY equals baseline;
- non-options data equals baseline;
- file cleanup truthful;
- runtime plugin SHA unchanged;
- outside sentinel unchanged;
- .maintenance absent.

## RC5 operational-state proof
After rollback:
1. The consumed migration grant must still be rejected as replay.
2. `mg_saoluu_used_migration_nonces` in DB must be exactly baseline, not newly mutated by RC5.
3. New replay entry must exist only in private `migration-nonces.json`, outside webroot, regular file, mode 0600.
4. New migration/rollback audit events must exist in private `audit-log.json`, outside webroot, regular file, mode 0600.
5. New audit events must not be appended to legacy `mg_saoluu_audit_log` DB option.
6. Private state files must contain no Recovery Key, admin password, grant body, credentials or raw sensitive values.

## Negative gates
Re-run the RC4 migration/security negatives:
- real Production RC block;
- host spoof;
- external-I/O gate;
- WP-Cron gate;
- target URL/IP/local target;
- prefix mismatch;
- freshness including HPOS;
- wrong archive SHA;
- wrong target;
- unpaired signer;
- expired grant;
- replay;
- corrupt encrypted archive;
- symlink/outside-root sentinel;
- schema missing inventory / DROP failure / type mismatch (contract evidence acceptable for unsafe injection);
- interrupted import only if deterministic safe injection exists; otherwise NOT TESTED.

New private-state negatives:
- symlink leaf for replay/audit private state must fail closed;
- unwritable/unsafe private state must prevent grant consumption before DB mutation.

## Report
`EXACT_SHA | ZIP_SHA256 | CI | SOURCE_VERSION_INTEGRITY | MIGRATION_ARCHIVE_SHA | GRANT_BINDING | BASELINE_DB_HASH_STABLE | BASELINE_OPTIONS_HASH | BASELINE_SCHEMA_COUNT_FINGERPRINT | TARGET_PREFLIGHT | STAGE_DB_UNCHANGED | APPLY_STATUS | POST_MIGRATION_SCHEMA_COUNT | URL_GUID | DATA_COUNTS_HPOS | RUNTIME_PLUGIN_SHA | CHECKPOINT_SHA | ROLLBACK_STATUS | ROLLBACK_ATTEMPT_STATUS | SCHEMA_RECONCILE | FINAL_SCHEMA_EXACT | FINAL_DB_HASH_STABLE | FINAL_OPTIONS_HASH | OPTIONS_EXACT | BASELINE_RESTORED | CLEANUP | REPLAY_AFTER_ROLLBACK | PRIVATE_NONCE_STATE | PRIVATE_AUDIT_STATE | LEGACY_DB_AUDIT_UNCHANGED | PRIVATE_STATE_REDACTION | PRODUCTION_BLOCK | HOST_SPOOF | EXTERNAL_IO_GATE | CRON_GATE | TARGET_URL_GATE | PREFIX_GATE | FRESHNESS_GATES | WRONG_SHA | WRONG_TARGET | UNPAIRED | EXPIRED | REPLAY | CORRUPT | SYMLINK_SENTINEL | SCHEMA_NEGATIVES | PRIVATE_STATE_NEGATIVES | INTERRUPTED_IMPORT | PERFORMANCE | DEBUG_REDACTION | BLOCKERS | FINAL_RECOMMENDATION`

Any DB-hash mismatch, wp_options mismatch, replay acceptance, private-state write into webroot, outside-root mutation, false rolled_back, or production write = NO-GO.
