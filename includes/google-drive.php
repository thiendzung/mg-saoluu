<?php
if (!defined('ABSPATH')) { exit; }

function mg_saoluu_drive_client_id() {
    if (defined('MG_SAOLUU_GOOGLE_CLIENT_ID') && MG_SAOLUU_GOOGLE_CLIENT_ID) return trim((string) MG_SAOLUU_GOOGLE_CLIENT_ID);
    return trim((string) get_option('mg_saoluu_drive_client_id', ''));
}

function mg_saoluu_drive_client_secret() {
    if (defined('MG_SAOLUU_GOOGLE_CLIENT_SECRET') && MG_SAOLUU_GOOGLE_CLIENT_SECRET) return trim((string) MG_SAOLUU_GOOGLE_CLIENT_SECRET);
    return mg_saoluu_decrypt_secret((string) get_option('mg_saoluu_drive_client_secret', ''));
}

function mg_saoluu_drive_redirect_uri() {
    return admin_url('admin.php?page=mg-saoluu-tools&tab=schedule&mg_drive_callback=1');
}

function mg_saoluu_drive_has_credentials() {
    return mg_saoluu_drive_client_id() !== '' && mg_saoluu_drive_client_secret() !== '';
}

function mg_saoluu_drive_token_data() {
    $json = mg_saoluu_decrypt_secret((string) get_option('mg_saoluu_drive_token', ''));
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function mg_saoluu_drive_save_token_data($data) {
    if (!is_array($data) || !mg_saoluu_crypto_available()) return false;
    $encrypted = mg_saoluu_encrypt_secret(wp_json_encode($data));
    if ($encrypted === '') return false;
    update_option('mg_saoluu_drive_token', $encrypted, false);
    return true;
}

function mg_saoluu_drive_is_connected() {
    $token = mg_saoluu_drive_token_data();
    return !empty($token['refresh_token']) || !empty($token['access_token']);
}

function mg_saoluu_drive_authorize_url() {
    if (!mg_saoluu_drive_has_credentials()) return '';
    $args = [
        'client_id' => mg_saoluu_drive_client_id(),
        'redirect_uri' => mg_saoluu_drive_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
        'state' => wp_create_nonce('mg_saoluu_drive_oauth'),
    ];
    return add_query_arg($args, 'https://accounts.google.com/o/oauth2/v2/auth');
}

function mg_saoluu_drive_exchange_code($code) {
    if (!mg_saoluu_drive_has_credentials()) return new WP_Error('drive_credentials', 'Chưa có Client ID và Client Secret của Google.');
    $response = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 30,
        'body' => [
            'code' => (string) $code,
            'client_id' => mg_saoluu_drive_client_id(),
            'client_secret' => mg_saoluu_drive_client_secret(),
            'redirect_uri' => mg_saoluu_drive_redirect_uri(),
            'grant_type' => 'authorization_code',
        ],
    ]);
    if (is_wp_error($response)) return $response;
    $code_http = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($code_http < 200 || $code_http >= 300 || !is_array($body) || empty($body['access_token'])) {
        $detail = is_array($body) ? ($body['error_description'] ?? $body['error'] ?? '') : '';
        return new WP_Error('drive_oauth', 'Google không cấp quyền.' . ($detail ? ' ' . sanitize_text_field($detail) : ''));
    }
    $old = mg_saoluu_drive_token_data();
    $data = [
        'access_token' => (string) $body['access_token'],
        'refresh_token' => (string) ($body['refresh_token'] ?? ($old['refresh_token'] ?? '')),
        'expires_at' => time() + max(60, (int) ($body['expires_in'] ?? 3600) - 60),
        'token_type' => (string) ($body['token_type'] ?? 'Bearer'),
        'scope' => (string) ($body['scope'] ?? ''),
    ];
    if ($data['refresh_token'] === '') return new WP_Error('drive_refresh_token', 'Google chưa trả về quyền truy cập dài hạn. Hãy ngắt kết nối rồi kết nối lại và đồng ý cấp quyền.');
    if (!mg_saoluu_drive_save_token_data($data)) return new WP_Error('drive_token_store', 'Không thể mã hóa và lưu quyền Google Drive trên hosting này.');
    return $data;
}

function mg_saoluu_drive_refresh_access_token($refresh_token) {
    $response = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 30,
        'body' => [
            'client_id' => mg_saoluu_drive_client_id(),
            'client_secret' => mg_saoluu_drive_client_secret(),
            'refresh_token' => (string) $refresh_token,
            'grant_type' => 'refresh_token',
        ],
    ]);
    if (is_wp_error($response)) return $response;
    $http = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($http < 200 || $http >= 300 || !is_array($body) || empty($body['access_token'])) {
        return new WP_Error('drive_refresh', 'Không thể làm mới kết nối Google Drive. Có thể quyền đã bị thu hồi.');
    }
    $data = mg_saoluu_drive_token_data();
    $data['access_token'] = (string) $body['access_token'];
    $data['expires_at'] = time() + max(60, (int) ($body['expires_in'] ?? 3600) - 60);
    if (!empty($body['scope'])) $data['scope'] = (string) $body['scope'];
    if (!mg_saoluu_drive_save_token_data($data)) return new WP_Error('drive_token_store', 'Không thể mã hóa và lưu quyền Google Drive trên hosting này.');
    return $data['access_token'];
}

function mg_saoluu_drive_access_token() {
    if (!mg_saoluu_drive_has_credentials()) return new WP_Error('drive_credentials', 'Chưa cấu hình Google Drive.');
    $data = mg_saoluu_drive_token_data();
    if (empty($data)) return new WP_Error('drive_disconnected', 'Google Drive chưa được kết nối.');
    if (!empty($data['access_token']) && (int) ($data['expires_at'] ?? 0) > time()) return (string) $data['access_token'];
    if (empty($data['refresh_token'])) return new WP_Error('drive_refresh_missing', 'Thiếu quyền truy cập dài hạn của Google Drive.');
    return mg_saoluu_drive_refresh_access_token($data['refresh_token']);
}

function mg_saoluu_drive_api_request($method, $url, $body = null, $headers = [], $timeout = 60) {
    $token = mg_saoluu_drive_access_token();
    if (is_wp_error($token)) return $token;
    $headers = array_merge(['Authorization' => 'Bearer ' . $token], $headers);
    $args = ['method' => strtoupper($method), 'timeout' => $timeout, 'headers' => $headers];
    if ($body !== null) {
        $args['body'] = $body;
        $args['data_format'] = 'body';
    }
    return wp_remote_request($url, $args);
}

function mg_saoluu_drive_test_connection() {
    $response = mg_saoluu_drive_api_request('GET', 'https://www.googleapis.com/drive/v3/about?fields=user,storageQuota');
    if (is_wp_error($response)) return $response;
    $http = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($http < 200 || $http >= 300 || !is_array($body)) return new WP_Error('drive_test', 'Không đọc được thông tin tài khoản Google Drive.');
    return $body;
}

function mg_saoluu_drive_ensure_folder() {
    $folder = trim((string) get_option('mg_saoluu_drive_folder_id', ''));
    if ($folder !== '') return $folder;
    $metadata = [
        'name' => 'MG Sao Luu - ' . mg_saoluu_host(),
        'mimeType' => 'application/vnd.google-apps.folder',
    ];
    $response = mg_saoluu_drive_api_request(
        'POST',
        'https://www.googleapis.com/drive/v3/files?fields=id,name',
        wp_json_encode($metadata),
        ['Content-Type' => 'application/json; charset=UTF-8']
    );
    if (is_wp_error($response)) return $response;
    $http = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($http < 200 || $http >= 300 || empty($body['id'])) return new WP_Error('drive_folder', 'Không thể tạo thư mục MG Sao Lưu trên Google Drive.');
    update_option('mg_saoluu_drive_folder_id', sanitize_text_field($body['id']), false);
    return sanitize_text_field($body['id']);
}

function mg_saoluu_drive_upload_file($file_path, $display_name = '') {
    if (!mg_saoluu_is_encrypted_backup($file_path)) {
        return new WP_Error('drive_requires_encryption', 'Backup chưa mã hóa. Không được gửi bản plaintext tới Google Drive.');
    }
    $name = basename((string) $file_path);
    $metadata = mg_saoluu_read_metadata(mg_saoluu_get_backup_dir() . 'metadata.json');
    if (empty($metadata[$name]['integrity_sha256']) ||
        ($metadata[$name]['integrity_status'] ?? '') !== 'verified' ||
        !is_file($file_path) || is_link($file_path) ||
        !hash_equals((string) $metadata[$name]['integrity_sha256'], (string) @hash_file('sha256', $file_path))) {
        return new WP_Error('drive_unverified_backup', 'Chỉ tải bản mã hóa đã được xác minh SHA-256 và chữ ký. Hãy chạy Kiểm tra bản sao trước.');
    }
    if (!is_readable($file_path) || filesize($file_path) < 1) return new WP_Error('drive_file', 'File cần gửi lên Google Drive không đọc được.');
    $token = mg_saoluu_drive_access_token();
    if (is_wp_error($token)) return $token;
    $folder = mg_saoluu_drive_ensure_folder();
    if (is_wp_error($folder)) return $folder;
    $name = sanitize_file_name($display_name ?: basename($file_path));
    $metadata = ['name' => $name, 'parents' => [$folder], 'appProperties' => [
        'mg_saoluu_host' => mg_saoluu_host(),
        'mg_saoluu_environment' => mg_saoluu_environment(),
        'mg_saoluu_version' => MG_SAOLUU_VERSION,
    ]];
    $init = wp_remote_post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,name,size,webViewLink,createdTime', [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Upload-Content-Type' => 'application/octet-stream',
            'X-Upload-Content-Length' => (string) filesize($file_path),
        ],
        'body' => wp_json_encode($metadata),
        'data_format' => 'body',
    ]);
    if (is_wp_error($init)) return $init;
    $http = wp_remote_retrieve_response_code($init);
    $session = wp_remote_retrieve_header($init, 'location');
    if ($http < 200 || $http >= 300 || !$session) return new WP_Error('drive_session', 'Google Drive không tạo được phiên tải file.');

    $size = (int) filesize($file_path);
    $chunk_size = 5 * 1024 * 1024; // Bội số của 256 KiB.
    $handle = @fopen($file_path, 'rb');
    if (!$handle) return new WP_Error('drive_read', 'Không thể mở file để gửi lên Google Drive.');
    $offset = 0;
    $final = null;
    @set_time_limit(0);
    while ($offset < $size) {
        $length = min($chunk_size, $size - $offset);
        $chunk = '';
        while (strlen($chunk) < $length && !feof($handle)) {
            $part = fread($handle, $length - strlen($chunk));
            if ($part === false) { fclose($handle); return new WP_Error('drive_read', 'Lỗi đọc file trong lúc gửi Google Drive.'); }
            $chunk .= $part;
        }
        if (strlen($chunk) !== $length) { fclose($handle); return new WP_Error('drive_read', 'File bị thiếu dữ liệu trong lúc tải lên.'); }
        $end = $offset + $length - 1;
        $response = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = wp_remote_request($session, [
                'method' => 'PUT',
                'timeout' => 300,
                'headers' => [
                    'Content-Length' => (string) $length,
                    'Content-Range' => 'bytes ' . $offset . '-' . $end . '/' . $size,
                    'Content-Type' => 'application/octet-stream',
                ],
                'body' => $chunk,
                'data_format' => 'body',
            ]);
            if (!is_wp_error($response)) {
                $retry_http = wp_remote_retrieve_response_code($response);
                if ($retry_http !== 429 && $retry_http < 500) break;
            }
            if ($attempt < 3) sleep($attempt * 2);
        }
        if (is_wp_error($response)) { fclose($handle); return $response; }
        $chunk_http = wp_remote_retrieve_response_code($response);
        if ($chunk_http === 200 || $chunk_http === 201) {
            $final = json_decode(wp_remote_retrieve_body($response), true);
            $offset = $size;
        } elseif ($chunk_http === 308) {
            $received_range = (string) wp_remote_retrieve_header($response, 'range');
            if (preg_match('/bytes=0-(\d+)/i', $received_range, $match)) {
                $offset = min($size, ((int) $match[1]) + 1);
            } else {
                $offset += $length;
            }
            if ($offset < $size && fseek($handle, $offset) !== 0) {
                fclose($handle);
                return new WP_Error('drive_seek', 'Không thể tiếp tục đọc file sau khi Google Drive xác nhận một phần tải lên.');
            }
        } else {
            $detail = wp_remote_retrieve_body($response);
            fclose($handle);
            return new WP_Error('drive_upload', 'Google Drive từ chối một phần file. Mã ' . $chunk_http . '. ' . sanitize_text_field(substr($detail, 0, 240)));
        }
    }
    fclose($handle);
    if (!is_array($final) || empty($final['id'])) return new WP_Error('drive_upload_finish', 'Google Drive chưa xác nhận file đã tải xong.');
    if (isset($final['size']) && (int) $final['size'] !== $size) return new WP_Error('drive_size_mismatch', 'Dung lượng file trên Google Drive không khớp bản trên hosting.');
    return $final;
}

function mg_saoluu_drive_disconnect() {
    delete_option('mg_saoluu_drive_token');
    delete_option('mg_saoluu_drive_folder_id');
}

add_action('admin_init', 'mg_saoluu_drive_oauth_callback');
function mg_saoluu_drive_oauth_callback() {
    if (!isset($_GET['page'], $_GET['mg_drive_callback']) || $_GET['page'] !== 'mg-saoluu-tools') return;
    if (!current_user_can('manage_options')) wp_die('Bạn không có quyền kết nối Google Drive.');
    $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
    if (!$state || !wp_verify_nonce($state, 'mg_saoluu_drive_oauth')) wp_die('Phiên kết nối Google Drive không hợp lệ hoặc đã hết hạn.');
    if (!empty($_GET['error'])) {
        set_transient('mg_saoluu_drive_notice_' . get_current_user_id(), ['type' => 'error', 'message' => 'Google Drive chưa được kết nối: ' . sanitize_text_field(wp_unslash($_GET['error']))], 60);
    } else {
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $result = $code ? mg_saoluu_drive_exchange_code($code) : new WP_Error('drive_code', 'Google không trả về mã kết nối.');
        if (is_wp_error($result)) {
            set_transient('mg_saoluu_drive_notice_' . get_current_user_id(), ['type' => 'error', 'message' => $result->get_error_message()], 60);
        } else {
            mg_saoluu_log_event('drive_connected', 'Đã kết nối Google Drive');
            set_transient('mg_saoluu_drive_notice_' . get_current_user_id(), ['type' => 'success', 'message' => 'Đã kết nối Google Drive. Plugin sẽ tự tạo thư mục riêng khi tải bản đầu tiên.'], 60);
        }
    }
    wp_safe_redirect(admin_url('admin.php?page=mg-saoluu-tools&tab=schedule'));
    exit;
}
