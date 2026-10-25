<?php
if (!defined('ABSPATH')) { exit; }
function mg_saoluu_handle_actions(&$message, &$status) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['mg_action'])) return;
    if (!current_user_can('manage_options')) wp_die('Bạn không có quyền thực hiện.');
    check_admin_referer('mg_saoluu_secure', 'mg_nonce');
    $action = sanitize_key(wp_unslash($_POST['mg_action']));
    if (in_array($action,['backup','pack_backup','pack_restore','restore_saved','restore_zip_upload','restore_sql_upload','verify_backup'],true)) mg_saoluu_debug_log('action_received',['phase'=>$action]);

    // v1.2.1 concentrates on BACKUP/RESTORE. Existing transfer and deploy
    // implementation/options remain on disk for a future migration, but no
    // archived POST or bookmarked URL may silently reactivate the old flows.
    if (in_array($action, [
        'data_export', 'data_import', 'delete_data_package',
        'deploy_create', 'deploy_install', 'delete_deploy_package',
        'unlock_deploy_production', 'lock_deploy_production',
    ], true)) {
        $status = 'error';
        $message = 'MG Sao Lưu v1.2.1 chỉ hỗ trợ Backup & Restore. Data Transfer/Deploy đã tạm khóa và sẽ thuộc sản phẩm riêng.';
        return;
    }

    // RC release cannot authorize production restore, even via a forged/stale admin POST.
    // Keep security_lock_restore available to revoke any old unlock token.
    if (strpos(MG_SAOLUU_VERSION, '-rc') !== false && in_array($action, [
        'security_restore_challenge', 'security_sign_restore_challenge', 'security_apply_restore_approval',
    ], true)) {
        $status = 'error';
        $message = 'Bản RC không hỗ trợ cấp quyền Restore production. Chỉ kiểm thử trên clone độc lập.';
        return;
    }

    if ($action === 'setup_environment') {
        $result = mg_saoluu_save_environment_choice($_POST['environment'] ?? 'auto', sanitize_text_field(wp_unslash($_POST['environment_verify'] ?? '')));
        if (is_wp_error($result)) { $status = 'error'; $message = $result->get_error_message(); $_GET['setup'] = 1; $_GET['step'] = 1; return; }
        $status = 'success'; $message = 'Đã xác nhận môi trường.'; $_GET['setup'] = 1; $_GET['step'] = 2; return;
    }

    if ($action === 'setup_storage') {
        update_option('mg_saoluu_retention', max(2, min(50, (int) ($_POST['retention'] ?? 7))), false);
        update_option('mg_saoluu_retention_full', max(2, min(50, (int) ($_POST['retention_full'] ?? 4))), false);
        update_option('mg_saoluu_retention_rollback', max(2, min(50, (int) ($_POST['retention_rollback'] ?? 3))), false);
        $dir = mg_saoluu_get_backup_dir();
        $storage = mg_saoluu_storage_preflight();
        if (is_wp_error($storage)) { $status = 'error'; $message = $storage->get_error_message(); $_GET['setup'] = 1; $_GET['step'] = 2; return; }
        $status = 'success'; $message = 'Nơi lưu bản sao đã sẵn sàng.'; $_GET['setup'] = 1; $_GET['step'] = 3; return;
    }

    if ($action === 'setup_security') {
        update_option('mg_saoluu_block_emails', isset($_POST['block_emails']) ? '1' : '0', false);
        update_option('mg_saoluu_force_noindex', isset($_POST['force_noindex']) ? '1' : '0', false);
        $status = 'success'; $message = 'Đã lưu lớp bảo vệ theo môi trường.'; $_GET['setup'] = 1; $_GET['step'] = 4; return;
    }

    if ($action === 'setup_test') {
        $action_lock = mg_saoluu_acquire_lock();
        if (is_wp_error($action_lock)) { $status = 'error'; $message = $action_lock->get_error_message(); return; }
        try {
        $test = mg_saoluu_test_database_tools();
        if (is_wp_error($test)) { $status = 'error'; $message = $test->get_error_message(); $_GET['setup'] = 1; $_GET['step'] = 4; return; }
        if (isset($_POST['create_test_backup'])) {
            $backup = mg_saoluu_create_backup('sql', 'Bản kiểm tra mã hóa sau khi cài plugin', 'backup_setup_test', true, ['encrypt' => true, 'upload_drive' => false]);
            if (is_wp_error($backup)) { $status = 'error'; $message = $backup->get_error_message(); $_GET['setup'] = 1; $_GET['step'] = 4; return; }
            mg_saoluu_log_event('setup_test_backup', 'Đã tạo bản kiểm tra', ['file' => $backup]);
        }
        $status = 'success'; $message = 'Kiểm tra đạt. Plugin có thể tạo bản sao database.'; $_GET['setup'] = 1; $_GET['step'] = 5; return;
        } finally { mg_saoluu_release_lock($action_lock); }
    }

    if ($action === 'setup_finish') {
        update_option('mg_saoluu_setup_complete', '1', false);
        mg_saoluu_log_event('setup_complete', 'Hoàn tất thiết lập ban đầu');
        $status = 'success'; $message = 'Thiết lập hoàn tất. Plugin đã sẵn sàng.'; $_GET['setup'] = 0;
        $after_setup = sanitize_key(wp_unslash($_POST['after_setup'] ?? 'overview'));
        $_GET['tab'] = $after_setup === 'drive' ? 'schedule' : 'overview';
        return;
    }

    if ($action === 'save_storage') {
        $enable_encrypt = isset($_POST['encrypt_default']);
        $enable_drive = isset($_POST['drive_auto_upload']);
        if ($enable_encrypt && mg_saoluu_encryption_password('') === '') {
            $status = 'error'; $message = 'Không tạo được Recovery Key tự động. Hãy kiểm tra OpenSSL trong tab Kiểm tra hệ thống.'; return;
        }
        if (!$enable_encrypt && (isset($_POST['auto_sql']) || isset($_POST['auto_full']) || $enable_drive)) {
            $status = 'error'; $message = 'Lịch sao lưu/offsite bắt buộc mã hóa và lưu Recovery Key.'; return;
        }
        if ($enable_drive && !mg_saoluu_drive_is_connected()) {
            $status = 'error'; $message = 'Chưa kết nối Google Drive nên chưa thể bật tự động tải lên.'; return;
        }
        update_option('mg_saoluu_retention', max(2, min(50, (int) ($_POST['retention'] ?? 7))), false);
        update_option('mg_saoluu_retention_full', max(2, min(50, (int) ($_POST['retention_full'] ?? 4))), false);
        update_option('mg_saoluu_retention_rollback', max(2, min(50, (int) ($_POST['retention_rollback'] ?? 3))), false);
        update_option('mg_saoluu_auto_sql', isset($_POST['auto_sql']) ? '1' : '0', false);
        update_option('mg_saoluu_auto_full', isset($_POST['auto_full']) ? '1' : '0', false);
        update_option('mg_saoluu_encrypt_default', $enable_encrypt ? '1' : '0', false);
        update_option('mg_saoluu_drive_auto_upload', $enable_drive ? '1' : '0', false);
        mg_saoluu_reschedule_cron();
        mg_saoluu_log_event('storage_settings', 'Đã cập nhật lịch, mã hóa và nơi lưu');
        $status = 'success'; $message = 'Đã lưu lịch sao lưu và nơi lưu.'; return;
    }


    if ($action === 'save_drive_settings') {
        $client_id = sanitize_text_field(wp_unslash($_POST['drive_client_id'] ?? ''));
        $client_secret = trim((string) wp_unslash($_POST['drive_client_secret'] ?? ''));
        $folder_id = sanitize_text_field(wp_unslash($_POST['drive_folder_id'] ?? ''));
        if ($client_secret !== '' && !mg_saoluu_crypto_available() && !defined('MG_SAOLUU_GOOGLE_CLIENT_SECRET')) {
            $status = 'error'; $message = 'Hosting chưa hỗ trợ mã hóa an toàn nên plugin không lưu Client Secret vào database. Hãy bật OpenSSL hoặc đặt Client Secret trong wp-config.php.'; return;
        }
        if (!defined('MG_SAOLUU_GOOGLE_CLIENT_ID')) update_option('mg_saoluu_drive_client_id', $client_id, false);
        if (!defined('MG_SAOLUU_GOOGLE_CLIENT_SECRET') && $client_secret !== '') {
            $encrypted_secret = mg_saoluu_encrypt_secret($client_secret);
            if ($encrypted_secret === '') { $status = 'error'; $message = 'Không thể mã hóa Client Secret. Cấu hình chưa được lưu.'; return; }
            update_option('mg_saoluu_drive_client_secret', $encrypted_secret, false);
        }
        if ($folder_id !== '') update_option('mg_saoluu_drive_folder_id', $folder_id, false); else delete_option('mg_saoluu_drive_folder_id');
        mg_saoluu_log_event('drive_settings', 'Đã cập nhật cấu hình Google Drive');
        $status = 'success'; $message = 'Đã lưu cấu hình Google Drive. Bước tiếp theo là bấm Kết nối Google Drive.'; return;
    }

    if ($action === 'drive_disconnect') {
        mg_saoluu_drive_disconnect();
        update_option('mg_saoluu_drive_auto_upload', '0', false);
        mg_saoluu_log_event('drive_disconnected', 'Đã ngắt Google Drive');
        $status = 'success'; $message = 'Đã ngắt kết nối Google Drive.'; return;
    }

    if ($action === 'drive_test') {
        $result = mg_saoluu_drive_test_connection();
        if (is_wp_error($result)) { $status = 'error'; $message = $result->get_error_message(); }
        else { $email = sanitize_email($result['user']['emailAddress'] ?? ''); $status = 'success'; $message = 'Kết nối Google Drive hoạt động' . ($email ? ': ' . $email : '.'); }
        return;
    }

    if ($action === 'drive_upload') {
        $name = basename(sanitize_file_name(wp_unslash($_POST['file_name'] ?? '')));
        if (!mg_saoluu_is_backup_filename($name)) { $status = 'error'; $message = 'Tên file không hợp lệ.'; return; }
        $path = mg_saoluu_get_backup_dir() . $name;
        if (!is_readable($path) || is_link($path)) { $status = 'error'; $message = 'Không tìm thấy file an toàn.'; return; }
        if (!mg_saoluu_is_encrypted_backup($path)) { $status = 'error'; $message = 'Chỉ gửi bản mã hóa .mgbackup lên Google Drive.'; return; }
        $result = mg_saoluu_drive_upload_file($path, $name);
        if (is_wp_error($result)) { $status = 'error'; $message = $result->get_error_message(); return; }
        $mf = mg_saoluu_get_backup_dir() . 'metadata.json'; $meta = mg_saoluu_read_metadata($mf);
        if (!isset($meta[$name])) $meta[$name] = [];
        $meta[$name]['drive_status'] = 'uploaded_unverified'; $meta[$name]['drive_id'] = sanitize_text_field($result['id']); $meta[$name]['drive_link'] = esc_url_raw($result['webViewLink'] ?? ''); $meta[$name]['drive_uploaded_at'] = current_time('mysql');
        mg_saoluu_save_metadata($mf, $meta);
        mg_saoluu_log_event('drive_upload_success', 'Tải thủ công lên Google Drive', ['file' => $name]);
        $status = 'success'; $message = 'Đã tải bản sao lên Google Drive.'; return;
    }

    if ($action === 'save_security') {
        $result = mg_saoluu_save_environment_choice($_POST['environment'] ?? 'auto', sanitize_text_field(wp_unslash($_POST['environment_verify'] ?? '')));
        if (is_wp_error($result)) { $status = 'error'; $message = $result->get_error_message(); return; }
        update_option('mg_saoluu_block_emails', isset($_POST['block_emails']) ? '1' : '0', false);
        update_option('mg_saoluu_force_noindex', isset($_POST['force_noindex']) ? '1' : '0', false);
        mg_saoluu_log_event('security_settings', 'Đã cập nhật cài đặt bảo mật');
        $status = 'success'; $message = 'Đã lưu cài đặt bảo mật.'; return;
    }

    if ($action === 'security_generate_device') {
        $force = isset($_POST['rotate_device_key']);
        $name = sanitize_text_field(wp_unslash($_POST['device_name'] ?? ''));
        $result = mg_saoluu_security_ensure_device_key($force, $name);
        if (is_wp_error($result)) { $status = 'error'; $message = $result->get_error_message(); }
        else { $status = 'success'; $message = $force ? 'Đã tạo khóa thiết bị mới. Các web thật đã ghép nối khóa cũ cần ghép nối lại.' : 'Khóa thiết bị Local đã sẵn sàng.'; }
        return;
    }

    if ($action === 'security_pair_code') {
        $code = mg_saoluu_security_pairing_code();
        if (is_wp_error($code)) { $status = 'error'; $message = $code->get_error_message(); }
        else { mg_saoluu_security_output_set('pair_code', $code, 30 * MINUTE_IN_SECONDS); $status = 'success'; $message = 'Đã tạo mã ghép nối. Sao chép mã này sang tab Bảo mật của web thật trong 30 phút.'; }
        return;
    }

    if ($action === 'security_import_pair') {
        if (mg_saoluu_environment() !== 'local') {
            $sensitive = mg_saoluu_security_sensitive_confirmation(
                'pairing',
                (string) wp_unslash($_POST['admin_password'] ?? ''),
                sanitize_text_field(wp_unslash($_POST['pair_phrase'] ?? '')),
                'GHEP NOI ' . strtoupper(mg_saoluu_host()),
                sanitize_text_field(wp_unslash($_POST['pair_host'] ?? ''))
            );
            if (is_wp_error($sensitive)) { $status = 'error'; $message = $sensitive->get_error_message(); return; }
        }
        $code = (string) wp_unslash($_POST['pair_code'] ?? '');
        $result = mg_saoluu_security_import_pairing_code($code);
        if (is_wp_error($result)) { $status = 'error'; $message = $result->get_error_message(); }
        else { $status = 'success'; $message = 'Đã ghép nối thiết bị: ' . ($result['name'] ?? 'Local') . ' · ' . ($result['fingerprint'] ?? ''); }
        return;
    }

    if ($action === 'security_revoke_signer') {
        $result = mg_saoluu_security_revoke_signer(wp_unslash($_POST['device_id'] ?? ''));
        if (is_wp_error($result)) { $status = 'error'; $message = $result->get_error_message(); }
        else { $status = 'success'; $message = 'Đã thu hồi quyền của thiết bị Local.'; }
        return;
    }

    if ($action === 'security_restore_challenge') {
        $result = mg_saoluu_security_create_restore_challenge();
        if (is_wp_error($result)) { $status = 'error'; $message = $result->get_error_message(); }
        else { mg_saoluu_security_output_set('restore_challenge', $result, 10 * MINUTE_IN_SECONDS); $status = 'success'; $message = 'Đã tạo yêu cầu mở khóa Restore. Sao chép mã sang Local để ký.'; }
        return;
    }

    if ($action === 'security_sign_restore_challenge') {
        $result = mg_saoluu_security_sign_restore_challenge((string) wp_unslash($_POST['restore_challenge_code'] ?? ''));
        if (is_wp_error($result)) { $status = 'error'; $message = $result->get_error_message(); }
        else { mg_saoluu_security_output_set('restore_approval', $result, 10 * MINUTE_IN_SECONDS); $status = 'success'; $message = 'Đã ký yêu cầu. Sao chép mã phê duyệt trở lại web thật.'; }
        return;
    }

    if ($action === 'security_apply_restore_approval') {
        $result = mg_saoluu_security_apply_restore_approval((string) wp_unslash($_POST['restore_approval_code'] ?? ''));
        if (is_wp_error($result)) { $status = 'error'; $message = $result->get_error_message(); }
        else { $status = 'success'; $message = 'Restore web thật đã được Local phê duyệt trong 10 phút.'; }
        return;
    }

    if ($action === 'security_lock_restore') {
        mg_saoluu_security_lock_restore();
        $status = 'success'; $message = 'Đã khóa lại Restore web thật.'; return;
    }

    if ($action === 'security_show_recovery_key') {
        $auth = mg_saoluu_security_verify_admin_password((string) wp_unslash($_POST['admin_password'] ?? ''), 'recovery_key');
        if (is_wp_error($auth)) { $status = 'error'; $message = $auth->get_error_message(); return; }
        $key = mg_saoluu_encryption_password('');
        if ($key === '') { $status = 'error'; $message = 'Không tạo/đọc được Recovery Key.'; return; }
        mg_saoluu_security_output_set('recovery_key', $key, 5 * MINUTE_IN_SECONDS);
        update_option('mg_saoluu_recovery_key_exported_at', current_time('mysql'), false);
        mg_saoluu_log_event('recovery_key_viewed', 'Hiển thị Recovery Key cho quản trị viên');
        $status = 'success'; $message = 'Recovery Key chỉ hiển thị tạm thời. Hãy lưu vào password manager rồi đóng trang.'; return;
    }

    if ($action === 'security_import_recovery_key') {
        $auth = mg_saoluu_security_verify_admin_password((string) wp_unslash($_POST['admin_password'] ?? ''), 'recovery_key_import');
        if (is_wp_error($auth)) { $status = 'error'; $message = $auth->get_error_message(); return; }
        $key = trim((string) wp_unslash($_POST['recovery_key'] ?? ''));
        if (strlen($key) < 32) { $status = 'error'; $message = 'Recovery Key không hợp lệ hoặc quá ngắn.'; return; }
        $encrypted = mg_saoluu_encrypt_secret($key);
        if ($encrypted === '') { $status = 'error'; $message = 'Không thể mã hóa Recovery Key để lưu.'; return; }
        update_option('mg_saoluu_managed_encryption_key', $encrypted, false);
        update_option('mg_saoluu_managed_encryption_key_created_at', current_time('mysql'), false);
        update_option('mg_saoluu_recovery_key_exported_at', current_time('mysql'), false);
        mg_saoluu_log_event('recovery_key_imported', 'Khôi phục Recovery Key từ bản lưu ngoại tuyến');
        $status = 'success'; $message = 'Đã khôi phục Recovery Key vào website. Hãy kiểm tra một bản sao mã hóa trước khi dùng Production.'; return;
    }

    if ($action === 'save_tools') {
        $mysql = isset($_POST['mysql_binary']) ? mg_saoluu_normalize_path(wp_unslash($_POST['mysql_binary'])) : '';
        $dump = isset($_POST['mysqldump_binary']) ? mg_saoluu_normalize_path(wp_unslash($_POST['mysqldump_binary'])) : '';
        if ($mysql && !mg_saoluu_path_is_executable($mysql)) { $status = 'error'; $message = 'Đường dẫn mysql không chạy được.'; return; }
        if ($dump && !mg_saoluu_path_is_executable($dump)) { $status = 'error'; $message = 'Đường dẫn mysqldump không chạy được.'; return; }
        $mysql ? update_option('mg_saoluu_mysql_binary', $mysql, false) : delete_option('mg_saoluu_mysql_binary');
        $dump ? update_option('mg_saoluu_mysqldump_binary', $dump, false) : delete_option('mg_saoluu_mysqldump_binary');
        mg_saoluu_clear_runtime_cache();
        $status = 'success'; $message = 'Đã lưu công cụ database.'; return;
    }

    if ($action === 'toggle_protect') {
        $action_lock = mg_saoluu_acquire_lock();
        if (is_wp_error($action_lock)) { $status = 'error'; $message = $action_lock->get_error_message(); return; }
        try {
        $name = basename(sanitize_file_name(wp_unslash($_POST['file_name'] ?? '')));
        if (!mg_saoluu_is_backup_filename($name)) { $status = 'error'; $message = 'Tên file không hợp lệ.'; return; }
        $metadata_file = mg_saoluu_get_backup_dir() . 'metadata.json';
        $metadata = mg_saoluu_read_metadata($metadata_file);
        if (!isset($metadata[$name])) $metadata[$name] = [];
        $metadata[$name]['protected'] = empty($metadata[$name]['protected']);
        mg_saoluu_save_metadata($metadata_file, $metadata);
        $status = 'success'; $message = $metadata[$name]['protected'] ? 'Đã giữ bản này, không tự xóa.' : 'Đã bỏ chế độ giữ bản.'; return;
        } finally { mg_saoluu_release_lock($action_lock); }
    }

    if ($action === 'verify_backup') {
        $action_lock = mg_saoluu_acquire_lock();
        if (is_wp_error($action_lock)) { $status = 'error'; $message = $action_lock->get_error_message(); return; }
        try {
        $name = basename(sanitize_file_name(wp_unslash($_POST['file_name'] ?? '')));
        $path = mg_saoluu_get_backup_dir() . $name;
        if (!mg_saoluu_is_backup_filename($name) || !is_readable($path)) { $status = 'error'; $message = 'Không tìm thấy bản sao.'; return; }
        $password = (string) wp_unslash($_POST['backup_password'] ?? '');
        $result = mg_saoluu_catalog_verify($name, $password);
        if (is_wp_error($result)) { $status = 'error'; $message = 'Không xác minh được: ' . $result->get_error_message(); }
        else {
            $status = 'success';
            if (!empty($result['authenticated_encryption'])) {
                $message = 'Pack v2 PASS: SHA-256 file, XChaCha20-Poly1305, giải nén và checksum pack.sql đều khớp. CHƯA thử khôi phục.';
            } else {
                $message = $result['state'] === 'verified'
                    ? 'Đã đối chiếu SHA-256, nội dung ZIP và chữ ký của website hiện tại. CHƯA thử khôi phục.'
                    : 'Nội dung ZIP khớp nhưng không xác thực được chữ ký nguồn bằng khóa hiện tại. CHƯA thử khôi phục.';
            }
            mg_saoluu_log_event('backup_verified', $message, ['file' => $name, 'state' => $result['state']]);
        }
        return;
        } finally { mg_saoluu_release_lock($action_lock); }
    }

    if ($action === 'clear_log') {
        $private = mg_saoluu_private_state_delete('audit-log.json');
        if (is_wp_error($private)) { $status='error'; $message=$private->get_error_message(); return; }
        delete_option('mg_saoluu_audit_log'); // Legacy history only, explicit admin action.
        $status = 'success'; $message = 'Đã xóa nhật ký thao tác.'; return;
    }

    if ($action === 'save_settings') {
        $env = isset($_POST['environment']) ? sanitize_key(wp_unslash($_POST['environment'])) : 'auto';
        if (!in_array($env, ['auto', 'local', 'staging', 'production'], true)) $env = 'auto';
        $auto_env = mg_saoluu_auto_environment();
        if ($auto_env === 'production' && $env === 'local') { $status = 'error'; $message = 'Không thể đặt một tên miền công khai thành Local. Hãy dùng địa chỉ .test hoặc localhost trên máy Mac.'; return; }
        if ($auto_env === 'production' && $env === 'staging') {
            $already_verified = strtolower((string) get_option('mg_saoluu_environment_verified_host', '')) === mg_saoluu_host();
            $verify = strtoupper(trim(sanitize_text_field(wp_unslash($_POST['environment_verify'] ?? ''))));
            if (!$already_verified && $verify !== 'DAY LA WEB THU') { $status = 'error'; $message = 'Để xác nhận đây là web thử nghiệm, hãy nhập đúng: DAY LA WEB THU'; return; }
            update_option('mg_saoluu_environment_verified_host', mg_saoluu_host(), false);
        } else {
            delete_option('mg_saoluu_environment_verified_host');
        }
        update_option('mg_saoluu_environment', $env, false);
        mg_saoluu_purge_device_identity_if_nonlocal();
        update_option('mg_saoluu_block_emails', isset($_POST['block_emails']) ? '1' : '0', false);
        update_option('mg_saoluu_retention', max(2, min(50, (int) ($_POST['retention'] ?? 7))), false);
        $mysql = isset($_POST['mysql_binary']) ? mg_saoluu_normalize_path(wp_unslash($_POST['mysql_binary'])) : '';
        $dump = isset($_POST['mysqldump_binary']) ? mg_saoluu_normalize_path(wp_unslash($_POST['mysqldump_binary'])) : '';
        if ($mysql && !mg_saoluu_path_is_executable($mysql)) { $status = 'error'; $message = 'Đường dẫn mysql không chạy được.'; return; }
        if ($dump && !mg_saoluu_path_is_executable($dump)) { $status = 'error'; $message = 'Đường dẫn mysqldump không chạy được.'; return; }
        $mysql ? update_option('mg_saoluu_mysql_binary', $mysql, false) : delete_option('mg_saoluu_mysql_binary');
        $dump ? update_option('mg_saoluu_mysqldump_binary', $dump, false) : delete_option('mg_saoluu_mysqldump_binary');
        $status = 'success'; $message = 'Đã lưu cài đặt. Hãy nhìn nhãn môi trường trên thanh quản trị để kiểm tra lại.'; return;
    }

    if (in_array($action, ['unlock_production','unlock_deploy_production'], true)) {
        $status = 'error'; $message = 'v1.1.9 không còn mở khóa bằng wp-config.php. Hãy dùng ghép nối Local trong tab Bảo mật.'; return;
    }
    if (in_array($action, ['lock_production','lock_deploy_production'], true)) {
        mg_saoluu_security_lock_restore();
        $status = 'success'; $message = 'Đã khóa lại thao tác web thật.'; return;
    }

    if ($action === 'test_tools') {
        mg_saoluu_clear_runtime_cache();
        $result = mg_saoluu_test_database_tools();
        if (is_wp_error($result)) { $status = 'error'; $message = $result->get_error_message(); }
        else { $status = 'success'; $message = 'Công cụ mysqldump đã sẵn sàng.'; }
        return;
    }

    if ($action === 'delete') {
        $action_lock = mg_saoluu_acquire_lock();
        if (is_wp_error($action_lock)) { $status = 'error'; $message = $action_lock->get_error_message(); return; }
        try {
        $name = basename(sanitize_file_name(wp_unslash($_POST['file_name'] ?? '')));
        if (!mg_saoluu_is_backup_filename($name)) { $status = 'error'; $message = 'Tên file không hợp lệ.'; return; }
        $metadata_file = mg_saoluu_get_backup_dir() . 'metadata.json';
        $metadata = mg_saoluu_read_metadata($metadata_file);
        if (!empty($metadata[$name]['protected'])) { $status = 'error'; $message = 'Bản sao đang được bảo vệ; không thể xóa.'; return; }
        if (mg_saoluu_retention_group($name, $metadata[$name] ?? []) === 'safe_full') {
            $full_count = 0;
            foreach (mg_saoluu_list_backup_files(true) as $candidate) {
                $other = basename($candidate);
                if (mg_saoluu_retention_group($other, $metadata[$other] ?? []) === 'safe_full') $full_count++;
            }
            if ($full_count <= 1) { $status = 'error'; $message = 'Không xóa bộ phục hồi website cuối cùng. Hãy tạo và xác minh bản thay thế trước.'; return; }
        }
        $path = mg_saoluu_get_backup_dir() . $name;
        if (is_link($path) || !is_file($path) || !@unlink($path)) { $status = 'error'; $message = 'Không thể xóa file.'; return; }
        unset($metadata[$name]); mg_saoluu_save_metadata($metadata_file, $metadata);
        mg_saoluu_log_event('delete', 'Xóa bản sao', ['file' => $name]);
        $status = 'success'; $message = 'Đã xóa bản sao lưu.'; return;
        } finally { mg_saoluu_release_lock($action_lock); }
    }

    $lock = mg_saoluu_acquire_lock();
    if (is_wp_error($lock)) { $status = 'error'; $message = $lock->get_error_message(); return; }
    try {
        if ($action === 'pack_backup') {
            $pack = sanitize_key(wp_unslash($_POST['pack'] ?? 'settings'));
            $note = sanitize_text_field(wp_unslash($_POST['backup_note'] ?? ''));
            $password = (string) wp_unslash($_POST['backup_password'] ?? '');
            $confirm = (string) wp_unslash($_POST['backup_password_confirm'] ?? '');
            if ($password !== '' && $password !== $confirm) {
                $status='error'; $message='Hai lần nhập mật khẩu không giống nhau.'; return;
            }
            $result = mg_saoluu_create_pack_backup($pack, $note, $password, true);
            if (is_wp_error($result)) {
                $status='error'; $message=$result->get_error_message();
            } else {
                mg_saoluu_log_event('pack_backup', 'Tạo Pack backup', ['file'=>$result,'pack'=>$pack]);
                $status='success';
                $message='Đã tạo Pack mã hóa: ' . $result . '.';
            }
            return;
        }

        if ($action === 'pack_restore') {
            if (!isset($_POST['pack_restore_confirm'])) {
                $status='error'; $message='Hãy xác nhận phục hồi đúng Pack đã chọn.'; return;
            }
            $self = mg_saoluu_self_integrity();
            if (empty($self['ok'])) {
                $status='error'; $message='Đã khóa Pack Restore vì integrity plugin không đạt.'; return;
            }
            $name = basename(sanitize_file_name(wp_unslash($_POST['file_name'] ?? '')));
            if (!mg_saoluu_is_backup_filename($name)) {
                $status='error'; $message='Tên Pack không hợp lệ.'; return;
            }
            $metadata = mg_saoluu_read_metadata(mg_saoluu_get_backup_dir() . 'metadata.json');
            if (($metadata[$name]['type'] ?? '') !== 'pack') {
                $status='error'; $message='File đã chọn không phải Pack v2.'; return;
            }
            $path = mg_saoluu_get_backup_dir() . $name;
            $password = (string) wp_unslash($_POST['backup_password'] ?? '');
            if ($password === '' && mg_saoluu_encryption_constant_available()) $password = (string) MG_SAOLUU_ENCRYPTION_KEY;
            if ($password === '') $password = mg_saoluu_managed_encryption_key(false);
            if ($password === '') {
                $status='error'; $message='Không có Recovery Key để mở Pack.'; return;
            }
            $result = mg_saoluu_restore_pack($path, $password);
            if (is_wp_error($result)) {
                $status='error'; $message=$result->get_error_message();
            } else {
                mg_saoluu_log_event('pack_restore_queued', 'Đã xếp Pack Restore worker', [
                    'file'=>$name,
                    'pack'=>$result['pack'] ?? '',
                    'job_id'=>$result['job_id'] ?? '',
                ]);
                $status='success';
                $message='Đã xác minh Pack và xếp Restore worker tối giản. Job ' . ($result['job_id'] ?? '') . ' sẽ chạy sau khi WordPress kết thúc request; mở lại tab Khôi phục để xem hậu kiểm.';
            }
            return;
        }

        if ($action === 'backup') {
            $type = sanitize_key(wp_unslash($_POST['backup_type'] ?? 'sql'));
            $note = sanitize_text_field(wp_unslash($_POST['backup_note'] ?? ''));
            $encrypt = isset($_POST['encrypt_backup']);
            $password = (string) wp_unslash($_POST['backup_password'] ?? '');
            $confirm_password = (string) wp_unslash($_POST['backup_password_confirm'] ?? '');
            if ($encrypt && $password !== '' && $password !== $confirm_password) { $status = 'error'; $message = 'Hai lần nhập mật khẩu mã hóa không giống nhau.'; return; }
            if (isset($_POST['upload_drive']) && !$encrypt) { $status = 'error'; $message = 'Gửi Drive yêu cầu bật mã hóa backup.'; return; }
            $result = mg_saoluu_create_backup($type, $note, 'backup', true, [
                'encrypt' => $encrypt,
                'password' => $password,
                'upload_drive' => isset($_POST['upload_drive']),
                'purpose' => $type === 'local_copy' ? 'transfer' : 'normal',
            ]);
            if (is_wp_error($result)) { $status = 'error'; $message = $result->get_error_message(); }
            else { mg_saoluu_log_event('backup', 'Tạo bản sao', ['file' => $result, 'type' => $type]); $status = 'success'; $message = 'Đã tạo bản sao: ' . $result . '. Nên tải một bản về Mac hoặc nơi lưu riêng.'; }
            return;
        }

        if (in_array($action, ['restore_saved', 'restore_zip_upload', 'restore_sql_upload'], true)) {
            if (mg_saoluu_environment() === 'production' && strpos(MG_SAOLUU_VERSION, '-rc') !== false) {
                $status = 'error'; $message = 'Bản RC chưa được phép restore production. Chỉ kiểm thử trên clone độc lập.'; return;
            }
            $self = mg_saoluu_self_integrity();
            if (empty($self['ok'])) { $status = 'error'; $message = 'Đã chặn khôi phục vì file của MG Sao Lưu đang thiếu hoặc lệch phiên bản. Hãy cài lại đúng ZIP trước.'; return; }
            if (mg_saoluu_environment() === 'production') {
                $sensitive = mg_saoluu_security_sensitive_confirmation(
                    'restore',
                    (string) wp_unslash($_POST['admin_password'] ?? ''),
                    sanitize_text_field(wp_unslash($_POST['restore_phrase'] ?? '')),
                    mg_saoluu_environment_info()['phrase'],
                    sanitize_text_field(wp_unslash($_POST['restore_host'] ?? ''))
                );
                if (is_wp_error($sensitive)) { $status = 'error'; $message = $sensitive->get_error_message(); return; }
            }
            $confirm = mg_saoluu_check_restore_confirmation($_POST['restore_phrase'] ?? '', isset($_POST['restore_confirm']));
            if (is_wp_error($confirm)) { $status = 'error'; $message = $confirm->get_error_message(); return; }
        }

        if ($action === 'restore_saved') {
            $name = basename(sanitize_file_name(wp_unslash($_POST['file_name'] ?? '')));
            if (!mg_saoluu_is_backup_filename($name)) { $status = 'error'; $message = 'Tên file không hợp lệ.'; return; }
            $path = mg_saoluu_get_backup_dir() . $name;
            if (!is_readable($path)) { $status = 'error'; $message = 'Không tìm thấy file sao lưu.'; return; }
            $restore_files = ($_POST['restore_mode'] ?? 'database') === 'files_database';
            $change_url = isset($_POST['change_url']);
            $password = (string) wp_unslash($_POST['backup_password'] ?? '');
            $result = mg_saoluu_restore_zip($path, $restore_files, $change_url, $password);
            if (is_wp_error($result)) {
                $status = 'error';
                $message = $result->get_error_message();
            } elseif (($result['operation'] ?? 'restore') === 'rollback') {
                mg_saoluu_log_event('rollback_saved', 'Đã áp dụng checkpoint rollback', ['file' => $name]);
                $status = 'success';
                $message = 'Rollback thành công về checkpoint: ' . ($result['rollback_checkpoint'] ?? $name) . '.';
            } else {
                mg_saoluu_log_event('restore_saved', 'Khôi phục bản đang lưu', ['file' => $name]);
                $status = 'success';
                $message = 'Khôi phục thành công. Bản dự phòng trước khôi phục: ' . $result['pre_backup'];
            }
            return;
        }

        if ($action === 'restore_zip_upload') {
            $max = min((int) wp_max_upload_size(), 2 * 1024 * 1024 * 1024);
            $file = $_FILES['zip_file'] ?? [];
            $valid = mg_saoluu_validate_backup_upload($file, $max);
            if (is_wp_error($valid)) { $status = 'error'; $message = $valid->get_error_message(); return; }
            $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            $staged = mg_saoluu_stage_upload($file, $extension);
            if (is_wp_error($staged)) { $status = 'error'; $message = $staged->get_error_message(); return; }
            $restore_files = ($_POST['restore_mode'] ?? 'database') === 'files_database';
            $change_url = isset($_POST['change_url']);
            $password = (string) wp_unslash($_POST['backup_password'] ?? '');
            $result = mg_saoluu_restore_zip($staged, $restore_files, $change_url, $password);
            @unlink($staged);
            if (is_wp_error($result)) { $status = 'error'; $message = $result->get_error_message(); }
            else { mg_saoluu_log_event('restore_upload', 'Nhập và khôi phục gói ZIP'); $status = 'success'; $message = 'Đã nhập và khôi phục gói sao lưu. Bản dự phòng trước khôi phục: ' . $result['pre_backup']; }
            return;
        }

        if ($action === 'restore_sql_upload') {
            if (mg_saoluu_environment() === 'production') { $status = 'error'; $message = 'Đã chặn nhập SQL riêng trên web thật. Hãy dùng file ZIP có chữ ký được tạo từ chính web thật này.'; return; }
            $max = min(MG_SAOLUU_MAX_SQL_UPLOAD, (int) wp_max_upload_size());
            $file = $_FILES['sql_file'] ?? [];
            $valid = mg_saoluu_validate_upload($file, 'sql', $max);
            if (is_wp_error($valid)) { $status = 'error'; $message = $valid->get_error_message(); return; }
            $staged = mg_saoluu_stage_upload($file, 'sql');
            if (is_wp_error($staged)) { $status = 'error'; $message = $staged->get_error_message(); return; }
            $source_url = esc_url_raw(wp_unslash($_POST['source_url'] ?? ''));
            $result = mg_saoluu_restore_raw_sql($staged, $source_url, isset($_POST['change_url']));
            @unlink($staged);
            if (is_wp_error($result)) { $status = 'error'; $message = $result->get_error_message(); }
            else { mg_saoluu_log_event('restore_sql', 'Khôi phục file SQL riêng'); $status = 'success'; $message = 'Đã khôi phục database. Bản dự phòng trước khôi phục: ' . $result['pre_backup']; }
            return;
        }
    } finally {
        mg_saoluu_release_lock($lock);
    }
}

