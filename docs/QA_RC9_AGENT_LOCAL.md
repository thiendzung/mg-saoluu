# Agent Local — MG Sao Luu v1.2.1-rc9 final runtime acceptance

## Goal
Verify the RC9 self-restore protection and complete the remaining runtime negative tests. Backup/Restore only.

## Hard boundaries
- No motgu.com/AZDIGI production changes.
- Do not restore over canonical motgu.test.
- Use a fresh disposable clone with separate WordPress root, DB and DB user.
- Block outbound SMTP, payments, webhooks, external cron and Woo Action Scheduler effects.
- No merge/tag/release.
- No polling loops, sleep, ps or pgrep.

## Exact package gate
Use the exact HEAD from the RC9 PR and its recorded deterministic ZIP SHA-256.
Run:
`sh scripts/verify_rc3_release.sh /ABSOLUTE/PRIVATE/NEW/mg-saoluu-v1.2.1-rc9.zip`
Require source + extracted ZIP lint/integrity/contracts/unsafe/schedule tests PASS and exact ZIP SHA match. Any mismatch: STOP.

## Positive self-protection test
1. Prepare a healthy isolated baseline and install exact RC9.
2. Use a verified full backup known to contain MG Sao Luu RC7.
3. Record SHA-256 of the active RC9 files at least:
   - `mg-saoluu.php`
   - `includes/restore.php`
4. Restore DB + files with URL conversion.
5. Verify:
   - Restore completed and health PASS.
   - Active plugin version remains RC9; it MUST NOT become RC7.
   - The two RC9 file SHA-256 values above remain unchanged across Restore.
   - Normal plugins/themes/uploads from backup restore as expected.
   - Journal duration realistic; .maintenance absent.
6. Explicitly rollback using the exact linked checkpoint.
7. Verify:
   - journal `rolled_back`;
   - `rollback_attempt_status=succeeded`;
   - baseline DB/files invariants restored;
   - active MG Sao Luu remains RC9 and both file SHA values are unchanged;
   - cleanup truthful and .maintenance absent.

A backup archive is allowed to CONTAIN the older MG Sao Luu copy. Runtime restore must simply not apply it to the executing plugin directory.

## Remaining runtime negative tests
Run on disposable clone only:
1. Duplicate ZIP entry: restore must fail closed before DB/file mutation.
2. Wrong DB prefix: fail before import.
3. Symlink leaf destination: fail closed; outside sentinel unchanged.
4. Symlinked ancestor: fail closed; outside sentinel unchanged.
5. Missing mysql CLI: fail before DB mutation and preserve configured path afterward.
6. Low-disk/preflight simulation using the supported test/filter mechanism; do not fill the physical disk.
7. Lock contention: re-confirm nonblocking operation lock.
8. Interrupted import/rollback only if safely reproducible with a controlled injected failure. If no safe deterministic mechanism exists, mark NOT TESTED; do not kill DB/PHP processes arbitrarily.

Wrong Recovery Key and corrupt encrypted backup were already runtime PASS in RC8; re-run only if package behavior changed unexpectedly.

## Evidence
Return:
`EXACT_SHA | ZIP_SHA256 | CI | RC9_SHA_BEFORE | RESTORE_STATUS | RC9_SHA_AFTER_RESTORE | RESTORE_COUNTS | ROLLBACK_STATUS | ROLLBACK_ATTEMPT_STATUS | RC9_SHA_AFTER_ROLLBACK | BASELINE_RESTORED | CLEANUP | MAINTENANCE | DUPLICATE_ZIP | WRONG_PREFIX | SYMLINK_LEAF | SYMLINK_ANCESTOR | MISSING_CLI | LOW_DISK | LOCK_CONTENTION | INTERRUPTED_IMPORT | DEBUG_REDACTION | BLOCKERS | FINAL_RECOMMENDATION`

## Decision
Any self-downgrade, false `rolled_back`, DB/files mismatch, outside-root write, or unsafe cleanup is NO-GO.
Production restore remains blocked until this exact RC9 ZIP passes the required runtime gates.
