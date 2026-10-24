<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Conservative, one-time Local -> fresh Production migration.
 *
 * IMPORTANT: all RC builds stay read-only on production. The stable-only
 * mutation gate also requires an explicit server-side wp-config opt-in.
 * Existing live websites with customers/orders are never migration targets.
 */
function mg_saoluu_migration_production_gate() {
    $env = mg_saoluu_environment();
    if ($env !== 'production' && $env !== 'staging') {
        return new WP_Error('migration_target_environment', 'Chỉ dùng trên production được duyệt hoặc Staging QA.');
    }
    $host = mg_saoluu_host();
    // The ONLY RC write path is an explicitly marked staging clone whose
    // canonical hostname starts with stage/staging/sandbox/test.
    $qa = $env === 'staging'
        && defined('MG_SAOLUU_MIGRATION_QA_MODE') && MG_SAOLUU_MIGRATION_QA_MODE === true
        && defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'staging'
        && preg_match('/^(?:stage|staging|sandbox|test)[.-]/i', $host);
    if ($env !== 'production' && !$qa) {
        return new WP_Error('migration_target_environment', 'Chỉ dùng trên production được duyệt hoặc clone Staging được phép QA.');
    }
    if (!$qa && strpos(MG_SAOLUU_VERSION, '-rc') !== false) {
        return new WP_Error('migration_rc_locked', 'Bản RC vẫn khóa toàn bộ ghi production.');
    }
    if (!$qa && (!defined('MG_SAOLUU_ENABLE_PRODUCTION_MIGRATION') || MG_SAOLUU_ENABLE_PRODUCTION_MIGRATION !== true)) {
        return new WP_Error('migration_optin_missing', 'Chưa cho phép chuyển site trên máy chủ đích qua cấu hình riêng.');
    }
    if (!$qa && (!defined('MG_SAOLUU_EXPECTED_TARGET_HOST')
        || !is_string(MG_SAOLUU_EXPECTED_TARGET_HOST)
        || !hash_equals(strtolower(trim(MG_SAOLUU_EXPECTED_TARGET_HOST)), $host))) {
        return new WP_Error('migration_host_not_pinned', 'Hostname production chưa được khóa đúng trong cấu hình máy chủ.');
    }
    if (!mg_saoluu_request_host_is_canonical()) {
        return new WP_Error('migration_host_mismatch', 'Host request không khớp địa chỉ WordPress chuẩn.');
    }
    if (!defined('DISABLE_WP_CRON') || DISABLE_WP_CRON !== true) {
        return new WP_Error('migration_cron_active', 'Hãy tắt WP-Cron ở cấu hình đích trước khi nhập.');
    }
    if (!defined('MG_SAOLUU_MIGRATION_EXTERNAL_IO_ISOLATED') || MG_SAOLUU_MIGRATION_EXTERNAL_IO_ISOLATED !== true) {
        return new WP_Error('migration_external_io_not_isolated', 'Chưa xác nhận cách ly SMTP, payment, webhook và outbound HTTP ở môi trường đích.');
    }
    if (!function_exists('is_ssl') || !is_ssl()) {
        return new WP_Error('migration_https_required', 'Phải xác nhận HTTPS cho trang quản trị web thật trước khi chuyển site.');
    }
    return true;
}

function mg_saoluu_migration_target_url($url) {
    $url = trim((string) $url);
    $host = wp_parse_url($url, PHP_URL_HOST);
    $scheme = wp_parse_url($url, PHP_URL_SCHEME);
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    if (!is_string($host) || $host === '' || strtolower((string) $scheme) !== 'https'
        || ($path !== '' && $path !== '/')
        || wp_parse_url($url, PHP_URL_USER) !== null
        || wp_parse_url($url, PHP_URL_QUERY) !== null
        || wp_parse_url($url, PHP_URL_FRAGMENT) !== null
        || wp_parse_url($url, PHP_URL_PORT) !== null) {
        return new WP_Error('migration_target_url_invalid', 'Địa chỉ đích phải là https://domain thật, không có đường dẫn, port hoặc tham số.');
    }
    $host = strtolower(rtrim($host, '.'));
    if (in_array($host, ['localhost', '127.0.0.1'], true)
        || str_ends_with($host, '.test') || str_ends_with($host, '.local')
        || filter_var($host, FILTER_VALIDATE_IP) !== false
        || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        || strpos($host, '.') === false) {
        return new WP_Error('migration_public_host_required', 'Tên miền đích phải là hostname công khai hợp lệ, không dùng IP/local domain.');
    }
    return 'https://' . $host;
}

/** Static manifest gate, no DB/filesystem writes. */
function mg_saoluu_migration_manifest_preflight($manifest, $target_url, $target_prefix) {
    if (!is_array($manifest) || ($manifest['backup_type'] ?? '') !== 'local_copy'
        || ($manifest['purpose'] ?? '') !== 'migration'
        || ($manifest['source_environment'] ?? '') !== 'local') {
        return new WP_Error('migration_wrong_package', 'Chỉ chấp nhận gói Local -> Production mã hóa, đúng mục đích.');
    }
    $normalized = mg_saoluu_migration_target_url($target_url);
    if (is_wp_error($normalized)) return $normalized;
    $source_url = (string) ($manifest['source_url'] ?? '');
    $source_host = strtolower((string) ($manifest['source_host'] ?? ''));
    $source_scheme = wp_parse_url($source_url, PHP_URL_SCHEME);
    $source_parsed_host = wp_parse_url($source_url, PHP_URL_HOST);
    if (!in_array(strtolower((string) $source_scheme), ['http','https'], true)
        || !is_string($source_parsed_host)
        || strtolower($source_parsed_host) !== $source_host
        || $source_host === wp_parse_url($normalized, PHP_URL_HOST)) {
        return new WP_Error('migration_source_invalid', 'Domain nguồn không hợp lệ hoặc trùng domain đích.');
    }
    if ((string) ($manifest['db_table_prefix'] ?? '') === ''
        || (string) $manifest['db_table_prefix'] !== (string) $target_prefix) {
        return new WP_Error('migration_prefix_mismatch', 'Prefix DB nguồn và đích khác nhau; không tự đổi tên bảng trên production.');
    }
    if (empty($manifest['hashes']['database_backup.sql']) || (int) ($manifest['file_count'] ?? 0) < 1) {
        return new WP_Error('migration_incomplete_package', 'Gói chuyển site thiếu DB hoặc không có file WordPress.');
    }
    return ['source_url' => $source_url, 'source_host' => $source_host, 'target_url' => $normalized];
}

/**
 * An existing content site is NOT an empty install. Fail closed and never
 * overwrite posts, customers or WooCommerce orders merely to change domain.
 */
function mg_saoluu_migration_target_fresh() {
    global $wpdb;
    $prefix = (string) $wpdb->prefix;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) return new WP_Error('migration_prefix_invalid', 'Prefix database đích không hợp lệ.');
    $queries = [
        'users' => "SELECT COUNT(*) FROM `{$prefix}users`",
        'comments' => "SELECT COUNT(*) FROM `{$prefix}comments`",
        'content' => "SELECT COUNT(*) FROM `{$prefix}posts` WHERE post_type NOT IN ('revision','nav_menu_item','customize_changeset','wp_template','wp_template_part','wp_global_styles','attachment')",
        'attachments' => "SELECT COUNT(*) FROM `{$prefix}posts` WHERE post_type = 'attachment'",
        'orders_posts' => "SELECT COUNT(*) FROM `{$prefix}posts` WHERE post_type IN ('shop_order','shop_order_refund')",
    ];
    $counts = [];
    foreach ($queries as $key => $sql) {
        $value = $wpdb->get_var($sql);
        if (!is_numeric($value)) return new WP_Error('migration_target_db_unreadable', 'Không đọc được dữ liệu trên website đích, đã dừng.');
        $counts[$key] = (int) $value;
    }
    $hpos_table = $prefix . 'wc_orders';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_table));
    if ($exists === $hpos_table) {
        $value = $wpdb->get_var("SELECT COUNT(*) FROM `{$hpos_table}`");
        if (!is_numeric($value)) return new WP_Error('migration_hpos_unreadable', 'Không thể xác minh đơn hàng HPOS.');
        $counts['hpos_orders'] = (int) $value;
    } else {
        $counts['hpos_orders'] = 0;
    }
    if ($counts['users'] !== 1 || $counts['comments'] !== 0 || $counts['content'] !== 0
        || $counts['attachments'] !== 0 || $counts['orders_posts'] !== 0 || $counts['hpos_orders'] !== 0) {
        return new WP_Error('migration_existing_site', 'Website đích phải là WordPress trắng: đúng 1 admin, không bài/page/media/comment/order. Hãy xóa nội dung mẫu hoặc dựng site đích mới.');
    }
    return $counts;
}

/**
 * Fast Local UI candidate lookup: read the catalog only, never scan/decrypt all
 * backup archives merely to render the Backup tab.
 */
function mg_saoluu_migration_local_candidates($limit = 20) {
    if (mg_saoluu_environment() !== 'local') return [];
    $dir = mg_saoluu_get_backup_dir();
    $metadata = mg_saoluu_read_metadata($dir . 'metadata.json');
    if (!is_array($metadata)) return [];
    $rows = [];
    foreach ($metadata as $name => $entry) {
        $name = basename((string) $name);
        if (!mg_saoluu_is_backup_filename($name) || !is_array($entry)) continue;
        if (($entry['type'] ?? '') !== 'local_copy' || ($entry['purpose'] ?? '') !== 'migration' || empty($entry['encrypted'])) continue;
        $path = $dir . $name;
        if (!is_file($path) || is_link($path)) continue;
        $rows[$name] = (string) ($entry['time'] ?? '');
    }
    arsort($rows, SORT_STRING);
    return array_slice(array_keys($rows), 0, max(1, min(50, (int) $limit)));
}

/** Produce a 10-minute target-bound, archive-bound token using Local Ed25519. */
function mg_saoluu_migration_sign_package($filename, $target_url, $password) {
    if (mg_saoluu_environment() !== 'local') return new WP_Error('migration_sign_local_only', 'Mã chuyển site chỉ ký trên Local.');
    $target = mg_saoluu_migration_target_url($target_url);
    if (is_wp_error($target)) return $target;
    $name = basename((string) $filename);
    if ($name !== $filename || !mg_saoluu_is_backup_filename($name)) return new WP_Error('migration_filename', 'Tên file backup không hợp lệ.');
    $path = mg_saoluu_get_backup_dir() . $name;
    if (!is_file($path) || is_link($path) || !mg_saoluu_is_encrypted_backup($path)) return new WP_Error('migration_encryption', 'Chỉ ký bản sao mã hóa có thật.');
    $manifest = mg_saoluu_read_backup_manifest($path, $password);
    if (is_wp_error($manifest)) return $manifest;
    global $wpdb;
    $pre = mg_saoluu_migration_manifest_preflight($manifest, $target, (string) $wpdb->prefix);
    if (is_wp_error($pre)) return $pre;
    if ($pre['source_host'] !== mg_saoluu_host()) return new WP_Error('migration_source_host', 'Tên miền nguồn backup khác website Local hiện tại.');
    $sha = @hash_file('sha256', $path);
    if (!is_string($sha) || !preg_match('/^[a-f0-9]{64}$/', $sha)) return new WP_Error('migration_hash', 'Không tính được SHA-256 bản sao.');
    $payload = [
        'format' => 'mgsl-migration-grant', 'version' => 1,
        'archive_sha256' => $sha,
        'source_host' => $pre['source_host'],
        'source_url' => $pre['source_url'],
        'target_url' => $target,
        'nonce' => bin2hex(random_bytes(24)),
        'created_at' => time(), 'expires_at' => time() + 10 * MINUTE_IN_SECONDS,
    ];
    $signed = mg_saoluu_security_sign_payload($payload);
    if (is_wp_error($signed)) return $signed;
    $grant = ['payload' => $payload, 'device_id' => $signed['device_id'], 'signature' => $signed['signature']];
    return 'MGMIGRATE1.' . mg_saoluu_b64url_encode(mg_saoluu_security_canonical_json($grant));
}

/**
 * Replay ledger lives outside WordPress DB so an exact rollback can restore
 * wp_options while still remembering consumed grants.
 */
function mg_saoluu_migration_nonce_ledger() {
    $private = mg_saoluu_private_state_read('migration-nonces.json');
    if (is_wp_error($private)) return $private;
    if (!is_array($private)) $private = [];
    // Legacy DB entries remain authoritative for backward compatibility.
    $legacy = get_option('mg_saoluu_used_migration_nonces', []);
    if (!is_array($legacy)) $legacy = [];
    return array_replace($legacy, $private);
}

function mg_saoluu_migration_nonce_ledger_write($ledger) {
    if (!is_array($ledger)) return new WP_Error('migration_nonce_write', 'Replay ledger không hợp lệ.');
    if (count($ledger) > 100) $ledger = array_slice($ledger, -100, null, true);
    $saved = mg_saoluu_private_state_write('migration-nonces.json', $ledger);
    return is_wp_error($saved)
        ? new WP_Error('migration_nonce_write', 'Không lưu được replay ledger ngoài database; đã dừng.')
        : true;
}

/** Verify source-device identity, target URL, exact encrypted archive, and replay. */
function mg_saoluu_migration_verify_grant($code, $archive_sha, $manifest, $target_url) {
    $code = trim((string) $code);
    if (strlen($code) > 8192 || strpos($code, 'MGMIGRATE1.') !== 0) return new WP_Error('migration_grant_format', 'Mã chuyển site không đúng định dạng.');
    $raw = mg_saoluu_b64url_decode(substr($code, strlen('MGMIGRATE1.')));
    $grant = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($grant) || !is_array($grant['payload'] ?? null)) return new WP_Error('migration_grant_invalid', 'Mã chuyển site không hợp lệ.');
    $p = $grant['payload'];
    $now = time();
    if (($p['format'] ?? '') !== 'mgsl-migration-grant' || (int) ($p['version'] ?? 0) !== 1
        || (int) ($p['created_at'] ?? 0) > $now + 30
        || (int) ($p['expires_at'] ?? 0) < $now
        || (int) ($p['expires_at'] ?? 0) - (int) ($p['created_at'] ?? 0) > 10 * MINUTE_IN_SECONDS
        || !preg_match('/^[a-f0-9]{48}$/', (string) ($p['nonce'] ?? ''))) {
        return new WP_Error('migration_grant_expired', 'Mã chuyển site hết hạn hoặc bị sửa.');
    }
    $target = mg_saoluu_migration_target_url($target_url);
    if (is_wp_error($target)) return $target;
    if (($p['target_url'] ?? '') !== $target || ($p['source_url'] ?? '') !== ($manifest['source_url'] ?? '')
        || ($p['source_host'] ?? '') !== ($manifest['source_host'] ?? '')
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($p['archive_sha256'] ?? ''))
        || !hash_equals((string) $p['archive_sha256'], (string) $archive_sha)) {
        return new WP_Error('migration_grant_mismatch', 'Mã ký không khớp domain hoặc SHA-256 của backup.');
    }
    $signer = (string) ($grant['device_id'] ?? '');
    $trusted = mg_saoluu_security_trusted_signers();
    if (!isset($trusted[$signer]) || (string) ($trusted[$signer]['source_host'] ?? '') !== $p['source_host']) {
        return new WP_Error('migration_source_untrusted', 'Thiết bị Local ký không khớp domain nguồn đã ghép nối.');
    }
    $verified = mg_saoluu_security_verify_payload_signature($p, (string) ($grant['signature'] ?? ''), $signer);
    if (is_wp_error($verified)) return $verified;
    $used = mg_saoluu_migration_nonce_ledger();
    if (is_wp_error($used)) return $used;
    if (isset($used[$p['nonce']])) return new WP_Error('migration_replay', 'Mã chuyển site đã được dùng.');
    return $p;
}

/** Burn the nonce in private storage before first DB mutation. */
function mg_saoluu_migration_consume_grant($payload) {
    $nonce = (string) ($payload['nonce'] ?? '');
    if (!preg_match('/^[a-f0-9]{48}$/', $nonce)) return new WP_Error('migration_grant_invalid', 'Thiếu mã một lần.');
    $used = mg_saoluu_migration_nonce_ledger();
    if (is_wp_error($used)) return $used;
    if (isset($used[$nonce])) return new WP_Error('migration_replay', 'Mã chuyển site đã dùng.');
    $used[$nonce] = ['at' => time(), 'sha256' => (string) ($payload['archive_sha256'] ?? '')];
    return mg_saoluu_migration_nonce_ledger_write($used);
}
