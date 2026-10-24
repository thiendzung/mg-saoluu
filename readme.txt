=== MG Sao Luu & Khoi Phuc An Toan ===
Contributors: thiendung
Tags: backup, restore, security, disaster-recovery
Requires at least: 7.1
Requires PHP: 8.3
Stable tag: 1.3.0-rc3
License: GPLv2 or later

MG Sao Luu v1.3.0-rc3 la pre-release thu nghiem Backup, Verify, Restore va Local -> Production migration tren clone.

== Description ==

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

= 1.3.0-rc3 =
* URL migration uses canonical slashless bases so stored URLs with or without trailing slash are updated.
* WordPress core subdirectory URLs are rewritten before shorter home URLs.
* RC production writes remain blocked; Staging QA only until runtime acceptance.

= 1.3.0-rc2 =
* Local -> new production encrypted migration package with target-bound Ed25519 single-use approval.
* Canonical WordPress domain protects restore environment from spoofed Host header.
* Strict destination empty-site, Woo HPOS, matching DB prefix and archive checksum gates.
* Production write stays disabled in RC; requires stable build and server opt-in after runtime acceptance.

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
