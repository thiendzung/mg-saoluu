<?php
if (!defined('ABSPATH')) { exit; }


function mg_saoluu_purge_device_identity_if_nonlocal() {
    if (!function_exists('mg_saoluu_environment') || mg_saoluu_environment() === 'local') return;
    foreach (['mg_saoluu_device_private_key','mg_saoluu_device_public_key','mg_saoluu_device_id','mg_saoluu_device_name','mg_saoluu_device_created_at'] as $option) delete_option($option);
}

register_activation_hook(MG_SAOLUU_FILE, 'mg_saoluu_activate');
function mg_saoluu_activate() {
    add_option('mg_saoluu_setup_complete', '0', '', false);
    update_option('mg_saoluu_installed_version', MG_SAOLUU_VERSION, false);
    add_option('mg_saoluu_block_emails', '1', '', false);
    add_option('mg_saoluu_force_noindex', '1', '', false);
    add_option('mg_saoluu_auto_sql', '0', '', false);
    add_option('mg_saoluu_auto_full', '0', '', false);
    add_option('mg_saoluu_encrypt_default', '1', '', false);
    add_option('mg_saoluu_drive_auto_upload', '0', '', false);
    update_option('mg_saoluu_data_version', MG_SAOLUU_DATA_VERSION, false);
    if (function_exists('mg_saoluu_managed_encryption_key')) mg_saoluu_managed_encryption_key(true);
    mg_saoluu_purge_device_identity_if_nonlocal();
    set_transient('mg_saoluu_activation_redirect', 1, 60);
    mg_saoluu_reschedule_cron();
}

register_deactivation_hook(MG_SAOLUU_FILE, 'mg_saoluu_deactivate');
function mg_saoluu_deactivate() {
    wp_clear_scheduled_hook('mg_saoluu_cron_daily');
    wp_clear_scheduled_hook('mg_saoluu_cron_weekly');
    delete_transient('mg_saoluu_operation_lock');
    delete_transient('mg_saoluu_production_restore_unlocked');
    delete_transient('mg_saoluu_production_deploy_unlocked');
}


add_action('admin_init', 'mg_saoluu_maybe_upgrade', 1);
function mg_saoluu_maybe_upgrade() {
    $installed = (string) get_option('mg_saoluu_installed_version', '');
    if ($installed === MG_SAOLUU_VERSION) return;
    $looks_existing = get_option('mg_saoluu_environment', null) !== null;
    // Bản vá và nâng cấp nhỏ giữ nguyên thiết lập đã hoàn tất.
    // Chỉ cài mới mới cần chạy trình thiết lập ban đầu.
    if ($installed === '' && !$looks_existing) {
        update_option('mg_saoluu_setup_complete', '0', false);
        set_transient('mg_saoluu_activation_redirect', 1, 60);
    }
    update_option('mg_saoluu_installed_version', MG_SAOLUU_VERSION, false);
    update_option('mg_saoluu_data_version', MG_SAOLUU_DATA_VERSION, false);
    add_option('mg_saoluu_encrypt_default', '1', '', false);
    add_option('mg_saoluu_drive_auto_upload', '0', '', false);
    if (function_exists('mg_saoluu_managed_encryption_key')) mg_saoluu_managed_encryption_key(true);
    if (function_exists('mg_saoluu_security_ensure_device_key') && mg_saoluu_environment() === 'local' && mg_saoluu_security_sodium_available()) mg_saoluu_security_ensure_device_key(false);
    mg_saoluu_purge_device_identity_if_nonlocal();
}


add_action('admin_init', 'mg_saoluu_activation_redirect', 2);
function mg_saoluu_activation_redirect() {
    if (!get_transient('mg_saoluu_activation_redirect')) return;
    delete_transient('mg_saoluu_activation_redirect');
    if (!current_user_can('manage_options') || wp_doing_ajax() || is_network_admin()) return;
    wp_safe_redirect(admin_url('admin.php?page=mg-saoluu-tools&setup=1&step=1'));
    exit;
}

function mg_saoluu_setup_complete() {
    return get_option('mg_saoluu_setup_complete', '0') === '1';
}

function mg_saoluu_signing_key_is_custom() {
    return defined('MG_SAOLUU_SIGNING_KEY') && is_string(MG_SAOLUU_SIGNING_KEY) && strlen(MG_SAOLUU_SIGNING_KEY) >= 32;
}

function mg_saoluu_signing_key() {
    return mg_saoluu_signing_key_is_custom() ? MG_SAOLUU_SIGNING_KEY : wp_salt('auth');
}

function mg_saoluu_production_constant_enabled() {
    return defined('MG_SAOLUU_ALLOW_PRODUCTION_RESTORE') && MG_SAOLUU_ALLOW_PRODUCTION_RESTORE === true;
}

function mg_saoluu_log_event($event, $message = '', $context = []) {
    $logs = get_option('mg_saoluu_audit_log', []);
    if (!is_array($logs)) $logs = [];
    $user = wp_get_current_user();
    array_unshift($logs, [
        'time' => current_time('mysql'),
        'event' => sanitize_key($event),
        'message' => sanitize_text_field($message),
        'environment' => mg_saoluu_environment(),
        'host' => mg_saoluu_host(),
        'user_id' => (int) get_current_user_id(),
        'user' => $user && $user->exists() ? $user->user_login : 'system',
        'context' => is_array($context) ? array_map('sanitize_text_field', $context) : [],
    ]);
    update_option('mg_saoluu_audit_log', array_slice($logs, 0, 100), false);
}

/** Do not reuse an earlier plugin build's displayed status on the Overview tab. */
function mg_saoluu_system_checks_cache_key() {
    return 'mg_saoluu_system_checks_v_' . str_replace(['.', '-'], '_', MG_SAOLUU_VERSION);
}

function mg_saoluu_system_checks($force = false) {
    static $runtime = null;
    $cache_key = mg_saoluu_system_checks_cache_key();
    if (!$force && is_array($runtime)) return $runtime;
    if (!$force) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $runtime = $cached;
    }
    $dir = mg_saoluu_get_backup_dir();
    $free = @disk_free_space($dir);
    $inside = !mg_saoluu_storage_path_safe($dir);
    $storage_check = mg_saoluu_storage_preflight();
    $checks = [
        'zip' => ['label' => 'Tạo file ZIP', 'ok' => class_exists('ZipArchive'), 'detail' => class_exists('ZipArchive') ? 'Sẵn sàng' : 'PHP chưa bật ZipArchive'],
        'storage' => ['label' => 'Thư mục lưu', 'ok' => !is_wp_error($storage_check), 'detail' => is_wp_error($storage_check) ? $storage_check->get_error_message() : $dir],
        'outside_web' => ['label' => 'Nằm ngoài thư mục website', 'ok' => !$inside && !is_wp_error($storage_check), 'detail' => $inside ? 'KHÔNG AN TOÀN: backup trong webroot' : (is_wp_error($storage_check) ? 'Cần sửa nơi lưu; không fallback vào wp-content' : 'Đã xác minh ngoài webroot')],
        'space' => ['label' => 'Dung lượng trống', 'ok' => $free === false || $free >= 500 * 1024 * 1024, 'detail' => $free === false ? 'Hosting không cung cấp số liệu' : size_format($free)],
        'database' => ['label' => 'Công cụ database', 'ok' => (bool) mg_saoluu_find_binary('mysqldump') && (bool) mg_saoluu_find_binary('mysql'), 'detail' => mg_saoluu_find_binary('mysqldump') && mg_saoluu_find_binary('mysql') ? 'mysqldump + mysql sẵn sàng' : 'Thiếu công cụ SQL CLI; backup/restore sẽ dừng an toàn'],
        'signing' => ['label' => 'Khóa ký riêng', 'ok' => mg_saoluu_signing_key_is_custom(), 'detail' => mg_saoluu_signing_key_is_custom() ? 'Đã cấu hình' : 'Đang dùng khóa WordPress'],
        'crypto' => ['label' => 'Mã hóa AES-256-GCM', 'ok' => mg_saoluu_crypto_available(), 'detail' => mg_saoluu_crypto_available() ? 'Sẵn sàng' : 'OpenSSL chưa hỗ trợ'],
        'encryption_key' => ['label' => 'Recovery Key tự động', 'ok' => mg_saoluu_encryption_key_available(), 'detail' => mg_saoluu_encryption_constant_available() ? 'Dùng khóa tương thích từ wp-config.php' : (mg_saoluu_encryption_key_available() ? 'Plugin quản lý tự động' : 'Chưa tạo được khóa')],
        'drive' => ['label' => 'Google Drive', 'ok' => mg_saoluu_drive_is_connected(), 'detail' => mg_saoluu_drive_is_connected() ? 'Đã kết nối' : 'Chưa kết nối'],
        'plugin_integrity' => ['label' => 'File của MG Sao Lưu', 'ok' => mg_saoluu_self_integrity()['ok'], 'detail' => mg_saoluu_self_integrity_label()],
        'acf' => ['label' => 'ACF / dữ liệu tùy chỉnh', 'ok' => true, 'detail' => (function_exists('mg_saoluu_acf_available') && mg_saoluu_acf_available()) ? 'Đã nhận ACF' : 'ACF nằm ngoài phạm vi sao lưu lõi'],
        'multisite' => ['label' => 'WordPress Multisite', 'ok' => !is_multisite(), 'detail' => is_multisite() ? 'Chưa hỗ trợ Multisite' : 'Website đơn'],
    ];
    set_transient($cache_key, $checks, 2 * MINUTE_IN_SECONDS);
    return $runtime = $checks;
}


function mg_saoluu_save_environment_choice($env, $verify = '') {
    $env = sanitize_key((string) $env);
    if (!in_array($env, ['auto', 'local', 'staging', 'production'], true)) $env = 'auto';
    $auto_env = mg_saoluu_auto_environment();
    if ($auto_env === 'production' && $env === 'local') {
        return new WP_Error('env_local_public', 'Không thể đặt một tên miền công khai thành Local. Local nên dùng .test hoặc localhost.');
    }
    if ($auto_env === 'production' && $env === 'staging') {
        $already = strtolower((string) get_option('mg_saoluu_environment_verified_host', '')) === mg_saoluu_host();
        if (!$already && strtoupper(trim((string) $verify)) !== 'DAY LA WEB THU') {
            return new WP_Error('env_staging_verify', 'Hãy nhập đúng DAY LA WEB THU để xác nhận tên miền công khai này là web thử nghiệm.');
        }
        update_option('mg_saoluu_environment_verified_host', mg_saoluu_host(), false);
    } else {
        delete_option('mg_saoluu_environment_verified_host');
    }
    update_option('mg_saoluu_environment', $env, false);
    mg_saoluu_purge_device_identity_if_nonlocal();
    return true;
}

add_filter('pre_option_blog_public', 'mg_saoluu_force_noindex_nonproduction');
function mg_saoluu_force_noindex_nonproduction($pre) {
    if (mg_saoluu_environment() !== 'production' && get_option('mg_saoluu_force_noindex', '1') === '1') return '0';
    return $pre;
}


add_filter('cron_schedules', 'mg_saoluu_add_weekly_schedule');
function mg_saoluu_add_weekly_schedule($schedules) {
    if (!isset($schedules['weekly'])) $schedules['weekly'] = ['interval' => 7 * DAY_IN_SECONDS, 'display' => 'Mỗi tuần'];
    return $schedules;
}

add_action('mg_saoluu_cron_daily', 'mg_saoluu_run_daily_backup');
function mg_saoluu_run_daily_backup() {
    if (get_option('mg_saoluu_auto_sql', '0') !== '1') return;
    $lock = mg_saoluu_acquire_lock();
    if (is_wp_error($lock)) { mg_saoluu_log_event('cron_backup_locked', $lock->get_error_message()); return; }
    try {
    $result = mg_saoluu_create_backup('sql', 'Tự động hằng ngày', 'backup_auto', true, [
        'purpose' => 'scheduled',
        'encrypt' => true,
        'use_default_encryption' => true,
        'upload_drive' => null,
    ]);
    } finally { mg_saoluu_release_lock($lock); }
    mg_saoluu_log_event(is_wp_error($result) ? 'cron_backup_failed' : 'cron_backup_success', is_wp_error($result) ? $result->get_error_message() : (string) $result);
}

add_action('mg_saoluu_cron_weekly', 'mg_saoluu_run_weekly_backup');
function mg_saoluu_run_weekly_backup() {
    if (get_option('mg_saoluu_auto_full', '0') !== '1') return;
    $lock = mg_saoluu_acquire_lock();
    if (is_wp_error($lock)) { mg_saoluu_log_event('cron_backup_locked', $lock->get_error_message()); return; }
    try {
    $result = mg_saoluu_create_backup('safe_full', 'Tự động hằng tuần', 'backup_auto', true, [
        'purpose' => 'scheduled',
        'encrypt' => true,
        'use_default_encryption' => true,
        'upload_drive' => null,
    ]);
    } finally { mg_saoluu_release_lock($lock); }
    mg_saoluu_log_event(is_wp_error($result) ? 'cron_backup_failed' : 'cron_backup_success', is_wp_error($result) ? $result->get_error_message() : (string) $result);
}

function mg_saoluu_reschedule_cron() {
    wp_clear_scheduled_hook('mg_saoluu_cron_daily');
    wp_clear_scheduled_hook('mg_saoluu_cron_weekly');
    if (get_option('mg_saoluu_auto_sql', '0') === '1') wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'mg_saoluu_cron_daily');
    if (get_option('mg_saoluu_auto_full', '0') === '1') wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'weekly', 'mg_saoluu_cron_weekly');
}

add_action('admin_menu', 'mg_saoluu_register_menu');
function mg_saoluu_register_menu() {
    add_menu_page('MG Sao Lưu', 'MG Sao Lưu', 'manage_options', 'mg-saoluu-tools', 'mg_saoluu_render_page', 'dashicons-backup', 99);
}


function mg_saoluu_is_admin_page($hook = '') {
    if ($hook === 'toplevel_page_mg-saoluu-tools') return true;
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    return $page === 'mg-saoluu-tools';
}

add_action('admin_enqueue_scripts', 'mg_saoluu_admin_assets');
function mg_saoluu_admin_assets($hook) {
    if (!mg_saoluu_is_admin_page($hook)) return;

    // Let WordPress serve/cache admin assets; do not inline-read CSS/JS on each request.
    wp_enqueue_style('mg-saoluu-admin', MG_SAOLUU_URL . 'assets/css/admin.css', [], MG_SAOLUU_VERSION);
    wp_enqueue_script('mg-saoluu-admin', MG_SAOLUU_URL . 'assets/js/admin.js', [], MG_SAOLUU_VERSION, true);
}
