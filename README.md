# MG Sao Lưu v1.2.1-rc5 (pre-release; backup & restore clone)

WordPress Backup → Verify → Store → Restore. Experimental restore on isolated Local/Staging clones only. Production restore is blocked. No live synchronization.

## Host → Local trial
1. Install on dedicated WordPress clone(s) with separate databases; verify WordPress 7.1+, PHP 8.3+, ZipArchive/OpenSSL, mysql/mysqldump, outside-webroot storage.
2. On authorized source Host choose **Backup → Gói Host → Local**, download encrypted `local_copy`, export the source Recovery Key to a password manager.
3. Create a fresh isolated Local clone with the same DB table prefix, disable email/payment/webhooks/indexing. Use **Restore → Nhập .mgbackup/.zip**, supply the source key, change URL (preserving GUID), restore DB+files.
4. Under **Cài đặt → Hệ thống → Debug backup/restore**, download `mg-saoluu-debug.jsonl`. Only event states and whitelisted fields; no passwords or customer details.
5. Inspect Woo HPOS, media, custom tables, theme/plugins, URL/GUID and rollback evidence. Never overwrite canonical `motgu.test` while developing.

### Logging
Plugin debug runs by default on Local/Staging only, OFF on Production, files stored outside webroot, 0600, 2 MB log rotation. An explicit `define('MG_SAOLUU_DEBUG', true);` overrides this for authorized staging/incident checks; disable afterwards. WordPress `WP_DEBUG_LOG` is separate. Do not expose PHP errors to public visitors.

### Constraints
- RC *always blocks* production restore. Does not sync Local → live Host.
- Backup excludes wp-config.php, .htaccess, Recovery Key and host secrets. Keep Recovery Kit separately.
- Google Drive uploads encrypted verified archives, but has no verified automatic download/restore flow.
- Source/contract tests cannot substitute actual independent WordPress + MariaDB/MySQL restore drill.

See [release QA](docs/RELEASE_QA.md) and [recovery runbook](RECOVERY_RUNBOOK.md).

### v1.2.1-rc5 limitation
Complex PHP-serialized values containing unavailable plugin objects, enums, cyclic references or excessively deep structures are kept unchanged during URL replacement to prevent corruption. Inspect remaining old URLs on the restored clone; do not treat this pre-release as proof of complete URL migration.
