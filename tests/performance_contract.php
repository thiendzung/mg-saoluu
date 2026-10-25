<?php
/** Static performance contracts; no WordPress instance, DB, filesystem scan or network. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = rtrim((string) (getenv('MGSL_TEST_PLUGIN_DIR') ?: dirname(__DIR__)), '/');
$count = 0;
function perf_check($name, $ok) {
    global $count; $count++;
    if (!$ok) { fwrite(STDERR, "FAIL $name\n"); exit(1); }
    echo "PASS $name\n";
}
$render = file_get_contents($root . '/admin/render.php');
$migration = file_get_contents($root . '/includes/migration.php');
$lifecycle = file_get_contents($root . '/includes/lifecycle.php');

perf_check('Backup tab is excluded from full backup-directory listing',
    str_contains($render, "in_array(\$active, ['overview', 'restore', 'backups'], true)")
    && !str_contains($render, "['overview', 'restore', 'backups', 'backup']"));

perf_check('Local migration picker uses bounded metadata candidates',
    str_contains($render, 'mg_saoluu_migration_local_candidates(20)'));

$start = strpos($migration, 'function mg_saoluu_migration_local_candidates');
$end = $start === false ? false : strpos($migration, '/** Produce a 10-minute', $start);
$body = ($start !== false && $end !== false) ? substr($migration, $start, $end - $start) : '';
perf_check('migration candidate lookup avoids archive scan/decrypt/hash',
    $body !== ''
    && str_contains($body, "mg_saoluu_read_metadata")
    && !str_contains($body, 'mg_saoluu_list_backup_files')
    && !str_contains($body, 'mg_saoluu_read_backup_manifest')
    && !str_contains($body, 'hash_file('));

perf_check('system-check cache is version scoped and DB-free',
    str_contains($lifecycle, 'mg_saoluu_system_checks_cache_key')
    && str_contains($lifecycle, "wp_cache_set(\$cache_key")
    && !str_contains($lifecycle, "set_transient(\$cache_key"));

perf_check('backup-list cache uses object cache instead of wp_options transient',
    str_contains(file_get_contents($root . '/includes/core.php'), "wp_cache_set(\$cache_key")
    && !str_contains(file_get_contents($root . '/includes/core.php'), "set_transient(\$cache_key"));

echo "PERFORMANCE TESTS $count/$count PASS\n";
