=== MG Sao Luu & Khoi Phuc An Toan ===
Contributors: thiendung
Tags: backup, restore, security, disaster-recovery
Requires at least: 7.1
Requires PHP: 8.3
Stable tag: 1.3.0-rc6
License: GPLv2 or later

MG Sao Luu v1.3.0-rc6: pre-release giu nguyen Backup/Restore da nghiem thu va them signed Local -> fresh Production migration QA.n ==

Backup WordPress database + files, private storage, encrypted archive, separate retention,
SHA-256 verification, Google Drive upload, protected restore and recovery runbook.
The Data Transfer/Theme Deploy functions from v1.1.9 are disabled and removed from active UI.
Legacy plugin options remain untouched; inactive Data/Deploy source files are no longer shipped.

Note: PHP ZipArchive, OpenSSL and mysql/mysqldump CLI must be checked on the target system.
A safe full backup does not include wp-config.php/.htaccess/Recovery Key. Separate Recovery Kit required.
Backup verify or cloud upload is NOT proof of a successful disaster restore.

== Installation ==

1. Install first on a disposable WordPress installation and a dedicated DB user/database.
2. Check private backup dir outside webroot, PHP extensions, MySQL CLI tools.
3. Export and store Recovery Key in password manager before scheduling backups.
4. Test encrypted database/full backup, verify catalog and restore on a separate clone.
5. Keep hosting JetBackup + offsite independently. Production only after separate acceptance.

== Changelog ==

= 1.3.0-rc6 =
* Seal explicit rollback DB state: after checkpoint import + schema reconcile, do not reapply the current target settings over the restored database.
* Capture and restore raw blog_public exactly; non-production noindex stays enforced by the runtime filter without changing the stored option.
* Move non-security binary/system/backup-list caches from DB-backed Transients to WordPress Object Cache so opening admin pages does not create new mg_options transient rows.
* Retain RC5 private replay/audit state and RC4 exact schema reconciliation.


= 1.3.0-rc6 =
* Move migration replay ledger and new operational audit events out of wp_options into atomic 0600 private JSON state outside webroot.
* Preserve legacy DB audit/replay data as read-only compatibility state.
* Prevent successful migration/rollback logging from changing mg_options after the checkpoint database has been restored.
* Keep RC4 exact table/view schema reconciliation and all RC migration security gates.


= 1.3.0-rc6 =
* Fix rollback schema reconciliation: checkpoint import now removes DB tables/views introduced after the baseline and verifies exact pre-Restore inventory before reporting rolled_back.
* Missing schema inventory or any drop/mismatch fails closed.
* Add regression coverage for the observed 12-table baseline -> 83-table migration -> exact 12-table rollback case.
\n\n= 1.3.0-rc6 =\n* Rebase signed Local -> fresh Production migration on the v1.2.1 Stable Backup/Restore hardening.\n* RC remains hard-blocked on real Production; mutation is available only on explicitly isolated Staging QA.\n* Canonical-host, fresh-target, HPOS/order, DB-prefix, Ed25519 one-time grant and URL/GUID safety gates retained.\n* Backup-tab migration picker uses metadata catalog only instead of scanning all archives.\n

= 1.2.1 =
* Stable Backup/Restore release after isolated-clone runtime acceptance through RC9.
* Production restore is same-host only and requires a valid manifest signature, paired Local signed approval, admin password, host and confirmation phrase.
* Transactional rollback, self-plugin protection, fail-closed ZIP/prefix/symlink/CLI/disk/lock gates and truthful schedule/offsite status retained.


= 1.2.1-rc9 =
* Keep the currently running MG Sao Luu plugin version intact during Restore/Rollback, even if the backup contains an older copy.
* Backup archives still retain MG Sao Luu for offline disaster recovery; runtime restore skips only the active plugin directory.
* Add regression coverage for extraction, copy, new-file bookkeeping and rollback cleanup self-protection.

= 1.2.1-rc8 =
* Fix transactional rollback: only report rolled_back after the original checkpoint is actually restored and cleanup succeeds.
* Refuse restore when the current target is not healthy enough to create a trustworthy rollback checkpoint.
* Preserve the original checkpoint if a rollback attempt fails but the safety recovery succeeds.
* Fix restore duration calculation across WordPress/server timezone differences.

= 1.2.1-rc7 =
* Show the last real scheduled backup result and missed cron events in Overview.
* Keep local verification distinct from failed or unverified Google Drive uploads.
* Add isolated regression tests for schedule health; production restore stays blocked.


= 1.2.1-rc6 =
* Honest in-progress state, PHP upload limit and linked rollback checkpoint in UI.
* Fail closed on short encryption/decryption writes and unsafe restore journal.
* Check shared/separate disk reserves; production restore stays blocked.

= 1.2.1-rc5 =
* Fix rollback checkpoint association for identical uploaded archive with generated filename.
* Keep SHA-256/journal validation and reject stale or altered checkpoints.
* Production restore remains disabled.

= 1.2.1-rc4 =
* Fix incomplete Astra object during URL migration on restore.
* Bound serialized data graph traversal, keep unsafe values unchanged; review remaining URLs on the clone.
* Local/Staging clone restore only; production restore always blocked.

= 1.2.1-rc2 =
* PHP 8.3+ / WordPress 7.1+ declared; atomic file lock for scheduled/admin jobs.
* Removed dormant Deploy/Data Transfer and PHP SQL fallback code from distribution.
* Load admin CSS/JS via WordPress enqueue instead of inlining files per request.
* RC forbids production restore; independent recovery drill is required before stable.

= 1.2.1-rc1 =
* Dedicated Backup & Recovery 5-tab UI. Transfer/Deploy POSTs fail closed.
* Native mysql CLI fail-closed; no PHP fallback after partial import.
* Verify final encrypted artifact, signatures and per-file checksums; honest offsite/restore-test statuses.
* Cloud upload requires preverified encrypted archive; automatic backup must encrypt.
* Private atomic catalog writes, last-full deletion protection, offline recovery verifier and runbook.
* SOURCE/CONTRACT QA only: no real WordPress/DBngin/cPanel restore drill yet.

= 1.2.0-rc1 =
* Backup path safety, separate retention, GUID preservation, URL-prefix gates, encryption improvements.
