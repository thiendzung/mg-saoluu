# MG Sao Lưu v1.3.0-rc1 (pre-release; backup/restore + fresh-site migration candidate)

WordPress Backup → Verify → Store → Restore. This candidate adds an opt-in, signed Local → **fresh** Production migration path. All production restore/migration writes stay hard-locked in RC. It does not sync existing customer/order data.

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

### v1.3.0-rc1 limitations
Complex PHP-serialized values containing unavailable plugin objects, enums, cyclic references or excessively deep structures are kept unchanged during URL replacement to prevent corruption. Inspect remaining old URLs on the restored clone; do not treat this pre-release as proof of complete URL migration.


## Local → fresh Production (design; RC remains locked)
- New Local Admin action creates an AES-256-GCM `local_copy` archive tagged `purpose=migration`. The original Local site retains the only signing private key.
- After pairing the Local public key on the destination, sign the exact backup SHA-256 and explicit HTTPS target domain; the Ed25519 grant expires in 10 minutes and is one-time-use.
- On a **new WordPress installation with matching table prefix**, the destination stages and independently decrypts/verifies the package in private storage before any DB import; a second explicit Admin confirmation + grant is required to apply.
- Apply uses an independently generated rollback checkpoint, URL replacement with GUID preserved, domain-matching checks, and retains the destination MG Sao Lưu plugin version.
- **Not supported:** overriding an existing content store, live WooCommerce orders/customers, nonmatching DB prefixes, multisite, or blindly copying wp-config.php, .htaccess, provider secrets and external service configs.
- The RC includes a **Staging-only QA gate**, requiring `WP_ENVIRONMENT_TYPE=staging`, `MG_SAOLUU_MIGRATION_QA_MODE=true`, `DISABLE_WP_CRON=true`, a canonical host beginning `staging.`, `stage.`, `test.` or `sandbox.`, and HTTPS. This does NOT unlock production domains or bypass signature checks.
- Before any future stable deployment: require real isolated staging tests, controlled checkout/mail/webhooks/cron isolation, a verified Recovery Kit, and Founder authorization. RC `-rc` is permanently blocked from production writes.
- Physical mid-write ENOSPC, power loss, hard-kill and complete replacement of unsupported serialized objects remain unproven. Do not describe this RC as production-ready.
