<?php
if (!defined('ABSPATH')) { exit; }

function mg_saoluu_is_safe_zip_entry($entry) {
    $entry = str_replace('\\', '/', (string) $entry);
    if ($entry === '' || strpos($entry, "\0") !== false) return false;
    if ($entry[0] === '/' || preg_match('/^[A-Za-z]:\//', $entry)) return false;
    foreach (explode('/', $entry) as $part) if ($part === '..') return false;
    return true;
}


function mg_saoluu_zip_entry_is_symlink($zip, $index) {
    if (!is_object($zip) || !method_exists($zip, 'getExternalAttributesIndex')) return false;
    $opsys = 0; $attr = 0;
    if (!$zip->getExternalAttributesIndex((int) $index, $opsys, $attr)) return false;
    $mode = ($attr >> 16) & 0xF000;
    return $mode === 0xA000; // Unix symbolic link.
}

function mg_saoluu_zip_open_safety_check($zip) {
    if (!is_object($zip)) return new WP_Error('zip_invalid', 'Đối tượng ZIP không hợp lệ.');
    if ((int) $zip->numFiles > 100000) return new WP_Error('zip_file_count', 'ZIP có quá nhiều file, đã dừng để bảo vệ máy chủ.');
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i); if (!$stat) continue;
        $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
        if (!mg_saoluu_is_safe_zip_entry($name)) return new WP_Error('zip_path_unsafe', 'ZIP chứa đường dẫn không an toàn: ' . $name);
        if (mg_saoluu_zip_entry_is_symlink($zip, $i)) return new WP_Error('zip_symlink', 'ZIP chứa symbolic link không được phép: ' . $name);
        $size = (int) ($stat['size'] ?? 0); $compressed = max(1, (int) ($stat['comp_size'] ?? 1));
        $total += $size;
        if ($size > 10 * 1024 * 1024 && ($size / $compressed) > MG_SAOLUU_MAX_ZIP_RATIO) return new WP_Error('zip_ratio', 'ZIP có mức nén bất thường, đã dừng để tránh file nguy hiểm.');
        if ($total > MG_SAOLUU_MAX_ZIP_UNCOMPRESSED) return new WP_Error('zip_too_large', 'Dung lượng giải nén vượt giới hạn an toàn.');
    }
    return ['uncompressed' => $total, 'files' => (int) $zip->numFiles];
}

function mg_saoluu_validate_upload($file, $extension, $max_size) {
    if (empty($file) || !isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])) return new WP_Error('upload_missing', 'Không nhận được file tải lên.');
    if ((int) $file['error'] !== UPLOAD_ERR_OK) return new WP_Error('upload_failed', 'Tải file thất bại. Mã lỗi: ' . (int) $file['error']);
    if (!is_uploaded_file($file['tmp_name'])) return new WP_Error('upload_invalid', 'File tải lên không hợp lệ.');
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== strtolower($extension)) return new WP_Error('extension_invalid', 'Chỉ chấp nhận file .' . $extension . '.');
    if ((int) $file['size'] < 1) return new WP_Error('upload_empty', 'File đang trống.');
    if ((int) $file['size'] > $max_size) return new WP_Error('upload_large', 'File vượt giới hạn ' . size_format($max_size) . '.');
    if ($extension === 'sql') {
        $handle = @fopen($file['tmp_name'], 'rb');
        $sample = $handle ? fread($handle, 1024 * 1024) : false;
        if ($handle) fclose($handle);
        if ($sample === false || strpos($sample, "\0") !== false || !preg_match('/\b(CREATE|INSERT|REPLACE|UPDATE|DELETE|DROP|ALTER|SET)\b/i', $sample)) {
            return new WP_Error('sql_content_invalid', 'Nội dung file không giống một bản sao SQL hợp lệ.');
        }
    }
    return true;
}

function mg_saoluu_validate_backup_upload($file, $max_size) {
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['zip', 'mgbackup'], true)) return new WP_Error('extension_invalid', 'Chỉ chấp nhận file .zip hoặc .mgbackup.');
    return mg_saoluu_validate_upload($file, $extension, $max_size);
}

function mg_saoluu_stage_upload($file, $extension) {
    $private_temp = mg_saoluu_private_temp_dir();
    if (is_wp_error($private_temp)) return $private_temp;
    $target = $private_temp . 'mg-upload-' . wp_generate_password(20, false, false) . '.' . sanitize_key($extension);
    if (!move_uploaded_file($file['tmp_name'], $target)) return new WP_Error('upload_move_failed', 'Không thể chuyển file vào thư mục tạm.');
    @chmod($target, 0600);
    return $target;
}

function mg_saoluu_make_temp_dir($prefix = 'mg-restore-') {
    $private_temp = mg_saoluu_private_temp_dir();
    if (is_wp_error($private_temp)) return $private_temp;
    $base = $private_temp . $prefix . wp_generate_password(18, false, false);
    if (!wp_mkdir_p($base)) return new WP_Error('temp_dir_failed', 'Không thể tạo thư mục tạm để kiểm tra bản sao.');
    @chmod($base, 0700);
    return trailingslashit($base);
}

function mg_saoluu_remove_tree($dir) {
    if (!is_dir($dir)) return;
    try {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    } catch (Throwable $e) {}
    @rmdir($dir);
}

function mg_saoluu_prepare_backup_file($path, $password = '') {
    if (!is_readable($path) || filesize($path) < 1) return new WP_Error('backup_missing', 'Không đọc được file sao lưu.');
    if (!mg_saoluu_is_encrypted_backup($path)) return ['path' => $path, 'temporary' => false, 'header' => []];
    $private_temp = mg_saoluu_private_temp_dir();
    if (is_wp_error($private_temp)) return $private_temp;
    $temp = $private_temp . 'mg-decrypted-' . wp_generate_password(20, false, false) . '.zip';
    // Reading a backup must NOT silently mint or rotate the site's Recovery Key.
    $key = (string) $password;
    if ($key === '' && mg_saoluu_encryption_constant_available()) $key = (string) MG_SAOLUU_ENCRYPTION_KEY;
    if ($key === '') $key = mg_saoluu_managed_encryption_key(false);
    if ($key === '') return new WP_Error('recovery_key_missing', 'Không có Recovery Key để đọc bản sao mã hóa; hãy nhập khóa gốc. Không tạo khóa mới khi kiểm tra.');
    $result = mg_saoluu_decrypt_backup_file($path, $temp, $key);
    if (is_wp_error($result)) return $result;
    return ['path' => $temp, 'temporary' => true, 'header' => $result];
}

function mg_saoluu_read_backup_manifest($path, $password = '') {
    $prepared = mg_saoluu_prepare_backup_file($path, $password);
    if (is_wp_error($prepared)) return $prepared;
    $manifest = mg_saoluu_read_zip_manifest($prepared['path']);
    if ($prepared['temporary']) @unlink($prepared['path']);
    return $manifest;
}

function mg_saoluu_extract_to_stage($zip_file, $stage_dir, $include_files) {
    $zip = new ZipArchive();
    if ($zip->open($zip_file) !== true) return new WP_Error('zip_open_failed', 'Không thể mở ZIP.');
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!$stat) continue;
        $name = ltrim(str_replace('\\', '/', $stat['name']), '/');
        if (mg_saoluu_zip_entry_is_symlink($zip, $i)) { $zip->close(); return new WP_Error('unsafe_symlink', 'ZIP chứa symbolic link không được phép: ' . $name); }
        if (!mg_saoluu_is_safe_zip_entry($name)) { $zip->close(); return new WP_Error('unsafe_entry', 'ZIP chứa đường dẫn không an toàn: ' . $name); }
        if (!$include_files && !in_array($name, ['database_backup.sql', 'mg-backup-manifest.json'], true)) continue;
        if ($include_files && $name !== 'database_backup.sql' && $name !== 'mg-backup-manifest.json' && mg_saoluu_is_excluded_path($name, 'safe_full')) continue;
        if (substr($name, -1) === '/') { if (!wp_mkdir_p($stage_dir . $name)) { $zip->close(); return new WP_Error('stage_mkdir_failed', 'Không thể tạo thư mục tạm.'); } continue; }
        $dest = $stage_dir . $name;
        if (!wp_mkdir_p(dirname($dest))) { $zip->close(); return new WP_Error('stage_mkdir_failed', 'Không thể tạo thư mục tạm: ' . dirname($name)); }
        $in = $zip->getStream($stat['name']);
        $out = @fopen($dest, 'wb');
        if (!$in || !$out) { if ($in) fclose($in); if ($out) fclose($out); $zip->close(); return new WP_Error('stage_write_failed', 'Không thể đọc file trong ZIP: ' . $name); }
        $copied = stream_copy_to_stream($in, $out); fclose($in); fclose($out);
        if ($copied === false || $copied !== (int) ($stat['size'] ?? -1)) { $zip->close(); return new WP_Error('stage_incomplete', 'File giải nén bị thiếu dữ liệu: '.$name); }
    }
    $zip->close();
    if (!is_readable($stage_dir . 'database_backup.sql')) return new WP_Error('sql_missing', 'Không tìm thấy database_backup.sql sau khi giải nén.');
    return true;
}

function mg_saoluu_copy_stage_files($stage_dir, $backup_type, $is_migration = false) {
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            $path = wp_normalize_path($file->getPathname());
            $relative = ltrim(substr($path, strlen(wp_normalize_path($stage_dir))), '/');
            if (in_array($relative, ['database_backup.sql', 'mg-backup-manifest.json'], true)) continue;
            if (mg_saoluu_is_excluded_path($relative, $backup_type === 'local_copy' ? 'local_copy' : 'safe_full')) continue;
            // Migration never replaces the plugin enforcing this write gate.
            if ($is_migration && strpos($relative, 'wp-content/plugins/mg-saoluu/') === 0) continue;
            $guard = mg_saoluu_restore_destination_check($relative);
            if (is_wp_error($guard)) return $guard;
            $target = $guard['path'];
            if (!is_dir(dirname($target)) && !wp_mkdir_p(dirname($target))) return new WP_Error('target_mkdir_failed', 'Không thể tạo thư mục đích an toàn.');
            // Re-check after mkdir and immediately before copy: a changed ancestor must fail closed.
            $guard = mg_saoluu_restore_destination_check($relative);
            if (is_wp_error($guard)) return $guard;
            if (!@copy($path, $guard['path'])) return new WP_Error('copy_failed', 'Không thể thay file đích.');
            $after = mg_saoluu_restore_destination_check($relative);
            if (is_wp_error($after) || empty($after['exists']) || empty($after['regular'])) return new WP_Error('restore_destination_changed', 'Đích file đã thay đổi trong lúc khôi phục; đã dừng để bảo vệ dữ liệu.');
        }
    } catch (Throwable $e) {
        return new WP_Error('copy_failed', 'Lỗi trong lúc thay file.');
    }
    return true;
}

function mg_saoluu_recursive_replace($old, $new, $value) {
    if (is_string($value)) {
        if (is_serialized($value)) {
            // Không gọi __wakeup/__unserialize từ dữ liệu nhập vào. Object tuần tự được giữ nguyên để tránh chạy mã ngoài ý muốn.
            if (preg_match('/^(?:O|C):/', $value)) return $value;
            $unserialized = @unserialize($value, ['allowed_classes' => false]);
            // Refuse unsafe/complex serialized payloads, including nested objects and
            // self-references. Preserve original bytes instead of breaking their structure.
            if ($unserialized === false && $value !== 'b:0;') return $value;
            if (mg_saoluu_contains_unsafe_serialized_value($unserialized)) return $value;
            return serialize(mg_saoluu_recursive_replace($old, $new, $unserialized));
        }
        return str_replace([$old, str_replace('/', '\\/', $old)], [$new, str_replace('/', '\\/', $new)], $value);
    }
    if (is_array($value)) { foreach ($value as $k => $v) $value[$k] = mg_saoluu_recursive_replace($old, $new, $v); return $value; }
    if (is_object($value)) { foreach (get_object_vars($value) as $k => $v) $value->$k = mg_saoluu_recursive_replace($old, $new, $v); return $value; }
    return $value;
}

/**
 * True means this decoded serialized value is unsafe to rewrite; leave bytes intact.
 * PHP serialized arrays may contain recursive R/r references, which otherwise cause
 * unbounded traversal and fatal memory exhaustion. Keep both depth and node budgets.
 */
function mg_saoluu_contains_unsafe_serialized_value($value, $depth = 0, &$visited = null) {
    if ($visited === null) $visited = 0;
    if (++$visited > 4096 || $depth >= 64) return true;
    // allowed_classes=false normally yields incomplete objects; enum objects are
    // also possible. Never mutate ANY object during URL migration.
    if (is_object($value)) return true;
    if (is_array($value)) {
        foreach ($value as $item) {
            if (mg_saoluu_contains_unsafe_serialized_value($item, $depth + 1, $visited)) return true;
        }
    }
    return false;
}

/**
 * Bounded, serialized-aware URL move on the current WP table prefix only.
 * Keyset traversal guarantees each primary key is processed once even when the
 * replacement URL CONTAINS the old URL (no repeated replacement / infinite loop).
 * The globally meaningful WordPress posts.guid column is immutable.
 */
function mg_saoluu_search_replace_database($old, $new) {
    global $wpdb;
    $old = untrailingslashit((string) $old);
    $new = untrailingslashit((string) $new);
    if (!$old || !$new || $old === $new) return ['tables' => 0, 'rows' => 0];
    $tables = $wpdb->get_col('SHOW TABLES');
    if (!is_array($tables)) return new WP_Error('replace_tables_failed', 'Không liệt kê được bảng database để thay URL.');
    $changed_rows = 0;
    $changed_tables = 0;
    foreach ($tables as $table) {
        // Never rewrite a different application's tables in the same database.
        if (strpos($table, $wpdb->prefix) !== 0) continue;
        $safe_table = str_replace('`', '``', $table);
        $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$safe_table}`", ARRAY_A);
        if (!is_array($columns)) return new WP_Error('replace_schema_failed', 'Không thể đọc schema: ' . $table);
        $primary = [];
        $text_columns = [];
        foreach ($columns as $column) {
            $field = (string) ($column['Field'] ?? '');
            if ($field === '') continue;
            if (($column['Key'] ?? '') === 'PRI') $primary[] = $field;
            if (strcasecmp($field, 'guid') === 0) continue; // WP posts.guid must not change.
            if (preg_match('/char|text|blob|binary|json/i', (string) ($column['Type'] ?? ''))) $text_columns[] = $field;
        }
        if (!$primary || !$text_columns) continue;
        $last_primary = null;
        $table_changed = false;
        $batches = 0;
        while (true) {
            if (++$batches > 10000) return new WP_Error('replace_batch_limit', 'Thay URL vượt giới hạn batch ở bảng ' . $table . '; chưa xác nhận thành công.');
            $likes = [];
            $params = [];
            foreach ($text_columns as $col) {
                $likes[] = '`' . str_replace('`', '``', $col) . '` LIKE %s';
                $params[] = '%' . $wpdb->esc_like($old) . '%';
            }
            $query = "SELECT * FROM `{$safe_table}` WHERE (" . implode(' OR ', $likes) . ')';
            if ($last_primary !== null) {
                $cursor_parts = [];
                // Composite PK: (k1>x) OR (k1=x AND k2>y) ...
                foreach ($primary as $i => $col) {
                    $and_parts = [];
                    for ($k = 0; $k < $i; $k++) {
                        $and_parts[] = '`' . str_replace('`', '``', $primary[$k]) . '` = %s';
                        $params[] = $last_primary[$primary[$k]];
                    }
                    $and_parts[] = '`' . str_replace('`', '``', $col) . '` > %s';
                    $params[] = $last_primary[$col];
                    $cursor_parts[] = '(' . implode(' AND ', $and_parts) . ')';
                }
                $query .= ' AND (' . implode(' OR ', $cursor_parts) . ')';
            }
            $order = array_map(function($field) { return '`' . str_replace('`', '``', $field) . '` ASC'; }, $primary);
            $query .= ' ORDER BY ' . implode(', ', $order) . ' LIMIT 150';
            $rows = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
            if (!is_array($rows)) return new WP_Error('replace_read_failed', 'Không thể đọc dữ liệu bảng: ' . $table);
            if (!$rows) break;
            foreach ($rows as $row) {
                $data = [];
                foreach ($text_columns as $column) {
                    if (!isset($row[$column]) || strpos((string) $row[$column], $old) === false) continue;
                    $replaced = mg_saoluu_recursive_replace($old, $new, $row[$column]);
                    if ($replaced !== $row[$column]) $data[$column] = $replaced;
                }
                if (!$data) continue;
                $where = [];
                foreach ($primary as $column) $where[$column] = $row[$column];
                $result = $wpdb->update($table, $data, $where);
                if ($result === false) return new WP_Error('replace_write_failed', 'Không cập nhật được một bản ghi trong: ' . $table);
                if ($result > 0) { $changed_rows++; $table_changed = true; }
            }
            $last_row = end($rows);
            $last_primary = [];
            foreach ($primary as $column) $last_primary[$column] = $last_row[$column];
            if (count($rows) < 150) break;
        }
        if ($table_changed) $changed_tables++;
    }
    return ['tables' => $changed_tables, 'rows' => $changed_rows];
}

function mg_saoluu_maintenance($enable) {
    $guard = mg_saoluu_restore_destination_check('.maintenance');
    if (is_wp_error($guard)) return $guard;
    $file = $guard['path'];
    if ($enable && $guard['exists']) {
        return new WP_Error('maintenance_already_active', 'Website đã có chế độ bảo trì; dừng restore để không ghi đè thao tác cập nhật khác.');
    }
    if ($enable) {
        $content = '<?php $upgrading = ' . time() . '; ?>';
        $written = @file_put_contents($file, $content, LOCK_EX);
        clearstatcache(true, $file);
        $check = mg_saoluu_restore_destination_check('.maintenance');
        if ($written !== strlen($content) || is_wp_error($check) || empty($check['regular'])) {
            return new WP_Error('maintenance_write_failed', 'Không bật được chế độ bảo trì an toàn; đã dừng trước khi nhập database.');
        }
    } elseif ($guard['exists']) {
        if (!@unlink($file)) return new WP_Error('maintenance_remove_failed', 'Không gỡ được chế độ bảo trì; cần kiểm tra thủ công.');
        clearstatcache(true, $file);
        if (file_exists($file) || is_link($file)) return new WP_Error('maintenance_remove_failed', 'Chế độ bảo trì vẫn tồn tại; cần kiểm tra thủ công.');
    }
    return true;
}

function mg_saoluu_capture_target_settings() {
    $names = [
        'mg_saoluu_environment','mg_saoluu_environment_verified_host','mg_saoluu_block_emails','mg_saoluu_force_noindex',
        'mg_saoluu_setup_complete','mg_saoluu_auto_sql','mg_saoluu_auto_full','mg_saoluu_audit_log','mg_saoluu_retention',
        'mg_saoluu_retention_sql','mg_saoluu_retention_full','mg_saoluu_retention_local_copy','mg_saoluu_retention_rollback','mg_saoluu_retention_other',
        'mg_saoluu_mysql_binary','mg_saoluu_mysqldump_binary','mg_saoluu_encrypt_default','mg_saoluu_drive_auto_upload',
        'mg_saoluu_drive_client_id','mg_saoluu_drive_client_secret','mg_saoluu_drive_token','mg_saoluu_drive_folder_id',
        'mg_saoluu_managed_encryption_key','mg_saoluu_managed_encryption_key_created_at','mg_saoluu_recovery_key_exported_at',
        'mg_saoluu_device_id','mg_saoluu_device_name','mg_saoluu_device_public_key','mg_saoluu_device_private_key','mg_saoluu_device_created_at',
        'mg_saoluu_trusted_signers','mg_saoluu_used_deploy_packages','mg_saoluu_used_migration_nonces',
        'mg_saoluu_installed_version','mg_saoluu_data_version'
    ];
    $values = [];
    foreach ($names as $name) $values[$name] = get_option($name, null);
    return ['options' => $values, 'blog_public' => get_option('blog_public', 0), 'target_environment' => mg_saoluu_environment()];
}

function mg_saoluu_restore_target_settings($settings) {
    foreach (($settings['options'] ?? []) as $name => $value) {
        if ($value === null) delete_option($name); else update_option($name, $value, false);
    }
    if (($settings['target_environment'] ?? '') !== 'production') update_option('blog_public', 0);
    else update_option('blog_public', $settings['blog_public'] ?? 1);
    delete_transient('mg_saoluu_production_restore_unlocked');
}

function mg_saoluu_production_unlocked() {
    if (mg_saoluu_environment() !== 'production') return true;
    $info = mg_saoluu_security_restore_unlock_info();
    return !empty($info['active']);
}

function mg_saoluu_check_restore_confirmation($phrase, $confirmed) {
    $expected = mg_saoluu_environment_info()['phrase'];
    if (!$confirmed || strtoupper(trim((string) $phrase)) !== $expected) return new WP_Error('confirm_failed', 'Bạn phải tích xác nhận và nhập đúng: ' . $expected);
    if (!mg_saoluu_production_unlocked()) return new WP_Error('production_locked', 'Khôi phục trên web thật đang bị khóa. Hãy mở khóa tạm thời trước.');
    return true;
}

/**
 * Check both the work/archive volume and the destination WordPress volume.
 * A shared device must satisfy the combined reserve. This is conservative,
 * not a guarantee against a disk filling after preflight.
 */
function mg_saoluu_restore_space_budget($free_work, $free_site, $same_volume, $need_work, $need_site) {
    if ($same_volume) {
        $needed = $need_work + $need_site;
        $available = $free_work !== false ? $free_work : $free_site;
        if ($available !== false && $available < $needed) {
            return new WP_Error('restore_space', 'Không đủ dung lượng chung cho giải nén và ghi website. Cần ' . size_format($needed) . ', còn ' . size_format($available) . '.');
        }
        return true;
    }
    if ($free_work !== false && $free_work < $need_work) {
        return new WP_Error('restore_space', 'Không đủ dung lượng thư mục tạm. Cần ' . size_format($need_work) . ', còn ' . size_format($free_work) . '.');
    }
    if ($free_site !== false && $free_site < $need_site) {
        return new WP_Error('restore_target_space', 'Ổ chứa website đích không đủ dung lượng. Cần ' . size_format($need_site) . ', còn ' . size_format($free_site) . '.');
    }
    return true;
}

function mg_saoluu_restore_preflight($zip_file) {
    $zip = new ZipArchive();
    if ($zip->open($zip_file) !== true) return new WP_Error('zip_open_failed', 'Không thể mở ZIP để tính dung lượng.');
    $uncompressed = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) { $stat = $zip->statIndex($i); if ($stat) $uncompressed += (int) ($stat['size'] ?? 0); }
    $zip->close();
    $private_temp = mg_saoluu_private_temp_dir();
    if (is_wp_error($private_temp)) return $private_temp;
    $free_work = @disk_free_space($private_temp);
    $free_site = @disk_free_space(ABSPATH);
    $work_stat = @stat($private_temp);
    $site_stat = @stat(ABSPATH);
    $same_volume = is_array($work_stat) && is_array($site_stat)
        && isset($work_stat['dev'], $site_stat['dev']) && $work_stat['dev'] === $site_stat['dev'];
    $required_work = max(300 * 1024 * 1024, (int) ($uncompressed * 1.15));
    $required_site = max(96 * 1024 * 1024, (int) ($uncompressed * 1.10));
    $check = mg_saoluu_restore_space_budget($free_work, $free_site, $same_volume, $required_work, $required_site);
    if (is_wp_error($check)) return $check;
    return ['uncompressed' => $uncompressed, 'required' => $required_work, 'site_required' => $required_site];
}

function mg_saoluu_restore_health_check($expected_home = '') {
    global $wpdb;
    $errors = [];
    $options_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->options));
    if ($options_table !== $wpdb->options) $errors[] = 'Không tìm thấy bảng cài đặt WordPress.';
    $home = (string) get_option('home', '');
    $siteurl = (string) get_option('siteurl', '');
    if ($home === '' || $siteurl === '') $errors[] = 'Địa chỉ WordPress đang trống.';
    if ($expected_home && untrailingslashit($home) !== untrailingslashit($expected_home)) $errors[] = 'Tên miền sau khôi phục không đúng địa chỉ đích.';
    $stylesheet = (string) get_option('stylesheet', '');
    if ($stylesheet && !is_dir(WP_CONTENT_DIR . '/themes/' . $stylesheet)) $errors[] = 'Không tìm thấy theme đang được kích hoạt: ' . $stylesheet;
    if ($errors) return new WP_Error('restore_health', implode(' ', $errors));
    return true;
}

function mg_saoluu_restore_journal($data = null) {
    $file = mg_saoluu_get_backup_dir() . 'restore-journal.json';
    if ($data === null) {
        if (!file_exists($file) && !is_link($file)) return [];
        if (!is_file($file) || is_link($file)) return ['status' => 'journal_invalid'];
        $json = @file_get_contents($file);
        $loaded = is_string($json) ? json_decode($json, true) : null;
        return is_array($loaded) && isset($loaded['status']) ? $loaded : ['status' => 'journal_invalid'];
    }
    $safe = mg_saoluu_storage_preflight();
    if (is_wp_error($safe)) return $safe;
    if (is_link($file) || (file_exists($file) && !is_file($file))) {
        return new WP_Error('journal_write_failed', 'Nhật ký restore không phải file an toàn; đã dừng trước khi ghi database.');
    }
    $data['updated_at'] = current_time('mysql');
    $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json) || $json === '') return new WP_Error('journal_write_failed', 'Không mã hóa được nhật ký restore.');
    // Atomic write: a disk-full failure must never truncate the previous journal.
    $temp = $file . '.' . wp_generate_password(16, false, false) . '.tmp';
    $handle = @fopen($temp, 'xb');
    if (!$handle) return new WP_Error('journal_write_failed', 'Không tạo được nhật ký restore tạm.');
    @chmod($temp, 0600);
    $length = strlen($json);
    $offset = 0;
    while ($offset < $length) {
        $count = @fwrite($handle, substr($json, $offset));
        if (!is_int($count) || $count <= 0) break;
        $offset += $count;
    }
    $flushed = @fflush($handle);
    $closed = @fclose($handle);
    if ($offset !== $length || !$flushed || !$closed || !@rename($temp, $file)) {
        @unlink($temp);
        return new WP_Error('journal_write_failed', 'Không ghi đủ nhật ký restore; đã dừng an toàn.');
    }
    return $data;
}

/** Unknown, interrupted and rollback-failed transactions must not be overwritten. */
function mg_saoluu_restore_journal_unfinished($journal) {
    if (!is_array($journal) || !$journal) return false;
    // Only a recorded completed/rolled-back transaction permits a new write.
    // Unknown status is not a safe reason to overwrite an existing checkpoint.
    return !in_array((string) ($journal['status'] ?? ''), ['completed', 'rolled_back'], true);
}

function mg_saoluu_restore_destination_check($relative) {
    $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
    if (!mg_saoluu_is_safe_zip_entry($relative)) return new WP_Error('restore_destination_unsafe', 'Đích file không an toàn; đã dừng.');
    $root = rtrim(wp_normalize_path(ABSPATH), '/');
    $canonical_root = realpath($root);
    if ($canonical_root === false) return new WP_Error('restore_root_unresolved', 'Không xác định được thư mục WordPress an toàn.');
    $parts = array_values(array_filter(explode('/', $relative), static function ($part) { return $part !== ''; }));
    if (!$parts) return new WP_Error('restore_destination_unsafe', 'Đích file không an toàn; đã dừng.');
    $current = $root;
    $last = count($parts) - 1;
    foreach ($parts as $index => $part) {
        $current .= '/' . $part;
        $exists = file_exists($current) || is_link($current);
        if (!$exists) continue;
        if (is_link($current)) return new WP_Error('restore_destination_symlink', 'Đích hoặc thư mục cha là symbolic link; đã dừng để bảo vệ dữ liệu.');
        $stat = @lstat($current);
        if (!$stat) return new WP_Error('restore_destination_unresolved', 'Không kiểm tra được đích file; đã dừng.');
        $type = $stat['mode'] & 0170000;
        $leaf = $index === $last;
        if ($type === 0040000) {
            if ($leaf) return new WP_Error('restore_destination_directory', 'Đích file là thư mục; đã dừng.');
        } elseif ($type === 0100000) {
            if (!$leaf) return new WP_Error('restore_destination_special', 'Thư mục cha không phải thư mục thường; đã dừng.');
        } else {
            return new WP_Error('restore_destination_special', 'Đích hoặc thư mục cha là file đặc biệt; đã dừng.');
        }
        $real = realpath($current);
        $canonical_root = rtrim(wp_normalize_path($canonical_root), '/');
        if ($real === false || (wp_normalize_path($real) !== $canonical_root && strpos(wp_normalize_path($real), trailingslashit($canonical_root)) !== 0)) {
            return new WP_Error('restore_destination_outside', 'Đích file nằm ngoài thư mục WordPress; đã dừng.');
        }
    }
    $leaf_exists = file_exists($current) || is_link($current);
    return ['path' => $current, 'exists' => $leaf_exists, 'regular' => $leaf_exists && is_file($current) && !is_link($current)];
}

function mg_saoluu_validate_restore_destinations($stage_dir, $backup_type) {
    $maintenance = mg_saoluu_restore_destination_check('.maintenance');
    if (is_wp_error($maintenance)) return $maintenance;
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            $path = wp_normalize_path($file->getPathname());
            $relative = ltrim(substr($path, strlen(wp_normalize_path($stage_dir))), '/');
            if (in_array($relative, ['database_backup.sql', 'mg-backup-manifest.json'], true)) continue;
            if (mg_saoluu_is_excluded_path($relative, $backup_type === 'local_copy' ? 'local_copy' : 'safe_full')) continue;
            $guard = mg_saoluu_restore_destination_check($relative);
            if (is_wp_error($guard)) return $guard;
        }
    } catch (Throwable $e) {
        return new WP_Error('restore_destination_scan_failed', 'Không kiểm tra được các đích file; đã dừng.');
    }
    return true;
}

function mg_saoluu_stage_new_files($stage_dir) {
    $new = [];
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            $path = wp_normalize_path($file->getPathname());
            $relative = ltrim(substr($path, strlen(wp_normalize_path($stage_dir))), '/');
            if (in_array($relative, ['database_backup.sql', 'mg-backup-manifest.json'], true)) continue;
            $guard = mg_saoluu_restore_destination_check($relative);
            if (is_wp_error($guard)) return $guard;
            if (!$guard['exists']) $new[] = $relative;
        }
    } catch (Throwable $e) { return new WP_Error('restore_destination_scan_failed', 'Không kiểm tra được các đích file; đã dừng.'); }
    return $new;
}

function mg_saoluu_remove_new_restore_files($relative_files, $unlink = null) {
    $result = ['requested' => 0, 'removed' => 0, 'already_absent' => 0, 'failed' => 0];
    $unlink = is_callable($unlink) ? $unlink : static function ($target) { return @unlink($target); };
    foreach ((array) $relative_files as $relative) {
        $result['requested']++;
        $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
        if (!mg_saoluu_is_safe_zip_entry($relative) || mg_saoluu_is_excluded_path($relative, 'safe_full')) {
            $result['failed']++;
            continue;
        }
        $guard = mg_saoluu_restore_destination_check($relative);
        if (is_wp_error($guard)) {
            $result['failed']++;
            continue;
        }
        if (!$guard['exists']) {
            $result['already_absent']++;
            continue;
        }
        $target = $guard['path'];
        // Re-check immediately before unlink so a changed ancestor or leaf fails closed.
        $guard = mg_saoluu_restore_destination_check($relative);
        if (is_wp_error($guard) || empty($guard['regular'])) {
            $result['failed']++;
            continue;
        }
        try {
            $deleted = (bool) $unlink($target);
        } catch (Throwable $e) {
            $deleted = false;
        }
        if ($deleted) {
            $after = mg_saoluu_restore_destination_check($relative);
            if (is_wp_error($after) || !empty($after['exists'])) $result['failed']++;
            else $result['removed']++;
        } elseif (!file_exists($target) && !is_link($target)) {
            $result['already_absent']++;
        } else {
            $result['failed']++;
        }
    }
    return $result;
}

/** Remove files introduced by the completed restore when applying its rollback checkpoint. */
function mg_saoluu_remove_previous_restore_files($journal) {
    $empty = ['requested' => 0, 'removed' => 0, 'already_absent' => 0, 'failed' => 0];
    if (!is_array($journal) || ($journal['status'] ?? '') !== 'completed') return $empty;
    $files = $journal['new_files'] ?? [];
    if (!is_array($files) || !$files) return $empty;
    return mg_saoluu_remove_new_restore_files($files);
}

/** Resolve only the exact, current full-restore checkpoint; accept an exact uploaded copy by SHA. */
function mg_saoluu_rollback_checkpoint_context($backup_file, $manifest, $restore_files) {
    if (!$restore_files) return ['rollback' => false, 'journal' => []];
    $name = basename((string) $backup_file);
    $purpose = sanitize_key($manifest['purpose'] ?? '');
    $candidate = $purpose === 'rollback' || strpos($name, 'backup_pre_restore_') === 0;
    if (!$candidate) return ['rollback' => false, 'journal' => []];

    $journal = mg_saoluu_restore_journal();
    $expected = basename((string) ($journal['pre_backup'] ?? ''));
    $expected_hash = strtolower((string) ($journal['pre_backup_sha256'] ?? ''));
    $actual_path = realpath($backup_file);
    $trusted_path = $expected !== '' ? realpath(trailingslashit(mg_saoluu_get_backup_dir()) . $expected) : false;
    $trusted_hash = $trusted_path !== false ? @hash_file('sha256', $trusted_path) : false;
    $actual_hash = $actual_path !== false && is_file($actual_path) && !is_link($backup_file) ? @hash_file('sha256', $actual_path) : false;
    $matches = ($journal['status'] ?? '') === 'completed'
        && !empty($journal['restore_files'])
        && is_array($journal['new_files'] ?? null)
        && $expected !== ''
        && preg_match('/^[a-f0-9]{64}$/', $expected_hash) === 1
        && $actual_path !== false
        && $trusted_path !== false
        && is_string($trusted_hash)
        && hash_equals($expected_hash, strtolower($trusted_hash))
        && is_string($actual_hash)
        && hash_equals($expected_hash, strtolower($actual_hash));
    if (!$matches) {
        return new WP_Error('rollback_checkpoint_stale', 'Không thể xác định đúng bản quay lại của lần khôi phục gần nhất. Đã dừng để tránh dọn nhầm file.');
    }
    return ['rollback' => true, 'journal' => $journal];
}

function mg_saoluu_rollback_from_backup($pre_path, $restore_files, $password, $target_settings) {
    $prepared = mg_saoluu_prepare_backup_file($pre_path, $password);
    if (is_wp_error($prepared)) return $prepared;
    $stage = mg_saoluu_make_temp_dir('mg-rollback-');
    if (is_wp_error($stage)) { if ($prepared['temporary']) @unlink($prepared['path']); return $stage; }
    $manifest = mg_saoluu_read_zip_manifest($prepared['path']);
    if (is_wp_error($manifest)) { mg_saoluu_remove_tree($stage); if ($prepared['temporary']) @unlink($prepared['path']); return $manifest; }
    $extract = mg_saoluu_extract_to_stage($prepared['path'], $stage, $restore_files);
    if (is_wp_error($extract)) { mg_saoluu_remove_tree($stage); if ($prepared['temporary']) @unlink($prepared['path']); return $extract; }
    if ($restore_files) {
        $destinations = mg_saoluu_validate_restore_destinations($stage, sanitize_key($manifest['backup_type'] ?? 'safe_full'));
        if (is_wp_error($destinations)) { mg_saoluu_remove_tree($stage); if ($prepared['temporary']) @unlink($prepared['path']); return $destinations; }
    }
    $import = mg_saoluu_run_mysql_import($stage . 'database_backup.sql');
    if (!is_wp_error($import) && $restore_files) $import = mg_saoluu_copy_stage_files($stage, sanitize_key($manifest['backup_type'] ?? 'safe_full'));
    mg_saoluu_restore_target_settings($target_settings);
    wp_cache_flush();
    if (!is_wp_error($import)) $import = mg_saoluu_restore_health_check(home_url('/'));
    mg_saoluu_remove_tree($stage);
    if ($prepared['temporary']) @unlink($prepared['path']);
    return is_wp_error($import) ? $import : true;
}

/** Distinguish incompatible WordPress DB table prefixes before import. */
function mg_saoluu_backup_prefix_preflight($manifest, $target_prefix, $cross_site) {
    $source_prefix = (string) ($manifest['db_table_prefix'] ?? '');
    if ($source_prefix === '' && $cross_site) {
        return new WP_Error('backup_prefix_unknown', 'Backup cũ thiếu db_table_prefix. Không nhập DB sang môi trường khác khi chưa xác nhận prefix bằng quy trình clone an toàn.');
    }
    if ($source_prefix !== '' && $source_prefix !== $target_prefix) {
        return new WP_Error('backup_prefix_mismatch', 'Prefix database của backup (' . $source_prefix . ') khác WordPress đích (' . $target_prefix . '). Không tự đổi tên bảng.');
    }
    return true;
}

/** Permit domain rewrite on production only with a verified signed migration grant. */
function mg_saoluu_restore_should_change_url($enabled, $target_env, $migration_approval = null) {
    return (bool) $enabled && ($target_env !== 'production' || $migration_approval !== null);
}

function mg_saoluu_restore_zip($backup_file, $restore_files, $change_url = true, $password = '', $migration_grant = '') {
    // RC builds may create backups on production, but must never overwrite a live DB.
    if (mg_saoluu_environment() === 'production' && strpos(MG_SAOLUU_VERSION, '-rc') !== false) {
        return new WP_Error('rc_production_restore_disabled', 'Bản RC chỉ được restore trên clone. Dùng Recovery Runbook độc lập khi cần cứu site production.');
    }
    if (mg_saoluu_restore_journal_unfinished(mg_saoluu_restore_journal())) {
        return new WP_Error('restore_unfinished', 'Phát hiện lần restore trước chưa hoàn tất. Không ghi tiếp database; kiểm tra checkpoint và Recovery Runbook.');
    }
    $safe = mg_saoluu_storage_preflight();
    if (is_wp_error($safe)) return $safe;
    $prepared = mg_saoluu_prepare_backup_file($backup_file, $password);
    if (is_wp_error($prepared)) return $prepared;
    $zip_file = $prepared['path'];
    $manifest = mg_saoluu_read_zip_manifest($zip_file);
    if (is_wp_error($manifest)) { if ($prepared['temporary']) @unlink($zip_file); return $manifest; }
    $preflight = mg_saoluu_restore_preflight($zip_file);
    if (is_wp_error($preflight)) { if ($prepared['temporary']) @unlink($zip_file); return $preflight; }

    // Confirm both native tools before making any DB or file change.
    // A failed SQL import may already be partial; no PHP second-pass fallback.
    if (!mg_saoluu_find_binary('mysql') || !mg_saoluu_find_binary('mysqldump')) {
        if ($prepared['temporary']) @unlink($zip_file);
        return new WP_Error('restore_cli_missing', 'Chưa đủ mysql và mysqldump CLI để khôi phục + tạo checkpoint rollback an toàn.');
    }
    $target_env = mg_saoluu_environment();
    $migration_approval = null;
    if ($migration_grant !== '') {
        $gate = mg_saoluu_migration_production_gate();
        if (is_wp_error($gate)) {
            if ($prepared['temporary']) @unlink($zip_file);
            return $gate;
        }
        if (!$restore_files || !$change_url || !mg_saoluu_is_encrypted_backup($backup_file)) {
            if ($prepared['temporary']) @unlink($zip_file);
            return new WP_Error('migration_full_required', 'Chuyển site cần bản mã hóa, DB + files và đổi URL.');
        }
        global $wpdb;
        $plan = mg_saoluu_migration_manifest_preflight($manifest, home_url('/'), (string) $wpdb->prefix);
        if (is_wp_error($plan)) {
            if ($prepared['temporary']) @unlink($zip_file);
            return $plan;
        }
        $fresh = mg_saoluu_migration_target_fresh();
        if (is_wp_error($fresh)) {
            if ($prepared['temporary']) @unlink($zip_file);
            return $fresh;
        }
        $archive_sha = @hash_file('sha256', $backup_file);
        if (!is_string($archive_sha)) {
            if ($prepared['temporary']) @unlink($zip_file);
            return new WP_Error('migration_hash', 'Không tính được SHA-256 gói chuyển site.');
        }
        $migration_approval = mg_saoluu_migration_verify_grant($migration_grant, $archive_sha, $manifest, home_url('/'));
        if (is_wp_error($migration_approval)) {
            if ($prepared['temporary']) @unlink($zip_file);
            return $migration_approval;
        }
    }
    $source_env = sanitize_key($manifest['source_environment'] ?? '');
    $source_host = strtolower((string) ($manifest['source_host'] ?? ''));
    global $wpdb;
    $prefix_guard = mg_saoluu_backup_prefix_preflight($manifest, (string) $wpdb->prefix, $source_host !== mg_saoluu_host());
    if (is_wp_error($prefix_guard)) {
        if ($prepared['temporary']) @unlink($zip_file);
        return $prefix_guard;
    }
    if ($target_env === 'production' && $migration_approval === null) {
        if ($source_env !== 'production' || $source_host !== mg_saoluu_host()) {
            if ($prepared['temporary']) @unlink($zip_file);
            return new WP_Error('cross_env_production', 'Đã chặn: chỉ phục hồi bản được tạo từ chính web thật này.');
        }
        if (!mg_saoluu_verify_manifest_signature($manifest)) {
            if ($prepared['temporary']) @unlink($zip_file);
            return new WP_Error('signature_invalid', 'File không có chữ ký đúng của website thật này.');
        }
    }
    $type = sanitize_key($manifest['backup_type'] ?? 'sql');
    $checkpoint = mg_saoluu_rollback_checkpoint_context($backup_file, $manifest, $restore_files);
    if (is_wp_error($checkpoint)) {
        if ($prepared['temporary']) @unlink($zip_file);
        return $checkpoint;
    }
    $rollback_restore = !empty($checkpoint['rollback']);
    $previous_journal = $checkpoint['journal'];
    if ($restore_files && $type === 'sql') { if ($prepared['temporary']) @unlink($zip_file); return new WP_Error('files_missing', 'Bản sao này chỉ có database.'); }
    if ($restore_files && $target_env !== 'production' && $type === 'safe_full' && $source_env === 'production') {
        if ($prepared['temporary']) @unlink($zip_file);
        return new WP_Error('wrong_package', 'Hãy dùng gói “Local/Thử nghiệm” để đưa web thật về Local hoặc web thử nghiệm.');
    }

    $rollback_password = mg_saoluu_encryption_password('');
    $pre = mg_saoluu_create_backup(
        $restore_files ? 'safe_full' : 'sql',
        'Tự động trước khi khôi phục',
        'backup_pre_restore',
        false,
        ['encrypt' => $rollback_password !== '', 'password' => $rollback_password, 'upload_drive' => false, 'purpose' => 'rollback']
    );
    if (is_wp_error($pre)) { if ($prepared['temporary']) @unlink($zip_file); return new WP_Error('pre_backup_failed', 'Đã dừng vì không tạo được bản quay lại: ' . $pre->get_error_message()); }
    $pre_path = mg_saoluu_get_backup_dir() . $pre;
    $pre_hash = @hash_file('sha256', $pre_path);
    if (!$pre_hash) { if ($prepared['temporary']) @unlink($zip_file); return new WP_Error('pre_backup_hash_failed', 'Đã dừng vì không fingerprint được bản quay lại.'); }
    $stage = mg_saoluu_make_temp_dir();
    if (is_wp_error($stage)) { if ($prepared['temporary']) @unlink($zip_file); return $stage; }
    $extract = mg_saoluu_extract_to_stage($zip_file, $stage, $restore_files);
    if (is_wp_error($extract)) { mg_saoluu_remove_tree($stage); if ($prepared['temporary']) @unlink($zip_file); return $extract; }
    $new_files = $restore_files ? mg_saoluu_stage_new_files($stage) : [];
    if (is_wp_error($new_files)) { mg_saoluu_remove_tree($stage); if ($prepared['temporary']) @unlink($zip_file); return $new_files; }
    if ($restore_files) {
        $destinations = mg_saoluu_validate_restore_destinations($stage, $type);
        if (is_wp_error($destinations)) { mg_saoluu_remove_tree($stage); if ($prepared['temporary']) @unlink($zip_file); return $destinations; }
    }

    $target_home = home_url('/'); $target_site = site_url('/');
    $target_settings = mg_saoluu_capture_target_settings();
    if ($migration_approval !== null) {
        $consume = mg_saoluu_migration_consume_grant($migration_approval);
        if (is_wp_error($consume)) {
            mg_saoluu_remove_tree($stage);
            if ($prepared['temporary']) @unlink($zip_file);
            return $consume;
        }
        // The one-time nonce must survive importing the source options table.
        $target_settings['options']['mg_saoluu_used_migration_nonces'] = get_option('mg_saoluu_used_migration_nonces', []);
    }
    $source_home = (string) ($manifest['source_url'] ?? '');
    $source_site = (string) ($manifest['source_site_url'] ?? $source_home);
    $journal = ['status' => 'running', 'source' => basename($backup_file), 'pre_backup' => $pre, 'pre_backup_sha256' => strtolower($pre_hash), 'restore_files' => (bool) $restore_files, 'new_file_count' => count($new_files), 'new_files' => $new_files, 'started_at' => current_time('mysql')];
    $journal_saved = mg_saoluu_restore_journal($journal);
    if (is_wp_error($journal_saved)) {
        mg_saoluu_remove_tree($stage);
        if ($prepared['temporary']) @unlink($zip_file);
        return $journal_saved;
    }
    mg_saoluu_debug_log('restore_started',['type'=>$type,'source_env'=>$source_env,'target_env'=>$target_env,'restore_files'=>$restore_files]);
    $maintenance = mg_saoluu_maintenance(true);
    if (is_wp_error($maintenance)) {
        $journal['status'] = 'maintenance_failed';
        $journal['error_code'] = $maintenance->get_error_code();
        mg_saoluu_restore_journal($journal);
        mg_saoluu_debug_log('restore_preflight_failed', ['code' => $maintenance->get_error_code()]);
        mg_saoluu_remove_tree($stage);
        if ($prepared['temporary']) @unlink($zip_file);
        return $maintenance;
    }
    $error = null; $replace_report = ['tables' => 0, 'rows' => 0];
    try {
        $import = mg_saoluu_run_mysql_import($stage . 'database_backup.sql');
        if (is_wp_error($import)) throw new RuntimeException($import->get_error_message());
        $journal['status'] = 'database_imported';
        $journal_saved = mg_saoluu_restore_journal($journal);
        if (is_wp_error($journal_saved)) throw new RuntimeException($journal_saved->get_error_message());
        mg_saoluu_debug_log('restore_database_imported',['target_env'=>$target_env]);
        if (mg_saoluu_restore_should_change_url($change_url, $target_env, $migration_approval)) {
            if ($source_home && untrailingslashit($source_home) !== untrailingslashit($target_home)) {
                $replace_report = mg_saoluu_search_replace_database($source_home, $target_home);
                if (is_wp_error($replace_report)) throw new RuntimeException($replace_report->get_error_message());
            }
            if ($source_site && untrailingslashit($source_site) !== untrailingslashit($target_site) && untrailingslashit($source_site) !== untrailingslashit($source_home)) {
                $site_report = mg_saoluu_search_replace_database($source_site, $target_site);
                if (is_wp_error($site_report)) throw new RuntimeException($site_report->get_error_message());
            }
            update_option('home', untrailingslashit($target_home)); update_option('siteurl', untrailingslashit($target_site));
        }
        if ($restore_files) {
            $copy = mg_saoluu_copy_stage_files($stage, $type, $migration_approval !== null);
            if (is_wp_error($copy)) throw new RuntimeException($copy->get_error_message());
            $journal['status'] = 'files_copied';
            $journal_saved = mg_saoluu_restore_journal($journal);
            if (is_wp_error($journal_saved)) throw new RuntimeException($journal_saved->get_error_message());
        }
        wp_cache_flush();
        $health = mg_saoluu_restore_health_check(mg_saoluu_restore_should_change_url($change_url, $target_env, $migration_approval) ? $target_home : '');
        if (is_wp_error($health)) throw new RuntimeException($health->get_error_message());
        if ($rollback_restore) {
            $cleanup = mg_saoluu_remove_previous_restore_files($previous_journal);
            $journal['rollback_file_cleanup'] = $cleanup;
            $journal['rollback_removed_new_file_count'] = $cleanup['removed'];
            if ($cleanup['failed'] > 0) throw new RuntimeException('Không thể hoàn tất dọn file của bản quay lại.');
        }
        $journal['status'] = 'completed';
        $journal['completed_at'] = current_time('mysql');
        $journal_saved = mg_saoluu_restore_journal($journal);
        if (is_wp_error($journal_saved)) throw new RuntimeException($journal_saved->get_error_message());
        mg_saoluu_debug_log('restore_complete',['type'=>$type]);
    } catch (Throwable $e) {
        $error = new WP_Error('restore_failed', $e->getMessage());
        $journal['status'] = 'rolling_back'; $journal['error'] = sanitize_text_field($e->getMessage()); mg_saoluu_restore_journal($journal); mg_saoluu_debug_log('restore_rollback_started',['type'=>$type]);
        $rollback = mg_saoluu_rollback_from_backup($pre_path, $restore_files, $rollback_password, $target_settings);
        if (is_wp_error($rollback)) {
            $journal['status'] = 'rollback_failed'; $journal['rollback_error'] = $rollback->get_error_message(); mg_saoluu_restore_journal($journal);
            $error = new WP_Error('rollback_failed', 'Khôi phục lỗi và tự quay lại cũng lỗi: ' . $rollback->get_error_message() . '. Bản dự phòng: ' . $pre);
        } else {
            $cleanup = mg_saoluu_remove_new_restore_files($new_files);
            $journal['rollback_file_cleanup'] = $cleanup;
            if ($cleanup['failed'] > 0) {
                $journal['status'] = 'rollback_failed';
                $journal['rollback_error'] = 'Không thể dọn hết file phát sinh sau lần khôi phục lỗi.';
                mg_saoluu_restore_journal($journal);
                $error = new WP_Error('rollback_failed', 'Khôi phục lỗi; database đã quay lại nhưng không thể dọn hết file phát sinh. Bản dự phòng: ' . $pre);
            } else {
            $journal['status'] = 'rolled_back'; $journal['rolled_back_at'] = current_time('mysql');
            $rollback_record = mg_saoluu_restore_journal($journal);
            $error = is_wp_error($rollback_record)
                ? new WP_Error('rollback_journal_failed', 'Đã quay lại dữ liệu nhưng không ghi được nhật ký; phải kiểm tra thủ công.')
                : new WP_Error('restore_rolled_back', 'Khôi phục không thành công nên plugin đã tự quay lại trạng thái trước đó. Lỗi ban đầu: ' . $e->getMessage());
            mg_saoluu_log_event('restore_rolled_back', $e->getMessage(), ['pre_backup' => $pre]);
            }
        }
    } finally {
        mg_saoluu_restore_target_settings($target_settings);
        $maintenance_cleanup = mg_saoluu_maintenance(false);
        if (is_wp_error($maintenance_cleanup)) {
            $journal['maintenance_cleanup_error'] = $maintenance_cleanup->get_error_code();
            if (!$error) {
                $journal['status'] = 'maintenance_cleanup_failed';
                $error = $maintenance_cleanup;
            }
            mg_saoluu_restore_journal($journal);
            mg_saoluu_debug_log('restore_maintenance_cleanup_failed', ['code' => $maintenance_cleanup->get_error_code()]);
        }
        mg_saoluu_remove_tree($stage);
        if ($prepared['temporary']) @unlink($zip_file);
    }
    if ($error) return $error;
    return ['pre_backup' => $pre, 'manifest' => $manifest, 'replace' => $replace_report];
}

function mg_saoluu_migrate_legacy_backups() {
    $new_dir = mg_saoluu_get_backup_dir();
    $old_dir = trailingslashit(plugin_dir_path(MG_SAOLUU_FILE));
    if (wp_normalize_path($new_dir) === wp_normalize_path($old_dir)) return;
    $files = array_merge((array) glob($old_dir . 'backup_*.zip'), (array) glob($old_dir . 'backup_*.mgbackup'));
    foreach ($files as $file) {
        $target = $new_dir . basename($file);
        if (!file_exists($target)) { if (!@rename($file, $target) && @copy($file, $target)) @unlink($file); }
    }
}

add_action('admin_init', 'mg_saoluu_initialize');
function mg_saoluu_initialize() {
    if (!current_user_can('manage_options')) return;
    mg_saoluu_get_backup_dir();
    mg_saoluu_migrate_legacy_backups();
}

add_action('admin_init', 'mg_saoluu_secure_download');
function mg_saoluu_secure_download() {
    if (!isset($_GET['page'], $_GET['mg_action']) || $_GET['page'] !== 'mg-saoluu-tools' || $_GET['mg_action'] !== 'download') return;
    if (!current_user_can('manage_options')) wp_die('Bạn không có quyền tải file.');
    check_admin_referer('mg_saoluu_download');
    $name = isset($_GET['file']) ? basename(sanitize_file_name(wp_unslash($_GET['file']))) : '';
    if (!mg_saoluu_is_backup_filename($name)) wp_die('Tên file không hợp lệ.');
    $path = mg_saoluu_get_backup_dir() . $name;
    if (!is_readable($path)) wp_die('Không tìm thấy file.');
    while (ob_get_level()) ob_end_clean();
    nocache_headers();
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . (substr($name, -9) === '.mgbackup' ? 'application/octet-stream' : 'application/zip'));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    mg_saoluu_log_event('download', 'Tải bản sao', ['file' => $name]);
    readfile($path); exit;
}

function mg_saoluu_restore_raw_sql($sql_file, $source_url = '', $change_url = true) {
    if (mg_saoluu_environment() === 'production') {
        return new WP_Error('raw_sql_production_disabled', 'Không nhập SQL riêng lên production qua plugin.');
    }
    if (mg_saoluu_restore_journal_unfinished(mg_saoluu_restore_journal())) {
        return new WP_Error('restore_unfinished', 'Nhật ký restore chưa hoàn tất; không nhập SQL khác cho đến khi kiểm tra checkpoint.');
    }
    $safe = mg_saoluu_storage_preflight();
    if (is_wp_error($safe)) return $safe;
    $rollback_password = mg_saoluu_encryption_password('');
    $pre = mg_saoluu_create_backup('sql', 'Tự động trước khi nhập file SQL', 'backup_pre_restore', false, [
        'encrypt' => $rollback_password !== '', 'password' => $rollback_password, 'upload_drive' => false, 'purpose' => 'rollback'
    ]);
    if (is_wp_error($pre)) return new WP_Error('pre_backup_failed', 'Đã dừng vì không tạo được bản quay lại: ' . $pre->get_error_message());
    $target_home = home_url('/'); $target_settings = mg_saoluu_capture_target_settings();
    $maintenance = mg_saoluu_maintenance(true);
    if (is_wp_error($maintenance)) { mg_saoluu_restore_target_settings($target_settings); return $maintenance; }
    $error = null; $replace = ['tables' => 0, 'rows' => 0];
    try {
        $result = mg_saoluu_run_mysql_import($sql_file);
        if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
        if ($change_url && mg_saoluu_environment() !== 'production' && $source_url) {
            $replace = mg_saoluu_search_replace_database($source_url, $target_home);
            if (is_wp_error($replace)) throw new RuntimeException($replace->get_error_message());
            update_option('home', untrailingslashit($target_home)); update_option('siteurl', untrailingslashit(site_url('/')));
        }
        $health = mg_saoluu_restore_health_check(mg_saoluu_environment() !== 'production' ? $target_home : '');
        if (is_wp_error($health)) throw new RuntimeException($health->get_error_message());
    } catch (Throwable $e) {
        $rollback = mg_saoluu_rollback_from_backup(mg_saoluu_get_backup_dir() . $pre, false, $rollback_password, $target_settings);
        $error = is_wp_error($rollback)
            ? new WP_Error('rollback_failed', 'Nhập SQL lỗi và không thể tự quay lại. Bản dự phòng: ' . $pre . '. ' . $rollback->get_error_message())
            : new WP_Error('restore_rolled_back', 'Nhập SQL lỗi nên plugin đã tự quay lại. ' . $e->getMessage());
    } finally {
        mg_saoluu_restore_target_settings($target_settings);
        $maintenance_cleanup = mg_saoluu_maintenance(false);
        if (is_wp_error($maintenance_cleanup)) {
            mg_saoluu_debug_log('restore_maintenance_cleanup_failed', ['code' => $maintenance_cleanup->get_error_code()]);
            if (!$error) $error = $maintenance_cleanup;
        }
    }
    if ($error) return $error;
    return ['pre_backup' => $pre, 'replace' => $replace];
}
