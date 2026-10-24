# Agent Local — MG Sao Lưu v1.2.1-rc8 rollback remediation re-test

## Goal

Re-test only the RC7 failures on an isolated local clone:

1. Restore DB + files from a verified backup.
2. Explicit rollback to the plugin-created pre-restore checkpoint.
3. Verify DB + filesystem actually returned to the pre-restore state.
4. Verify restore duration is correct.
5. If these pass, continue the negative tests that RC7 STOP rule postponed.

Do not retest production. Do not touch motgu.com or AZDIGI.

## Source gate

Use the exact HEAD from the RC8 remediation PR. Do not infer from main or PR #24.

Build with:

`sh scripts/verify_rc3_release.sh /ABSOLUTE/PRIVATE/NEW/mg-saoluu-v1.2.1-rc8.zip`

Before installing, require:

- GitHub Actions PASS on that exact HEAD.
- Local source + extracted ZIP lint/integrity/contracts PASS.
- Local ZIP SHA-256 exactly matches the SHA recorded in the issue handoff.
- Plugin header + integrity manifest both report `1.2.1-rc8`.

Any mismatch: STOP.

## Important RC7 root cause to verify

RC7 had two separate problems:

- The UI parsed WordPress-local journal timestamps with the server timezone. In UTC+7 this added 25,200 seconds; a real ~7-second restore displayed ~25,207 seconds.
- An explicit rollback checkpoint could fail its health check. RC7 then restored the guard backup (the post-restore state) but labeled the transaction `rolled_back`. The DB therefore still contained the restored 122 posts while the journal could look successful. RC8 must never report this as a successful rollback.

RC8 also refuses to start a restore if the current target is already internally unhealthy (for example its database activates a theme directory that does not exist), because such a state cannot be a trustworthy rollback checkpoint.

## Environment

Use a fresh disposable clone, e.g. `mgsl-rc8-recovery.test`, with:

- independent WordPress root;
- independent database and DB user;
- no symlink back to motgu.test;
- outbound SMTP/payment/webhooks/external cron/Action Scheduler effects blocked;
- PHP/WordPress/MySQL prerequisites unchanged from the RC7 accepted environment.

Do not restore over canonical motgu.test.

## Baseline gate — before restore

Create a deliberately measurable healthy baseline:

- record post count, media count, Artwork count, product count;
- record `home`, `siteurl`, DB prefix and GUID hash;
- record active stylesheet and prove that `wp-content/themes/<stylesheet>` exists;
- create one **baseline sentinel file that exists before restore** and hash it;
- choose one file that exists in the source backup but is absent from the clone baseline; record that absence so new-file cleanup can be proven;
- snapshot/hash baseline DB and relevant filesystem invariants.

The baseline must pass the plugin restore health check. If the active theme is missing, do not repair it silently after the test begins; report the preflight block.

## Restore test

Install exact RC8 and restore the same class of verified full backup used in RC7.

Require:

- journal `operation=restore`;
- status `completed`;
- realistic duration (seconds, not +25,200 seconds);
- expected restored post/media/Artwork/product counts;
- HPOS PASS;
- URL rewrite PASS;
- GUID hash unchanged;
- source-only file appears if it is part of the backup;
- baseline sentinel remains unchanged;
- `.maintenance` removed.

Record the exact plugin-created `pre_backup` checkpoint filename + SHA-256 privately.

## Explicit rollback test

In MG Sao Lưu select the **exact checkpoint linked by the completed restore journal** and choose DB + files.

A PASS requires all of the following:

- final journal status exactly `rolled_back`;
- `rollback_attempt_status=succeeded`;
- rollback cleanup requested count reflects files introduced by the original restore when applicable;
- DB post/media/Artwork/product counts return to baseline;
- `home`, `siteurl`, prefix and GUID hash return to baseline invariants;
- baseline sentinel still matches its original hash;
- the source-only file that did not exist at baseline is removed by rollback cleanup;
- original baseline active theme exists and health check passes;
- `.maintenance` absent;
- duration is realistic.

Do not accept a green UI message as proof. Compare DB and filesystem evidence.

### Required fail-closed behavior

If applying the rollback checkpoint fails but RC8 successfully restores the guard backup made immediately before that rollback attempt:

- journal must **NOT** become `rolled_back`;
- original completed-restore checkpoint/new-file evidence must remain available;
- `rollback_attempt_status` must be `failed_reverted`;
- UI must explicitly say rollback did not succeed;
- DB must match the state that existed immediately before the rollback attempt.

If RC8 says `rolled_back` while DB/files do not match baseline: FAIL + STOP.

## Negative tests after Restore -> Rollback PASS

Only after the positive rollback passes, continue the postponed clone-only tests:

1. wrong Recovery Key;
2. corrupt ZIP;
3. duplicate ZIP entry;
4. wrong DB prefix;
5. symlink leaf;
6. symlinked ancestor;
7. missing mysql CLI;
8. low-disk/preflight simulation;
9. lock contention;
10. interrupted import/rollback if safely reproducible.

For each: PASS / FAIL / NOT TESTED + evidence. Never touch files outside clone root.

## Evidence/report

Return:

`EXACT_SHA`
`ZIP_SHA256`
`CI_RESULT`
`BASELINE_HEALTH`
`BASELINE_DB_HASH`
`BASELINE_COUNTS`
`BASELINE_THEME`
`BASELINE_SENTINEL_SHA`
`SOURCE_ONLY_FILE_BEFORE`
`RESTORE_STATUS`
`RESTORE_DURATION_SECONDS`
`RESTORE_COUNTS`
`RESTORE_GUID_HASH`
`CHECKPOINT_NAME_SHA`
`ROLLBACK_STATUS`
`ROLLBACK_ATTEMPT_STATUS`
`ROLLBACK_DURATION_SECONDS`
`ROLLBACK_CLEANUP_REQUESTED_REMOVED_FAILED`
`ROLLBACK_DB_HASH_OR_INVARIANTS`
`ROLLBACK_COUNTS`
`ROLLBACK_GUID_HASH`
`BASELINE_SENTINEL_AFTER_SHA`
`SOURCE_ONLY_FILE_AFTER_ROLLBACK`
`MAINTENANCE_CLEAN`
`NEGATIVE_TESTS`
`DEBUG_REDACTION`
`BLOCKERS`
`FINAL_RECOMMENDATION`

## Decision

- Any mismatch in DB/files after rollback: **NO-GO**.
- Any false `rolled_back`: **NO-GO**.
- Any cleanup failure: **NO-GO**.
- Positive Restore -> Rollback PASS plus relevant negative tests PASS is required before considering merge or any production restore workflow.

No merge, stable tag or production action is authorized by this task.
