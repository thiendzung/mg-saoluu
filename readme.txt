=== MG Sao Luu & Khoi Phuc An Toan ===
Contributors: thiendung
Tags: backup, restore, security, disaster-recovery
Requires at least: 7.1
Requires PHP: 8.3
Stable tag: 1.3.1
Current release: 1.3.2-rc2 (candidate, not stable)
License: GPLv2 or later

Candidate v1.3.2-rc2: P2A CLI checkpoint plus Local-only one-time Recovery Key disclosure; not released.

== Description ==

Backup WordPress database + files, private storage, SHA-256 verification, checkpoint rollback va tu dong doi domain khi Restore.
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

= 1.3.2-rc2 (candidate) =
* Add Local-only existing Recovery Key one-time display inside simple Backup tab with admin re-auth and no-store response.
* Block historical show/import actions; encryption/offsite and Production remain separately gated.

= 1.3.2-rc1 (candidate) =
* Adds WP-CLI-only backup/list/verify for protected DB checkpoints; no CLI restore.
* Requires isolated clone QA before installing into a WordPress runtime.

= 1.3.1 =
* Active UI chi con Sao luu, Khoi phuc, Ban da luu.
* Full Backup luon gom database + files; ZIP Deflate level 1 uu tien toc do.
* Full Restore luon database + files, tao checkpoint truoc khi ghi va tu rewrite domain nguon sang domain hien tai.
* Bo signer/challenge/unlock va phan biet Production/Local/Staging khoi luong su dung.
* Giu checksum, DB prefix guard, path/symlink guard, snapshot prune va automatic rollback.


= 1.3.0-rc9 =
* Move Pack DB apply/post-verify/rollback into a standalone CLI worker that never bootstraps WordPress/plugins/themes/mu-plugins.
* Handoff the existing flock until PHP process exit; worker starts only after all WordPress/native shutdown callbacks finish.
* Pack v2 manifest adds deterministic scope state hash; post-restore mismatch automatically restores the exact pre-apply checkpoint.
* Add safe QA injections for schema mismatch and post-verify mismatch.
* UI reports queued/running/completed/reverted truthfully from private journal.


= 1.3.0-rc7 =
* Simplify Backup/Restore into small data Packs: Settings, WordPress Content, Full DB.
* Separate Migration/domain rewrite from Restore; Pack Restore is same-host and same-prefix only.
* Settings Pack excludes WordPress transient rows.
* Content Pack keeps posts/postmeta + taxonomy + comments as one consistent table group.
* Pack v2 uses native mysqldump, Zstd -1 when available, and streaming XChaCha20-Poly1305 encryption.
* Restore creates a checkpoint of the same Pack only.
* Routine binary/system/backup-list/self-integrity caches no longer write DB transients.


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
