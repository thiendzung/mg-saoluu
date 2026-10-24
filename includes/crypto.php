<?php
if (!defined('ABSPATH')) { exit; }

function mg_saoluu_crypto_available() {
    return function_exists('openssl_encrypt')
        && function_exists('openssl_decrypt')
        && in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true);
}

function mg_saoluu_encryption_constant_available() {
    return defined('MG_SAOLUU_ENCRYPTION_KEY')
        && is_string(MG_SAOLUU_ENCRYPTION_KEY)
        && strlen(MG_SAOLUU_ENCRYPTION_KEY) >= 16;
}

function mg_saoluu_managed_encryption_key($create = true) {
    $stored = (string) get_option('mg_saoluu_managed_encryption_key', '');
    if ($stored !== '') {
        $plain = mg_saoluu_decrypt_secret($stored);
        if (is_string($plain) && strlen($plain) >= 32) return $plain;
        // Không tự ghi đè khóa cũ khi WordPress salts thay đổi hoặc dữ liệu bị hỏng.
        // Làm vậy có thể khiến người dùng tưởng backup cũ vẫn mở được bằng khóa mới.
        return '';
    }
    if (!$create || !mg_saoluu_crypto_available()) return '';
    try {
        $plain = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        return '';
    }
    $encrypted = mg_saoluu_encrypt_secret($plain);
    if ($encrypted === '') return '';
    update_option('mg_saoluu_managed_encryption_key', $encrypted, false);
    update_option('mg_saoluu_managed_encryption_key_created_at', current_time('mysql'), false);
    return $plain;
}

function mg_saoluu_encryption_key_available() {
    if (mg_saoluu_encryption_constant_available()) return true;
    return mg_saoluu_managed_encryption_key(false) !== '';
}

function mg_saoluu_encryption_password($provided = '') {
    $provided = (string) $provided;
    if ($provided !== '') return $provided;
    if (mg_saoluu_encryption_constant_available()) return (string) MG_SAOLUU_ENCRYPTION_KEY;
    return mg_saoluu_managed_encryption_key(true);
}

function mg_saoluu_is_encrypted_backup($path) {
    if (!is_readable($path)) return false;
    $handle = @fopen($path, 'rb');
    if (!$handle) return false;
    $magic = fread($handle, 8);
    fclose($handle);
    return $magic === 'MGSLENC1';
}

function mg_saoluu_encrypt_backup_file($source, $target, $password) {
    if (!mg_saoluu_crypto_available()) return new WP_Error('crypto_missing', 'Máy chủ chưa hỗ trợ AES-256-GCM. Không thể mã hóa bản sao.');
    if (!is_readable($source) || filesize($source) < 1) return new WP_Error('encrypt_source', 'File cần mã hóa không đọc được.');
    $password = (string) $password;
    if (strlen($password) < 12) return new WP_Error('password_short', 'Mật khẩu mã hóa cần ít nhất 12 ký tự.');

    $salt = random_bytes(32);
    $iterations = 210000;
    $key = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
    $header = [
        'format' => 'mg-saoluu-encrypted',
        'version' => 1,
        'cipher' => 'aes-256-gcm',
        'kdf' => 'pbkdf2-sha256',
        'iterations' => $iterations,
        'salt' => base64_encode($salt),
        'chunk_size' => MG_SAOLUU_ENCRYPT_CHUNK,
        'original_name' => basename($source),
        'original_size' => (int) filesize($source),
        'created_at' => gmdate('c'),
    ];
    $header_json = wp_json_encode($header, JSON_UNESCAPED_SLASHES);
    if (!$header_json) return new WP_Error('encrypt_header', 'Không thể tạo thông tin mã hóa.');

    $in = @fopen($source, 'rb');
    $out = @fopen($target, 'wb');
    if (!$in || !$out) {
        if ($in) fclose($in);
        if ($out) fclose($out);
        return new WP_Error('encrypt_open', 'Không thể mở file để mã hóa.');
    }
    @chmod($target, 0600);
    fwrite($out, 'MGSLENC1');
    fwrite($out, pack('N', strlen($header_json)));
    fwrite($out, $header_json);
    $header_hash = hash('sha256', $header_json, true);
    $index = 0;
    $ok = true;
    while (!feof($in)) {
        $plain = fread($in, MG_SAOLUU_ENCRYPT_CHUNK);
        if ($plain === false) { $ok = false; break; }
        if ($plain === '') break;
        $iv = random_bytes(12);
        $tag = '';
        $aad = 'MGSLENC1' . $header_hash . pack('N', $index);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($cipher === false || strlen($tag) !== 16) { $ok = false; break; }
        fwrite($out, pack('N', strlen($cipher)));
        fwrite($out, $iv);
        fwrite($out, $tag);
        fwrite($out, $cipher);
        $index++;
    }
    fclose($in);
    fflush($out);
    fclose($out);
    if (!$ok || !is_file($target) || filesize($target) < 1) {
        @unlink($target);
        return new WP_Error('encrypt_failed', 'Mã hóa file thất bại. File tạm đã được xóa.');
    }
    return $header;
}

function mg_saoluu_decrypt_backup_file($source, $target, $password) {
    if (!mg_saoluu_crypto_available()) return new WP_Error('crypto_missing', 'Máy chủ chưa hỗ trợ AES-256-GCM.');
    if (!is_readable($source)) return new WP_Error('decrypt_source', 'File mã hóa không đọc được.');
    $password = (string) $password;
    if ($password === '') return new WP_Error('password_missing', 'Bản sao đã mã hóa. Hãy nhập mật khẩu giải mã.');

    $in = @fopen($source, 'rb');
    if (!$in) return new WP_Error('decrypt_open', 'Không thể mở file mã hóa.');
    if (fread($in, 8) !== 'MGSLENC1') { fclose($in); return new WP_Error('encrypt_format', 'File không đúng định dạng mã hóa của MG Sao Lưu.'); }
    $length_raw = fread($in, 4);
    if (strlen($length_raw) !== 4) { fclose($in); return new WP_Error('encrypt_header', 'Phần đầu file mã hóa bị hỏng.'); }
    $header_len = unpack('Nlen', $length_raw)['len'];
    if ($header_len < 50 || $header_len > 65536) { fclose($in); return new WP_Error('encrypt_header', 'Kích thước thông tin mã hóa không hợp lệ.'); }
    $header_json = fread($in, $header_len);
    $header = json_decode((string) $header_json, true);
    if (!is_array($header) || ($header['format'] ?? '') !== 'mg-saoluu-encrypted') { fclose($in); return new WP_Error('encrypt_header', 'Thông tin mã hóa không hợp lệ.'); }
    $salt = base64_decode((string) ($header['salt'] ?? ''), true);
    $iterations = (int) ($header['iterations'] ?? 0);
    if ($salt === false || strlen($salt) !== 32 || $iterations < 100000) { fclose($in); return new WP_Error('encrypt_header', 'Thông tin tạo khóa không hợp lệ.'); }
    $key = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
    $out = @fopen($target, 'wb');
    if (!$out) { fclose($in); return new WP_Error('decrypt_target', 'Không thể tạo file ZIP tạm sau giải mã.'); }
    @chmod($target, 0600);
    $header_hash = hash('sha256', $header_json, true);
    $index = 0;
    $written = 0;
    $error = null;
    while (!feof($in)) {
        $record_len_raw = fread($in, 4);
        if ($record_len_raw === '') break;
        if (strlen($record_len_raw) !== 4) { $error = 'File mã hóa bị cắt giữa chừng.'; break; }
        $record_len = unpack('Nlen', $record_len_raw)['len'];
        if ($record_len < 1 || $record_len > MG_SAOLUU_ENCRYPT_CHUNK + 32) { $error = 'Một phần dữ liệu mã hóa có kích thước bất thường.'; break; }
        $iv = fread($in, 12);
        $tag = fread($in, 16);
        $cipher = '';
        while (strlen($cipher) < $record_len && !feof($in)) {
            $part = fread($in, $record_len - strlen($cipher));
            if ($part === false) break 2;
            $cipher .= $part;
        }
        if (strlen($iv) !== 12 || strlen($tag) !== 16 || strlen($cipher) !== $record_len) { $error = 'File mã hóa bị thiếu dữ liệu.'; break; }
        $aad = 'MGSLENC1' . $header_hash . pack('N', $index);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($plain === false) { $error = 'Sai mật khẩu hoặc file mã hóa đã bị thay đổi.'; break; }
        fwrite($out, $plain);
        $written += strlen($plain);
        $index++;
    }
    fclose($in);
    fflush($out);
    fclose($out);
    $expected = (int) ($header['original_size'] ?? 0);
    if (!$error && $expected > 0 && $written !== $expected) $error = 'Dung lượng sau giải mã không khớp với bản gốc.';
    if ($error) {
        @unlink($target);
        return new WP_Error('decrypt_failed', $error);
    }
    return $header;
}

function mg_saoluu_secret_key($legacy = false) {
    if ($legacy) return hash('sha256', wp_salt('secure_auth') . mg_saoluu_signing_key(), true);
    return hash('sha256', wp_salt('secure_auth') . wp_salt('logged_in') . 'mg-saoluu-credentials-v2', true);
}

function mg_saoluu_encrypt_secret($plain) {
    if ($plain === '') return '';
    if (!mg_saoluu_crypto_available()) return '';
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt((string) $plain, 'aes-256-gcm', mg_saoluu_secret_key(false), OPENSSL_RAW_DATA, $iv, $tag, 'mgsl-secret-v2', 16);
    return $cipher === false ? '' : 'gcm2:' . base64_encode($iv . $tag . $cipher);
}

function mg_saoluu_decrypt_secret($stored) {
    $stored = (string) $stored;
    if ($stored === '') return '';
    if (strpos($stored, 'gcm2:') === 0) {
        $raw = base64_decode(substr($stored, 5), true);
        if ($raw === false || strlen($raw) < 29 || !mg_saoluu_crypto_available()) return '';
        $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', mg_saoluu_secret_key(false), OPENSSL_RAW_DATA, $iv, $tag, 'mgsl-secret-v2');
        return $plain === false ? '' : $plain;
    }
    if (strpos($stored, 'gcm:') === 0) {
        $raw = base64_decode(substr($stored, 4), true);
        if ($raw === false || strlen($raw) < 29 || !mg_saoluu_crypto_available()) return '';
        $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', mg_saoluu_secret_key(true), OPENSSL_RAW_DATA, $iv, $tag, 'mgsl-secret');
        return $plain === false ? '' : $plain;
    }
    $decoded = base64_decode($stored, true);
    return $decoded === false ? '' : $decoded;
}
