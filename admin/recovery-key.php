<?php
if (!defined('ABSPATH')) { exit; }

/** One-request-only disclosure. Never materialize the key in a transient, option or URL. */
function mg_saoluu_reveal_recovery_key() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        status_header(405);
        wp_die('Chỉ cho phép POST.', 'MG Sao Luu', ['response' => 405]);
    }
    if (!current_user_can('manage_options')) {
        status_header(403);
        wp_die('Không đủ quyền.', 'MG Sao Luu', ['response' => 403]);
    }
    check_admin_referer('mg_saoluu_reveal_recovery_key', 'mg_recovery_nonce');
    if (!is_ssl()) {
        status_header(403);
        wp_die('Cần kết nối HTTPS để xem Recovery Key.', 'MG Sao Luu', ['response' => 403]);
    }
    if (headers_sent()) {
        wp_die('Không thiết lập được no-store; đã dừng hiển thị khóa.', 'MG Sao Luu', ['response' => 500]);
    }
    nocache_headers();
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
    header('Referrer-Policy: no-referrer', true);
    header('X-Content-Type-Options: nosniff', true);
    header('X-Frame-Options: DENY', true);
    header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'", true);

    $integrity = mg_saoluu_self_integrity(true);
    if (empty($integrity['ok'])) {
        wp_die('Plugin integrity không đạt; không hiển thị khóa.', 'MG Sao Luu', ['response' => 503]);
    }
    $raw_password = $_POST['admin_password'] ?? '';
    $password = is_string($raw_password) ? (string) wp_unslash($raw_password) : '';
    $auth = mg_saoluu_security_verify_admin_password($password, 'recovery_key_view');
    if (is_wp_error($auth)) {
        wp_die(esc_html($auth->get_error_message()), 'MG Sao Luu', ['response' => 403]);
    }
    // Read-only: do not mint, rotate, import, or store a key on this request.
    $key = mg_saoluu_encryption_constant_available()
        ? (string) MG_SAOLUU_ENCRYPTION_KEY
        : mg_saoluu_managed_encryption_key(false);
    if (!is_string($key) || strlen($key) < 16) {
        wp_die('Không tìm thấy Recovery Key hiện có; không tạo khóa mới.', 'MG Sao Luu', ['response' => 404]);
    }

    header('Content-Type: text/html; charset=UTF-8', true);
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow,noarchive"><title>Recovery Key - MG Sao Luu</title>';
    echo '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:760px;margin:3rem auto;padding:0 1rem;color:#20252b}pre{white-space:pre-wrap;overflow-wrap:anywhere;border:1px solid #ccc;padding:1.2rem;background:#f6f7f7}a{color:#006b9b}</style></head><body><main>';
    echo '<h1>Recovery Key hiện có</h1><p>Sao chép vào password manager có thể truy cập từ thiết bị khác. Không gửi qua chat, email hoặc GitHub. Đóng tab sau khi lưu.</p>';
    echo '<pre translate="no">' . esc_html($key) . '</pre>';
    echo '<p><a rel="noreferrer" href="' . esc_url(admin_url('admin.php?page=mg-saoluu-tools&tab=backup')) . '">Quay lại Sao lưu</a></p>';
    echo '</main></body></html>';
    exit;
}
add_action('admin_post_mg_saoluu_reveal_recovery_key', 'mg_saoluu_reveal_recovery_key');
