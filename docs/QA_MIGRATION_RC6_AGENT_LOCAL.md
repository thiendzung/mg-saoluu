# Agent Local — MG Sao Luu v1.3.0-rc6 exact DB rollback seal

## Goal
Prove the RC6 fix for the remaining RC5 mismatch: after explicit rollback, the checkpoint DB remains authoritative and no post-rollback plugin cache/settings write changes mg_options.

## Hard boundaries
- No motgu.com / Production writes.
- No motgu.test changes.
- Local source + isolated Staging target only.
- Separate root/DB/user; external SMTP/payment/webhook/outbound HTTP isolated.
- No merge/tag/release.
- Do not publish Recovery Key, grant, credentials or raw logs.

## Package gate
Use exact HEAD + ZIP SHA from the Issue handoff.
Run:
`sh scripts/verify_rc3_release.sh /ABSOLUTE/PRIVATE/NEW/mg-saoluu-v1.3.0-rc6.zip`

Require all source + extracted ZIP gates PASS and exact ZIP SHA match.

## Baseline
Before Stage/Apply record:
- stable/normalized full DB hash;
- normalized mg_options hash;
- raw stored blog_public value;
- exact SHOW FULL TABLES inventory/types + schema fingerprint;
- data counts, URL/prefix/theme/GUID;
- runtime plugin SHA;
- outside-root sentinel SHA.

## Positive path
Repeat full signed Local -> isolated Staging migration:
1. exact RC6 + self-integrity;
2. encrypted migration package;
3. archive SHA + verify;
4. paired signer + exact 10-minute archive/target grant;
5. fresh target preflight;
6. Stage and prove DB unchanged;
7. Apply and verify content/URL/GUID/HPOS/plugin SHA/checkpoint;
8. record post-migration schema inventory/count;
9. explicit rollback using the exact linked checkpoint.

## RC6 rollback PASS requires ALL
- journal rolled_back;
- rollback_attempt_status=succeeded;
- schema reconcile exact;
- final SHOW FULL TABLES exactly baseline;
- FINAL stable DB hash EXACTLY equals baseline;
- FINAL mg_options hash EXACTLY equals baseline;
- raw stored blog_public EXACTLY equals baseline;
- no new _transient_mg_saoluu_* or _transient_timeout_mg_saoluu_* rows introduced by opening the Restore/Overview UI after rollback;
- non-options data equals baseline;
- file cleanup truthful;
- runtime plugin SHA unchanged;
- sentinel unchanged;
- .maintenance absent.

## RC6 cache/settings proof
1. Explicit rollback finalizer must not reapply the current target settings.
2. Non-production noindex remains effective through filter behavior even if raw blog_public baseline is 1.
3. Binary/system/backup-list cache activity must not create DB transient rows.
4. Private replay/audit state from RC5 remains outside webroot, mode 0600.
5. Consumed grant remains rejected as replay after rollback.
6. Legacy DB audit/nonce options remain baseline exact.

## Negative gates
Re-run RC5 migration/security negatives:
- Production RC block;
- host spoof;
- external-I/O gate;
- cron gate;
- target URL/IP/local target;
- prefix mismatch;
- freshness including HPOS;
- wrong SHA/target;
- unpaired signer;
- expired/replay;
- corrupt archive;
- symlink/outside-root sentinel;
- schema negatives;
- private-state negatives.

Interrupted import: only with deterministic safe injection; otherwise NOT TESTED.

## Report
`EXACT_SHA | ZIP_SHA256 | CI | SOURCE_VERSION_INTEGRITY | MIGRATION_ARCHIVE_SHA | GRANT_BINDING | BASELINE_DB_HASH_STABLE | BASELINE_OPTIONS_HASH | BASELINE_BLOG_PUBLIC_RAW | BASELINE_SCHEMA_COUNT_FINGERPRINT | TARGET_PREFLIGHT | STAGE_DB_UNCHANGED | APPLY_STATUS | POST_MIGRATION_SCHEMA_COUNT | URL_GUID | DATA_COUNTS_HPOS | RUNTIME_PLUGIN_SHA | CHECKPOINT_SHA | ROLLBACK_STATUS | ROLLBACK_ATTEMPT_STATUS | SCHEMA_RECONCILE | FINAL_SCHEMA_EXACT | FINAL_DB_HASH_STABLE | FINAL_OPTIONS_HASH | FINAL_BLOG_PUBLIC_RAW | OPTIONS_EXACT | POST_ROLLBACK_MGSL_TRANSIENT_ROWS | BASELINE_RESTORED | CLEANUP | REPLAY_AFTER_ROLLBACK | PRIVATE_NONCE_STATE | PRIVATE_AUDIT_STATE | LEGACY_DB_STATE_EXACT | NOINDEX_EFFECTIVE | PRODUCTION_BLOCK | HOST_SPOOF | EXTERNAL_IO_GATE | CRON_GATE | TARGET_URL_GATE | PREFIX_GATE | FRESHNESS_GATES | WRONG_SHA | WRONG_TARGET | UNPAIRED | EXPIRED | REPLAY | CORRUPT | SYMLINK_SENTINEL | SCHEMA_NEGATIVES | PRIVATE_STATE_NEGATIVES | INTERRUPTED_IMPORT | PERFORMANCE | DEBUG_REDACTION | BLOCKERS | FINAL_RECOMMENDATION`

Any full-DB/options/blog_public mismatch, new MGSL transient DB row, replay acceptance, false rolled_back, outside-root write or Production write = NO-GO.
