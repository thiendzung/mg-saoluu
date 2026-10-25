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

/**
 * Write every byte, including when PHP produces a short fwrite().
 * An injected writer is used only by the standalone failure regression.
 */
function mg_saoluu_write_all($handle, $bytes, $writer = null) {
    $length = strlen((string) $bytes);
    $offset = 0;
    while ($offset < $length) {
        $chunk = substr($bytes, $offset);
        $count = $writer ? $writer($handle, $chunk) : @fwrite($handle, $chunk);
        if (!is_int($count) || $count <= 0 || $count > strlen($chunk)) return false;
        $offset += $count;
    }
    return true;
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
    $ok = mg_saoluu_write_all($out, 'MGSLENC1')
        && mg_saoluu_write_all($out, pack('N', strlen($header_json)))
        && mg_saoluu_write_all($out, $header_json);
    $header_hash = hash('sha256', $header_json, true);
    $index = 0;
    while ($ok && !feof($in)) {
        $plain = fread($in, MG_SAOLUU_ENCRYPT_CHUNK);
        if ($plain === false) { $ok = false; break; }
        if ($plain === '') break;
        $iv = random_bytes(12);
        $tag = '';
        $aad = 'MGSLENC1' . $header_hash . pack('N', $index);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($cipher === false || strlen($tag) !== 16) { $ok = false; break; }
        $ok = mg_saoluu_write_all($out, pack('N', strlen($cipher)))
            && mg_saoluu_write_all($out, $iv)
            && mg_saoluu_write_all($out, $tag)
            && mg_saoluu_write_all($out, $cipher);
        if (!$ok) break;
        $index++;
    }
    fclose($in);
    $flushed = @fflush($out);
    $closed = @fclose($out);
    if (!$ok || !$flushed || !$closed || !is_file($target) || filesize($target) < 1) {
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
        if (!mg_saoluu_write_all($out, $plain)) {
            $error = 'Không ghi đủ file tạm sau giải mã (có thể đã hết dung lượng).';
            break;
        }
        $written += strlen($plain);
        $index++;
    }
    fclose($in);
    $flushed = @fflush($out);
    $closed = @fclose($out);
    if ((!$flushed || !$closed) && !$error) $error = 'Không thể hoàn tất ghi file tạm sau giải mã.';
    $expected = (int) ($header['original_size'] ?? 0);
    if (!$error && $expected > 0 && $written !== $expected) $error = 'Dung lượng sau giải mã không khớp với bản gốc.';
    if ($error) {
        @unlink($target);
        return new WP_Error('decrypt_failed', $error);
    }
    return $header;
}

/**
 * Pack v2 uses libsodium secretstream for large-file authenticated encryption.
 * The legacy MGSLENC1 AES-GCM reader/writer remains for v1.2.1 compatibility.
 */
function mg_saoluu_pack_crypto_available() {
    return function_exists('sodium_crypto_pwhash')
        && function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')
        && function_exists('sodium_crypto_secretstream_xchacha20poly1305_push')
        && function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_pull')
        && function_exists('sodium_crypto_secretstream_xchacha20poly1305_pull')
        && defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13');
}

function mg_saoluu_pack_magic() {
    return 'MGSLPK2!';
}

function mg_saoluu_is_pack_backup($path) {
    if (!is_readable($path) || !is_file($path)) return false;
    $h = @fopen($path, 'rb');
    if (!$h) return false;
    $magic = fread($h, 8);
    fclose($h);
    return $magic === mg_saoluu_pack_magic();
}

function mg_saoluu_pack_derive_key($password, $salt, $opslimit, $memlimit) {
    if (!mg_saoluu_pack_crypto_available()) return new WP_Error('pack_crypto_missing', 'Máy chủ cần PHP Sodium để dùng Pack v2.');
    if (!is_string($salt) || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) return new WP_Error('pack_kdf_salt', 'Salt Pack v2 không hợp lệ.');
    $password = (string) $password;
    if (strlen($password) < 12) return new WP_Error('password_short', 'Recovery Key/mật khẩu cần ít nhất 12 ký tự.');
    try {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $password,
            $salt,
            (int) $opslimit,
            (int) $memlimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    } catch (Throwable $e) {
        return new WP_Error('pack_kdf_failed', 'Không tạo được khóa Pack v2.');
    }
}

function mg_saoluu_encrypt_pack_file($source, $target, $password, $meta = []) {
    if (!mg_saoluu_pack_crypto_available()) return new WP_Error('pack_crypto_missing', 'Máy chủ cần PHP Sodium để tạo Pack v2.');
    if (!is_readable($source) || !is_file($source) || filesize($source) < 1) return new WP_Error('pack_encrypt_source', 'Payload Pack v2 không đọc được.');
    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    $opslimit = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
    $memlimit = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
    $key = mg_saoluu_pack_derive_key($password, $salt, $opslimit, $memlimit);
    if (is_wp_error($key)) return $key;
    try {
        [$state, $stream_header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
    } catch (Throwable $e) {
        sodium_memzero($key);
        return new WP_Error('pack_encrypt_init', 'Không khởi tạo được Pack v2.');
    }
    sodium_memzero($key);

    $chunk_size = 1024 * 1024;
    $header = [
        'format' => 'mg-saoluu-pack-encrypted',
        'version' => 2,
        'cipher' => 'xchacha20poly1305-secretstream',
        'kdf' => 'argon2id13',
        'opslimit' => $opslimit,
        'memlimit' => $memlimit,
        'salt' => base64_encode($salt),
        'stream_header' => base64_encode($stream_header),
        'chunk_size' => $chunk_size,
        'original_size' => (int) filesize($source),
        'created_at' => gmdate('c'),
        'meta' => is_array($meta) ? $meta : [],
    ];
    $header_json = wp_json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($header_json) || $header_json === '') {
        sodium_memzero($state);
        return new WP_Error('pack_encrypt_header', 'Không tạo được header Pack v2.');
    }
    $aad = hash('sha256', $header_json, true);
    $in = @fopen($source, 'rb');
    $out = @fopen($target, 'xb');
    if (!$in || !$out) {
        if ($in) fclose($in);
        if ($out) fclose($out);
        sodium_memzero($state);
        return new WP_Error('pack_encrypt_open', 'Không mở được file Pack v2.');
    }
    @chmod($target, 0600);
    $ok = mg_saoluu_write_all($out, mg_saoluu_pack_magic())
        && mg_saoluu_write_all($out, pack('N', strlen($header_json)))
        && mg_saoluu_write_all($out, $header_json);
    $size = (int) filesize($source);
    $read = 0;
    try {
        while ($ok && $read < $size) {
            $plain = fread($in, min($chunk_size, $size - $read));
            if ($plain === false || $plain === '') { $ok = false; break; }
            $read += strlen($plain);
            $tag = $read >= $size
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, $aad, $tag);
            $ok = mg_saoluu_write_all($out, pack('N', strlen($cipher)))
                && mg_saoluu_write_all($out, $cipher);
        }
    } catch (Throwable $e) {
        $ok = false;
    }
    sodium_memzero($state);
    fclose($in);
    $flushed = @fflush($out);
    $closed = @fclose($out);
    if (!$ok || !$flushed || !$closed || $read !== $size) {
        @unlink($target);
        return new WP_Error('pack_encrypt_failed', 'Mã hóa Pack v2 thất bại; file dở đã bị xóa.');
    }
    return $header;
}

function mg_saoluu_decrypt_pack_file($source, $target, $password) {
    if (!mg_saoluu_pack_crypto_available()) return new WP_Error('pack_crypto_missing', 'Máy chủ cần PHP Sodium để đọc Pack v2.');
    if (!is_readable($source) || !is_file($source)) return new WP_Error('pack_decrypt_source', 'Pack v2 không đọc được.');
    $in = @fopen($source, 'rb');
    if (!$in) return new WP_Error('pack_decrypt_open', 'Không mở được Pack v2.');
    if (fread($in, 8) !== mg_saoluu_pack_magic()) { fclose($in); return new WP_Error('pack_format', 'File không phải Pack v2.'); }
    $len_raw = fread($in, 4);
    if (strlen($len_raw) !== 4) { fclose($in); return new WP_Error('pack_header', 'Header Pack v2 bị cắt.'); }
    $header_len = unpack('Nlen', $len_raw)['len'];
    if ($header_len < 100 || $header_len > 131072) { fclose($in); return new WP_Error('pack_header', 'Header Pack v2 có kích thước bất thường.'); }
    $header_json = fread($in, $header_len);
    $header = json_decode((string) $header_json, true);
    if (!is_array($header)
        || ($header['format'] ?? '') !== 'mg-saoluu-pack-encrypted'
        || (int) ($header['version'] ?? 0) !== 2
        || ($header['cipher'] ?? '') !== 'xchacha20poly1305-secretstream'
        || ($header['kdf'] ?? '') !== 'argon2id13') {
        fclose($in);
        return new WP_Error('pack_header', 'Header Pack v2 không hợp lệ.');
    }
    $salt = base64_decode((string) ($header['salt'] ?? ''), true);
    $stream_header = base64_decode((string) ($header['stream_header'] ?? ''), true);
    $opslimit = (int) ($header['opslimit'] ?? 0);
    $memlimit = (int) ($header['memlimit'] ?? 0);
    $chunk_size = (int) ($header['chunk_size'] ?? 0);
    if (!is_string($salt) || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES
        || !is_string($stream_header) || strlen($stream_header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
        || $opslimit < 1 || $opslimit > SODIUM_CRYPTO_PWHASH_OPSLIMIT_SENSITIVE
        || $memlimit < SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE || $memlimit > 512 * 1024 * 1024
        || $chunk_size < 65536 || $chunk_size > 8 * 1024 * 1024) {
        fclose($in);
        return new WP_Error('pack_header', 'Thông số bảo mật Pack v2 không hợp lệ.');
    }
    $key = mg_saoluu_pack_derive_key($password, $salt, $opslimit, $memlimit);
    if (is_wp_error($key)) { fclose($in); return $key; }
    try {
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($stream_header, $key);
    } catch (Throwable $e) {
        sodium_memzero($key); fclose($in);
        return new WP_Error('pack_decrypt_init', 'Không khởi tạo được giải mã Pack v2.');
    }
    sodium_memzero($key);
    $out = @fopen($target, 'xb');
    if (!$out) { sodium_memzero($state); fclose($in); return new WP_Error('pack_decrypt_target', 'Không tạo được payload tạm Pack v2.'); }
    @chmod($target, 0600);
    $aad = hash('sha256', $header_json, true);
    $written = 0; $final_seen = false; $error = '';
    while (!feof($in)) {
        $record_len_raw = fread($in, 4);
        if ($record_len_raw === '') break;
        if (strlen($record_len_raw) !== 4) { $error = 'Pack v2 bị cắt giữa record.'; break; }
        $record_len = unpack('Nlen', $record_len_raw)['len'];
        if ($record_len < 1 || $record_len > $chunk_size + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES + 64) {
            $error = 'Pack v2 có record bất thường.'; break;
        }
        $cipher = '';
        while (strlen($cipher) < $record_len && !feof($in)) {
            $part = fread($in, $record_len - strlen($cipher));
            if ($part === false) { $error = 'Không đọc được Pack v2.'; break 2; }
            $cipher .= $part;
        }
        if (strlen($cipher) !== $record_len) { $error = 'Pack v2 thiếu dữ liệu.'; break; }
        if ($final_seen) { $error = 'Pack v2 có dữ liệu sau FINAL tag.'; break; }
        try {
            $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher, $aad);
        } catch (Throwable $e) {
            $pulled = false;
        }
        if ($pulled === false) { $error = 'Sai Recovery Key hoặc Pack v2 đã bị thay đổi.'; break; }
        [$plain, $tag] = $pulled;
        if (!mg_saoluu_write_all($out, $plain)) { $error = 'Không ghi đủ payload Pack v2.'; break; }
        $written += strlen($plain);
        if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) $final_seen = true;
    }
    sodium_memzero($state);
    fclose($in);
    $flushed = @fflush($out); $closed = @fclose($out);
    $expected = (int) ($header['original_size'] ?? 0);
    if (!$error && (!$final_seen || $expected < 1 || $written !== $expected)) $error = 'Pack v2 thiếu FINAL tag hoặc sai kích thước.';
    if (!$error && (!$flushed || !$closed)) $error = 'Không hoàn tất được payload Pack v2.';
    if ($error) {
        @unlink($target);
        return new WP_Error('pack_decrypt_failed', $error);
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
