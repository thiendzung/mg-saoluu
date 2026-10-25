<?php
/**
 * MG Sao Luu Pack restore worker.
 * CLI-only by design: never bootstrap WordPress, themes, plugins or mu-plugins.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

function mgw_fail($message, $code = 1) {
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}
function mgw_read_json($path) {
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}
function mgw_write_json($path, $data) {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) return false;
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $h = @fopen($tmp, 'xb');
    if (!$h) return false;
    @chmod($tmp, 0600);
    $ok = fwrite($h, $json) === strlen($json) && fflush($h);
    fclose($h);
    if (!$ok || !@rename($tmp, $path)) { @unlink($tmp); return false; }
    @chmod($path, 0600);
    return true;
}
function mgw_journal($job, $status, $extra = []) {
    $base = [
        'job_id' => (string)($job['job_id'] ?? ''),
        'status' => $status,
        'pack' => (string)($job['pack'] ?? ''),
        'updated_at' => gmdate('c'),
    ];
    mgw_write_json((string)$job['journal'], array_merge($base, $extra));
}
function mgw_safe_file($path, $must_exist = true) {
    if (!is_string($path) || $path === '' || strpos($path, "\0") !== false || is_link($path)) return false;
    if ($must_exist && (!is_file($path) || !is_readable($path))) return false;
    return true;
}
function mgw_run_dump($job, $output) {
    $binary = (string)$job['mysqldump_binary'];
    $config = (string)$job['mysql_config'];
    if (!mgw_safe_file($binary) || !is_executable($binary) || !mgw_safe_file($config)) return false;
    $parts = [
        escapeshellarg($binary),
        '--defaults-extra-file=' . escapeshellarg($config),
    ];
    foreach ((array)$job['dump_options'] as $option) {
        if (!is_string($option) || $option === '' || strpos($option, "\0") !== false) return false;
        $parts[] = escapeshellarg($option);
    }
    $parts[] = escapeshellarg((string)$job['database']);
    foreach ((array)$job['tables'] as $table) {
        if (!preg_match('/^[A-Za-z0-9_$]+$/', (string)$table)) return false;
        $parts[] = escapeshellarg((string)$table);
    }
    $err = $output . '.err';
    $cmd = implode(' ', $parts) . ' > ' . escapeshellarg($output) . ' 2> ' . escapeshellarg($err);
    $out = []; $status = 1;
    exec($cmd, $out, $status);
    @chmod($output, 0600);
    @unlink($err);
    return $status === 0 && is_file($output) && filesize($output) > 0;
}
function mgw_state_hash($path) {
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
function mgw_write_all($h, $data) {
    $len = strlen($data); $off = 0;
    while ($off < $len) {
        $n = fwrite($h, substr($data, $off));
        if (!is_int($n) || $n <= 0) return false;
        $off += $n;
    }
    return true;
}
function mgw_build_checkpoint($job, $path) {
    $pack = (string)$job['pack'];
    if ($pack === 'full_db') {
        if (!mgw_run_dump($job, $path)) return '';
        return mgw_state_hash($path);
    }
    $data = $path . '.data';
    if (!mgw_run_dump($job, $data)) { @unlink($data); return ''; }
    $state_hash = mgw_state_hash($data);
    $out = @fopen($path, 'xb');
    $in = @fopen($data, 'rb');
    if (!$out || !$in) {
        if ($out) fclose($out); if ($in) fclose($in);
        @unlink($data); @unlink($path); return '';
    }
    @chmod($path, 0600);
    $head = "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n"
        . (string)$job['cleanup_sql'] . "\n";
    $ok = mgw_write_all($out, $head) && stream_copy_to_stream($in, $out) !== false
        && mgw_write_all($out, "\nCOMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n")
        && fflush($out);
    fclose($in); fclose($out); @unlink($data);
    if (!$ok) { @unlink($path); return ''; }
    return $state_hash;
}
function mgw_import($job, $sql) {
    $binary = (string)$job['mysql_binary'];
    $config = (string)$job['mysql_config'];
    if (!mgw_safe_file($binary) || !is_executable($binary) || !mgw_safe_file($config) || !mgw_safe_file($sql)) return false;
    $err = $sql . '.import.err';
    $cmd = escapeshellarg($binary)
        . ' --defaults-extra-file=' . escapeshellarg($config)
        . ' ' . escapeshellarg((string)$job['database'])
        . ' < ' . escapeshellarg($sql)
        . ' 2> ' . escapeshellarg($err);
    $out = []; $status = 1;
    exec($cmd, $out, $status);
    @unlink($err);
    return $status === 0;
}
function mgw_current_hash($job, $work) {
    $verify = $work . '/verify-' . bin2hex(random_bytes(5)) . '.sql';
    if (!mgw_run_dump($job, $verify)) { @unlink($verify); return ''; }
    $hash = mgw_state_hash($verify);
    @unlink($verify);
    return $hash;
}
function mgw_cleanup($paths) {
    foreach ($paths as $path) {
        if (is_string($path) && $path !== '' && is_file($path) && !is_link($path)) @unlink($path);
    }
}

if ($argc !== 2) mgw_fail('Usage: php pack-restore-worker.php /private/job.json', 2);
$job_file = $argv[1];
if (!mgw_safe_file($job_file)) mgw_fail('Unsafe job file.', 3);
$job = mgw_read_json($job_file);
if (!$job || (int)($job['version'] ?? 0) !== 1) mgw_fail('Invalid job.', 4);

$required = ['job_id','journal','work_dir','global_lock','pack','database','mysql_binary','mysqldump_binary','mysql_config','target_sql','expected_state_hash','dump_options','tables','cleanup_sql'];
foreach ($required as $key) if (!array_key_exists($key, $job)) mgw_fail('Missing job field.', 5);

$work = realpath((string)$job['work_dir']);
if (!$work || !is_dir($work) || is_link($work)) mgw_fail('Unsafe work dir.', 6);
$journal = (string)$job['journal'];
if (dirname($journal) !== $work || dirname((string)$job['target_sql']) !== $work || dirname((string)$job['mysql_config']) !== $work) mgw_fail('Job paths escape work dir.', 7);

$lock_path = (string)$job['global_lock'];
if (is_link($lock_path) || dirname($lock_path) === $work) mgw_fail('Unsafe global lock.', 8);
$lock = @fopen($lock_path, 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) mgw_fail('Another Backup/Restore operation is running.', 8);
@chmod($lock_path, 0600);

$started = microtime(true);
$checkpoint = $work . '/checkpoint.sql';
$target = (string)$job['target_sql'];
$config = (string)$job['mysql_config'];
mgw_journal($job, 'running', ['started_at' => gmdate('c')]);

$checkpoint_hash = mgw_build_checkpoint($job, $checkpoint);
if ($checkpoint_hash === '') {
    mgw_journal($job, 'checkpoint_failed', ['error_code' => 'checkpoint_dump_failed']);
    mgw_cleanup([$target, $config, $checkpoint, $job_file]);
    flock($lock, LOCK_UN); fclose($lock); exit(20);
}

$import_ok = mgw_import($job, $target);
if (!$import_ok) {
    $rollback_ok = mgw_import($job, $checkpoint);
    $rollback_hash = $rollback_ok ? mgw_current_hash($job, $work) : '';
    $reverted = $rollback_ok && hash_equals($checkpoint_hash, $rollback_hash);
    mgw_journal($job, $reverted ? 'restore_import_failed_reverted' : 'restore_import_failed_rollback_failed', [
        'error_code' => 'mysql_import_failed',
        'checkpoint_hash' => $checkpoint_hash,
        'rollback_hash' => $rollback_hash,
        'rollback_verified' => $reverted,
        'duration_ms' => (int)round((microtime(true) - $started) * 1000),
    ]);
    mgw_cleanup([$target, $config, $checkpoint, $job_file]);
    flock($lock, LOCK_UN); fclose($lock); exit($reverted ? 21 : 22);
}

$actual_hash = mgw_current_hash($job, $work);
$expected_hash = strtolower((string)$job['expected_state_hash']);
if (!empty($job['qa_force_post_verify_mismatch'])) $actual_hash = str_repeat('0', 64);

if ($actual_hash === '' || !hash_equals($expected_hash, strtolower($actual_hash))) {
    $rollback_ok = mgw_import($job, $checkpoint);
    $rollback_hash = $rollback_ok ? mgw_current_hash($job, $work) : '';
    $reverted = $rollback_ok && hash_equals($checkpoint_hash, $rollback_hash);
    mgw_journal($job, $reverted ? 'restore_post_verify_failed_reverted' : 'restore_post_verify_failed_rollback_failed', [
        'error_code' => 'restore_post_verify_failed',
        'expected_state_hash' => $expected_hash,
        'actual_state_hash' => $actual_hash,
        'checkpoint_hash' => $checkpoint_hash,
        'rollback_hash' => $rollback_hash,
        'rollback_verified' => $reverted,
        'duration_ms' => (int)round((microtime(true) - $started) * 1000),
    ]);
    mgw_cleanup([$target, $config, $checkpoint, $job_file]);
    flock($lock, LOCK_UN); fclose($lock); exit($reverted ? 23 : 24);
}

mgw_journal($job, 'completed', [
    'expected_state_hash' => $expected_hash,
    'actual_state_hash' => $actual_hash,
    'checkpoint_hash' => $checkpoint_hash,
    'post_verify' => true,
    'duration_ms' => (int)round((microtime(true) - $started) * 1000),
]);
mgw_cleanup([$target, $config, $checkpoint, $job_file]);
flock($lock, LOCK_UN);
fclose($lock);
exit(0);
