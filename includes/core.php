<?php
if (!defined('ABSPATH')) { exit; }
function mg_saoluu_normalize_path($path) {
    $path = trim((string) $path);
    if ($path === '') return '';
    if (strpos($path, '~/') === 0) {
        $home = getenv('HOME');
        if ($home) $path = rtrim($home, '/\\') . DIRECTORY_SEPARATOR . substr($path, 2);
    }
    return wp_normalize_path($path);
}

function mg_saoluu_exec_available() {
    if (!function_exists('exec')) return false;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('exec', $disabled, true);
}

function mg_saoluu_path_is_executable($path) {
    $path = mg_saoluu_normalize_path($path);
    if ($path === '' || !is_file($path)) return false;
    return PHP_OS_FAMILY === 'Windows' ? is_readable($path) : is_executable($path);
}

function mg_saoluu_command_path($command) {
    if (!mg_saoluu_exec_available()) return '';
    $out = []; $status = 1;
    exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $out, $status);
    if ($status === 0 && !empty($out[0])) {
        $candidate = mg_saoluu_normalize_path($out[0]);
        if (mg_saoluu_path_is_executable($candidate)) return $candidate;
    }
    return '';
}

function mg_saoluu_binary_candidates($type) {
    $names = $type === 'mysqldump' ? ['mysqldump', 'mariadb-dump'] : ['mysql', 'mariadb'];
    $items = [];
    foreach ($names as $name) {
        $path = mg_saoluu_command_path($name);
        if ($path) $items[] = $path;
    }
    foreach (['/opt/homebrew/bin', '/usr/local/bin', '/usr/local/mysql/bin'] as $dir) {
        foreach ($names as $name) $items[] = $dir . '/' . $name;
    }
    $patterns = [
        '/Users/Shared/DBngin/mysql/*/bin/', '/Users/Shared/DBngin/mariadb/*/bin/',
        '/Users/Shared/Herd/services/mysql/*/bin/', '/Users/Shared/Herd/services/mariadb/*/bin/'
    ];
    $home = getenv('HOME');
    if ($home) {
        $patterns[] = rtrim($home, '/\\') . '/Library/Application Support/Herd/bin/services/mysql/*/bin/';
        $patterns[] = rtrim($home, '/\\') . '/Library/Application Support/Herd/bin/services/mariadb/*/bin/';
    }
    foreach ($patterns as $pattern) {
        $dirs = glob($pattern, GLOB_ONLYDIR);
        if (!is_array($dirs)) continue;
        rsort($dirs, SORT_NATURAL);
        foreach ($dirs as $dir) foreach ($names as $name) $items[] = trailingslashit($dir) . $name;
    }
    return array_values(array_unique(array_map('mg_saoluu_normalize_path', $items)));
}

function mg_saoluu_clear_runtime_cache() {
    wp_cache_delete('binary_mysql', 'mg_saoluu');
    wp_cache_delete('binary_mysqldump', 'mg_saoluu');
    wp_cache_delete('system_checks_' . str_replace(['.', '-'], '_', MG_SAOLUU_VERSION), 'mg_saoluu');
    mg_saoluu_invalidate_backup_cache();
}

function mg_saoluu_find_binary($type, $force = false) {
    static $runtime = [];
    $type = $type === 'mysqldump' ? 'mysqldump' : 'mysql';
    $option = $type === 'mysqldump' ? 'mg_saoluu_mysqldump_binary' : 'mg_saoluu_mysql_binary';
    $configured = mg_saoluu_normalize_path(get_option($option, ''));
    if ($configured && mg_saoluu_path_is_executable($configured)) return $configured;

    if (!$force && array_key_exists($type, $runtime)) return $runtime[$type];
    $cache_key = 'binary_' . $type;
    if (!$force) {
        $cached = wp_cache_get($cache_key, 'mg_saoluu');
        if ($cached === '__missing__') return $runtime[$type] = '';
        if (is_string($cached) && $cached !== '' && mg_saoluu_path_is_executable($cached)) {
            return $runtime[$type] = $cached;
        }
    }

    $found = '';
    foreach (mg_saoluu_binary_candidates($type) as $candidate) {
        if (mg_saoluu_path_is_executable($candidate)) { $found = $candidate; break; }
    }
    wp_cache_set($cache_key, $found !== '' ? $found : '__missing__', 'mg_saoluu', 10 * MINUTE_IN_SECONDS);
    return $runtime[$type] = $found;
}

function mg_saoluu_binary_version($path) {
    if (!mg_saoluu_exec_available() || !mg_saoluu_path_is_executable($path)) return '';
    $out = []; $status = 1;
    exec(escapeshellarg($path) . ' --version 2>&1', $out, $status);
    return $status === 0 ? trim(implode(' ', $out)) : '';
}

function mg_saoluu_parse_db_host($db_host) {
    $result = ['host' => trim((string) $db_host), 'port' => '', 'socket' => ''];
    if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $db_host, $m)) { $result['host'] = $m[1]; $result['port'] = $m[2]; }
    elseif (preg_match('/^([^:]+):(\d+)$/', $db_host, $m)) { $result['host'] = $m[1]; $result['port'] = $m[2]; }
    elseif (preg_match('/^([^:]+):(.+\.sock)$/', $db_host, $m)) { $result['host'] = $m[1]; $result['socket'] = $m[2]; }
    if (!$result['socket'] && strtolower($result['host']) === 'localhost' && mg_saoluu_is_local_environment()) $result['host'] = '127.0.0.1';
    return $result;
}

function mg_saoluu_mysql_option_escape($value) {
    return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $value) . '"';
}

function mg_saoluu_create_mysql_config() {
    $dir = mg_saoluu_private_temp_dir();
    if (is_wp_error($dir)) return $dir;
    $db = mg_saoluu_parse_db_host(DB_HOST);
    $file = trailingslashit($dir) . 'mg-saoluu-' . wp_generate_password(20, false, false) . '.cnf';
    $text = "[client]\n";
    $text .= 'host=' . mg_saoluu_mysql_option_escape($db['host']) . "\n";
    if ($db['port']) $text .= 'port=' . mg_saoluu_mysql_option_escape($db['port']) . "\n";
    if ($db['socket']) $text .= 'socket=' . mg_saoluu_mysql_option_escape($db['socket']) . "\n";
    $text .= 'user=' . mg_saoluu_mysql_option_escape(DB_USER) . "\n";
    $text .= 'password=' . mg_saoluu_mysql_option_escape(DB_PASSWORD) . "\n";
    $text .= "default-character-set=utf8mb4\n";
    // Credentials must never be written to wp-content or appear in a public temp file.
    $handle = @fopen($file, 'xb');
    if (!$handle) return new WP_Error('mysql_config_failed', 'Không thể tạo cấu hình MySQL tạm.');
    @chmod($file, 0600);
    $written = @fwrite($handle, $text);
    $flushed = @fflush($handle);
    fclose($handle);
    if ($written !== strlen($text) || !$flushed) {
        @unlink($file);
        return new WP_Error('mysql_config_failed', 'Không thể ghi cấu hình MySQL tạm đầy đủ.');
    }
    return $file;
}

/** Resolve nonexistent leaf segments against the nearest real parent (symlink aware). */
function mg_saoluu_storage_real_path($path) {
    $path = rtrim(mg_saoluu_normalize_path($path), '/');
    if ($path === '') return '';
    $tail = [];
    $parent = $path;
    while (!file_exists($parent) && !is_link($parent)) {
        $next = dirname($parent);
        if ($next === $parent || $next === '.') return '';
        array_unshift($tail, basename($parent));
        $parent = $next;
    }
    $real = realpath($parent);
    if ($real === false) return '';
    return rtrim(wp_normalize_path($real), '/') . ($tail ? '/' . implode('/', $tail) : '');
}

function mg_saoluu_storage_is_inside($path, $root) {
    $p = mg_saoluu_storage_real_path($path);
    $r = mg_saoluu_storage_real_path($root);
    if (!$p || !$r) return true; // Fail closed if the actual path cannot be established.
    return $p === $r || strpos($p, trailingslashit($r)) === 0;
}

/** True only if backups cannot be fetched through this site's document roots. */
function mg_saoluu_storage_path_safe($candidate) {
    $candidate = mg_saoluu_normalize_path($candidate);
    if ($candidate === '' || $candidate === '/' || $candidate[0] !== '/' || strpos($candidate, "\0") !== false) return false;
    $boundaries = [ABSPATH, WP_CONTENT_DIR];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) $boundaries[] = (string) $_SERVER['DOCUMENT_ROOT'];
    foreach ($boundaries as $root) {
        if (mg_saoluu_storage_is_inside($candidate, $root)) return false;
    }
    return true;
}

/** External candidate. Never silently fall back into wp-content on shared hosting. */
function mg_saoluu_storage_default_path() {
    $root = rtrim(wp_normalize_path(ABSPATH), '/');
    $site_key = substr(hash('sha256', $root . '|' . DB_NAME), 0, 12);
    if (PHP_OS_FAMILY === 'Darwin' && getenv('HOME')) {
        $candidate = rtrim(wp_normalize_path(getenv('HOME')), '/') . '/Library/Application Support/MG Sao Luu/' . $site_key;
        if (mg_saoluu_storage_path_safe($candidate)) return $candidate;
    }
    // WordPress may be installed below the public document root. Select its parent.
    $document_root = !empty($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : $root;
    $parent = dirname(rtrim(wp_normalize_path($document_root), '/'));
    $candidate = $parent . '/.mg-saoluu-' . sanitize_file_name(basename($root)) . '-' . $site_key;
    return $candidate;
}

function mg_saoluu_get_backup_dir() {
    static $dir = null;
    if ($dir !== null) return $dir;
    $configured = defined('MG_SAOLUU_BACKUP_DIR') && MG_SAOLUU_BACKUP_DIR;
    $candidate = $configured ? mg_saoluu_normalize_path(MG_SAOLUU_BACKUP_DIR) : mg_saoluu_storage_default_path();
    // A misconfigured path is NEVER used to store private archives, even before preflight.
    if (!mg_saoluu_storage_path_safe($candidate)) {
        $dir = trailingslashit(wp_normalize_path(mg_saoluu_storage_default_path()));
        return $dir;
    }
    if (!is_dir($candidate)) wp_mkdir_p($candidate);
    if (is_dir($candidate)) @chmod($candidate, 0700);
    $dir = trailingslashit(wp_normalize_path($candidate));
    mg_saoluu_secure_backup_directory($dir);
    return $dir;
}

/** Return a recoverable WP_Error instead of writing archives to a web-visible path. */
function mg_saoluu_storage_preflight() {
    if (defined('MG_SAOLUU_BACKUP_DIR') && MG_SAOLUU_BACKUP_DIR &&
        !mg_saoluu_storage_path_safe(MG_SAOLUU_BACKUP_DIR)) {
        return new WP_Error('unsafe_backup_location', 'MG_SAOLUU_BACKUP_DIR nằm trong webroot, không phải đường dẫn tuyệt đối, hoặc qua symlink không an toàn. Hãy chọn thư mục ngoài public_html.');
    }
    $dir = mg_saoluu_get_backup_dir();
    if (!mg_saoluu_storage_path_safe($dir)) return new WP_Error('unsafe_backup_location', 'Thư mục sao lưu không nằm ngoài webroot. Đã chặn mọi thao tác ghi backup.');
    if (!is_dir($dir) || !is_writable($dir)) return new WP_Error('backup_dir_unwritable', 'Không thể ghi thư mục sao lưu ngoài webroot. Plugin không fallback vào wp-content.');
    return true;
}

/** Keep decrypted database/media and credentials in an owner-only directory outside every webroot. */
function mg_saoluu_private_temp_dir() {
    $safe = mg_saoluu_storage_preflight();
    if (is_wp_error($safe)) return $safe;
    $dir = trailingslashit(mg_saoluu_get_backup_dir()) . '.working';
    if (is_link($dir) || !mg_saoluu_storage_path_safe($dir)) {
        return new WP_Error('unsafe_private_temp', 'Thư mục tạm của MG Sao Lưu không an toàn.');
    }
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return new WP_Error('private_temp_failed', 'Không thể tạo thư mục tạm bên ngoài webroot.');
    }
    @chmod($dir, 0700);
    if (!is_dir($dir) || !is_writable($dir)) {
        return new WP_Error('private_temp_failed', 'Thư mục tạm bên ngoài webroot không thể ghi.');
    }
    return trailingslashit($dir);
}

function mg_saoluu_secure_backup_directory($dir) {
    if (!is_dir($dir) || !is_writable($dir)) return;
    $files = [
        'index.php' => "<?php\n// Silence is golden.\n",
        '.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
        'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>"
    ];
    foreach ($files as $name => $content) {
        $path = $dir . $name;
        if (!file_exists($path)) @file_put_contents($path, $content, LOCK_EX);
    }
}

function mg_saoluu_is_backup_filename($name) {
    return (bool) preg_match('/^backup_[a-z0-9_-]+_\d{8}_\d{6}(?:_[a-z0-9]{6})?\.(?:zip|mgbackup)$/i', basename((string) $name));
}


function mg_saoluu_backup_cache_key() {
    return 'mg_saoluu_backup_files_' . substr(hash('sha256', mg_saoluu_get_backup_dir()), 0, 12);
}

function mg_saoluu_invalidate_backup_cache() {
    if (function_exists('mg_saoluu_get_backup_dir')) wp_cache_delete(mg_saoluu_backup_cache_key(), 'mg_saoluu');
}

function mg_saoluu_list_backup_files($force = false) {
    $cache_key = mg_saoluu_backup_cache_key();
    if (!$force) {
        $cached = wp_cache_get($cache_key, 'mg_saoluu');
        if (is_array($cached)) return $cached;
    }
    $dir = mg_saoluu_get_backup_dir();
    $files = array_merge((array) glob($dir . 'backup_*.zip'), (array) glob($dir . 'backup_*.mgbackup'));
    $files = array_values(array_filter(array_unique($files), 'is_file'));
    usort($files, function($a, $b){ return filemtime($b) <=> filemtime($a); });
    wp_cache_set($cache_key, $files, 'mg_saoluu', 20);
    return $files;
}

function mg_saoluu_save_metadata($file, $metadata) {
    $json = wp_json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) return false;
    // Temp in the SAME private directory enables an atomic rename on Unix.
    // This avoids a torn/truncated catalog if PHP dies mid-write.
    try { $suffix = bin2hex(random_bytes(8)); } catch (Throwable $e) { return false; }
    $temp = dirname($file) . '/.mg-catalog-' . $suffix . '.tmp';
    $handle = @fopen($temp, 'xb');
    if (!$handle) return false;
    @chmod($temp, 0600);
    $size = strlen($json); $offset = 0;
    while ($offset < $size) {
        $written = @fwrite($handle, substr($json, $offset));
        if ($written === false || $written === 0) break;
        $offset += $written;
    }
    $ok = $offset === $size && @fflush($handle);
    @fclose($handle);
    if (!$ok || !@rename($temp, $file)) { @unlink($temp); return false; }
    @chmod($file, 0600);
    mg_saoluu_invalidate_backup_cache();
    return true;
}

function mg_saoluu_read_metadata($file) {
    if (!file_exists($file)) return [];
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/**
 * Small operational state that must survive DB rollback without changing
 * wp_options. Files live in the same private backup directory, are JSON,
 * atomically replaced and permissioned 0600.
 */
function mg_saoluu_private_state_path($name) {
    $allowed = ['audit-log.json', 'migration-nonces.json'];
    if (!in_array((string) $name, $allowed, true)) {
        return new WP_Error('private_state_name', 'Tên private state không hợp lệ.');
    }
    $safe = mg_saoluu_storage_preflight();
    if (is_wp_error($safe)) return $safe;
    $dir = trailingslashit(mg_saoluu_get_backup_dir());
    $path = $dir . $name;
    if (is_link($path) || (file_exists($path) && !is_file($path))) {
        return new WP_Error('private_state_unsafe', 'Private state path không phải file an toàn.');
    }
    return $path;
}

function mg_saoluu_private_state_read($name) {
    $path = mg_saoluu_private_state_path($name);
    if (is_wp_error($path)) return $path;
    if (!file_exists($path)) return [];
    $data = mg_saoluu_read_metadata($path);
    return is_array($data) ? $data : [];
}

function mg_saoluu_private_state_write($name, $data) {
    $path = mg_saoluu_private_state_path($name);
    if (is_wp_error($path)) return $path;
    if (!is_array($data)) return new WP_Error('private_state_invalid', 'Private state phải là dữ liệu mảng.');
    $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) return new WP_Error('private_state_write', 'Không mã hóa được private state.');

    try { $suffix = bin2hex(random_bytes(8)); }
    catch (Throwable $e) { return new WP_Error('private_state_write', 'Không tạo được private state tạm.'); }
    $temp = dirname($path) . '/.mg-state-' . $suffix . '.tmp';
    $handle = @fopen($temp, 'xb');
    if (!$handle) return new WP_Error('private_state_write', 'Không tạo được private state tạm.');
    @chmod($temp, 0600);
    $size = strlen($json);
    $offset = 0;
    while ($offset < $size) {
        $written = @fwrite($handle, substr($json, $offset));
        if (!is_int($written) || $written <= 0) break;
        $offset += $written;
    }
    $ok = $offset === $size && @fflush($handle);
    @fclose($handle);
    if (!$ok || !@rename($temp, $path)) {
        @unlink($temp);
        return new WP_Error('private_state_write', 'Không ghi được private state an toàn.');
    }
    @chmod($path, 0600);
    clearstatcache(true, $path);
    if (!is_file($path) || is_link($path)) return new WP_Error('private_state_write', 'Private state sau ghi không hợp lệ.');
    return true;
}

function mg_saoluu_private_state_delete($name) {
    $path = mg_saoluu_private_state_path($name);
    if (is_wp_error($path)) return $path;
    if (!file_exists($path)) return true;
    if (!is_file($path) || is_link($path)) return new WP_Error('private_state_unsafe', 'Private state không thể xóa an toàn.');
    return @unlink($path) ? true : new WP_Error('private_state_delete', 'Không xóa được private state.');
}

/**
 * Atomic, non-blocking operation lock across PHP-FPM/cron workers.
 * Uses a private filesystem lock rather than a transient get/set race.
 * The handle must remain open until release. No polling or sleep.
 */
function &mg_saoluu_operation_lock_handles() {
    static $handles = [];
    return $handles;
}

function mg_saoluu_acquire_lock() {
    $dir = mg_saoluu_private_temp_dir();
    if (is_wp_error($dir)) return $dir;
    if (!function_exists('flock')) return new WP_Error('flock_unavailable', 'Hosting không hỗ trợ khóa file an toàn.');
    $path = $dir . 'mg-saoluu-operation.lock';
    if (is_link($path)) return new WP_Error('operation_lock_unsafe', 'Đường dẫn khóa bị thay bằng symlink.');
    $handle = @fopen($path, 'c+b');
    if (!$handle) return new WP_Error('operation_lock_unavailable', 'Không thể mở file khóa ngoài webroot.');
    @chmod($path, 0600);
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return new WP_Error('operation_locked', 'Một việc Backup/Restore khác đang chạy.');
    }
    try { $token = bin2hex(random_bytes(16)); }
    catch (Throwable $e) { @flock($handle, LOCK_UN); fclose($handle); return new WP_Error('operation_token_failed', 'Không tạo được mã khóa tác vụ.'); }
    $handles =& mg_saoluu_operation_lock_handles();
    $handles[$token] = $handle;
    return $token;
}

function mg_saoluu_release_lock($token) {
    $handles =& mg_saoluu_operation_lock_handles();
    if (!is_string($token) || !isset($handles[$token])) return;
    $handle = $handles[$token];
    unset($handles[$token]);
    @flock($handle, LOCK_UN);
    fclose($handle);
}

function mg_saoluu_is_excluded_path($relative, $type = 'safe_full') {
    $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
    $exact = ['wp-config.php', '.htaccess', '.user.ini', 'php.ini', 'error_log', 'wp-content/debug.log'];
    if (in_array($relative, $exact, true)) return true;
    $prefixes = ['wp-content/cache/', 'wp-content/upgrade/', 'wp-content/backups/', '.git/', 'node_modules/'];
    foreach ($prefixes as $prefix) if (strpos($relative, $prefix) === 0) return true;
    if (strpos('/' . $relative, '/node_modules/') !== false || strpos('/' . $relative, '/.git/') !== false) return true;
    if (preg_match('#(^|/)(error_log|debug\.log|\.DS_Store)$#', $relative)) return true;
    if ($type === 'local_copy') {
        $allowed = ['wp-content/themes/', 'wp-content/plugins/', 'wp-content/mu-plugins/', 'wp-content/uploads/'];
        foreach ($allowed as $prefix) if (strpos($relative, $prefix) === 0) return false;
        return true;
    }
    return false;
}

function mg_saoluu_manifest_signature($manifest) {
    unset($manifest['signature']);
    ksort($manifest);
    $mode = isset($manifest['signing_mode']) ? (string) $manifest['signing_mode'] : 'wordpress';
    $key = ($mode === 'custom' && mg_saoluu_signing_key_is_custom()) ? MG_SAOLUU_SIGNING_KEY : wp_salt('auth');
    return hash_hmac('sha256', wp_json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $key);
}

function mg_saoluu_manifest($type) {
    global $wp_version, $wpdb;
    $manifest = [
        'format' => 'mg-saoluu',
        'format_version' => 2,
        'plugin_version' => MG_SAOLUU_VERSION,
        'backup_type' => $type,
        'created_at' => current_time('mysql'),
        'source_environment' => mg_saoluu_environment(),
        'source_url' => home_url('/'),
        'source_site_url' => site_url('/'),
        'source_host' => mg_saoluu_host(),
        'wordpress_version' => isset($wp_version) ? $wp_version : '',
        'php_version' => PHP_VERSION,
        'db_fingerprint' => substr(hash('sha256', DB_NAME . '|' . DB_HOST), 0, 16),
        'db_table_prefix' => (string) $wpdb->prefix,
        'signing_mode' => mg_saoluu_signing_key_is_custom() ? 'custom' : 'wordpress',
        'notes' => 'Không chứa wp-config.php, .htaccess, php.ini, cache hoặc file sao lưu cũ.',
    ];
    $manifest['signature'] = mg_saoluu_manifest_signature($manifest);
    return $manifest;
}

function mg_saoluu_verify_manifest_signature($manifest) {
    if (empty($manifest['signature'])) return false;
    return hash_equals((string) $manifest['signature'], mg_saoluu_manifest_signature($manifest));
}

/** Native CLI only; intentionally no PHP SQL parser/export fallback. */
function mg_saoluu_run_mysqldump($sql_file) {
    $binary = mg_saoluu_find_binary('mysqldump');
    if (mg_saoluu_exec_available() && $binary) {
        $config = mg_saoluu_create_mysql_config();
        if (!is_wp_error($config)) {
            $version = mg_saoluu_binary_version($binary);
            $options = ['--single-transaction', '--quick', '--skip-lock-tables', '--hex-blob', '--default-character-set=utf8mb4'];
            if (stripos($version, 'MariaDB') === false) { $options[] = '--no-tablespaces'; $options[] = '--set-gtid-purged=OFF'; }
            $private_temp = mg_saoluu_private_temp_dir();
            if (is_wp_error($private_temp)) { @unlink($config); return $private_temp; }
            $error_file = $private_temp . 'mg-dump-error-' . wp_generate_password(10, false, false) . '.log';
            $command = sprintf('%s --defaults-extra-file=%s %s %s > %s 2> %s', escapeshellarg($binary), escapeshellarg($config), implode(' ', array_map('escapeshellarg', $options)), escapeshellarg(DB_NAME), escapeshellarg($sql_file), escapeshellarg($error_file));
            $out = []; $status = 1; @set_time_limit(0); exec($command, $out, $status); @unlink($config);
            if ($status === 0 && file_exists($sql_file) && filesize($sql_file) > 0) { @unlink($error_file); return true; }
            // SQL can be partial after CLI failure. Never silently retry using an
            // unrelated exporter and never package a possibly inconsistent dump.
            if (file_exists($sql_file)) @unlink($sql_file);
            @unlink($error_file);
            return new WP_Error('mysqldump_failed', 'mysqldump thất bại. Đã dừng, không thay bằng PHP export vì không thể bảo đảm đầy đủ dữ liệu.');
        }
        return new WP_Error('mysql_config_unavailable', 'Không tạo được file kết nối MySQL riêng tư cho mysqldump.');
    }
    return new WP_Error('mysqldump_required', 'Backup v1.2.1 cần mysqldump. Hãy cấu hình binary trong Cài đặt → Kiểm tra hệ thống; không dùng PHP fallback thiếu kiểm chứng.');
}

/** Native CLI only; intentionally no PHP SQL parser/export fallback. */
function mg_saoluu_run_mysql_import($sql_file) {
    if (!is_readable($sql_file) || filesize($sql_file) < 1) return new WP_Error('sql_unreadable', 'File SQL trống hoặc không thể đọc.');
    $binary = mg_saoluu_find_binary('mysql');
    if (mg_saoluu_exec_available() && $binary) {
        $config = mg_saoluu_create_mysql_config();
        if (!is_wp_error($config)) {
            $command = sprintf('%s --defaults-extra-file=%s %s < %s 2>&1', escapeshellarg($binary), escapeshellarg($config), escapeshellarg(DB_NAME), escapeshellarg($sql_file));
            $out = []; $status = 1; @set_time_limit(0); exec($command, $out, $status); @unlink($config);
            if ($status === 0) return true;
            // A failed native import may already have changed tables. Retrying
            // with the PHP importer can destroy the only recoverable state.
            return new WP_Error('mysql_import_failed', 'mysql import thất bại; dữ liệu có thể đã ghi một phần. Chuyển sang rollback, không chạy PHP importer lần hai.');
        }
        return new WP_Error('mysql_config_unavailable', 'Không tạo được file kết nối MySQL riêng tư cho restore.');
    }
    return new WP_Error('mysql_required', 'Restore v1.2.1 yêu cầu mysql CLI. Không nhập SQL bằng PHP fallback chưa được nghiệm thu.');
}

function mg_saoluu_test_database_tools() {
    $private_temp = mg_saoluu_private_temp_dir();
    if (is_wp_error($private_temp)) return $private_temp;
    $temp = $private_temp . 'mg-test-' . wp_generate_password(10, false, false) . '.sql';
    $result = mg_saoluu_run_mysqldump($temp);
    if (file_exists($temp)) @unlink($temp);
    return $result;
}

function mg_saoluu_zip_validate($zip_file) {
    if (!class_exists('ZipArchive')) return new WP_Error('zip_missing', 'Máy chủ chưa bật ZipArchive.');
    if (!is_readable($zip_file) || filesize($zip_file) < 1) return new WP_Error('zip_empty', 'File ZIP không hợp lệ.');
    $zip = new ZipArchive();
    if ($zip->open($zip_file, ZipArchive::CHECKCONS) !== true) return new WP_Error('zip_open_failed', 'Không thể mở hoặc kiểm tra file ZIP.');
    $has_sql = false; $has_manifest = false; $total = 0; $seen_names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!$stat) continue;
        $name = str_replace('\\', '/', $stat['name']);
        if (function_exists('mg_saoluu_zip_entry_is_symlink') && mg_saoluu_zip_entry_is_symlink($zip, $i)) { $zip->close(); return new WP_Error('unsafe_zip_symlink', 'ZIP chứa symbolic link không được phép: ' . $name); }
        if (!mg_saoluu_is_safe_zip_entry($name)) { $zip->close(); return new WP_Error('unsafe_zip', 'ZIP chứa đường dẫn không an toàn: ' . $name); }
        if (isset($seen_names[$name])) { $zip->close(); return new WP_Error('zip_duplicate', 'ZIP chứa entry trùng tên.'); }
        $seen_names[$name] = true;
        if ($name === 'database_backup.sql') $has_sql = true;
        if ($name === 'mg-backup-manifest.json') $has_manifest = true;
        $total += isset($stat['size']) ? (int) $stat['size'] : 0;
        $compressed = isset($stat['comp_size']) ? max(1, (int) $stat['comp_size']) : 1;
        if (($stat['size'] ?? 0) > 10 * 1024 * 1024 && (($stat['size'] ?? 0) / $compressed) > MG_SAOLUU_MAX_ZIP_RATIO) {
            $zip->close(); return new WP_Error('zip_ratio', 'ZIP có mức nén bất thường, đã dừng để tránh file nguy hiểm.');
        }
    }
    $zip->close();
    if (!$has_sql) return new WP_Error('sql_missing', 'ZIP không có database_backup.sql.');
    if (!$has_manifest) return new WP_Error('manifest_missing', 'ZIP không có thông tin nguồn. Không dùng file này để chuyển giữa các môi trường.');
    if ($total > MG_SAOLUU_MAX_ZIP_UNCOMPRESSED) return new WP_Error('zip_too_large', 'Dung lượng giải nén vượt giới hạn an toàn.');
    return true;
}

function mg_saoluu_verify_zip_hashes($zip_file, $manifest) {
    $expected = isset($manifest['hashes']) && is_array($manifest['hashes']) ? $manifest['hashes'] : [];
    if (!$expected) return new WP_Error('hashes_missing', 'Bản sao không có danh sách kiểm tra nội dung.');
    $zip = new ZipArchive();
    if ($zip->open($zip_file) !== true) return new WP_Error('zip_open_failed', 'Không thể mở ZIP để kiểm tra nội dung.');
    $seen = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', $zip->getNameIndex($i));
        if ($name === 'mg-backup-manifest.json' || substr($name, -1) === '/') continue;
        if (!isset($expected[$name])) { $zip->close(); return new WP_Error('unexpected_file', 'ZIP có file không nằm trong danh sách an toàn: ' . $name); }
        $stream = $zip->getStream($name);
        if (!$stream) { $zip->close(); return new WP_Error('hash_read_failed', 'Không thể đọc file để kiểm tra: ' . $name); }
        $ctx = hash_init('sha256');
        while (!feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) { fclose($stream); $zip->close(); return new WP_Error('hash_read_failed', 'Không thể kiểm tra file: ' . $name); }
            hash_update($ctx, $chunk);
        }
        fclose($stream);
        $actual = hash_final($ctx);
        if (!hash_equals((string) $expected[$name], $actual)) { $zip->close(); return new WP_Error('hash_mismatch', 'File đã bị thay đổi hoặc hỏng: ' . $name); }
        $seen[$name] = true;
    }
    $zip->close();
    foreach ($expected as $name => $hash) if (empty($seen[$name])) return new WP_Error('hash_file_missing', 'ZIP bị thiếu file: ' . $name);
    return true;
}

function mg_saoluu_read_zip_manifest($zip_file) {
    $valid = mg_saoluu_zip_validate($zip_file);
    if (is_wp_error($valid)) return $valid;
    $zip = new ZipArchive();
    $zip->open($zip_file);
    $json = $zip->getFromName('mg-backup-manifest.json');
    $zip->close();
    $manifest = json_decode((string) $json, true);
    if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'mg-saoluu') return new WP_Error('manifest_invalid', 'Thông tin nguồn trong ZIP không hợp lệ.');
    $hash_check = mg_saoluu_verify_zip_hashes($zip_file, $manifest);
    if (is_wp_error($hash_check)) return $hash_check;
    return $manifest;
}

