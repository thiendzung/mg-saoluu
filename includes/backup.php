<?php
if (!defined('ABSPATH')) { exit; }

function mg_saoluu_zip_fast_compress_index($zip, $index) {
    if (!is_object($zip) || !method_exists($zip, 'setCompressionIndex') || !defined('ZipArchive::CM_DEFLATE')) return;
    // Level 1 favors backup speed while retaining broad ZIP compatibility.
    @$zip->setCompressionIndex((int) $index, ZipArchive::CM_DEFLATE, 1);
}

function mg_saoluu_collect_backup_files($type) {
    $items = [];
    $total = 0;
    if ($type === 'sql') return ['files' => [], 'size' => 0];
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ABSPATH, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $backup_dir = wp_normalize_path(mg_saoluu_get_backup_dir());
        $root = trailingslashit(wp_normalize_path(ABSPATH));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            $real = $file->getRealPath();
            if (!$real) continue;
            $path = wp_normalize_path($real);
            if (strpos($path, $backup_dir) === 0) continue;
            $relative = ltrim(substr($path, strlen($root)), '/');
            if ($relative === '' || mg_saoluu_is_excluded_path($relative, $type)) continue;
            if (!is_readable($path)) return new WP_Error('source_unreadable', 'Không thể đọc file quan trọng: ' . $relative);
            $items[] = ['path' => $path, 'relative' => $relative, 'size' => (int) $file->getSize()];
            $total += (int) $file->getSize();
        }
    } catch (Throwable $e) {
        return new WP_Error('scan_failed', 'Không thể đọc hết file website: ' . $e->getMessage());
    }
    return ['files' => $items, 'size' => $total];
}

function mg_saoluu_backup_preflight($type, $source_size) {
    $safe = mg_saoluu_storage_preflight();
    if (is_wp_error($safe)) return $safe;
    $dir = mg_saoluu_get_backup_dir();
    if (!is_dir($dir) || !is_writable($dir)) return new WP_Error('backup_dir_unwritable', 'Thư mục sao lưu không thể ghi.');
    $temp = mg_saoluu_private_temp_dir();
    if (is_wp_error($temp)) return $temp;
    $free = @disk_free_space($dir);
    if ($free !== false) {
        $minimum = $type === 'sql' ? 250 * 1024 * 1024 : max(500 * 1024 * 1024, (int) ($source_size * 1.15));
        $minimum = (int) apply_filters('mg_saoluu_required_backup_space', $minimum, $type, $source_size);
        if ($free < $minimum) return new WP_Error('low_disk', 'Dung lượng trống chưa đủ an toàn. Cần khoảng ' . size_format($minimum) . ', hiện còn ' . size_format($free) . '.');
    }
    return true;
}

function mg_saoluu_create_backup($type, $note = '', $prefix = 'backup', $apply_retention = true, $options = []) {
    mg_saoluu_debug_log('backup_start', ['type'=>$type]);
    $safe = mg_saoluu_storage_preflight();
    if (is_wp_error($safe)) return $safe;
    if (!in_array($type, ['sql', 'safe_full', 'local_copy'], true)) return new WP_Error('type_invalid', 'Kiểu sao lưu không hợp lệ.');
    if (!class_exists('ZipArchive')) return new WP_Error('zip_missing', 'Máy chủ chưa bật ZipArchive.');
    $options = wp_parse_args(is_array($options) ? $options : [], [
        'encrypt' => false,
        'password' => '',
        'upload_drive' => null,
        'purpose' => 'normal',
        'use_default_encryption' => false,
    ]);

    // Resolve encryption/offsite policy BEFORE creating any SQL/ZIP file.
    $upload_requested = $options['upload_drive'] === null
        ? (get_option('mg_saoluu_drive_auto_upload', '0') === '1')
        : (bool) $options['upload_drive'];
    $encrypt = (bool) $options['encrypt'];
    if (!$encrypt && !empty($options['use_default_encryption']) && get_option('mg_saoluu_encrypt_default', '0') === '1') {
        $encrypt = true;
    }
    if ($upload_requested && !$encrypt) {
        return new WP_Error('offsite_requires_encryption', 'Chỉ gửi backup đã mã hóa lên Google Drive; hãy bật mã hóa và lưu Recovery Key trước.');
    }

    if ($type === 'local_copy' && !$encrypt) return new WP_Error('local_copy_requires_encryption', 'Gói Host → Local bắt buộc mã hóa.');
    $collection = mg_saoluu_collect_backup_files($type);
    if (is_wp_error($collection)) return $collection;
    $preflight = mg_saoluu_backup_preflight($type, (int) $collection['size']);
    if (is_wp_error($preflight)) return $preflight;

    $dir = mg_saoluu_get_backup_dir();
    $date = current_time('Ymd_His');
    $rand = strtolower(wp_generate_password(6, false, false));
    $base = sanitize_file_name($prefix . '_' . $type . '_' . $date . '_' . $rand);
    $zip_name = $base . '.zip';
    $zip_file = $dir . $zip_name;
    $private_temp = mg_saoluu_private_temp_dir();
    if (is_wp_error($private_temp)) return $private_temp;
    $sql_file = $private_temp . 'mg-db-' . wp_generate_password(16, false, false) . '.sql';

    // This file is private before mysql CLI redirects output to it.
    $sql_handle = @fopen($sql_file, 'xb');
    if (!$sql_handle) return new WP_Error('sql_temp_failed', 'Không tạo được SQL tạm.');
    @chmod($sql_file, 0600);
    fclose($sql_handle);
    $dump = mg_saoluu_run_mysqldump($sql_file);
    if (is_wp_error($dump)) { @unlink($sql_file); return $dump; }
    $zip = new ZipArchive();
    if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($sql_file);
        return new WP_Error('zip_create_failed', 'Không thể tạo file ZIP.');
    }

    $hashes = [];
    $added = 0;
    @set_time_limit(0);
    foreach ($collection['files'] as $item) {
        $hash = @hash_file('sha256', $item['path']);
        if (!$hash || !$zip->addFile($item['path'], $item['relative'])) {
            $zip->close(); @unlink($sql_file); @unlink($zip_file);
            return new WP_Error('file_add_failed', 'Đã dừng vì không thể đưa file vào bản sao: ' . $item['relative']);
        }
        mg_saoluu_zip_fast_compress_index($zip, $zip->numFiles - 1);
        $hashes[$item['relative']] = $hash;
        $added++;
    }

    $sql_hash = @hash_file('sha256', $sql_file);
    if (!$sql_hash || !$zip->addFile($sql_file, 'database_backup.sql')) {
        $zip->close(); @unlink($sql_file); @unlink($zip_file);
        return new WP_Error('sql_add_failed', 'Không thể đưa database vào file sao lưu.');
    }
    mg_saoluu_zip_fast_compress_index($zip, $zip->numFiles - 1);
    $hashes['database_backup.sql'] = $sql_hash;
    $manifest = mg_saoluu_manifest($type);
    $manifest['file_count'] = $added;
    $manifest['source_size'] = (int) $collection['size'];
    $manifest['hashes'] = $hashes;
    $manifest['note'] = sanitize_text_field($note);
    $manifest['purpose'] = sanitize_key($options['purpose']);
    $manifest['signature'] = mg_saoluu_manifest_signature($manifest);
    if (!$zip->addFromString('mg-backup-manifest.json', wp_json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
        $zip->close(); @unlink($sql_file); @unlink($zip_file);
        return new WP_Error('manifest_add_failed', 'Không thể ghi thông tin kiểm tra vào bản sao.');
    }
    mg_saoluu_zip_fast_compress_index($zip, $zip->numFiles - 1);
    $zip->close();
    @unlink($sql_file);
    mg_saoluu_debug_log('backup_archive_ready',['type'=>$type,'file_count'=>$added]);

    $check = mg_saoluu_read_zip_manifest($zip_file);
    if (is_wp_error($check)) { @unlink($zip_file); return $check; }

    $final_name = $zip_name;
    $final_path = $zip_file;
    if ($encrypt) {
        $password = mg_saoluu_encryption_password($options['password']);
        if ($password === '') { @unlink($zip_file); return new WP_Error('encrypt_key_missing', 'Đã chọn mã hóa nhưng chưa tạo được Recovery Key hoặc chưa nhập mật khẩu.'); }
        $encrypted_name = $base . '.mgbackup';
        $encrypted_path = $dir . $encrypted_name;
        $encrypted = mg_saoluu_encrypt_backup_file($zip_file, $encrypted_path, $password);
        if (is_wp_error($encrypted)) { @unlink($zip_file); return $encrypted; }
        @unlink($zip_file);
        $final_name = $encrypted_name;
        $final_path = $encrypted_path;
    }

    // Verify the exact finished artifact, including decrypt-on-read if encrypted,
    // not just the intermediate unencrypted ZIP. A file is not "verified" solely
    // because ZipArchive::close() or a remote upload succeeded.
    $checked = mg_saoluu_read_backup_manifest($final_path, $encrypt ? $password : '');
    if (is_wp_error($checked) || !mg_saoluu_verify_manifest_signature(is_array($checked) ? $checked : [])) {
        @unlink($final_path);
        return new WP_Error('backup_verify_failed', 'Không xác minh được backup cuối cùng: ' . (is_wp_error($checked) ? $checked->get_error_message() : 'chữ ký nguồn không khớp'));
    }
    $final_sha = @hash_file('sha256', $final_path);
    if (!$final_sha) { @unlink($final_path); return new WP_Error('backup_hash_failed', 'Không tính được fingerprint của backup.'); }

    $metadata_file = $dir . 'metadata.json';
    $metadata = mg_saoluu_read_metadata($metadata_file);
    $metadata[$final_name] = [
        'note' => $note ?: 'Không có ghi chú',
        'time' => current_time('mysql'),
        'type' => $type,
        'source_environment' => mg_saoluu_environment(),
        'source_url' => home_url('/'),
        'size' => filesize($final_path),
        'encrypted' => $encrypt ? 1 : 0,
        'purpose' => sanitize_key($options['purpose']),
        'drive_status' => 'not_uploaded',
        'integrity_status' => 'verified',
        'integrity_verified_at' => current_time('mysql'),
        'integrity_size' => (int) filesize($final_path),
        'integrity_sha256' => $final_sha,
        'restore_tested' => false,
    ];
    if (!mg_saoluu_save_metadata($metadata_file, $metadata)) {
        return new WP_Error('catalog_write_failed', 'Backup đã được tạo nhưng catalog không lưu được; hãy kiểm tra trước khi coi là an toàn: ' . $final_name);
    }

    if ($upload_requested) {
        $upload = mg_saoluu_drive_upload_file($final_path, $final_name);
        $metadata = mg_saoluu_read_metadata($metadata_file);
        if (is_wp_error($upload)) {
            $metadata[$final_name]['drive_status'] = 'failed';
            $metadata[$final_name]['drive_error'] = sanitize_text_field($upload->get_error_message());
            mg_saoluu_log_event('drive_upload_failed', $upload->get_error_message(), ['file' => $final_name]);
        } else {
            $metadata[$final_name]['drive_status'] = 'uploaded_unverified';
            $metadata[$final_name]['drive_id'] = sanitize_text_field($upload['id']);
            $metadata[$final_name]['drive_link'] = esc_url_raw($upload['webViewLink'] ?? '');
            $metadata[$final_name]['drive_uploaded_at'] = current_time('mysql');
            mg_saoluu_log_event('drive_upload_success', 'Upload Drive hoàn tất; chưa kiểm chứng tải về hoặc restore', ['file' => $final_name]);
        }
        mg_saoluu_save_metadata($metadata_file, $metadata);
    }

    mg_saoluu_debug_log('backup_verified',['type'=>$type,'bytes'=>(int)filesize($final_path)]);
    if ($apply_retention) {
        $retained = mg_saoluu_apply_retention();
        if (is_wp_error($retained)) mg_saoluu_log_event('retention_failed', $retained->get_error_message());
    }
    return $final_name;
}

/** Retention groups are independent: daily SQL cannot evict the last full copy. */
function mg_saoluu_retention_group($filename, $metadata = []) {
    $type = (string) ($metadata['type'] ?? '');
    $purpose = (string) ($metadata['purpose'] ?? '');
    if ($purpose === 'rollback' || strpos($filename, 'backup_pre_') === 0) return 'rollback';
    if (!$type && preg_match('/_(safe_full|local_copy|sql)_\d{8}_\d{6}/', $filename, $m)) $type = $m[1];
    return in_array($type, ['sql', 'safe_full', 'local_copy'], true) ? $type : 'other';
}

function mg_saoluu_apply_retention() {
    $safe = mg_saoluu_storage_preflight();
    if (is_wp_error($safe)) return $safe;
    $dir = mg_saoluu_get_backup_dir();
    $legacy = max(2, min(50, (int) get_option('mg_saoluu_retention', 7)));
    $limits = [
        'sql' => max(2, min(50, (int) get_option('mg_saoluu_retention_sql', $legacy))),
        'safe_full' => max(2, min(50, (int) get_option('mg_saoluu_retention_full', 4))),
        'local_copy' => max(2, min(50, (int) get_option('mg_saoluu_retention_local_copy', 3))),
        'rollback' => max(2, min(50, (int) get_option('mg_saoluu_retention_rollback', 3))),
        'other' => max(2, min(50, (int) get_option('mg_saoluu_retention_other', 3))),
    ];
    $metadata_file = $dir . 'metadata.json';
    $metadata = mg_saoluu_read_metadata($metadata_file);
    $counts = array_fill_keys(array_keys($limits), 0);
    $files = mg_saoluu_list_backup_files(true); // Bypass stale transient during cleanup.
    $full_files = 0;
    foreach ($files as $file) if (mg_saoluu_retention_group(basename($file), $metadata[basename($file)] ?? []) === 'safe_full') $full_files++;
    $changed = false;
    foreach ($files as $file) {
        $name = basename($file);
        if (!mg_saoluu_is_backup_filename($name)) continue;
        $group = mg_saoluu_retention_group($name, $metadata[$name] ?? []);
        if (!empty($metadata[$name]['protected'])) continue; // Not part of disposable quota.
        $counts[$group]++;
        if ($counts[$group] <= $limits[$group]) continue;
        if ($group === 'safe_full' && $full_files <= 1) continue;
        if (@unlink($file)) {
            unset($metadata[$name]);
            if ($group === 'safe_full') $full_files--;
            $changed = true;
        }
    }
    if ($changed && !mg_saoluu_save_metadata($metadata_file, $metadata)) return new WP_Error('retention_metadata_failed', 'Đã dọn file nhưng không lưu được metadata. Cần kiểm tra lại backup.');
    return true;
}
