# MG Sao Lưu v1.2.1

WordPress Backup → Verify → Store → Restore. Restore đã qua nghiệm thu trên clone độc lập. Trên Production, plugin chỉ cho phép khôi phục backup của chính host đó sau phê duyệt ký từ Local và xác nhận lại của quản trị viên. Không có live synchronization.

## Host → Local trial
1. Install on dedicated WordPress clone(s) with separate databases; verify WordPress 7.1+, PHP 8.3+, ZipArchive/OpenSSL, mysql/mysqldump, outside-webroot storage.
2. On authorized source Host choose **Backup → Gói Host → Local**, download encrypted `local_copy`, export the source Recovery Key to a password manager.
3. Create a fresh isolated Local clone with the same DB table prefix, disable email/payment/webhooks/indexing. Use **Restore → Nhập .mgbackup/.zip**, supply the source key, change URL (preserving GUID), restore DB+files.
4. Under **Cài đặt → Hệ thống → Debug backup/restore**, download `mg-saoluu-debug.jsonl`. Only event states and whitelisted fields; no passwords or customer details.
5. Inspect Woo HPOS, media, custom tables, theme/plugins, URL/GUID and rollback evidence. Never overwrite canonical `motgu.test` while developing.

### Scheduled backups
SQL daily and full weekly are event-driven via WP-Cron. The Overview now displays the *last actual attempt*, *last locally verified backup*, next event and failure/overdue warnings. An offsite upload error never counts as an offsite success; an uploaded Drive file remains **unverified** until download and comparison. The status record stores only timestamps and error codes, not secrets or paths. Enabling the schedule does not prove that WordPress cron ran.

### Logging
Plugin debug runs by default on Local/Staging only, OFF on Production, files stored outside webroot, 0600, 2 MB log rotation. An explicit `define('MG_SAOLUU_DEBUG', true);` overrides this for authorized staging/incident checks; disable afterwards. WordPress `WP_DEBUG_LOG` is separate. Do not expose PHP errors to public visitors.

### Constraints
- Production restore chỉ nhận backup được tạo từ chính production host, manifest hợp lệ, Local đã ghép nối ký mở khóa 10 phút và quản trị viên xác nhận lại mật khẩu + host + câu xác nhận. Không dùng tính năng này để đồng bộ Local → live Host.
- Backup excludes wp-config.php, .htaccess, Recovery Key and host secrets. Keep Recovery Kit separately.
- Google Drive uploads encrypted verified archives, but has no verified automatic download/restore flow.
- Source/contract tests cannot substitute actual independent WordPress + MariaDB/MySQL restore drill.

See [release QA](docs/RELEASE_QA.md) and [recovery runbook](RECOVERY_RUNBOOK.md).

### Giới hạn đã biết
Complex PHP-serialized values containing unavailable plugin objects, enums, cyclic references or excessively deep structures are kept unchanged during URL replacement to prevent corruption. Kiểm tra các URL cũ còn lại trên clone sau restore; URL rewrite không phải cơ chế migration tổng quát.


### Restore engine self-protection
Full backups may contain the MG Sao Luu plugin version that existed when the archive was created. During runtime Restore/Rollback, the plugin deliberately skips its own currently executing directory so an older archive cannot downgrade or replace the restore engine mid-transaction. The archived copy remains available for offline disaster recovery; restore of the plugin itself must be done separately from a trusted release ZIP/Recovery Runbook.
