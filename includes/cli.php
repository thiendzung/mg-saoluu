<?php
/** P2A: pre-deploy SQL checkpoint commands. No HTTP surface. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) { return; }

function mg_saoluu_cli_json($data) {
    $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        WP_CLI::line('{"ok":false,"error_code":"json_encode_failed"}');
        WP_CLI::halt(1);
    }
    WP_CLI::line($json);
}

function mg_saoluu_cli_fail($code) {
    $safe = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $code));
    mg_saoluu_cli_json(['ok' => false, 'error_code' => $safe ?: 'checkpoint_failed']);
    fwrite(STDERR, 'MG Sao Luu checkpoint failed: ' . ($safe ?: 'checkpoint_failed') . "\n");
    WP_CLI::halt(1);
}

function mg_saoluu_cli_options($args, $assoc, $required) {
    if ($args) return new WP_Error('unexpected_positionals', 'No positional arguments allowed.');
    if (!is_array($assoc) || array_diff(array_keys($assoc), $required)) {
        return new WP_Error('unexpected_options', 'Unsupported CLI options.');
    }
    foreach ($required as $name) {
        if (!array_key_exists($name, $assoc) || !is_string($assoc[$name]) || $assoc[$name] === '') {
            return new WP_Error('missing_option', 'Missing a required CLI option.');
        }
    }
    if ($assoc['format'] !== 'json') return new WP_Error('unsupported_format', 'Only JSON output is supported.');
    return true;
}

function mg_saoluu_cli_backup_id($filename) {
    return 'c' . substr(hash('sha256', $filename), 0, 32);
}

/** Snapshot all base tables in the connected database, not just the WP prefix. */
function mg_saoluu_cli_engine_inventory() {
    global $wpdb;
    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return new WP_Error('db_unavailable', 'Database is not available.');
    }
    $rows = $wpdb->get_results("SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", ARRAY_A);
    if (!is_array($rows) || !empty($wpdb->last_error) || !$rows) {
        return new WP_Error('inventory_unavailable', 'Unable to inventory the complete database.');
    }
    $names = []; $engines = [];
    foreach ($rows as $row) {
        $name = (string) ($row['table_name'] ?? '');
        $engine = strtoupper((string) ($row['engine'] ?? ''));
        if ($name === '' || $engine === '') return new WP_Error('inventory_invalid', 'Invalid database table inventory.');
        if ($engine !== 'INNODB') return new WP_Error('unsafe_storage_engine', 'Non-transactional table cannot be checkpointed consistently.');
        $names[] = $name;
        $engines[$engine] = ($engines[$engine] ?? 0) + 1;
    }
    return [
        'table_count' => count($names),
        'table_names_sha256' => hash('sha256', implode("\n", $names)),
        'engines' => $engines,
    ];
}

function mg_saoluu_cli_checkpoint_transaction($git_ref) {
    $before = mg_saoluu_cli_engine_inventory();
    if (is_wp_error($before)) return $before;
    $options = [
        'encrypt' => false, 'upload_drive' => false,
        'purpose' => 'pre-deploy', 'git_ref' => $git_ref,
        'table_inventory' => $before,
    ];
    $name = mg_saoluu_create_backup('sql', 'Pre-deploy DB checkpoint', 'backup_predeploy', false, $options);
    if (is_wp_error($name)) return $name;
    // The backup engine validated inventory before the archive and before catalog commit.
    $dir = mg_saoluu_get_backup_dir();
    $meta = mg_saoluu_read_metadata($dir . 'metadata.json');
    $record = $meta[$name] ?? null;
    if (!is_array($record) || empty($record['protected']) || ($record['purpose'] ?? '') !== 'pre-deploy') {
        return new WP_Error('checkpoint_catalog_invalid', 'Checkpoint metadata is incomplete.');
    }
    $path = $dir . $name;
    if (is_link($path) || !is_file($path)) return new WP_Error('checkpoint_missing', 'Checkpoint artifact is missing.');
    $manifest = mg_saoluu_read_zip_manifest($path);
    if (is_wp_error($manifest) || !mg_saoluu_verify_manifest_signature($manifest)) {
        return new WP_Error('checkpoint_verify_failed', 'Checkpoint archive verification failed.');
    }
    $sha = hash_file('sha256', $path);
    if (!is_string($sha) || !hash_equals((string) ($record['integrity_sha256'] ?? ''), $sha)
        || ($manifest['git_ref'] ?? '') !== $git_ref || ($manifest['table_inventory'] ?? null) !== $before) {
        return new WP_Error('checkpoint_identity_mismatch', 'Checkpoint digest or provenance mismatch.');
    }
    return [
        'ok' => true, 'backup_id' => mg_saoluu_cli_backup_id($name),
        'scope' => 'database', 'git_ref' => $git_ref, 'archive_sha256' => $sha,
        'sql_sha256' => (string) ($manifest['hashes']['database_backup.sql'] ?? ''),
        'table_count' => $before['table_count'], 'engine_inventory' => $before['engines'],
        'created_at' => (string) ($manifest['checkpoint_created_at_utc'] ?? ''),
        'archive_verified' => true, 'restore_drill_pass' => false,
        'protected' => true, 'error_code' => null,
    ];
}

/** CLI: wp mg-saoluu backup --components=database --reason=pre-deploy --ref=<FULL_SHA> --format=json */
function mg_saoluu_cli_backup($args, $assoc) {
    $valid = mg_saoluu_cli_options($args, $assoc, ['components', 'reason', 'ref', 'format']);
    if (is_wp_error($valid)) mg_saoluu_cli_fail($valid->get_error_code());
    if ($assoc['components'] !== 'database' || $assoc['reason'] !== 'pre-deploy') {
        mg_saoluu_cli_fail('invalid_checkpoint_scope');
    }
    if (!preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/iD', $assoc['ref'])) {
        mg_saoluu_cli_fail('invalid_git_ref');
    }
    $token = mg_saoluu_acquire_lock();
    if (is_wp_error($token)) mg_saoluu_cli_fail($token->get_error_code());
    try {
        $result = mg_saoluu_cli_checkpoint_transaction(strtolower($assoc['ref']));
    } finally {
        mg_saoluu_release_lock($token);
    }
    if (is_wp_error($result)) mg_saoluu_cli_fail($result->get_error_code());
    mg_saoluu_cli_json($result);
}

/** CLI: wp mg-saoluu list --format=json */
function mg_saoluu_cli_list($args, $assoc) {
    $valid = mg_saoluu_cli_options($args, $assoc, ['format']);
    if (is_wp_error($valid)) mg_saoluu_cli_fail($valid->get_error_code());
    $safe = mg_saoluu_storage_preflight();
    if (is_wp_error($safe)) mg_saoluu_cli_fail($safe->get_error_code());
    $dir = mg_saoluu_get_backup_dir();
    $metadata = mg_saoluu_read_metadata($dir . 'metadata.json');
    $items = [];
    foreach (mg_saoluu_list_backup_files(true) as $path) {
        if (is_link($path) || !is_file($path)) continue;
        $name = basename($path);
        if (!mg_saoluu_is_backup_filename($name)) continue;
        $meta = $metadata[$name] ?? [];
        $items[] = [
            'backup_id' => mg_saoluu_cli_backup_id($name), 'type' => (string) ($meta['type'] ?? 'unknown'),
            'purpose' => (string) ($meta['purpose'] ?? 'unknown'),
            'created_at' => (string) ($meta['checkpoint_created_at_utc'] ?? $meta['time'] ?? ''),
            'git_ref' => $meta['git_ref'] ?? null,
            'stored_integrity_status' => (string) ($meta['integrity_status'] ?? 'unknown'),
            'archive_verified_now' => false, 'protected' => !empty($meta['protected']),
            'restore_drill_pass' => !empty($meta['restore_tested']) && !empty($meta['restore_evidence_id']),
        ];
    }
    mg_saoluu_cli_json(['ok' => true, 'backups' => $items, 'count' => count($items), 'error_code' => null]);
}

/** CLI: wp mg-saoluu verify --id=<BACKUP_ID> --format=json */
function mg_saoluu_cli_verify($args, $assoc) {
    $valid = mg_saoluu_cli_options($args, $assoc, ['id', 'format']);
    if (is_wp_error($valid)) mg_saoluu_cli_fail($valid->get_error_code());
    if (!preg_match('/\Ac[0-9a-f]{32}\z/D', $assoc['id'])) mg_saoluu_cli_fail('invalid_backup_id');
    $safe = mg_saoluu_storage_preflight();
    if (is_wp_error($safe)) mg_saoluu_cli_fail($safe->get_error_code());
    $dir = mg_saoluu_get_backup_dir();
    $metadata = mg_saoluu_read_metadata($dir . 'metadata.json');
    $target = null;
    foreach (mg_saoluu_list_backup_files(true) as $path) {
        if (is_link($path) || !is_file($path)) continue;
        $name = basename($path);
        if (mg_saoluu_is_backup_filename($name) && mg_saoluu_cli_backup_id($name) === $assoc['id']) {
            $target = $path;
            break;
        }
    }
    if ($target === null) mg_saoluu_cli_fail('backup_not_found');
    $name = basename($target);
    $meta = $metadata[$name] ?? [];
    if (($meta['type'] ?? '') !== 'sql' || ($meta['purpose'] ?? '') !== 'pre-deploy'
        || empty($meta['protected']) || substr($name, -4) !== '.zip') {
        mg_saoluu_cli_fail('checkpoint_not_allowed');
    }
    $digest = hash_file('sha256', $target);
    if (!is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/D', (string) ($meta['integrity_sha256'] ?? ''))
        || !hash_equals((string) $meta['integrity_sha256'], $digest)) {
        mg_saoluu_cli_fail('archive_hash_mismatch');
    }
    $manifest = mg_saoluu_read_zip_manifest($target);
    if (is_wp_error($manifest)) mg_saoluu_cli_fail($manifest->get_error_code());
    if (!mg_saoluu_verify_manifest_signature($manifest)) mg_saoluu_cli_fail('manifest_signature_mismatch');
    if (($manifest['backup_type'] ?? '') !== 'sql'
        || ($manifest['purpose'] ?? '') !== 'pre-deploy'
        || ($manifest['git_ref'] ?? '') !== ($meta['git_ref'] ?? '')
        || ($manifest['db_table_prefix'] ?? '') === ''
        || !isset($manifest['hashes']['database_backup.sql'])
        || count($manifest['hashes']) !== 1) {
        mg_saoluu_cli_fail('checkpoint_manifest_invalid');
    }
    mg_saoluu_cli_json([
        'ok' => true, 'backup_id' => $assoc['id'], 'scope' => 'database',
        'git_ref' => $manifest['git_ref'], 'archive_sha256' => $digest,
        'sql_sha256' => $manifest['hashes']['database_backup.sql'],
        'created_at' => (string) ($manifest['checkpoint_created_at_utc'] ?? ''),
        'archive_verified' => true,
        'restore_drill_pass' => !empty($meta['restore_tested']) && !empty($meta['restore_evidence_id']),
        'error_code' => null,
    ]);
}

WP_CLI::add_command('mg-saoluu backup', 'mg_saoluu_cli_backup');
WP_CLI::add_command('mg-saoluu list', 'mg_saoluu_cli_list');
WP_CLI::add_command('mg-saoluu verify', 'mg_saoluu_cli_verify');
