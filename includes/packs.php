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

function mg_saoluu_pack_settings_predicate() {
    return "LEFT(option_name,11) <> '_transient_' AND LEFT(option_name,16) <> '_site_transient_'";
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
    if ($pack === 'settings') $options[] = '--where=' . mg_saoluu_pack_settings_predicate();
    return $options;
}

function mg_saoluu_pack_state_hash_file($path) {
    $h = @fopen($path, 'rb');
    if (!$h) return '';
    $ctx = hash_init('sha256');
    while (($line = fgets($h)) !== false) {
        $line = preg_replace('/\\sAUTO_INCREMENT=\\d+\\b/i', '', $line);
        hash_update($ctx, $line);
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
    $state_hash = mg_saoluu_pack_state_hash_file($output);
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
        'settings_scope' => $pack === 'settings' ? 'exclude_transients' : '',
        'sql_sha256' => $sql_sha,
        'sql_size' => (int) filesize($sql_file),
        'state_hash' => $state_hash,
        'state_hash_algorithm' => 'sha256-mysqldump-order-by-primary-v1',
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

function mg_saoluu_restore_pack_internal($backup_file, $password, $with_checkpoint = true) {
    if (!mg_saoluu_find_binary('mysql')) return new WP_Error('mysql_required', 'Pack Restore cần mysql/mariadb CLI native.');
    if (mg_saoluu_environment() === 'production' && strpos(MG_SAOLUU_VERSION, '-rc') !== false) {
        return new WP_Error('rc_production_restore_disabled', 'RC7 chỉ test Pack Restore trên clone/staging.');
    }
    $prepared = mg_saoluu_pack_prepare_zip($backup_file, $password);
    if (is_wp_error($prepared)) return $prepared;
    $manifest = $prepared['manifest'];
    $pack = sanitize_key((string) ($manifest['pack'] ?? ''));
    if (!mg_saoluu_pack_definition($pack)) { @unlink($prepared['zip']); return new WP_Error('pack_invalid', 'Pack không được hỗ trợ.'); }
    if (strtolower((string) ($manifest['source_host'] ?? '')) !== strtolower(mg_saoluu_host())) {
        @unlink($prepared['zip']);
        return new WP_Error('pack_host_mismatch', 'Pack Restore chỉ dùng cùng host. Migration/domain rewrite là chức năng riêng.');
    }
    global $wpdb;
    if ((string) ($manifest['db_table_prefix'] ?? '') !== (string) $wpdb->prefix) {
        @unlink($prepared['zip']);
        return new WP_Error('pack_prefix_mismatch', 'DB prefix của Pack không khớp site hiện tại.');
    }
    if ($pack !== 'full_db') {
        $tables = mg_saoluu_pack_tables($pack);
        $schema = mg_saoluu_pack_schema_fingerprint($tables);
        if (is_wp_error($schema) || !hash_equals((string) ($manifest['schema_fingerprint'] ?? ''), (string) $schema)) {
            @unlink($prepared['zip']);
            return new WP_Error('pack_schema_mismatch', 'Schema hiện tại khác Pack; đã dừng trước khi ghi DB.');
        }
    }

    $checkpoint = '';
    if ($with_checkpoint) {
        $checkpoint = mg_saoluu_create_pack_backup($pack, 'Checkpoint trước Pack Restore', $password, false, 'backup_pre_pack');
        if (is_wp_error($checkpoint)) { @unlink($prepared['zip']); return $checkpoint; }
    }
    $sql_file = mg_saoluu_pack_extract_sql($prepared['zip'], $manifest);
    @unlink($prepared['zip']);
    if (is_wp_error($sql_file)) return $sql_file;

    $import = mg_saoluu_run_mysql_import($sql_file);
    @unlink($sql_file);
    if (is_wp_error($import)) {
        if ($with_checkpoint && $checkpoint !== '') {
            $rollback_path = mg_saoluu_get_backup_dir() . $checkpoint;
            $rolled = mg_saoluu_restore_pack_internal($rollback_path, $password, false);
            if (is_wp_error($rolled)) {
                return new WP_Error('pack_rollback_failed', 'Pack Restore lỗi và checkpoint cùng Pack cũng không khôi phục được.');
            }
            return new WP_Error('pack_restore_reverted', 'Pack Restore lỗi; checkpoint cùng Pack đã được áp dụng lại.');
        }
        return $import;
    }
    wp_cache_flush();
    return [
        'operation' => $with_checkpoint ? 'restore' : 'rollback',
        'pack' => $pack,
        'checkpoint' => $checkpoint,
    ];
}

function mg_saoluu_restore_pack($backup_file, $password) {
    return mg_saoluu_restore_pack_internal($backup_file, $password, true);
}
