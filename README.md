# MG Sao Lưu

Plugin WordPress dành riêng cho **Backup → Verify → Store → Restore**. Không deploy theme hoặc đồng bộ nội dung.

**Trạng thái:** `v1.2.1-rc2` — mã RC có thể tạo backup, nhưng **chặn restore trực tiếp trên production**; bắt buộc thử trên clone trước.

## Chức năng

- Sao lưu toàn bộ database (bao gồm WooCommerce HPOS) hoặc tạo bản website gồm DB + files.
- AES-256-GCM, SHA-256/HMAC, lưu ngoài webroot, retention riêng và Google Drive upload (chưa xác minh download ngược).
- Xác minh bản sao, restore có checkpoint/rollback cố gắng, kiểm tra offline bằng `tools/recovery-verify.php`.

## Yêu cầu

WordPress **7.1+**, PHP **8.3+** (`ZipArchive`, `OpenSSL`), MySQL/MariaDB tương thích và **`mysql` + `mysqldump` CLI**. Chọn đường dẫn backup **ngoài webroot** và giữ Recovery Key riêng.

## Sử dụng

1. Cài **trên WordPress clone có database riêng**.
2. Vào **MG Sao Lưu → Cài đặt** kiểm tra PHP/CLI, storage, Recovery Key.
3. Tạo bản mã hóa → **Xác minh** → lưu offsite.
4. **Thử restore trên clone khác**; chỉ vận hành production sau khi nghiệm thu.

**Lưu ý:** Bản website *không kèm* `wp-config.php`, `.htaccess`, cấu hình hosting và Recovery Key. Cần Recovery Kit riêng; upload Drive thành công **không đồng nghĩa restore thành công**.

[Hướng dẫn khôi phục](RECOVERY_RUNBOOK.md) · [Thay đổi](CHANGELOG.md) · License: GPL-2.0-or-later.
