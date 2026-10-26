<?php
/** Local-only, one-response Recovery Key disclosure. No transient or key generation. */
if (!defined('ABSPATH')) { exit; }

/** Return the existing key only after re-authentication; never mint or rotate it. */
function mg_saoluu_recovery_key_read_once($password) {
    if (!current_user_can('manage_options')) {
        return new WP_Error('recovery_key_forbidden', 'Không có quyền xem Recovery Key.');
    }
    if (mg_saoluu_environment() !== 'local') {
        return new WP_Error('recovery_key_local_only', 'Bản RC chỉ cho xem Recovery Key tại Local.');
    }
    $auth = mg_saoluu_security_verify_admin_password((string) $password, 'recovery_key_once');
    if (is_wp_error($auth)) return $auth;

    // A read-only lookup: encryption_password('') would create a new key.
    if (mg_saoluu_encryption_constant_available()) {
        $key = (string) MG_SAOLUU_ENCRYPTION_KEY;
    } else {
        $key = mg_saoluu_managed_encryption_key(false);
    }
    if (!is_string($key) || strlen($key) < 16) {
        return new WP_Error('recovery_key_missing', 'Không đọc được Recovery Key hiện có. Không tạo khóa mới.');
    }
    return $key;
}

/** Standalone, cache-disabled HTML; never put the key in URL, log or transient. */
function mg_saoluu_recovery_key_once_html($key, $back_url) {
    return '<!doctype html><html lang="vi"><head><meta charset="utf-8">'
        . '<meta name="robots" content="noindex,nofollow,noarchive">'
        . '<meta name="referrer" content="no-referrer">'
        . '<title>Recovery Key — MG Sao Lưu</title>'
        . '<style>body{max-width:750px;margin:50px auto;padding:20px;font:16px/1.5 system-ui,sans-serif}'
        . 'textarea{display:block;width:100%;box-sizing:border-box;min-height:110px;overflow-wrap:anywhere}'
        . 'p{margin:12px 0}a{color:#135e96}</style></head><body>'
        . '<h1>Recovery Key hiện có</h1>'
        . '<p>Chỉ hiển thị trong phản hồi này. Sao chép vào password manager có thể truy cập độc lập với máy Mac, rồi đóng trang.</p>'
        . '<p><strong>Không gửi khóa qua chat, email hoặc GitHub. Việc xem khóa không chứng minh khóa đã được lưu.</strong></p>'
        . '<textarea aria-label="Recovery Key" readonly autocomplete="off" spellcheck="false">'
        . esc_textarea($key) . '</textarea>'
        . '<p><a rel="noreferrer" href="' . esc_url($back_url) . '">Trở lại tab Sao lưu</a></p>'
        . '</body></html>';
}

function mg_saoluu_admin_post_recovery_key_once() {
    // admin-post.php invokes this handler before WordPress admin HTML is sent.
    if (headers_sent()) wp_die('Không thể thiết lập header bảo mật.', 'MG Sao Lưu', ['response' => 500]);
    nocache_headers();
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    header('Content-Type: text/html; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !current_user_can('manage_options')) {
        wp_die('Thao tác bị từ chối.', 'MG Sao Lưu', ['response' => 403]);
    }
    check_admin_referer('mg_saoluu_recovery_key_once', 'mgsl_recovery_nonce');
    // Fail closed if the installed plugin has a mismatched package signature.
    $integrity = mg_saoluu_self_integrity();
    if (empty($integrity['ok'])) {
        wp_die('Không xác minh được tính toàn vẹn plugin.', 'MG Sao Lưu', ['response' => 503]);
    }
    $password = isset($_POST['admin_password']) && is_string($_POST['admin_password'])
        ? (string) wp_unslash($_POST['admin_password']) : '';
    $key = mg_saoluu_recovery_key_read_once($password);
    if (is_wp_error($key)) {
        wp_die(esc_html($key->get_error_message()), 'MG Sao Lưu', ['response' => 403]);
    }

    // Audit only the action, never its secret value. No exported_at update: viewing != off-device escrow.
    mg_saoluu_log_event('recovery_key_viewed_once', 'Quản trị viên xem Recovery Key tại Local.');
    echo mg_saoluu_recovery_key_once_html($key, admin_url('admin.php?page=mg-saoluu-tools&tab=backup'));
    exit;
}
add_action('admin_post_mg_saoluu_recovery_key_once', 'mg_saoluu_admin_post_recovery_key_once');
