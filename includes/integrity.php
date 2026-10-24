<?php
if (!defined('ABSPATH')) { exit; }

function mg_saoluu_integrity_file() { return MG_SAOLUU_DIR . 'integrity.json'; }

function mg_saoluu_self_integrity($force = false) {
    $cache_key = 'mg_saoluu_self_integrity_' . str_replace('.', '_', MG_SAOLUU_VERSION);
    if (!$force) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;
    }
    $required = [
        'mg-saoluu.php', 'admin/actions.php', 'admin/render.php',
        'includes/lifecycle.php', 'includes/environment.php', 'includes/core.php', 'includes/crypto.php', 'includes/security.php',
        'includes/google-drive.php', 'includes/backup.php', 'includes/restore.php', 'includes/catalog.php', 'includes/integrity.php',
        'tools/recovery-verify.php', 'RECOVERY_RUNBOOK.md', 'assets/css/admin.css', 'assets/js/admin.js'
    ];
    $result = ['ok' => true, 'missing' => [], 'mismatch' => [], 'checked' => 0, 'manifest' => false, 'assets' => []];
    $manifest_path = mg_saoluu_integrity_file();
    $manifest = [];
    if (is_readable($manifest_path)) {
        $decoded = json_decode((string) file_get_contents($manifest_path), true);
        if (is_array($decoded) && isset($decoded['files']) && is_array($decoded['files'])) { $manifest = $decoded['files']; $result['manifest'] = true; }
    }
    if (!$result['manifest']) $result['ok'] = false;
    // Audit every packaged file, not merely a short allowlist; a missing
    // integrity.json cannot silently count as a complete plugin install.
    $to_check = array_values(array_unique(array_merge($required, array_keys($manifest))));
    foreach ($to_check as $relative) {
        if (!is_string($relative) || $relative === '' || $relative[0] === '/' ||
            strpos($relative, "\0") !== false || in_array('..', explode('/', str_replace('\\', '/', $relative)), true)) {
            $result['ok'] = false; $result['mismatch'][] = 'invalid manifest entry'; continue;
        }
        $path = MG_SAOLUU_DIR . $relative;
        if (!is_file($path) || !is_readable($path) || is_link($path)) {
            $result['ok'] = false; $result['missing'][] = $relative; continue;
        }
        $result['checked']++;
        if (!isset($manifest[$relative]) || !is_string($manifest[$relative]) ||
            !preg_match('/^[a-f0-9]{64}$/i', $manifest[$relative]) ||
            !hash_equals(strtolower($manifest[$relative]), (string) hash_file('sha256', $path))) {
            $result['ok'] = false; $result['mismatch'][] = $relative;
        }
    }
    foreach (['assets/css/admin.css','assets/js/admin.js'] as $relative) {
        $path = MG_SAOLUU_DIR . $relative;
        $result['assets'][$relative] = ['exists' => is_file($path), 'readable' => is_readable($path), 'size' => is_file($path) ? (int) filesize($path) : 0];
    }
    set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
    return $result;
}

function mg_saoluu_self_integrity_label($result = null) {
    if ($result === null) $result = mg_saoluu_self_integrity();
    if (!is_array($result) || empty($result['ok'])) {
        $parts = [];
        if (!empty($result['missing'])) $parts[] = 'thiếu ' . count($result['missing']) . ' file';
        if (!empty($result['mismatch'])) $parts[] = count($result['mismatch']) . ' file sai phiên bản';
        return $parts ? implode(' · ', $parts) : 'Cần kiểm tra';
    }
    return 'Đầy đủ · v' . MG_SAOLUU_VERSION;
}
