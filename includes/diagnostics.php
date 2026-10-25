<?php
if (!defined('ABSPATH')) { exit; }

/** Structured, bounded, opt-in diagnostics. Never store passwords, tokens or paths. */
function mg_saoluu_debug_enabled() {
    return defined('MG_SAOLUU_DEBUG') && MG_SAOLUU_DEBUG === true;
}

function mg_saoluu_debug_context($context) {
    if (!is_array($context)) return [];
    $allowed = ['type', 'phase', 'result', 'code', 'mode', 'drive_status',
        'restore_files', 'file_count', 'bytes', 'url_rows', 'url_tables'];
    $numbers = ['restore_files', 'file_count', 'bytes', 'url_rows', 'url_tables'];
    $safe = [];
    foreach ($context as $key => $value) {
        if (!in_array($key, $allowed, true) || !is_scalar($value)) continue;
        if (in_array($key, $numbers, true)) {
            if (is_bool($value) || is_numeric($value)) $safe[$key] = max(0, (int) $value);
            continue;
        }
        $text = (string) $value;
        if (preg_match('/^[a-z0-9_-]{1,56}$/iD', $text)) $safe[$key] = $text;
    }
    return $safe;
}

function mg_saoluu_debug_file() {
    $safe = mg_saoluu_storage_preflight();
    if (is_wp_error($safe)) return '';
    $file = mg_saoluu_get_backup_dir() . 'mg-saoluu-debug.jsonl';
    if (is_link($file) || !mg_saoluu_storage_path_safe($file)) return '';
    return $file;
}

function mg_saoluu_debug_log($event, $context = []) {
    if (!mg_saoluu_debug_enabled() || !is_string($event) ||
        !preg_match('/^[a-z0-9_-]{1,56}$/iD', $event)) return false;
    $file = mg_saoluu_debug_file();
    if ($file === '') return false;
    static $request_id = null;
    if ($request_id === null) {
        try { $request_id = bin2hex(random_bytes(6)); }
        catch (Throwable $e) { return false; }
    }
    $row = [
        'ts' => gmdate('c'), 'request_id' => $request_id, 'version' => MG_SAOLUU_VERSION,
        'event' => strtolower($event),
        'details' => mg_saoluu_debug_context($context),
    ];
    $line = wp_json_encode($row, JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) return false;
    $line .= "\n";
    $lock_file = $file . '.lock';
    if (is_link($lock_file)) return false;
    $lock = @fopen($lock_file, 'c+b');
    if (!$lock) return false;
    @chmod($lock_file, 0600);
    if (!@flock($lock, LOCK_EX | LOCK_NB)) { @fclose($lock); return false; }
    try {
        clearstatcache(true, $file);
        if (is_file($file) && filesize($file) >= 2 * 1024 * 1024) {
            @unlink($file . '.1');
            @rename($file, $file . '.1');
        }
        if (is_link($file)) return false;
        $handle = @fopen($file, 'ab');
        if (!$handle) return false;
        @chmod($file, 0600);
        $written = @fwrite($handle, $line);
        $flushed = @fflush($handle);
        @fclose($handle);
        return $written === strlen($line) && $flushed;
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function mg_saoluu_debug_tail($limit = 50) {
    $file = mg_saoluu_debug_file();
    if (!$file || !is_file($file) || !is_readable($file)) return [];
    $data = @file_get_contents($file);
    if (!is_string($data)) return [];
    $lines = array_filter(explode("\n", trim($data)), 'strlen');
    return array_slice(array_values($lines), -max(1, min(100, (int) $limit)));
}

add_action('admin_init', 'mg_saoluu_debug_download');
function mg_saoluu_debug_download() {
    if (($_GET['page'] ?? '') !== 'mg-saoluu-tools' ||
        ($_GET['mg_action'] ?? '') !== 'download_debug') return;
    if (!current_user_can('manage_options')) wp_die('Bạn không có quyền tải nhật ký.');
    check_admin_referer('mg_saoluu_debug_download');
    $file = mg_saoluu_debug_file();
    if (!$file || !is_file($file) || !is_readable($file) || is_link($file)) wp_die('Chưa có nhật ký debug an toàn.');
    while (ob_get_level()) ob_end_clean();
    nocache_headers();
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Content-Disposition: attachment; filename="mg-saoluu-debug.jsonl"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}
