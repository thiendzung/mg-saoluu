## 1.2.1-rc2 — Backup & Restore scope/compatibility (2026-10-24)

- Target WordPress 7.1+, PHP 8.3+; confirm CLI/ZipArchive/OpenSSL before install. RC explicitly blocks production restore.
- Remove inactive Data Transfer/Deploy and unused PHP SQL fallback code from distribution; existing WordPress options kept.
- Use nonblocking private-file `flock` to serialize UI and scheduled backup/restore, no polling.
- Use WordPress enqueue for admin assets; shorter README; preserve independent Recovery Kit/restore caveats.
- Tested static/contract only; real WordPress + DBngin/cPanel restore acceptance pending.

# Changelog

## 1.2.1-rc1 — 2026-10-24 (Backup & Recovery focus; staging-only)

- Reframed the product to BACKUP → VERIFY → STORE → RESTORE. Five-tab WP Admin UI; legacy Data Transfer/Deploy is no longer loaded/accessible, including old POST action names. Existing settings and legacy files retained for upgrade compatibility.
- Fail-closed native mysqldump/mysql requirements; never run PHP SQL importer after an unsuccessful CLI import on a potentially partially-mutated DB.
- Read back and verify the final archive including encrypted round-trip, content SHA-256 and current-site HMAC. Catalog distinguishes verified vs content-only provenance vs uploaded-unverified vs genuinely restore-tested.
- Separate offsite policy: Cloud upload requires an encrypted, signed/locally verified artifact; scheduled backups always require a Recovery Key. No implicit plaintext upload.
- Atomically replace the private metadata catalog, forbid deleting the final full recovery archive via UI, keep existing retention protections.
- New WordPress-independent CLI decrypt/hash inspector and RECOVERY_RUNBOOK; neither claims live production restore or HMAC provenance without original signing key.
- Setup wizard test backup now explicitly encrypted; system UI never claims PHP importer/exporter fallback is available when native SQL CLI is absent.
- Safety/contract tests & static syntax/ZIP integrity are not a substitute for full restore on an isolated WordPress + DBngin/cPanel environment. Production NO-GO.

## 1.2.0-rc1 — 2026-10-23 (pre-release; local/staging test only)

- Hardened backup storage: fail-closed outside public webroot, no wp-content fallback, symlink/path validation.
- Independent retention SQL/full/local_copy/rollback; protect flagged backups and last full.
- Content export UUID identity now deterministic when absent, without writing source post/term meta.
- URL restore: GUID excluded, prefix-scoped keyset traversal, serialized-aware, explicit error propagation.
- New backup manifest carries db_table_prefix; cross-site prefix mismatch or unknown legacy prefix blocked.
- Deploy probe failure or non-2xx triggers rollback (HTTP 2xx still requires independent visual QA).
- Cleanup newly sideloaded attachment IDs if content import fails before SQL rollback.
- Default encryption enabled for new installs; selected automated encryption cannot silently fall back to plaintext.
- Admin retention fields split by SQL/full/rollback; source/restore/backup invariants tested in local mock harness.
- Not yet a remote SSH one-click release or full disaster recovery guarantee.


## 1.1.9

### Security architecture
- Thêm Security Center: ưu tiên cảnh báo cần xử lý, thu gọn kiểm tra đã đạt.
- Thêm ghép nối Local ↔ Staging/Production bằng Ed25519.
- Private key chỉ được tạo và lưu trên Local; Staging/Production chỉ lưu public key.
- Loại bỏ yêu cầu shared `MG_SAOLUU_DEPLOY_KEY` trong luồng mới.
- Không còn yêu cầu `MG_SAOLUU_ALLOW_PRODUCTION_DEPLOY` hoặc `MG_SAOLUU_ALLOW_PRODUCTION_RESTORE`.
- Deploy package khóa theo domain, hết hạn 30 phút, có Package ID/nonce và dùng một lần.
- Staging và Production đều xác minh chữ ký Local trước khi cài package.
- Restore Production dùng challenge/approval ký từ Local và chỉ mở 10 phút.
- Production Deploy/Restore yêu cầu xác thực lại mật khẩu admin, câu xác nhận, domain và rate limit 5 lần/30 phút.
- Tự khóa quyền sửa Theme/Plugin PHP trong WordPress Admin khi plugin hoạt động.
- Thêm Recovery Key tự quản lý, có thể xuất/khôi phục sau khi nhập lại mật khẩu admin; không tự ghi đè khóa cũ nếu WordPress salts thay đổi.
- Tách mã hóa Google credentials khỏi khóa ký backup mới ghi; vẫn đọc định dạng cũ để tương thích.

### UI / System
- Tab Bảo mật gọn hơn, chỉ hiện lỗi/cảnh báo trước.
- Tab Kiểm tra hệ thống không lặp lại toàn bộ thông tin bảo mật.
- Thêm trạng thái integrity CSS/JS/plugin file rõ ràng.
- Thêm credit kín đáo `Built by Thiên Dũng` ở cuối trang quản trị/README.

### Data / Deploy
- Giữ cơ chế quét Custom Post Type, taxonomy, ACF và media có chọn lọc.
- Cấu trúc ACF tiếp tục khuyến nghị đi bằng `acf-json` cùng code.
- Deploy hàng ngày chỉ dành cho Theme/Plugin; không đẩy SQL Production theo luồng thông thường.

### Compatibility
- Vẫn đọc `MG_SAOLUU_ENCRYPTION_KEY` và `MG_SAOLUU_SIGNING_KEY` nếu cài đặt cũ đang dùng.
- Các constant `MG_SAOLUU_DEPLOY_KEY` và `MG_SAOLUU_ALLOW_PRODUCTION_*` không còn cần cho kiến trúc mới.
- Số phiên bản được đặt lại thành 1.1.9 theo yêu cầu dự án, kế thừa nhánh phát triển trước đó.
