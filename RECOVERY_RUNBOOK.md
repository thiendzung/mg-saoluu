# MG Sao Lưu v1.2.1-rc3 — Recovery Kit & Disaster Restore

## Bước 0 — Trước khi có sự cố

Lưu ở **hai địa điểm độc lập**, không nằm cùng `public_html` hoặc tài khoản có thể bị xóa cùng hosting:

- Ít nhất một `.mgbackup` (DB + files) mới nhất, một bản độc lập trước thay đổi lớn, fingerprint SHA-256 của file, manifest và thời điểm sao lưu.
- **Recovery Key** hoặc mật khẩu riêng của bản mã hóa, lưu trong password manager; nếu mất key không thể mở `.mgbackup`.
- `wp-config.php` và site salts/secret, thông tin DB host/name/user (credential cất riêng, không plaintext trong Git), cấu hình `.htaccess`/rewrite/PHP/cron/cPanel, plugin/license thật; WordPress domain/permalink/SSL settings.
- Source Git SHA của theme/plugin đang dùng, môi trường PHP + MySQL/MariaDB và danh sách bảng; các khóa tích hợp thanh toán phải được kiểm soát riêng.
- Bằng chứng khôi phục trên WordPress clone với DB riêng (ngày, scope, ảnh hưởng, kiểm chứng). `checksum ok` không phải `restore tested`.

## Khi backup cần kiểm tra ngoài WordPress

Trên Mac/terminal có PHP CLI **với ZipArchive và OpenSSL**, tải bản backup về thư mục riêng mode 0700; không dùng đường dẫn trong webroot.

```sh
php tools/recovery-verify.php /path/private/full-backup.mgbackup
```

Nhập Recovery Key qua terminal/stdin khi được hỏi. Nếu cần giữ ZIP đã giải mã để dùng công cụ database độc lập:

```sh
php tools/recovery-verify.php /path/private/full-backup.mgbackup --keep=/path/private/recovered.zip
```

Công cụ sẽ kiểm tra từng checksum SHA-256 và xác thực AES-GCM khi giải mã; **không** tự xác minh chữ ký nguồn được ký bằng WordPress salts/custom signing key. Nếu có bất cứ lỗi xác minh nào, STOP, không import SQL.

## Khôi phục thử lên Herd + DBngin (khuyến nghị trước mọi restore thật)

1. Chuẩn bị WordPress core cùng phiên bản tương thích trên **host/tên miền thử riêng**; đặt `wp-config.php` clone riêng, DBngin **DB và user riêng**, không có quyền truy cập DB production hoặc `motgu.test`. Chặn email/checkout/webhook và indexing từ đầu.
2. Kiểm chứng SHA-256 của artifact, giải mã ZIP ngoài webroot và đọc manifest. Đối chiếu `db_table_prefix` và trạng thái source. Khi khác prefix, không nhập cưỡng bức vào DB có dữ liệu. Không tự động chỉnh prefix trong SQL thô.
3. Khôi phục database + media + theme/plugin theo backup set. Dùng công cụ CLI native phù hợp phiên bản; `wp db import` sử dụng credentials của **clone**. Nếu thao tác nhập lỗi, bỏ clone và dựng mới từ snapshot sạch; không import chồng như một lần thử thứ hai.
4. Khi domain thay đổi, dùng WP-CLI `search-replace --dry-run` trước, sau đó thao tác chỉ trên clone với `--skip-columns=guid`. Kiểm tra URL serialized và `home/siteurl` bằng source chính xác. Không thực hiện trên DB production.
5. Kiểm tra URL Home, Single Post, ảnh, theme CSS, WP Admin login, media, SEO data, Woo Product và HPOS Orders, các bảng MG-OS đã có, UTF-8, và quyền file/cron. Chỉ khi các bảng đúng + file đọc được mới được ghi nhận `RESTORE_DRILL_PASS`.
6. Lưu bằng chứng không có credential/PII, dispose clone theo chính sách riêng. Không xóa backup gốc hoặc các bản JetBackup của AZDIGI.

## Khi production lỗi thật

- **Lỗi theme/plugin:** rollback release code đúng Git SHA trước; không full-restore DB làm mất bài viết/đơn hàng mới.
- **Lỗi một bài/file media:** ưu tiên WordPress revision hoặc restore chọn lọc từ snapshot; tránh ghi đè toàn DB.
- **Lỗi DB:** khóa ghi/checkout khi phù hợp, chụp snapshot trạng thái lỗi nếu còn đọc được, chọn thời điểm backup rõ ràng và ước lượng dữ liệu sau thời điểm backup sẽ mất. Dùng restore runbook được Founder duyệt.
- **Mất hosting:** mở cPanel/hosting mới hoặc nhờ AZDIGI hỗ trợ full account restore. Dựng WordPress + DB/config từ Recovery Kit, giải mã backup và phục hồi bằng công cụ độc lập. Không giả định màn MG Sao Lưu còn truy cập được. Kiểm tra kết quả rồi mới mở traffic, email và checkout.

## Giới hạn hiện tại

v1.2.1-rc1 chưa có wizard restore Google Drive/offline tự động, restore thực tế qua cPanel/AZDIGI chưa nghiệm thu, dữ liệu DB+uploads không có snapshot filesystem nguyên tử, và offsite trên Google Drive chưa có xác minh tải lại/immutable retention. **Production restore: NO-GO cho tới khi có clone drill và Founder phê duyệt.**
