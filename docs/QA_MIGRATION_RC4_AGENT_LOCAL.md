# Agent Local — MG Sao Luu v1.3.0-rc4 full migration re-acceptance

## Goal
Re-run the full RC migration gate after the RC3 rollback schema blocker.

## Exact blocker being remediated
RC3 positive migration succeeded, file cleanup succeeded, and journal said rolled_back, but:
- baseline DB schema: 12 objects;
- post-migration schema: 83 objects;
- rollback checkpoint import restored data but left 71 extra tables.

RC4 must prove exact schema restoration, not merely content restoration.

## Hard boundaries
- No motgu.com / Production writes.
- No motgu.test changes.
- Local source + isolated Staging target only.
- Separate WP root, DB and DB user.
- External SMTP/payment/webhook/outbound HTTP isolated.
- No merge/tag/release.
- No unrelated plugin/theme updates.
- Do not publish Recovery Key, migration grant or raw logs.

## Package gate
Use exact HEAD + ZIP SHA from the RC4 issue handoff.
Run:
`sh scripts/verify_rc3_release.sh /ABSOLUTE/PRIVATE/NEW/mg-saoluu-v1.3.0-rc4.zip`

Require all source + extracted ZIP tests PASS and exact SHA match.

## Full positive path
Repeat the complete RC3 acceptance:
1. Local exact RC4 + self-integrity.
2. Create encrypted migration local_copy.
3. Verify archive + SHA.
4. Pair signer and create exact 10-minute target/archive-bound grant.
5. Staging fresh-target preflight.
6. Record baseline:
   - DB dump/hash;
   - exact SHOW FULL TABLES inventory with table/view type;
   - object count;
   - data counts;
   - URL/prefix/theme/GUID;
   - plugin SHA;
   - outside-root sentinel.
7. Stage archive and prove DB unchanged.
8. Apply migration.
9. Verify data/URL/GUID/runtime-plugin/health/checkpoint.
10. Record post-migration schema inventory/count.
11. Explicit rollback using the exact checkpoint.

## RC4 rollback PASS requires ALL
- journal status = rolled_back;
- rollback_attempt_status = succeeded;
- rollback_db_schema_reconcile exists;
- reconcile expected = baseline schema object count;
- reconcile before = post-migration object count;
- reconcile extra = exact number introduced;
- reconcile removed = extra;
- reconcile after = baseline count;
- final SHOW FULL TABLES inventory + object types exactly equal baseline;
- final DB dump/hash/invariants equal baseline;
- file cleanup PASS;
- runtime MG Sao Luu SHA unchanged;
- sentinel unchanged;
- .maintenance absent.

Any extra/missing/type-mismatched DB object after rollback = FAIL.

## Full negative gates
Re-run all RC3 negatives:
- real Production RC block;
- host spoof;
- external-I/O gate;
- cron gate;
- target URL;
- prefix mismatch;
- all freshness gates including HPOS;
- wrong SHA;
- wrong target;
- unpaired signer;
- expired grant;
- replay;
- corrupt archive;
- symlink/outside-root sentinel;
- lock/restore safety gates if previously part of fixture.
Interrupted import: only if safe deterministic injection exists; otherwise NOT TESTED.

## New schema negatives
1. Missing baseline schema inventory must fail closed; never report rolled_back.
2. Simulated/drop failure must produce rollback failure, not false success.
3. Schema mismatch after reconcile must fail closed.

## Report
`EXACT_SHA | ZIP_SHA256 | CI | SOURCE_VERSION_INTEGRITY | MIGRATION_ARCHIVE_SHA | GRANT_BINDING | BASELINE_DB_HASH | BASELINE_SCHEMA_COUNT | BASELINE_SCHEMA_FINGERPRINT | TARGET_PREFLIGHT | STAGE_DB_UNCHANGED | APPLY_STATUS | POST_MIGRATION_SCHEMA_COUNT | URL_GUID | DATA_COUNTS_HPOS | RUNTIME_PLUGIN_SHA | CHECKPOINT_SHA | ROLLBACK_STATUS | ROLLBACK_ATTEMPT_STATUS | SCHEMA_RECONCILE_EXPECTED_BEFORE_EXTRA_REMOVED_AFTER | FINAL_SCHEMA_EXACT | FINAL_DB_HASH | BASELINE_RESTORED | CLEANUP | PRODUCTION_BLOCK | HOST_SPOOF | EXTERNAL_IO_GATE | CRON_GATE | TARGET_URL_GATE | PREFIX_GATE | FRESHNESS_GATES | WRONG_SHA | WRONG_TARGET | UNPAIRED | EXPIRED | REPLAY | CORRUPT | SYMLINK_SENTINEL | SCHEMA_NEGATIVES | INTERRUPTED_IMPORT | PERFORMANCE | DEBUG_REDACTION | BLOCKERS | FINAL_RECOMMENDATION`

No secret values in published evidence.
