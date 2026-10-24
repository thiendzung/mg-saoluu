<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Verify a stored backup without applying SQL or overwriting WordPress files.
 *
 * - ZIP hash verification is ALWAYS performed (including decrypted .mgbackup).
 * - A valid manifest HMAC proves the current site's signing secret recognizes it.
 * - Without that HMAC, only content-hash consistency can be established; never
 *   claim that an imported/cross-site archive has authenticated provenance.
 * - This is NOT a recovery drill. Restore-tested requires external evidence.
 */
function mg_saoluu_catalog_verify($filename, $password = '') {
    $safe = mg_saoluu_storage_preflight();
    if (is_wp_error($safe)) return $safe;
    $filename = (string) $filename;
    if (basename($filename) !== $filename || !mg_saoluu_is_backup_filename($filename)) {
        return new WP_Error('invalid_backup_name', 'Tên bản sao không hợp lệ.');
    }
    $dir = mg_saoluu_get_backup_dir();
    $path = $dir . $filename;
    if (is_link($path) || !is_file($path) || !is_readable($path)) {
        return new WP_Error('missing_backup', 'Bản sao không tồn tại hoặc không thể đọc an toàn.');
    }
    $metadata_file = $dir . 'metadata.json';
    $metadata = mg_saoluu_read_metadata($metadata_file);
    $size = (int) filesize($path);
    $digest = @hash_file('sha256', $path);
    if (!$digest) return new WP_Error('hash_failed', 'Không đọc được SHA-256 của bản sao.');
    if (!empty($metadata[$filename]['integrity_sha256']) &&
        !hash_equals((string) $metadata[$filename]['integrity_sha256'], $digest)) {
        return new WP_Error('stored_hash_mismatch', 'Bản sao đã khác với fingerprint trước đó; đã dừng xác minh.');
    }
    $manifest = mg_saoluu_read_backup_manifest($path, $password);
    if (is_wp_error($manifest)) return $manifest;

    $authenticated = mg_saoluu_verify_manifest_signature($manifest);
    $state = $authenticated ? 'verified' : 'content_hash_only';
    if (!isset($metadata[$filename]) || !is_array($metadata[$filename])) $metadata[$filename] = [];
    $metadata[$filename]['integrity_status'] = $state;
    $metadata[$filename]['integrity_verified_at'] = current_time('mysql');
    $metadata[$filename]['integrity_sha256'] = $digest;
    $metadata[$filename]['integrity_size'] = $size;
    if (!isset($metadata[$filename]['restore_tested'])) $metadata[$filename]['restore_tested'] = false;
    if (!mg_saoluu_save_metadata($metadata_file, $metadata)) {
        return new WP_Error('catalog_save_failed', 'Bản sao đọc được nhưng không lưu được trạng thái catalog.');
    }
    return [
        'state' => $state,
        'signed_by_current_site' => $authenticated,
        'sha256' => $digest,
        'size' => $size,
        'manifest' => $manifest,
    ];
}

/** False unless a complete recovery set has a verified archive and a separate restore drill. */
function mg_saoluu_catalog_has_recovery_proof($meta) {
    return is_array($meta) && ($meta['type'] ?? '') === 'safe_full'
        && ($meta['integrity_status'] ?? '') === 'verified'
        && !empty($meta['restore_tested']) && !empty($meta['restore_evidence_id']);
}
