# Agent Local — MG Sao Lưu v1.2.1-rc7 / MOTGU.test acceptance

## Scope and hard boundaries

- This is **backup and restore only**. No Local-to-Production migration, Deploy, data sync or DNS work.
- Allowed: inspect/upgrade MG Sao Lưu on **motgu.test** after making a verified pre-change snapshot; run non-destructive SQL/full backup, verify/download and observe cron there.
- **Never RESTORE or import SQL over the canonical motgu.test DB/files.**
- Restore, rollback, corrupt-ZIP, symlink and injected-failure tests are confined to a separate disposable mgsl-rc7-recovery.test WordPress installation, private root and **different DB + DB user**.
- **motgu.com, AZDIGI production, payment, customers, mail/webhook endpoints, production cron and Google production credentials are out of scope.**
- No merge, release tag or production write without Founder approval. If any isolation/preflight fails, stop and report the blocker.
- Do not overwrite wp-config.php, salts, Recovery Keys, domain-specific config or active MG Sao Lưu plugin code from an imported backup.

## Exact source / package gate

1. Inspect the named PR and resolve its **exact current HEAD**; do not infer from main, old RC6 ZIP or migration PR #21. Checkout exact SHA into a **disposable clean worktree**.
2. Run: sh scripts/verify_rc3_release.sh /ABSOLUTE/PRIVATE/NEW/mg-saoluu-v1.2.1-rc7.zip. Output file must **not exist**, and path must not be inside repository/webroot.
3. Record shell exit status, exact SHA, ZIP SHA-256, source/extracted PHP lint, manifest, contracts + unsafe-config, schedule_health.php test, ZIP CRC/payload PASS.
4. Inspect main plugin header version 1.2.1-rc7, integrity manifest version and the candidate ZIP directory prefix mg-saoluu/. Never use an unverified ZIP.

## Preflight on the Mac and motgu.test — before any change

1. Record git/worktree status, plugin version, WordPress/PHP/MySQL or MariaDB versions, available disk, current WP-Cron schedule, database prefix, custom tables, Woo HPOS status, backup storage real path/permissions, existing unfinished restore journal and plugin debug status. **Redact credentials and PII**.
2. Independently backup the existing motgu.test database and plugin files, verify/read-back the backup, store separately outside webroot and record SHA. Preserve current plugin ZIP/code and current options so plugin-only rollback is possible. Do **not** remove existing checkpoints.
3. Stop if WordPress is multisite, PHP <8.3, WordPress <7.1, ZipArchive/OpenSSL or mysql/mysqldump missing, private storage unsafe, free space inadequate, an operation is active or there is an unfinished restore journal.
4. Upgrade only MG Sao Lưu on motgu.test using the verified RC7 ZIP. Confirm actual plugin header and self-integrity; do not modify other plugins/theme or WordPress core.

## Non-destructive MOTGU.test checks

1. Create one **encrypted SQL backup**, one **encrypted safe_full**. Record start/end, elapsed time, generated file size, SHA-256, catalog status and source/target scope. Export Recovery Key to a private password manager, never into issues/chats/logs.
2. Use **Verify** to read back each final encrypted artifact, not merely check ZIP existence. Download and compare SHA-256 on Mac. Protect at least one proven full backup from retention.
3. On motgu.test, test wp cron event list and an intentionally triggered wp cron event run mg_saoluu_cron_daily **once** if SQL auto-backup is configured and disk space is safe. Verify last attempt/local success and next scheduled event are truthful. **No periodic shell polling, sleep, ps or pgrep.**
4. Inspect Overview for: disabled/missing schedule, latest actual success, error codes, and Drive uploaded_unverified or failed. Do not count Drive upload as verified recovery.
5. Confirm private mg-saoluu-debug.jsonl: outside webroot, only whitelisted fields, file mode 0600, bounded rotation and no Recovery Key, passwords, absolute paths, customer/order information. Local default debug may remain on during QA; do not enable production debug.
6. If Drive is already connected to a **non-production test account**, optionally upload encrypted backup, manually download exact archive and compare SHA-256. Otherwise mark **NOT TESTED**, do not connect a production account.

## Disposable clone restore / rollback drill

1. Build mgsl-rc7-recovery.test with a dedicated DB/user and private filesystem **not symlinked to motgu.test**. Verify outbound SMTP, payment gateways, Woo Action Scheduler, webhooks and external cron are **actually isolated** before importing any DB. Noindex alone is insufficient.
2. Snapshot and hash the clean target. Install exact RC7 on clone, load a verified .mgbackup, use source Recovery Key, explicitly confirm operation, select DB+files and URL conversion. **Do not restore to motgu.test**.
3. Verify WordPress admin/front end, post/page/CPT/Artwork, media/WebP, theme/plugins, custom DB tables, Woo HPOS and order isolation, menu and links, serialized URL data, home/siteurl, unchanged GUID, database prefix, .maintenance cleanup and restore journal completed.
4. On disposable clones only, run fail-closed negatives: wrong Recovery Key, corrupted/duplicate ZIP entry, wrong table prefix, unsafe symlink/ancestor, missing mysql CLI, simulated low disk, lock contention and an interrupted import/rollback drill if safely reproducible. Verify no outside-root sentinel changed and no false completed or rolled_back journal.
5. Use the checkpoint associated with restore to roll back disposable target. Compare **DB and filesystem hashes/invariants with original target**, including new-file cleanup. A plausible UI message is not evidence of successful rollback.
6. If an unfinished/unsafe journal or partial rollback remains, stop: do not rerun restore, delete checkpoints or try a production site. Escalate with sanitized evidence.
7. WordPress WP_DEBUG_LOG is optional **on clone only**, path outside webroot. Always set WP_DEBUG_DISPLAY=false. Do not upload raw WP logs or archives to GitHub.

## Agent Local report / GO criteria

Report in the task issue, **without secrets, unredacted SQL or backups**:

- SOURCE_EXACT_SHA | FINAL_ZIP_SHA256 | SOURCE+EXTRACTED_CI | WP/PHP/SQL/CLI VERSIONS
- MOTGU_TEST_BEFORE_VERSION | MOTGU_TEST_AFTER_VERSION | PRECHANGE_SNAPSHOT_SHA
- SQL_BACKUP_VERIFY | FULL_BACKUP_VERIFY | MAC_DOWNLOAD_SHA | CRON_HEALTH | DRIVE_ROUNDTRIP
- CLONE_BEFORE_HASH | RESTORE_DB_FILES_URL_GUID_HPOS | CLONE_AFTER | NEGATIVE_TESTS
- CHECKPOINT_SHA | ROLLBACK_DB_FILES | JOURNAL_CLEANUP | DEBUG_REDACTION
- PASS/FAIL/NOT_TESTED (each gate) | BLOCKERS | RECOMMENDATION

The candidate is **NO-GO for stable/production restore** until actual clone E2E and rollback prove correct on the **same exact ZIP/SHA**. Contract CI alone is insufficient. Production backup setup is a separate Founder-controlled step after acceptance.
