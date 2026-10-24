<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Security architecture v1.1.9
 * - Local device keeps an Ed25519 private key.
 * - Production stores only trusted public keys.
 * - Deploy packages are target-bound, short-lived and one-time-use.
 * - Production restore requires a signed challenge from a paired Local device.
 */

function mg_saoluu_security_sodium_available() {
    return function_exists('sodium_crypto_sign_keypair')
        && function_exists('sodium_crypto_sign_detached')
        && function_exists('sodium_crypto_sign_verify_detached');
}

function mg_saoluu_b64url_encode($data) {
    return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '=');
}

function mg_saoluu_b64url_decode($data) {
    $data = strtr((string) $data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode($data, true);
}

function mg_saoluu_security_uuid() {
    if (function_exists('wp_generate_uuid4')) return wp_generate_uuid4();
    $hex = bin2hex(random_bytes(16));
    return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-a'.substr($hex,17,3).'-'.substr($hex,20,12);
}

function mg_saoluu_security_normalize_host($value) {
    $value = trim(strtolower((string) $value));
    if ($value === '') return '';
    if (strpos($value, '://') === false) $value = 'https://' . $value;
    $host = wp_parse_url($value, PHP_URL_HOST);
    if (!$host) return '';
    return strtolower(rtrim((string) $host, '.'));
}

function mg_saoluu_security_is_list_array($array) {
    if (!is_array($array)) return false;
    if ($array === []) return true;
    return array_keys($array) === range(0, count($array) - 1);
}

function mg_saoluu_security_canonicalize($value) {
    if (!is_array($value)) return $value;
    if (mg_saoluu_security_is_list_array($value)) {
        return array_map('mg_saoluu_security_canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = mg_saoluu_security_canonicalize($item);
    return $value;
}

function mg_saoluu_security_canonical_json($value) {
    return wp_json_encode(mg_saoluu_security_canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function mg_saoluu_security_fingerprint($public_key) {
    $raw = is_string($public_key) ? $public_key : '';
    if ($raw === '') return '';
    $hex = strtoupper(substr(hash('sha256', $raw), 0, 24));
    return implode(':', str_split($hex, 4));
}

function mg_saoluu_security_device() {
    $public_b64 = (string) get_option('mg_saoluu_device_public_key', '');
    $private_stored = (string) get_option('mg_saoluu_device_private_key', '');
    $public = $public_b64 !== '' ? base64_decode($public_b64, true) : false;
    $private_b64 = $private_stored !== '' ? mg_saoluu_decrypt_secret($private_stored) : '';
    $private = $private_b64 !== '' ? base64_decode($private_b64, true) : false;
    return [
        'id' => (string) get_option('mg_saoluu_device_id', ''),
        'name' => (string) get_option('mg_saoluu_device_name', ''),
        'public' => is_string($public) ? $public : '',
        'private' => is_string($private) ? $private : '',
        'fingerprint' => is_string($public) ? mg_saoluu_security_fingerprint($public) : '',
        'created_at' => (string) get_option('mg_saoluu_device_created_at', ''),
    ];
}

function mg_saoluu_security_device_ready() {
    $device = mg_saoluu_security_device();
    return mg_saoluu_security_sodium_available()
        && $device['id'] !== ''
        && strlen($device['public']) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        && strlen($device['private']) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;
}

function mg_saoluu_security_ensure_device_key($force = false, $name = '') {
    if (!mg_saoluu_security_sodium_available()) return new WP_Error('sodium_missing', 'PHP chưa có Sodium/Ed25519 nên chưa thể tạo khóa thiết bị an toàn.');
    if (!$force && mg_saoluu_security_device_ready()) return mg_saoluu_security_device();
    if (mg_saoluu_environment() !== 'local') return new WP_Error('device_key_environment', 'Private key chỉ được tạo và giữ trên Local. Staging/web thật chỉ lưu public key.');

    try {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        $private = sodium_crypto_sign_secretkey($pair);
    } catch (Throwable $e) {
        return new WP_Error('device_key_failed', 'Không tạo được khóa thiết bị: ' . $e->getMessage());
    }
    $stored = mg_saoluu_encrypt_secret(base64_encode($private));
    if ($stored === '') return new WP_Error('device_key_store_failed', 'Không mã hóa được private key để lưu trên Local.');
    if ($name === '') $name = 'Máy phát triển · ' . mg_saoluu_host();
    update_option('mg_saoluu_device_id', mg_saoluu_security_uuid(), false);
    update_option('mg_saoluu_device_name', sanitize_text_field($name), false);
    update_option('mg_saoluu_device_public_key', base64_encode($public), false);
    update_option('mg_saoluu_device_private_key', $stored, false);
    update_option('mg_saoluu_device_created_at', current_time('mysql'), false);
    mg_saoluu_log_event('device_key_created', 'Tạo khóa ký Ed25519 cho thiết bị Local');
    return mg_saoluu_security_device();
}

function mg_saoluu_security_pairing_code() {
    $device = mg_saoluu_security_ensure_device_key(false);
    if (is_wp_error($device)) return $device;
    $payload = [
        'format' => 'mgsl-pair',
        'version' => 1,
        'device_id' => $device['id'],
        'device_name' => $device['name'],
        'public_key' => base64_encode($device['public']),
        'fingerprint' => $device['fingerprint'],
        'source_host' => mg_saoluu_host(),
        'created_at' => time(),
        'expires_at' => time() + 30 * MINUTE_IN_SECONDS,
    ];
    $payload['checksum'] = hash('sha256', mg_saoluu_security_canonical_json($payload));
    return 'MGPAIR1.' . mg_saoluu_b64url_encode(mg_saoluu_security_canonical_json($payload));
}

function mg_saoluu_security_parse_pairing_code($code) {
    $code = trim((string) $code);
    if (strpos($code, 'MGPAIR1.') !== 0) return new WP_Error('pair_format', 'Mã ghép nối không đúng định dạng.');
    $raw = mg_saoluu_b64url_decode(substr($code, 8));
    $data = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($data) || ($data['format'] ?? '') !== 'mgsl-pair' || (int) ($data['version'] ?? 0) !== 1) return new WP_Error('pair_invalid', 'Mã ghép nối không hợp lệ.');
    $checksum = (string) ($data['checksum'] ?? '');
    unset($data['checksum']);
    if ($checksum === '' || !hash_equals($checksum, hash('sha256', mg_saoluu_security_canonical_json($data)))) return new WP_Error('pair_checksum', 'Mã ghép nối bị thay đổi hoặc hỏng.');
    if ((int) ($data['expires_at'] ?? 0) < time()) return new WP_Error('pair_expired', 'Mã ghép nối đã hết hạn. Hãy tạo mã mới trên Local.');
    $public = base64_decode((string) ($data['public_key'] ?? ''), true);
    if (!is_string($public) || !defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES') || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return new WP_Error('pair_public_key', 'Public key trong mã ghép nối không hợp lệ.');
    if (!hash_equals((string) ($data['fingerprint'] ?? ''), mg_saoluu_security_fingerprint($public))) return new WP_Error('pair_fingerprint', 'Fingerprint của mã ghép nối không khớp.');
    $data['public_raw'] = $public;
    return $data;
}

function mg_saoluu_security_trusted_signers() {
    $items = get_option('mg_saoluu_trusted_signers', []);
    return is_array($items) ? $items : [];
}

function mg_saoluu_security_import_pairing_code($code) {
    if (mg_saoluu_environment() === 'local') return new WP_Error('pair_target_local', 'Mã ghép nối Local cần được nhập trên Staging hoặc web thật.');
    $data = mg_saoluu_security_parse_pairing_code($code);
    if (is_wp_error($data)) return $data;
    $trusted = mg_saoluu_security_trusted_signers();
    $id = sanitize_text_field((string) $data['device_id']);
    if ($id === '') return new WP_Error('pair_device_id', 'Mã ghép nối thiếu ID thiết bị.');
    $trusted[$id] = [
        'device_id' => $id,
        'name' => sanitize_text_field((string) ($data['device_name'] ?? 'Local device')),
        'public_key' => base64_encode($data['public_raw']),
        'fingerprint' => sanitize_text_field((string) $data['fingerprint']),
        'source_host' => sanitize_text_field((string) ($data['source_host'] ?? '')),
        'paired_at' => current_time('mysql'),
        'paired_by' => (int) get_current_user_id(),
    ];
    update_option('mg_saoluu_trusted_signers', $trusted, false);
    mg_saoluu_log_event('pairing_added', 'Ghép nối thiết bị Local', ['device' => $trusted[$id]['name'], 'fingerprint' => $trusted[$id]['fingerprint']]);
    return $trusted[$id];
}

function mg_saoluu_security_revoke_signer($device_id) {
    $device_id = sanitize_text_field((string) $device_id);
    $trusted = mg_saoluu_security_trusted_signers();
    if (!isset($trusted[$device_id])) return new WP_Error('signer_missing', 'Không tìm thấy thiết bị đã ghép nối.');
    $name = $trusted[$device_id]['name'] ?? $device_id;
    unset($trusted[$device_id]);
    update_option('mg_saoluu_trusted_signers', $trusted, false);
    mg_saoluu_log_event('pairing_revoked', 'Thu hồi thiết bị Local', ['device' => $name]);
    return true;
}

function mg_saoluu_security_sign_payload($payload) {
    $device = mg_saoluu_security_ensure_device_key(false);
    if (is_wp_error($device)) return $device;
    $json = mg_saoluu_security_canonical_json($payload);
    if (!is_string($json) || $json === '') return new WP_Error('sign_payload', 'Không chuẩn hóa được dữ liệu cần ký.');
    try {
        $signature = sodium_crypto_sign_detached($json, $device['private']);
    } catch (Throwable $e) {
        return new WP_Error('sign_failed', 'Không ký được dữ liệu: ' . $e->getMessage());
    }
    return [
        'device_id' => $device['id'],
        'fingerprint' => $device['fingerprint'],
        'signature' => base64_encode($signature),
    ];
}

function mg_saoluu_security_verify_payload_signature($payload, $signature_b64, $device_id) {
    if (!mg_saoluu_security_sodium_available()) return new WP_Error('sodium_missing', 'Web thật chưa có Sodium/Ed25519 để xác minh chữ ký.');
    $trusted = mg_saoluu_security_trusted_signers();
    $device_id = sanitize_text_field((string) $device_id);
    if (empty($trusted[$device_id])) return new WP_Error('signer_untrusted', 'Gói này không được ký bởi thiết bị Local đã ghép nối.');
    $public = base64_decode((string) ($trusted[$device_id]['public_key'] ?? ''), true);
    $signature = base64_decode((string) $signature_b64, true);
    if (!is_string($public) || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) return new WP_Error('signature_format', 'Public key hoặc chữ ký không hợp lệ.');
    $json = mg_saoluu_security_canonical_json($payload);
    try {
        $ok = sodium_crypto_sign_verify_detached($signature, $json, $public);
    } catch (Throwable $e) {
        return new WP_Error('signature_verify_failed', 'Không xác minh được chữ ký.');
    }
    return $ok ? true : new WP_Error('signature_invalid', 'Chữ ký Ed25519 không hợp lệ. Dữ liệu có thể đã bị thay đổi.');
}

function mg_saoluu_security_rate_key($scope) {
    return 'mg_saoluu_rate_' . sanitize_key($scope) . '_' . (int) get_current_user_id();
}

function mg_saoluu_security_rate_state($scope) {
    $state = get_transient(mg_saoluu_security_rate_key($scope));
    return is_array($state) ? $state : ['fails' => 0, 'locked_until' => 0];
}

function mg_saoluu_security_rate_limited($scope) {
    $state = mg_saoluu_security_rate_state($scope);
    return (int) ($state['locked_until'] ?? 0) > time() ? $state : false;
}

function mg_saoluu_security_record_failure($scope) {
    $key = mg_saoluu_security_rate_key($scope);
    $state = mg_saoluu_security_rate_state($scope);
    $state['fails'] = (int) ($state['fails'] ?? 0) + 1;
    if ($state['fails'] >= 5) $state['locked_until'] = time() + 30 * MINUTE_IN_SECONDS;
    set_transient($key, $state, 30 * MINUTE_IN_SECONDS);
    return $state;
}

function mg_saoluu_security_record_success($scope) {
    delete_transient(mg_saoluu_security_rate_key($scope));
}

function mg_saoluu_security_verify_admin_password($password, $scope = 'sensitive', $clear_on_success = true) {
    $limited = mg_saoluu_security_rate_limited($scope);
    if ($limited) {
        $minutes = max(1, (int) ceil(((int) $limited['locked_until'] - time()) / 60));
        return new WP_Error('rate_limited', 'Thao tác nhạy cảm đang bị khóa khoảng ' . $minutes . ' phút do nhập sai nhiều lần.');
    }
    $user = wp_get_current_user();
    if (!$user || !$user->exists() || !wp_check_password((string) $password, $user->user_pass, $user->ID)) {
        $state = mg_saoluu_security_record_failure($scope);
        mg_saoluu_log_event('sensitive_auth_failed', 'Xác thực lại quản trị viên thất bại', ['scope' => $scope, 'fails' => (string) $state['fails']]);
        return new WP_Error('password_invalid', 'Mật khẩu tài khoản quản trị hiện tại không đúng.');
    }
    if ($clear_on_success) mg_saoluu_security_record_success($scope);
    return true;
}

function mg_saoluu_security_sensitive_confirmation($scope, $password, $phrase, $expected_phrase, $host = '') {
    $auth = mg_saoluu_security_verify_admin_password($password, $scope, false);
    if (is_wp_error($auth)) return $auth;
    if (strtoupper(trim((string) $phrase)) !== strtoupper(trim((string) $expected_phrase))) {
        mg_saoluu_security_record_failure($scope);
        return new WP_Error('phrase_invalid', 'Câu xác nhận không đúng.');
    }
    if ($host !== '' && mg_saoluu_security_normalize_host($host) !== mg_saoluu_host()) {
        mg_saoluu_security_record_failure($scope);
        return new WP_Error('host_invalid', 'Tên miền xác nhận không đúng website hiện tại.');
    }
    mg_saoluu_security_record_success($scope);
    return true;
}

function mg_saoluu_security_output_set($type, $value, $ttl = 600) {
    set_transient('mg_saoluu_security_output_' . (int) get_current_user_id(), ['type' => sanitize_key($type), 'value' => (string) $value], $ttl);
}

function mg_saoluu_security_output_get($consume = true) {
    $key = 'mg_saoluu_security_output_' . (int) get_current_user_id();
    $value = get_transient($key);
    if ($consume && $value) delete_transient($key);
    return is_array($value) ? $value : [];
}

function mg_saoluu_security_create_restore_challenge() {
    if (mg_saoluu_environment() !== 'production') return new WP_Error('challenge_environment', 'Yêu cầu mở khóa Restore chỉ tạo trên web thật.');
    if (!mg_saoluu_security_trusted_signers()) return new WP_Error('challenge_no_signer', 'Chưa ghép nối thiết bị Local. Vào tab Bảo mật để ghép nối trước.');
    $challenge = [
        'format' => 'mgsl-restore-challenge',
        'version' => 1,
        'challenge_id' => mg_saoluu_security_uuid(),
        'target_host' => mg_saoluu_host(),
        'requested_by' => (int) get_current_user_id(),
        'nonce' => bin2hex(random_bytes(16)),
        'created_at' => time(),
        'expires_at' => time() + 10 * MINUTE_IN_SECONDS,
    ];
    set_transient('mg_saoluu_restore_challenge_' . $challenge['challenge_id'], $challenge, 10 * MINUTE_IN_SECONDS);
    mg_saoluu_log_event('restore_challenge_created', 'Tạo yêu cầu mở khóa Restore web thật', ['challenge' => $challenge['challenge_id']]);
    return 'MGRESTORE1.' . mg_saoluu_b64url_encode(mg_saoluu_security_canonical_json($challenge));
}

function mg_saoluu_security_parse_restore_challenge($code) {
    $code = trim((string) $code);
    if (strpos($code, 'MGRESTORE1.') !== 0) return new WP_Error('challenge_format', 'Mã yêu cầu Restore không đúng định dạng.');
    $raw = mg_saoluu_b64url_decode(substr($code, 11));
    $data = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($data) || ($data['format'] ?? '') !== 'mgsl-restore-challenge' || (int) ($data['version'] ?? 0) !== 1) return new WP_Error('challenge_invalid', 'Mã yêu cầu Restore không hợp lệ.');
    if ((int) ($data['expires_at'] ?? 0) < time()) return new WP_Error('challenge_expired', 'Yêu cầu Restore đã hết hạn. Hãy tạo yêu cầu mới.');
    if (empty($data['challenge_id']) || empty($data['target_host']) || empty($data['nonce'])) return new WP_Error('challenge_incomplete', 'Yêu cầu Restore thiếu thông tin.');
    return $data;
}

function mg_saoluu_security_sign_restore_challenge($code) {
    if (mg_saoluu_environment() !== 'local') return new WP_Error('approval_source', 'Chỉ Local đã giữ private key mới ký yêu cầu Restore.');
    $challenge = mg_saoluu_security_parse_restore_challenge($code);
    if (is_wp_error($challenge)) return $challenge;
    if (mg_saoluu_security_normalize_host($challenge['target_host']) === mg_saoluu_host()) return new WP_Error('approval_same_host', 'Không ký yêu cầu cho chính website Local hiện tại.');
    $signed = mg_saoluu_security_sign_payload($challenge);
    if (is_wp_error($signed)) return $signed;
    $approval = [
        'format' => 'mgsl-restore-approval',
        'version' => 1,
        'challenge' => $challenge,
        'device_id' => $signed['device_id'],
        'fingerprint' => $signed['fingerprint'],
        'signature' => $signed['signature'],
    ];
    mg_saoluu_log_event('restore_challenge_signed', 'Đã ký yêu cầu Restore web thật', ['target' => $challenge['target_host']]);
    return 'MGAPPROVE1.' . mg_saoluu_b64url_encode(mg_saoluu_security_canonical_json($approval));
}

function mg_saoluu_security_apply_restore_approval($code) {
    if (mg_saoluu_environment() !== 'production') return new WP_Error('approval_target', 'Mã phê duyệt chỉ nhập trên web thật.');
    $code = trim((string) $code);
    if (strpos($code, 'MGAPPROVE1.') !== 0) return new WP_Error('approval_format', 'Mã phê duyệt không đúng định dạng.');
    $raw = mg_saoluu_b64url_decode(substr($code, 11));
    $approval = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($approval) || ($approval['format'] ?? '') !== 'mgsl-restore-approval') return new WP_Error('approval_invalid', 'Mã phê duyệt không hợp lệ.');
    $challenge = $approval['challenge'] ?? null;
    if (!is_array($challenge)) return new WP_Error('approval_challenge', 'Mã phê duyệt thiếu yêu cầu gốc.');
    if ((int) ($challenge['expires_at'] ?? 0) < time()) return new WP_Error('approval_expired', 'Mã phê duyệt đã hết hạn.');
    if (mg_saoluu_security_normalize_host($challenge['target_host'] ?? '') !== mg_saoluu_host()) return new WP_Error('approval_host', 'Mã phê duyệt dành cho website khác.');
    $id = (string) ($challenge['challenge_id'] ?? '');
    $stored = $id !== '' ? get_transient('mg_saoluu_restore_challenge_' . $id) : false;
    if (!is_array($stored) || !hash_equals(hash('sha256', mg_saoluu_security_canonical_json($stored)), hash('sha256', mg_saoluu_security_canonical_json($challenge)))) return new WP_Error('approval_challenge_missing', 'Yêu cầu gốc không còn hợp lệ hoặc đã được sử dụng.');
    $verify = mg_saoluu_security_verify_payload_signature($challenge, (string) ($approval['signature'] ?? ''), (string) ($approval['device_id'] ?? ''));
    if (is_wp_error($verify)) return $verify;
    delete_transient('mg_saoluu_restore_challenge_' . $id);
    $unlock = [
        'device_id' => sanitize_text_field((string) $approval['device_id']),
        'fingerprint' => sanitize_text_field((string) ($approval['fingerprint'] ?? '')),
        'approved_at' => time(),
        'expires_at' => time() + 10 * MINUTE_IN_SECONDS,
    ];
    set_transient('mg_saoluu_production_restore_unlocked', $unlock, 10 * MINUTE_IN_SECONDS);
    mg_saoluu_log_event('production_restore_approved', 'Local đã phê duyệt Restore web thật trong 10 phút', ['device' => $unlock['device_id']]);
    return $unlock;
}

function mg_saoluu_security_restore_unlock_info() {
    if (mg_saoluu_environment() !== 'production') return ['active' => true, 'expires_at' => 0];
    $data = get_transient('mg_saoluu_production_restore_unlocked');
    if (!is_array($data) || (int) ($data['expires_at'] ?? 0) <= time()) return ['active' => false, 'expires_at' => 0];
    return array_merge(['active' => true], $data);
}

function mg_saoluu_security_lock_restore() {
    delete_transient('mg_saoluu_production_restore_unlocked');
    mg_saoluu_log_event('production_restore_lock', 'Khóa lại Restore web thật');
}

function mg_saoluu_security_deploy_used_ids() {
    $items = get_option('mg_saoluu_used_deploy_packages', []);
    return is_array($items) ? $items : [];
}

function mg_saoluu_security_deploy_package_used($package_id) {
    $items = mg_saoluu_security_deploy_used_ids();
    return isset($items[(string) $package_id]);
}

function mg_saoluu_security_mark_deploy_package_used($package_id, $manifest = []) {
    $package_id = sanitize_text_field((string) $package_id);
    if ($package_id === '') return;
    $items = mg_saoluu_security_deploy_used_ids();
    $items[$package_id] = [
        'used_at' => current_time('mysql'),
        'target_host' => sanitize_text_field((string) ($manifest['target_host'] ?? mg_saoluu_host())),
        'component' => sanitize_text_field((string) (($manifest['component_type'] ?? '') . ':' . ($manifest['component_slug'] ?? ''))),
    ];
    if (count($items) > 250) $items = array_slice($items, -250, null, true);
    update_option('mg_saoluu_used_deploy_packages', $items, false);
}

add_filter('map_meta_cap', 'mg_saoluu_security_disable_file_editor_caps', 10, 4);
function mg_saoluu_security_disable_file_editor_caps($caps, $cap, $user_id, $args) {
    if (in_array($cap, ['edit_plugins', 'edit_themes', 'edit_files'], true)) return ['do_not_allow'];
    return $caps;
}

function mg_saoluu_security_file_editor_locked() {
    return true; // Enforced by map_meta_cap above while this plugin is active.
}

function mg_saoluu_security_is_https_effective() {
    if (is_ssl()) return true;
    return stripos((string) home_url('/'), 'https://') === 0 && stripos((string) admin_url(), 'https://') === 0;
}

function mg_saoluu_security_checks() {
    $env = mg_saoluu_environment();
    $storage = mg_saoluu_storage_preflight();
    $inside = is_wp_error($storage) || !mg_saoluu_storage_path_safe(mg_saoluu_get_backup_dir());
    $integrity = mg_saoluu_self_integrity();
    $trusted = mg_saoluu_security_trusted_signers();
    $device_ready = mg_saoluu_security_device_ready();
    $restore_unlock = mg_saoluu_security_restore_unlock_info();
    $checks = [];
    $add = function($id, $label, $status, $detail, $group = 'core', $action = '') use (&$checks) {
        $checks[$id] = compact('id','label','status','detail','group','action');
    };

    $add('integrity', 'File plugin', !empty($integrity['ok']) ? 'pass' : 'fail', !empty($integrity['ok']) ? 'Đúng phiên bản và đầy đủ' : mg_saoluu_self_integrity_label($integrity), 'core');
    $add('https', 'HTTPS quản trị', ($env !== 'production' || mg_saoluu_security_is_https_effective()) ? 'pass' : 'fail', mg_saoluu_security_is_https_effective() ? 'Đang dùng HTTPS' : ($env === 'production' ? 'Web thật chưa xác nhận HTTPS' : 'Không bắt buộc trên Local'), 'wordpress');
    $add('file_editor', 'Sửa PHP trong WP Admin', mg_saoluu_security_file_editor_locked() ? 'pass' : 'warn', mg_saoluu_security_file_editor_locked() ? 'Đã khóa bởi plugin' : 'Chưa khóa', 'wordpress');
    $display_errors = strtolower(trim((string) ini_get('display_errors')));
    $debug_public = defined('WP_DEBUG_DISPLAY') ? !WP_DEBUG_DISPLAY : !in_array($display_errors, ['1','on','yes','true'], true);
    $add('debug_display', 'Hiển thị lỗi công khai', $debug_public ? 'pass' : 'warn', $debug_public ? 'Đang tắt' : 'Đang bật; có thể lộ đường dẫn/lỗi', 'wordpress');
    $add('backup_access', 'Truy cập trực tiếp bản sao', !$inside ? 'pass' : 'fail', !$inside ? 'Bản sao nằm ngoài web root' : (is_wp_error($storage) ? $storage->get_error_message() : 'Nơi lưu không an toàn, không tạo bản sao'), 'backup');
    $add('crypto', 'Mã hóa bản sao', mg_saoluu_crypto_available() ? 'pass' : 'fail', mg_saoluu_crypto_available() ? 'AES-256-GCM sẵn sàng' : 'OpenSSL/AES-256-GCM chưa sẵn sàng', 'backup');
    $add('backup_signature', 'Chữ ký bản sao', 'pass', mg_saoluu_signing_key_is_custom() ? 'Đang dùng khóa ký riêng tương thích' : 'Đang dùng WordPress secure key', 'backup');
    $add('credentials_crypto', 'Mã hóa thông tin kết nối', mg_saoluu_crypto_available() ? 'pass' : 'fail', mg_saoluu_crypto_available() ? 'Google token/secret được mã hóa bằng AES-256-GCM' : 'Chưa thể mã hóa secret an toàn', 'backup');
    $add('managed_key', 'Recovery Key tự động', mg_saoluu_encryption_key_available() ? 'pass' : 'warn', mg_saoluu_encryption_key_available() ? 'Đã có khóa cho bản sao tự động' : 'Chưa tạo Recovery Key', 'backup');
    $recovery_saved = mg_saoluu_encryption_constant_available() || (string) get_option('mg_saoluu_recovery_key_exported_at', '') !== '';
    $add('recovery_export', 'Recovery Key đã lưu ngoài website', $recovery_saved ? 'pass' : 'warn', $recovery_saved ? (mg_saoluu_encryption_constant_available() ? 'Đang dùng khóa legacy trong wp-config.php' : 'Đã xác nhận xuất Recovery Key') : 'Nên xuất một lần và lưu trong password manager', 'backup');
    $add('drive', 'Bản sao ngoài hosting', mg_saoluu_drive_is_connected() ? 'pass' : 'warn', mg_saoluu_drive_is_connected() ? 'Google Drive đã kết nối' : 'Chưa có bản sao tự động ngoài hosting', 'backup', 'schedule');
    $add('sodium', 'Chữ ký Ed25519', mg_saoluu_security_sodium_available() ? 'pass' : 'fail', mg_saoluu_security_sodium_available() ? 'Sodium/Ed25519 sẵn sàng' : 'PHP chưa có Sodium', 'deploy');
    if ($env === 'local') {
        $add('device_key', 'Private key thiết bị', $device_ready ? 'pass' : 'warn', $device_ready ? 'Đã tạo · chỉ giữ trên Local này' : 'Chưa tạo khóa thiết bị', 'deploy');
    } else {
        $add('pairing', 'Thiết bị Local đã ghép nối', count($trusted) > 0 ? 'pass' : 'warn', count($trusted) > 0 ? count($trusted) . ' thiết bị được tin cậy' : 'Chưa có Local public key', 'deploy');
        $add('deploy_default_lock', $env === 'production' ? 'Triển khai web thật' : 'Triển khai web thử nghiệm', 'pass', 'Chỉ nhận package đã ký, đúng domain, chưa hết hạn và chưa dùng', 'deploy');
        if ($env === 'production') $add('restore_lock', 'Khôi phục web thật', empty($restore_unlock['active']) ? 'pass' : 'warn', empty($restore_unlock['active']) ? 'Đang khóa' : 'ĐANG MỞ tạm thời', 'deploy');
    }
    $add('anti_replay', 'Chống dùng lại package', 'pass', 'Package ID chỉ được dùng một lần trên Production', 'deploy');
    $add('rate_limit', 'Giới hạn thử sai', 'pass', '5 lần sai sẽ khóa thao tác nhạy cảm 30 phút', 'deploy');
    return $checks;
}

function mg_saoluu_security_summary() {
    $checks = mg_saoluu_security_checks();
    $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'na' => 0, 'issues' => [], 'passed' => []];
    foreach ($checks as $check) {
        $status = isset($summary[$check['status']]) ? $check['status'] : 'warn';
        $summary[$status]++;
        if ($status === 'pass' || $status === 'na') $summary['passed'][] = $check; else $summary['issues'][] = $check;
    }
    $summary['label'] = $summary['fail'] > 0 ? 'Cần xử lý ngay' : ($summary['warn'] > 0 ? 'Khá tốt' : 'Tốt');
    $summary['status'] = $summary['fail'] > 0 ? 'fail' : ($summary['warn'] > 0 ? 'warn' : 'pass');
    return $summary;
}
