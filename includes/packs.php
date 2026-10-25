<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Pack Backup keeps restore scope intentionally small.
 * Migration/domain rewrite is not part of this module.
 */
function mg_saoluu_pack_registry() {
    return [
        'settings' => [
            'label' => 'Cài đặt',
            'description' => 'wp_options, bỏ transient/cache tạm.',
            'mode' => 'selective',
        ],
        'content' => [
            'label' => 'Nội dung WordPress',
            'description' => 'Posts/Pages/CPT + post meta + taxonomy + comments.',
            'mode' => 'tables',
        ],
        'full_db' => [
            'label' => 'Toàn bộ database',
            'description' => 'Toàn bộ DB bằng mysqldump native.',
            'mode' => 'full',
        ],
    ];
}

function mg_saoluu_pack_definition($pack) {
    $pack = sanitize_key((string) $pack);
    $all = mg_saoluu_pack_registry();
    return $all[$pack] ?? null;
}

function mg_saoluu_pack_tables($pack) {
    global $wpdb;
    $pack = sanitize_key((string) $pack);
    if ($pack === 'settings') return [$wpdb->options];
    if ($pack === 'content') {
        return [
            $wpdb->posts,
            $wpdb->postmeta,
            $wpdb->terms,
            $wpdb->term_taxonomy,
            $wpdb->term_relationships,
            $wpdb->termmeta,
            $wpdb->comments,
            $wpdb->commentmeta,
        ];
    }
    if ($pack === 'full_db') return [];
    return new WP_Error('pack_invalid', 'Pack backup không hợp lệ.');
}

function mg_saoluu_pack_settings_scope() {
    return 'exclude_transients_runtime_v3';
}

function mg_saoluu_pack_settings_hash_algorithm() {
    return 'sha256-settings-logical-row-v1';
}

function mg_saoluu_pack_settings_predicate() {
    // Settings Pack contains durable configuration, not runtime scheduler/cache
    // state that may legitimately change between import and post-verify.
    return "LEFT(option_name,11) <> '_transient_'"
        . " AND LEFT(option_name,16) <> '_site_transient_'"
        . " AND option_name NOT IN ('cron','rewrite_rules','auto_updater.lock','core_updater.lock')";
}

function mg_saoluu_pack_quote_identifier($name) {
    $name = (string) $name;
    if ($name === '' || strlen($name) > 64 || preg_match('/[^A-Za-z0-9_$]/', $name)) return '';
    $tick = chr(96);
    return $tick . str_replace($tick, $tick . $tick, $name) . $tick;
}

function mg_saoluu_pack_normalize_schema_sql($sql) {
    $sql = preg_replace('/\\sAUTO_INCREMENT=\\d+\\b/i', '', (string) $sql);
    return preg_replace('/\\s+/', ' ', trim((string) $sql));
}

function mg_saoluu_pack_schema_fingerprint($tables) {
    global $wpdb;
    if (!is_array($tables) || !$tables) return '';
    $items = [];
    foreach ($tables as $table) {
        $quoted = mg_saoluu_pack_quote_identifier($table);
        if ($quoted === '') return new WP_Error('pack_schema_name', 'Tên bảng không an toàn.');
        $row = $wpdb->get_row('SHOW CREATE TABLE ' . $quoted, ARRAY_N);
        if (!is_array($row) || empty($row[1])) return new WP_Error('pack_schema_missing', 'Không đọc được schema bảng ' . $table . '.');
        // AUTO_INCREMENT=N is runtime data state, not structural schema. MySQL
        // may advance it after inserts even when columns/indexes are unchanged.
        $items[(string) $table] = mg_saoluu_pack_normalize_schema_sql((string) $row[1]);
    }
    ksort($items, SORT_STRING);
    return hash('sha256', wp_json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function mg_saoluu_pack_cleanup_sql($pack, $tables) {
    $pack = sanitize_key((string) $pack);
    if ($pack === 'settings') {
        $quoted = mg_saoluu_pack_quote_identifier($tables[0] ?? '');
        if ($quoted === '') return new WP_Error('pack_table_invalid', 'Bảng settings không hợp lệ.');
        return 'DELETE FROM ' . $quoted . ' WHERE ' . mg_saoluu_pack_settings_predicate() . ';';
    }
    $lines = [];
    foreach ((array) $tables as $table) {
        $quoted = mg_saoluu_pack_quote_identifier($table);
        if ($quoted === '') return new WP_Error('pack_table_invalid', 'Tên bảng Pack không hợp lệ.');
        $lines[] = 'DELETE FROM ' . $quoted . ';';
    }
    return implode("\n", $lines);
}

/**
 * One deterministic dump profile is used for backup-state hashing and worker
 * post-restore verification. Rows are ordered by primary/unique key.
 */
function mg_saoluu_pack_dump_options($pack, $binary) {
    $options = [
        '--single-transaction',
        '--quick',
        '--skip-lock-tables',
        '--hex-blob',
        '--default-character-set=utf8mb4',
        '--skip-comments',
        '--skip-add-locks',
        '--order-by-primary',
    ];
    if ($pack !== 'full_db') {
        $options[] = '--no-create-info';
        $options[] = '--replace';
        $options[] = '--skip-triggers';
    }
    $version = mg_saoluu_binary_version($binary);
    if (stripos($version, 'MariaDB') === false) {
        $options[] = '--no-tablespaces';
        $options[] = '--set-gtid-purged=OFF';
    }
    if ($pack === 'settings') {
        $options[] = '--skip-extended-insert';
        $options[] = '--where=' . mg_saoluu_pack_settings_predicate();
    }
    return $options;
}

function mg_saoluu_pack_state_hash_line($line, $pack = '') {
    $line = preg_replace('/\\sAUTO_INCREMENT=\\d+\\b/i', '', (string) $line);
    if ($pack === 'settings') {
        // wp_options.option_id is a physical surrogate key, not Settings meaning.
        // With --skip-extended-insert each dump row is one REPLACE statement,
        // so normalize only the first numeric value while retaining
        // option_name, option_value and autoload byte-for-byte.
        $line = preg_replace(
            '/^(REPLACE INTO `[^`]+` VALUES \\()\\d+(,)/',
            '$1<OPTION_ID>$2',
            $line
        );
    }
    return $line;
}

function mg_saoluu_pack_state_hash_file($path, $pack = '') {
    $h = @fopen($path, 'rb');
    if (!$h) return '';
    $ctx = hash_init('sha256');
    if ($pack === 'settings') {
        $rows = [];
        while (($line = fgets($h)) !== false) {
            $line = mg_saoluu_pack_state_hash_line($line, $pack);
            if (strpos($line, 'REPLACE INTO `') === 0) $rows[] = $line;
        }
        fclose($h);
        if (!$rows) return '';
        sort($rows, SORT_STRING);
        foreach ($rows as $line) hash_update($ctx, $line);
        return hash_final($ctx);
    }
    while (($line = fgets($h)) !== false) {
        hash_update($ctx, mg_saoluu_pack_state_hash_line($line, $pack));
    }
    fclose($h);
    return hash_final($ctx);
}

function mg_saoluu_pack_run_dump($pack, $output, $tables = null) {
    $binary = mg_saoluu_find_binary('mysqldump');
    if (!mg_saoluu_exec_available() || !$binary) return new WP_Error('mysqldump_required', 'Pack backup cần mysqldump/mariadb-dump native.');
    $config = mg_saoluu_create_mysql_config();
    if (is_wp_error($config)) return $config;
    $options = mg_saoluu_pack_dump_options($pack, $binary);
    if ($tables === null) {
        $tables = $pack === 'full_db' ? [] : mg_saoluu_pack_tables($pack);
        if (is_wp_error($tables)) { @unlink($config); return $tables; }
    }

    $private_temp = mg_saoluu_private_temp_dir();
    if (is_wp_error($private_temp)) { @unlink($config); return $private_temp; }
    $error_file = $private_temp . 'mg-pack-dump-' . wp_generate_password(10, false, false) . '.log';
    $parts = [
        escapeshellarg($binary),
        '--defaults-extra-file=' . escapeshellarg($config),
    ];
    foreach ($options as $option) $parts[] = escapeshellarg($option);
    $parts[] = escapeshellarg(DB_NAME);
    foreach ((array) $tables as $table) $parts[] = escapeshellarg($table);
    $command = implode(' ', $parts) . ' > ' . escapeshellarg($output) . ' 2> ' . escapeshellarg($error_file);
    $out = []; $status = 1; @set_time_limit(0);
    exec($command, $out, $status);
    @unlink($config);
    if ($status !== 0 || !is_file($output) || filesize($output) < 1) {
        @unlink($output); @unlink($error_file);
        return new WP_Error('pack_dump_failed', 'Không tạo được SQL Pack nhất quán.');
    }
    @unlink($error_file);
    @chmod($output, 0600);
    $state_hash = mg_saoluu_pack_state_hash_file($output, $pack);
    if ($state_hash === '') { @unlink($output); return new WP_Error('pack_state_hash', 'Không fingerprint được trạng thái Pack.'); }
    return ['state_hash' => $state_hash, 'dump_options' => $options];
}

function mg_saoluu_pack_dump_sql($pack, $sql_file) {
    $pack = sanitize_key((string) $pack);
    if (!mg_saoluu_pack_definition($pack)) return new WP_Error('pack_invalid', 'Pack backup không hợp lệ.');

    if ($pack === 'full_db') {
        return mg_saoluu_pack_run_dump($pack, $sql_file, []);
    }

    $tables = mg_saoluu_pack_tables($pack);
    if (is_wp_error($tables)) return $tables;
    $cleanup = mg_saoluu_pack_cleanup_sql($pack, $tables);
    if (is_wp_error($cleanup)) return $cleanup;
    $private = mg_saoluu_private_temp_dir();
    if (is_wp_error($private)) return $private;
    $data_file = $private . 'mg-pack-data-' . wp_generate_password(14, false, false) . '.sql';

    $dump = mg_saoluu_pack_run_dump($pack, $data_file, $tables);
    if (is_wp_error($dump)) return $dump;

    $out = @fopen($sql_file, 'xb');
    $in = @fopen($data_file, 'rb');
    if (!$out || !$in) {
        if ($out) fclose($out); if ($in) fclose($in);
        @unlink($data_file); @unlink($sql_file);
        return new WP_Error('pack_sql_temp', 'Không tạo được SQL Pack tạm.');
    }
    @chmod($sql_file, 0600);
    $header = "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n" . $cleanup . "\n";
    $ok = mg_saoluu_write_all($out, $header)
        && stream_copy_to_stream($in, $out) !== false
        && mg_saoluu_write_all($out, "\nCOMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n")
        && @fflush($out);
    fclose($in); fclose($out); @unlink($data_file);
    if (!$ok) { @unlink($sql_file); return new WP_Error('pack_sql_write', 'Không hoàn tất SQL Pack.'); }
    return $dump;
}

function mg_saoluu_pack_zstd_binary() {
    static $binary = null;
    if ($binary !== null) return $binary;
    $binary = mg_saoluu_command_path('zstd');
    foreach (['/usr/bin/zstd','/usr/local/bin/zstd','/opt/homebrew/bin/zstd'] as $candidate) {
        if ($binary === '' && mg_saoluu_path_is_executable($candidate)) $binary = $candidate;
    }
    return $binary;
}

function mg_saoluu_pack_build_zip($sql_file, $manifest, $zip_file, $prefer_store = false) {
    if (!class_exists('ZipArchive')) return new WP_Error('zip_missing', 'Máy chủ chưa bật ZipArchive.');
    $zip = new ZipArchive();
    if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return new WP_Error('pack_zip_create', 'Không tạo được container Pack.');
    $manifest_json = wp_json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $ok = is_string($manifest_json)
        && $zip->addFile($sql_file, 'pack.sql')
        && $zip->addFromString('pack-manifest.json', $manifest_json);
    if ($ok && $prefer_store && method_exists($zip, 'setCompressionName')) {
        $zip->setCompressionName('pack.sql', ZipArchive::CM_STORE);
        $zip->setCompressionName('pack-manifest.json', ZipArchive::CM_STORE);
    }
    $closed = $zip->close();
    if (!$ok || !$closed || !is_file($zip_file) || filesize($zip_file) < 1) {
        @unlink($zip_file);
        return new WP_Error('pack_zip_failed', 'Không hoàn tất container Pack.');
    }
    @chmod($zip_file, 0600);
    return true;
}

function mg_saoluu_pack_compress_zip($zip_file, $payload_file) {
    $zstd = mg_saoluu_pack_zstd_binary();
    if ($zstd !== '') {
        $command = escapeshellarg($zstd) . ' -q -1 -T1 -f ' . escapeshellarg($zip_file) . ' -o ' . escapeshellarg($payload_file) . ' 2>&1';
        $out = []; $status = 1; @set_time_limit(0); exec($command, $out, $status);
        if ($status !== 0 || !is_file($payload_file) || filesize($payload_file) < 1) {
            @unlink($payload_file);
            return new WP_Error('pack_zstd_failed', 'Zstd có sẵn nhưng nén Pack thất bại.');
        }
        @chmod($payload_file, 0600);
        return 'zstd-1';
    }
    if (!@copy($zip_file, $payload_file)) return new WP_Error('pack_payload_copy', 'Không tạo được payload Pack.');
    @chmod($payload_file, 0600);
    return 'zip-deflate';
}

function mg_saoluu_pack_decompress_payload($payload_file, $zip_file, $compression) {
    if ($compression === 'zstd-1') {
        $zstd = mg_saoluu_pack_zstd_binary();
        if ($zstd === '') return new WP_Error('pack_zstd_missing', 'Pack này cần zstd để giải nén.');
        $command = escapeshellarg($zstd) . ' -q -d -f ' . escapeshellarg($payload_file) . ' -o ' . escapeshellarg($zip_file) . ' 2>&1';
        $out = []; $status = 1; @set_time_limit(0); exec($command, $out, $status);
        if ($status !== 0 || !is_file($zip_file) || filesize($zip_file) < 1) {
            @unlink($zip_file);
            return new WP_Error('pack_zstd_decompress', 'Không giải nén được payload Zstd.');
        }
        @chmod($zip_file, 0600);
        return true;
    }
    if ($compression !== 'zip-deflate') return new WP_Error('pack_compression', 'Thuật toán nén Pack không được hỗ trợ.');
    if (!@copy($payload_file, $zip_file)) return new WP_Error('pack_payload_copy', 'Không tạo được ZIP tạm.');
    @chmod($zip_file, 0600);
    return true;
}

function mg_saoluu_pack_manifest_from_zip($zip_file) {
    $zip = new ZipArchive();
    if ($zip->open($zip_file) !== true) return new WP_Error('pack_zip_open', 'Không mở được container Pack.');
    if ((int) $zip->numFiles !== 2
        || $zip->locateName('pack.sql', ZipArchive::FL_NOCASE) === false
        || $zip->locateName('pack-manifest.json', ZipArchive::FL_NOCASE) === false) {
        $zip->close();
        return new WP_Error('pack_zip_shape', 'Container Pack phải chỉ chứa pack.sql và pack-manifest.json.');
    }
    for ($i=0; $i<$zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = (string) ($stat['name'] ?? '');
        if (!mg_saoluu_is_safe_zip_entry($name) || mg_saoluu_zip_entry_is_symlink($zip, $i)) {
            $zip->close();
            return new WP_Error('pack_zip_unsafe', 'Container Pack có entry không an toàn.');
        }
    }
    $raw = $zip->getFromName('pack-manifest.json');
    $zip->close();
    $manifest = json_decode((string) $raw, true);
    if (!is_array($manifest)
        || ($manifest['format'] ?? '') !== 'mg-saoluu-data-pack'
        || !in_array((int) ($manifest['version'] ?? 0), [1, 2], true)
        || !mg_saoluu_pack_definition($manifest['pack'] ?? '')) {
        return new WP_Error('pack_manifest', 'Manifest Pack không hợp lệ.');
    }
    return $manifest;
}

function mg_saoluu_pack_upload_limit() {
    $php_limit = (int) wp_max_upload_size();
    $hard_limit = 2 * 1024 * 1024 * 1024;
    return $php_limit > 0 ? min($php_limit, $hard_limit) : 0;
}

function mg_saoluu_stage_pack_upload($file) {
    if (!is_array($file)) return new WP_Error('pack_upload_missing', 'Chưa chọn file Pack.');
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'File vượt upload_max_filesize của hosting.',
            UPLOAD_ERR_FORM_SIZE => 'File vượt giới hạn của biểu mẫu.',
            UPLOAD_ERR_PARTIAL => 'File chỉ được tải lên một phần.',
            UPLOAD_ERR_NO_FILE => 'Chưa chọn file Pack.',
            UPLOAD_ERR_NO_TMP_DIR => 'Hosting thiếu thư mục upload tạm.',
            UPLOAD_ERR_CANT_WRITE => 'Hosting không ghi được file upload tạm.',
            UPLOAD_ERR_EXTENSION => 'PHP extension đã chặn file upload.',
        ];
        return new WP_Error('pack_upload_error', $messages[$error] ?? 'Upload Pack thất bại.');
    }

    $limit = mg_saoluu_pack_upload_limit();
    $reported_size = (int) ($file['size'] ?? 0);
    if ($limit < 1) return new WP_Error('pack_upload_disabled', 'Hosting hiện không cho phép upload file.');
    if ($reported_size < 1 || $reported_size > $limit) {
        return new WP_Error('pack_upload_size', 'Dung lượng Pack vượt giới hạn upload hiện tại.');
    }

    $name = sanitize_file_name((string) ($file['name'] ?? ''));
    if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'mgbackup') {
        return new WP_Error('pack_upload_type', 'Restore Pack trực tiếp chỉ nhận file .mgbackup.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return new WP_Error('pack_upload_source', 'Nguồn upload không hợp lệ.');
    }

    $private = mg_saoluu_private_temp_dir();
    if (is_wp_error($private)) return $private;
    try { $token = bin2hex(random_bytes(10)); }
    catch (Throwable $e) { return new WP_Error('pack_upload_temp', 'Không tạo được tên file upload private.'); }

    $target = trailingslashit($private) . 'mg-pack-upload-' . $token . '.mgbackup';
    if (!move_uploaded_file($tmp, $target)) {
        return new WP_Error('pack_upload_move', 'Không chuyển được Pack vào vùng private.');
    }
    @chmod($target, 0600);
    clearstatcache(true, $target);
    $actual_size = is_file($target) ? (int) @filesize($target) : 0;
    if ($actual_size < 1 || $actual_size > $limit) {
        @unlink($target);
        return new WP_Error('pack_upload_size', 'Dung lượng Pack sau upload không hợp lệ.');
    }
    if (!mg_saoluu_is_pack_backup($target)) {
        @unlink($target);
        return new WP_Error('pack_upload_format', 'File không có định dạng Pack v2 hợp lệ.');
    }
    return $target;
}

function mg_saoluu_pack_prepare_zip($backup_file, $password) {
    if (!is_readable($backup_file) || !is_file($backup_file)) return new WP_Error('pack_missing', 'Không đọc được Pack backup.');
    $password = (string) $password;
    if ($password === '' && mg_saoluu_encryption_constant_available()) $password = (string) MG_SAOLUU_ENCRYPTION_KEY;
    if ($password === '') $password = mg_saoluu_managed_encryption_key(false);
    if ($password === '') return new WP_Error('recovery_key_missing', 'Không có Recovery Key để mở Pack v2.');
    $private = mg_saoluu_private_temp_dir();
    if (is_wp_error($private)) return $private;
    $payload = $private . 'mg-pack-payload-' . wp_generate_password(16, false, false) . '.bin';
    $zip_file = $private . 'mg-pack-zip-' . wp_generate_password(16, false, false) . '.zip';
    $header = mg_saoluu_decrypt_pack_file($backup_file, $payload, $password);
    if (is_wp_error($header)) return $header;
    $compression = sanitize_key((string) ($header['meta']['compression'] ?? ''));
    $decompressed = mg_saoluu_pack_decompress_payload($payload, $zip_file, $compression);
    @unlink($payload);
    if (is_wp_error($decompressed)) return $decompressed;
    $manifest = mg_saoluu_pack_manifest_from_zip($zip_file);
    if (is_wp_error($manifest)) { @unlink($zip_file); return $manifest; }
    return ['zip' => $zip_file, 'manifest' => $manifest, 'header' => $header];
}

function mg_saoluu_read_pack_manifest($backup_file, $password) {
    $prepared = mg_saoluu_pack_prepare_zip($backup_file, $password);
    if (is_wp_error($prepared)) return $prepared;
    @unlink($prepared['zip']);
    return $prepared['manifest'];
}

function mg_saoluu_verify_pack_backup($backup_file, $password = '') {
    $prepared = mg_saoluu_pack_prepare_zip($backup_file, $password);
    if (is_wp_error($prepared)) return $prepared;
    $sql = mg_saoluu_pack_extract_sql($prepared['zip'], $prepared['manifest']);
    @unlink($prepared['zip']);
    if (is_wp_error($sql)) return $sql;
    @unlink($sql);
    return $prepared['manifest'];
}

function mg_saoluu_create_pack_backup($pack, $note = '', $password = '', $apply_retention = true, $prefix = 'backup_pack') {
    $pack = sanitize_key((string) $pack);
    $definition = mg_saoluu_pack_definition($pack);
    if (!$definition) return new WP_Error('pack_invalid', 'Pack backup không hợp lệ.');
    if (!mg_saoluu_pack_crypto_available()) return new WP_Error('pack_crypto_missing', 'Pack v2 cần PHP Sodium.');
    $safe = mg_saoluu_storage_preflight();
    if (is_wp_error($safe)) return $safe;
    if (!mg_saoluu_find_binary('mysqldump')) {
        return new WP_Error('pack_cli_missing', 'Pack Backup cần mysqldump/mariadb-dump native.');
    }
    $password = mg_saoluu_encryption_password($password);
    if ($password === '') return new WP_Error('pack_key_missing', 'Không có Recovery Key để mã hóa Pack.');

    $tables = mg_saoluu_pack_tables($pack);
    if (is_wp_error($tables)) return $tables;
    $schema = $pack === 'full_db' ? '' : mg_saoluu_pack_schema_fingerprint($tables);
    if (is_wp_error($schema)) return $schema;

    $private = mg_saoluu_private_temp_dir();
    if (is_wp_error($private)) return $private;
    $token = wp_generate_password(16, false, false);
    $sql_file = $private . 'mg-pack-' . $token . '.sql';
    $zip_file = $private . 'mg-pack-' . $token . '.zip';
    $payload_file = $private . 'mg-pack-' . $token . '.payload';

    $dump = mg_saoluu_pack_dump_sql($pack, $sql_file);
    if (is_wp_error($dump)) return $dump;
    $state_hash = (string) ($dump['state_hash'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $state_hash)) { @unlink($sql_file); return new WP_Error('pack_state_hash', 'Pack thiếu state hash hậu kiểm.'); }
    $sql_sha = @hash_file('sha256', $sql_file);
    if (!$sql_sha) { @unlink($sql_file); return new WP_Error('pack_sql_hash', 'Không fingerprint được SQL Pack.'); }

    $manifest = [
        'format' => 'mg-saoluu-data-pack',
        'version' => 2,
        'plugin_version' => MG_SAOLUU_VERSION,
        'pack' => $pack,
        'pack_label' => $definition['label'],
        'source_host' => mg_saoluu_host(),
        'source_url' => home_url('/'),
        'source_environment' => mg_saoluu_environment(),
        'db_table_prefix' => $GLOBALS['wpdb']->prefix,
        'tables' => array_values((array) $tables),
        'schema_fingerprint' => $schema,
        'settings_scope' => $pack === 'settings' ? mg_saoluu_pack_settings_scope() : '',
        'sql_sha256' => $sql_sha,
        'sql_size' => (int) filesize($sql_file),
        'state_hash' => $state_hash,
        'state_hash_algorithm' => $pack === 'settings'
            ? mg_saoluu_pack_settings_hash_algorithm()
            : 'sha256-mysqldump-order-by-primary-v1',
        'note' => sanitize_text_field($note),
        'created_at' => gmdate('c'),
    ];
    $zstd = mg_saoluu_pack_zstd_binary();
    $built = mg_saoluu_pack_build_zip($sql_file, $manifest, $zip_file, $zstd !== '');
    @unlink($sql_file);
    if (is_wp_error($built)) return $built;

    $compression = mg_saoluu_pack_compress_zip($zip_file, $payload_file);
    @unlink($zip_file);
    if (is_wp_error($compression)) return $compression;

    $dir = mg_saoluu_get_backup_dir();
    $date = current_time('Ymd_His');
    $rand = strtolower(wp_generate_password(6, false, false));
    $name = sanitize_file_name($prefix . '_' . $pack . '_' . $date . '_' . $rand . '.mgbackup');
    $target = $dir . $name;
    $encrypted = mg_saoluu_encrypt_pack_file($payload_file, $target, $password, [
        'pack' => $pack,
        'compression' => $compression,
        'source_host' => mg_saoluu_host(),
        'db_table_prefix' => $GLOBALS['wpdb']->prefix,
    ]);
    @unlink($payload_file);
    if (is_wp_error($encrypted)) return $encrypted;

    $verified = mg_saoluu_verify_pack_backup($target, $password);
    if (is_wp_error($verified)
        || ($verified['sql_sha256'] ?? '') !== $sql_sha
        || ($verified['state_hash'] ?? '') !== $state_hash
        || ($verified['pack'] ?? '') !== $pack) {
        @unlink($target);
        return new WP_Error('pack_verify_failed', 'Pack cuối không qua decrypt/decompress/manifest verification.');
    }
    $sha = @hash_file('sha256', $target);
    if (!$sha) { @unlink($target); return new WP_Error('pack_hash_failed', 'Không fingerprint được Pack cuối.'); }

    $metadata_file = $dir . 'metadata.json';
    $metadata = mg_saoluu_read_metadata($metadata_file);
    $metadata[$name] = [
        'note' => $note ?: $definition['label'],
        'time' => current_time('mysql'),
        'type' => 'pack',
        'pack' => $pack,
        'source_environment' => mg_saoluu_environment(),
        'source_url' => home_url('/'),
        'size' => (int) filesize($target),
        'encrypted' => 1,
        'purpose' => strpos($prefix, 'backup_pre_') === 0 ? 'rollback' : 'normal',
        'compression' => $compression,
        'integrity_status' => 'verified',
        'integrity_verified_at' => current_time('mysql'),
        'integrity_size' => (int) filesize($target),
        'integrity_sha256' => $sha,
        'restore_tested' => false,
        'drive_status' => 'not_uploaded',
    ];
    if (!mg_saoluu_save_metadata($metadata_file, $metadata)) {
        return new WP_Error('pack_catalog_write', 'Pack đã tạo nhưng catalog không lưu được.');
    }
    if ($apply_retention) mg_saoluu_apply_retention();
    return $name;
}

function mg_saoluu_pack_extract_sql($zip_file, $manifest) {
    $private = mg_saoluu_private_temp_dir();
    if (is_wp_error($private)) return $private;
    $sql_file = $private . 'mg-pack-restore-' . wp_generate_password(16, false, false) . '.sql';
    $zip = new ZipArchive();
    if ($zip->open($zip_file) !== true) return new WP_Error('pack_zip_open', 'Không mở được Pack container.');
    $in = $zip->getStream('pack.sql');
    $out = @fopen($sql_file, 'xb');
    if (!$in || !$out) {
        if ($in) fclose($in); if ($out) fclose($out); $zip->close();
        return new WP_Error('pack_sql_extract', 'Không đọc được SQL trong Pack.');
    }
    @chmod($sql_file, 0600);
    $copied = stream_copy_to_stream($in, $out);
    fclose($in); fclose($out); $zip->close();
    if ($copied === false || !is_file($sql_file) || filesize($sql_file) < 1) {
        @unlink($sql_file);
        return new WP_Error('pack_sql_extract', 'SQL Pack bị thiếu.');
    }
    $sha = @hash_file('sha256', $sql_file);
    if (!$sha || !hash_equals((string) ($manifest['sql_sha256'] ?? ''), $sha)) {
        @unlink($sql_file);
        return new WP_Error('pack_sql_hash', 'Checksum SQL Pack không khớp.');
    }
    return $sql_file;
}

function mg_saoluu_pack_php_cli() {
    $candidates = [
        mg_saoluu_command_path('php'),
        defined('PHP_BINDIR') ? PHP_BINDIR . '/php' : '',
        '/usr/bin/php',
        '/usr/local/bin/php',
        '/opt/homebrew/bin/php',
    ];
    foreach (array_values(array_unique(array_filter($candidates))) as $candidate) {
        if (!mg_saoluu_path_is_executable($candidate)) continue;
        $out = []; $status = 1;
        @exec(escapeshellarg($candidate) . ' -r ' . escapeshellarg('echo PHP_SAPI;'), $out, $status);
        if ($status === 0 && trim(implode('', $out)) === 'cli') return $candidate;
    }
    return '';
}

function mg_saoluu_pack_jobs_dir() {
    $dir = trailingslashit(mg_saoluu_get_backup_dir()) . 'jobs/';
    if (!file_exists($dir) && !wp_mkdir_p($dir)) return new WP_Error('pack_job_dir', 'Không tạo được thư mục job private.');
    if (!is_dir($dir) || is_link($dir) || !mg_saoluu_storage_path_safe($dir)) return new WP_Error('pack_job_dir', 'Thư mục job không an toàn.');
    @chmod($dir, 0700);
    return trailingslashit($dir);
}

function mg_saoluu_pack_write_private_json($path, $data) {
    if (!is_array($data)) return new WP_Error('pack_job_json', 'Job phải là dữ liệu mảng.');
    $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) return new WP_Error('pack_job_json', 'Không mã hóa được job.');
    try { $suffix = bin2hex(random_bytes(8)); }
    catch (Throwable $e) { return new WP_Error('pack_job_temp', 'Không tạo được tên file job tạm.'); }
    $tmp = dirname($path) . '/.mg-job-' . $suffix . '.tmp';
    $h = @fopen($tmp, 'xb');
    if (!$h) return new WP_Error('pack_job_write', 'Không tạo được job private.');
    @chmod($tmp, 0600);
    $ok = mg_saoluu_write_all($h, $json) && @fflush($h);
    fclose($h);
    if (!$ok || !@rename($tmp, $path)) {
        @unlink($tmp);
        return new WP_Error('pack_job_write', 'Không ghi được job private.');
    }
    @chmod($path, 0600);
    return true;
}

function mg_saoluu_pack_latest_job_status() {
    $dir = mg_saoluu_pack_jobs_dir();
    if (is_wp_error($dir)) return [];
    $files = glob($dir . '*/journal.json') ?: [];
    if (!$files) return [];
    usort($files, static function($a, $b){ return @filemtime($b) <=> @filemtime($a); });
    $data = mg_saoluu_read_metadata($files[0]);
    if (!is_array($data)) return [];
    if (($data['status'] ?? '') === 'queued') {
        $updated = isset($data['updated_at']) ? strtotime((string) $data['updated_at']) : false;
        if ($updated && time() - $updated > 300) {
            $data['status'] = 'worker_launch_failed_stale';
            $data['error_code'] = 'worker_launch_failed_stale';
        }
    }
    return $data;
}

function mg_saoluu_pack_cleanup_failed_launch($job_file) {
    $job = mg_saoluu_read_metadata($job_file);
    if (is_array($job)) {
        foreach (['mysql_config', 'target_sql'] as $key) {
            $path = (string) ($job[$key] ?? '');
            if ($path !== '' && is_file($path) && !is_link($path)) @unlink($path);
        }
    }
    if (is_file($job_file) && !is_link($job_file)) @unlink($job_file);
}

function mg_saoluu_pack_schedule_worker_launch($php, $worker, $job_file, $journal) {
    if (!mg_saoluu_exec_available()) return new WP_Error('pack_worker_exec', 'Hosting đã tắt exec nên không thể chạy worker Restore an toàn.');
    if (!mg_saoluu_path_is_executable($php) || !is_file($worker) || !is_readable($worker)) return new WP_Error('pack_worker_missing', 'Không tìm thấy PHP CLI worker.');

    add_action('shutdown', static function() use ($php, $worker, $job_file, $journal) {
        // Register the actual CLI invocation from inside WordPress shutdown so it
        // is appended to PHP's shutdown queue after callbacks already registered.
        register_shutdown_function(static function() use ($php, $worker, $job_file, $journal) {
            $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($job_file);
            $out = []; $status = 1;
            @exec($cmd, $out, $status);
            if ($status !== 0) {
                $state = mg_saoluu_read_metadata($journal);
                $terminal = is_array($state) ? (string) ($state['status'] ?? '') : '';
                if ($terminal === '' || $terminal === 'queued') {
                    mg_saoluu_pack_write_private_json($journal, [
                        'status' => 'worker_launch_failed',
                        'error_code' => 'worker_launch_failed',
                        'updated_at' => gmdate('c'),
                    ]);
                    mg_saoluu_pack_cleanup_failed_launch($job_file);
                }
            }
        });

        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    }, PHP_INT_MAX);

    return true;
}

function mg_saoluu_restore_pack($backup_file, $password, $operation_lock_token = '') {
    if (!mg_saoluu_find_binary('mysql') || !mg_saoluu_find_binary('mysqldump')) {
        return new WP_Error('pack_cli_missing', 'Pack Restore cần mysql và mysqldump/mariadb-dump CLI.');
    }
    if (mg_saoluu_environment() === 'production') {
        return new WP_Error('production_pack_restore_disabled', 'Pack Restore bị khóa trên Production. Chỉ Full Site Restore cùng-host đã được phê duyệt mới được phép.');
    }

    $prepared = mg_saoluu_pack_prepare_zip($backup_file, $password);
    if (is_wp_error($prepared)) return $prepared;
    $manifest = $prepared['manifest'];
    $pack = sanitize_key((string) ($manifest['pack'] ?? ''));
    if (!mg_saoluu_pack_definition($pack)) { @unlink($prepared['zip']); return new WP_Error('pack_invalid', 'Pack không được hỗ trợ.'); }
    if ((int) ($manifest['version'] ?? 0) < 2 || !preg_match('/^[a-f0-9]{64}$/', (string) ($manifest['state_hash'] ?? ''))) {
        @unlink($prepared['zip']);
        return new WP_Error('pack_post_verify_missing', 'Pack cũ không có state hash hậu kiểm; bản RC chỉ Verify, không Restore file này.');
    }
    if (strtolower((string) ($manifest['source_host'] ?? '')) !== strtolower(mg_saoluu_host())) {
        @unlink($prepared['zip']);
        return new WP_Error('pack_host_mismatch', 'Pack Restore chỉ dùng cùng host. Migration/domain rewrite là chức năng riêng.');
    }

    global $wpdb;
    if ((string) ($manifest['db_table_prefix'] ?? '') !== (string) $wpdb->prefix) {
        @unlink($prepared['zip']);
        return new WP_Error('pack_prefix_mismatch', 'DB prefix của Pack không khớp site hiện tại.');
    }
    if ($pack === 'settings') {
        $settings_scope_ok = (string) ($manifest['settings_scope'] ?? '') === mg_saoluu_pack_settings_scope();
        $settings_hash_ok = (string) ($manifest['state_hash_algorithm'] ?? '') === mg_saoluu_pack_settings_hash_algorithm();
        if (!$settings_scope_ok || !$settings_hash_ok) {
            @unlink($prepared['zip']);
            return new WP_Error(
                'pack_settings_scope_mismatch',
                'Pack Settings dùng fingerprint cũ. Hãy tạo Pack Settings mới bằng RC12 trước khi Restore.'
            );
        }
    }

    if ($pack !== 'full_db') {
        $tables = mg_saoluu_pack_tables($pack);
        $schema = mg_saoluu_pack_schema_fingerprint($tables);
        if (defined('MG_SAOLUU_QA_FORCE_SCHEMA_MISMATCH') && MG_SAOLUU_QA_FORCE_SCHEMA_MISMATCH === true && mg_saoluu_environment() !== 'production') {
            $schema = 'qa-forced-mismatch';
        }
        if (is_wp_error($schema) || !hash_equals((string) ($manifest['schema_fingerprint'] ?? ''), (string) $schema)) {
            @unlink($prepared['zip']);
            return new WP_Error('pack_schema_mismatch', 'Schema hiện tại khác Pack; đã dừng trước khi ghi DB.');
        }
    } else {
        $tables = [];
    }

    $sql_file = mg_saoluu_pack_extract_sql($prepared['zip'], $manifest);
    @unlink($prepared['zip']);
    if (is_wp_error($sql_file)) return $sql_file;

    $jobs = mg_saoluu_pack_jobs_dir();
    if (is_wp_error($jobs)) { @unlink($sql_file); return $jobs; }
    try { $job_id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6)); }
    catch (Throwable $e) { @unlink($sql_file); return new WP_Error('pack_job_id', 'Không tạo được mã job.'); }
    $work = $jobs . $job_id;
    if (!@mkdir($work, 0700, false) || is_link($work)) { @unlink($sql_file); return new WP_Error('pack_job_dir', 'Không tạo được thư mục job.'); }
    @chmod($work, 0700);

    $target_sql = $work . '/target.sql';
    if (!@rename($sql_file, $target_sql)) {
        if (!@copy($sql_file, $target_sql)) { @unlink($sql_file); return new WP_Error('pack_job_sql', 'Không stage được SQL cho worker.'); }
        @unlink($sql_file);
    }
    @chmod($target_sql, 0600);

    $config = mg_saoluu_create_mysql_config();
    if (is_wp_error($config)) { @unlink($target_sql); return $config; }
    $job_config = $work . '/mysql.cnf';
    if (!@rename($config, $job_config)) {
        if (!@copy($config, $job_config)) { @unlink($config); @unlink($target_sql); return new WP_Error('pack_job_config', 'Không stage được MySQL config private.'); }
        @unlink($config);
    }
    @chmod($job_config, 0600);

    $mysql = mg_saoluu_find_binary('mysql');
    $mysqldump = mg_saoluu_find_binary('mysqldump');
    $php = mg_saoluu_pack_php_cli();
    if ($php === '') { @unlink($job_config); @unlink($target_sql); return new WP_Error('pack_php_cli_missing', 'Restore hậu kiểm cần PHP CLI tối giản.'); }

    $cleanup = $pack === 'full_db' ? '' : mg_saoluu_pack_cleanup_sql($pack, $tables);
    if (is_wp_error($cleanup)) { @unlink($job_config); @unlink($target_sql); return $cleanup; }
    $journal = $work . '/journal.json';
    $job_file = $work . '/job.json';

    $job = [
        'version' => 1,
        'job_id' => $job_id,
        'journal' => $journal,
        'work_dir' => $work,
        'pack' => $pack,
        'database' => DB_NAME,
        'mysql_binary' => $mysql,
        'mysqldump_binary' => $mysqldump,
        'mysql_config' => $job_config,
        'target_sql' => $target_sql,
        'expected_state_hash' => strtolower((string) $manifest['state_hash']),
        'dump_options' => mg_saoluu_pack_dump_options($pack, $mysqldump),
        'tables' => array_values((array) $tables),
        'cleanup_sql' => (string) $cleanup,
        'qa_force_post_verify_mismatch' => defined('MG_SAOLUU_QA_FORCE_POST_VERIFY_MISMATCH')
            && MG_SAOLUU_QA_FORCE_POST_VERIFY_MISMATCH === true
            && mg_saoluu_environment() !== 'production',
    ];
    $saved = mg_saoluu_pack_write_private_json($job_file, $job);
    if (is_wp_error($saved)) { @unlink($job_config); @unlink($target_sql); return $saved; }
    $queued = mg_saoluu_pack_write_private_json($journal, [
        'job_id' => $job_id,
        'status' => 'queued',
        'pack' => $pack,
        'expected_state_hash' => $job['expected_state_hash'],
        'updated_at' => gmdate('c'),
    ]);
    if (is_wp_error($queued)) { @unlink($job_file); @unlink($job_config); @unlink($target_sql); return $queued; }

    if (!mg_saoluu_defer_lock_release($operation_lock_token)) {
        @unlink($job_file); @unlink($job_config); @unlink($target_sql);
        return new WP_Error('pack_lock_handoff', 'Không thể giữ khóa Backup/Restore tới khi worker bắt đầu.');
    }
    $scheduled = mg_saoluu_pack_schedule_worker_launch($php, MG_SAOLUU_DIR . 'bin/pack-restore-worker.php', $job_file, $journal);
    if (is_wp_error($scheduled)) {
        mg_saoluu_cancel_deferred_lock($operation_lock_token);
        @unlink($job_file); @unlink($job_config); @unlink($target_sql);
        return $scheduled;
    }

    return [
        'operation' => 'queued',
        'pack' => $pack,
        'job_id' => $job_id,
        'journal' => $journal,
    ];
}

