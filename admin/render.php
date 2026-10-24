<?php
if (!defined('ABSPATH')) { exit; }
function mg_saoluu_render_setup_wizard($message = '', $status = '') {
    $step = max(1, min(5, (int) ($_GET['step'] ?? 1)));
    $env = mg_saoluu_environment();
    $auto_env = mg_saoluu_auto_environment();
    $info = mg_saoluu_environment_info($env);
    $backup_dir = '';
    $inside_web = false;
    $free = false;
    $checks = [];
    if ($step === 2) {
        $backup_dir = mg_saoluu_get_backup_dir();
        $inside_web = !mg_saoluu_storage_path_safe($backup_dir) || is_wp_error(mg_saoluu_storage_preflight());
        $free = @disk_free_space($backup_dir);
    } elseif ($step === 4) {
        $checks = mg_saoluu_system_checks();
    }
    $manual_env = get_option('mg_saoluu_environment', 'auto');
    $selected_env = in_array($manual_env, ['local', 'staging', 'production'], true) ? $manual_env : $auto_env;
    $steps = [1 => 'Môi trường', 2 => 'Nơi lưu', 3 => 'Bảo vệ', 4 => 'Kiểm tra', 5 => 'Hoàn tất'];
    $drive_notice = get_transient('mg_saoluu_drive_notice_' . get_current_user_id()); if ($drive_notice) delete_transient('mg_saoluu_drive_notice_' . get_current_user_id());
    ?>
    <div class="wrap mgsl-setup-wrap">
        <div class="mgsl-setup-head"><h1>Thiết lập MG Sao Lưu</h1><p>5 bước để plugin nhận đúng môi trường và tránh thao tác nhầm.</p></div>
        <div class="mgsl-progress"><?php foreach ($steps as $n => $label): ?><div class="<?php echo $n === $step ? 'active' : ($n < $step ? 'done' : ''); ?>"><?php echo esc_html($n . '. ' . $label); ?></div><?php endforeach; ?></div>
        <?php if ($drive_notice): ?><div class="mgsl-message <?php echo ($drive_notice['type']??'error') === 'success' ? 'success' : 'error'; ?>"><?php echo esc_html($drive_notice['message']??''); ?></div><?php endif; ?>
        <?php if ($message): ?><div class="mgsl-message <?php echo $status === 'success' ? 'success' : 'error'; ?>"><?php echo esc_html($message); ?></div><?php endif; ?>
        <div class="mgsl-setup-card">
        <?php if ($step === 1): ?>
            <h2>Website này dùng để làm gì?</h2>
            <div class="mgsl-note mgsl-warning">Plugin tự nhận là <strong><?php echo esc_html(mg_saoluu_environment_info($auto_env)['label']); ?></strong>. Anh vẫn cần xác nhận thủ công.</div>
            <form method="post">
                <?php wp_nonce_field('mg_saoluu_secure', 'mg_nonce'); ?><input type="hidden" name="mg_action" value="setup_environment">
                <div class="mgsl-field"><label>Chọn môi trường</label><select name="environment" required><option value="local" <?php selected($selected_env, 'local'); ?>>Local · máy Mac · ví dụ motgu.test</option><option value="staging" <?php selected($selected_env, 'staging'); ?>>Web thử nghiệm · ví dụ staging.motgu.com</option><option value="production" <?php selected($selected_env, 'production'); ?>>Web thật · có dữ liệu khách hàng</option></select></div>
                <?php if ($auto_env === 'production'): ?><div class="mgsl-field"><label>Nếu đây là web thử nghiệm, nhập: DAY LA WEB THU</label><input type="text" name="environment_verify" placeholder="Chỉ cần nhập khi chọn Web thử nghiệm"></div><?php endif; ?>
                <div class="mgsl-note"><strong>Địa chỉ hiện tại:</strong> <?php echo esc_html(home_url('/')); ?></div>
                <div class="mgsl-actions"><span></span><button class="button button-primary button-large">Tiếp tục</button></div>
            </form>
        <?php elseif ($step === 2): ?>
            <h2>Nơi lưu bản sao</h2>
            <div class="mgsl-setup-grid">
                <div class="mgsl-box"><h3>Thư mục đang dùng</h3><p class="mgsl-code"><?php echo esc_html($backup_dir); ?></p><p class="<?php echo is_writable($backup_dir) ? 'mgsl-ok' : 'mgsl-bad'; ?>"><?php echo is_writable($backup_dir) ? 'Có thể ghi file' : 'Không thể ghi file'; ?></p></div>
                <div class="mgsl-box"><h3>Mức an toàn</h3><p class="<?php echo !$inside_web ? 'mgsl-ok' : 'mgsl-bad'; ?>"><?php echo !$inside_web ? 'Nằm ngoài thư mục website' : 'Đang nằm trong website'; ?></p><p>Dung lượng trống: <strong><?php echo $free === false ? 'Không xác định' : esc_html(size_format($free)); ?></strong></p></div>
            </div>
            <?php if ($inside_web): ?><div class="mgsl-note mgsl-warning">Chưa có thư mục backup an toàn ngoài webroot. MG Sao Lưu v1.2.1 chặn sao lưu/khôi phục cho đến khi cấu hình nơi lưu phù hợp.</div><?php endif; ?>
            <form method="post"><?php wp_nonce_field('mg_saoluu_secure', 'mg_nonce'); ?><input type="hidden" name="mg_action" value="setup_storage"><div class="mgsl-field"><label>Giữ SQL hằng ngày</label><input type="number" name="retention" min="2" max="50" value="<?php echo esc_attr(get_option('mg_saoluu_retention', 7)); ?>"></div><div class="mgsl-field"><label>Giữ Full hằng tuần</label><input type="number" name="retention_full" min="2" max="50" value="<?php echo esc_attr(get_option('mg_saoluu_retention_full', 4)); ?>"></div><div class="mgsl-field"><label>Giữ checkpoint rollback</label><input type="number" name="retention_rollback" min="2" max="50" value="<?php echo esc_attr(get_option('mg_saoluu_retention_rollback', 3)); ?>"></div><div class="mgsl-actions"><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mg-saoluu-tools&setup=1&step=1')); ?>">Quay lại</a><button class="button button-primary button-large">Tiếp tục</button></div></form>
        <?php elseif ($step === 3): ?>
            <h2>Bảo vệ theo môi trường</h2>
            <form method="post"><?php wp_nonce_field('mg_saoluu_secure', 'mg_nonce'); ?><input type="hidden" name="mg_action" value="setup_security">
                <div class="mgsl-box"><p><label><input type="checkbox" name="block_emails" value="1" <?php checked(get_option('mg_saoluu_block_emails', '1'), '1'); ?>> Chặn email khi ở Local hoặc web thử nghiệm</label></p><p><label><input type="checkbox" name="force_noindex" value="1" <?php checked(get_option('mg_saoluu_force_noindex', '1'), '1'); ?>> Buộc Local và web thử nghiệm không cho Google lập chỉ mục</label></p></div>
                <?php if ($env === 'production'): ?><div class="mgsl-note mgsl-danger"><strong>Web thật:</strong> <?php echo strpos(MG_SAOLUU_VERSION, '-rc') !== false ? 'Bản RC khóa hoàn toàn Restore production; mã phê duyệt Local không mở khóa được.' : 'Khôi phục web thật yêu cầu thiết bị Local đã ghép nối ký phê duyệt trong 10 phút.'; ?></div><?php else: ?><div class="mgsl-note mgsl-warning"><strong>Lưu ý:</strong> plugin chặn email WordPress, nhưng không đảm bảo chặn mọi dịch vụ gửi trực tiếp. Trên web thử nghiệm vẫn cần tắt SMTP, webhook và thanh toán thật.</div><?php endif; ?>
                <div class="mgsl-actions"><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mg-saoluu-tools&setup=1&step=2')); ?>">Quay lại</a><button class="button button-primary button-large">Tiếp tục</button></div>
            </form>
        <?php elseif ($step === 4): ?>
            <h2>Kiểm tra hệ thống</h2>
            <table class="mgsl-check-table"><?php foreach ($checks as $check): ?><tr><td><?php echo esc_html($check['label']); ?></td><td class="<?php echo $check['ok'] ? 'mgsl-ok' : 'mgsl-bad'; ?>"><?php echo $check['ok'] ? 'Đạt' : 'Cần xem lại'; ?></td><td><?php echo esc_html($check['detail']); ?></td></tr><?php endforeach; ?></table>
            <form method="post"><?php wp_nonce_field('mg_saoluu_secure', 'mg_nonce'); ?><input type="hidden" name="mg_action" value="setup_test"><p><label><input type="checkbox" name="create_test_backup" value="1" checked> Tạo một bản sao database kiểm tra đã mã hóa (cần Recovery Key)</label></p><div class="mgsl-actions"><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mg-saoluu-tools&setup=1&step=3')); ?>">Quay lại</a><button class="button button-primary button-large">Chạy kiểm tra</button></div></form>
        <?php else: ?>
            <h2>Thiết lập đã sẵn sàng</h2>
            <div class="mgsl-setup-grid"><div class="mgsl-box"><h3>Môi trường</h3><p style="color:<?php echo esc_attr($info['color']); ?>;font-weight:800"><?php echo esc_html($info['label']); ?></p><p><?php echo esc_html(home_url('/')); ?></p></div><div class="mgsl-box"><h3>Quy trình an toàn</h3><p>Sao lưu → Xác minh → Lưu ngoài hosting → Thử khôi phục.</p></div></div>
            <?php if (!mg_saoluu_signing_key_is_custom()): ?><div class="mgsl-note mgsl-warning">Bản sao đang được ký bằng khóa bảo mật của WordPress. <?php echo strpos(MG_SAOLUU_VERSION, '-rc') !== false ? 'Bản RC không hỗ trợ mở khóa Restore production.' : 'Khôi phục web thật yêu cầu phê duyệt Ed25519 từ Local.'; ?></div><?php endif; ?>
            <div class="mgsl-note"><strong>Google Drive là bước tùy chọn.</strong> Có thể cấu hình ngay sau khi hoàn tất tại <strong>Cài đặt → Lịch &amp; lưu trữ → Google Drive</strong>.</div>
            <?php if (mg_saoluu_environment() === 'local'): ?><div class="mgsl-note mgsl-success"><strong>Bước bảo mật tiếp theo:</strong> vào tab <strong>Bảo mật</strong> để tạo private key Local và mã ghép nối cho Staging/web thật.</div><?php else: ?><div class="mgsl-note mgsl-success"><strong>Bước bảo mật tiếp theo:</strong> vào tab <strong>Bảo mật</strong> và nhập mã ghép nối được tạo trên Local. Môi trường này chỉ lưu public key.</div><?php endif; ?>
            <form method="post"><?php wp_nonce_field('mg_saoluu_secure', 'mg_nonce'); ?><input type="hidden" name="mg_action" value="setup_finish"><div class="mgsl-actions"><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mg-saoluu-tools&setup=1&step=4')); ?>">Quay lại</a><div><button type="submit" name="after_setup" value="overview" class="button button-large">Hoàn tất và mở tổng quan</button> <button type="submit" name="after_setup" value="drive" class="button button-primary button-large">Hoàn tất và cấu hình Google Drive</button></div></div></form>
        <?php endif; ?>
        </div>
    </div>
    <?php
}

function mg_saoluu_render_page() {
    if (!current_user_can('manage_options')) wp_die('Bạn không có quyền truy cập.');
    $message = ''; $status = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
        $status = 'error'; $message = 'File gửi lên vượt giới hạn của hosting. Hãy tăng post_max_size và upload_max_filesize hoặc dùng file nhỏ hơn.';
    } else {
        mg_saoluu_handle_actions($message, $status);
    }

    if (!mg_saoluu_setup_complete() || (isset($_GET['setup']) && (string) $_GET['setup'] === '1')) {
        mg_saoluu_render_setup_wizard($message, $status);
        return;
    }

    $tabs = [
        'overview' => 'Tổng quan', 'backup' => 'Sao lưu', 'backups' => 'Bản đã lưu',
        'restore' => 'Khôi phục', 'settings' => 'Cài đặt'
    ];
    $requested_tab = sanitize_key(wp_unslash($_POST['return_tab'] ?? $_GET['tab'] ?? 'overview'));
    $settings_section = sanitize_key(wp_unslash($_GET['section'] ?? 'schedule'));
    if (in_array($requested_tab, ['schedule', 'security', 'system'], true)) {
        $settings_section = $requested_tab; // Existing form/link remains compatible.
        $requested_tab = 'settings';
    }
    if (!in_array($settings_section, ['schedule', 'security', 'system'], true)) $settings_section = 'schedule';
    // Unknown bookmarks (including legacy transfer/deploy) land on Overview.
    $active = isset($tabs[$requested_tab]) ? $requested_tab : 'overview';
    $page_section = $active === 'settings' ? $settings_section : $active;

    // Chỉ tải dữ liệu cần cho tab đang mở. Trước đây mọi tab đều quét file,
    // tìm MySQL, đọc nhật ký và kiểm tra Google Drive nên hosting chậm rõ rệt.
    $env = mg_saoluu_environment();
    $auto_env = mg_saoluu_auto_environment();
    $info = mg_saoluu_environment_info($env);
    $manual_env = get_option('mg_saoluu_environment', 'auto');
    $block_emails = get_option('mg_saoluu_block_emails', '1') === '1';
    $force_noindex = get_option('mg_saoluu_force_noindex', '1') === '1';
    $production_unlocked = $env === 'production' && strpos(MG_SAOLUU_VERSION, '-rc') === false ? mg_saoluu_production_unlocked() : false;
    $phrase = $info['phrase'];

    $backup_dir = '';
    $metadata = [];
    $files = [];
    $total_size = 0;
    $latest = '';
    $backup_inside_web = false;
    $checks = [];
    $logs = [];
    $mysql = '';
    $dump = '';
    $drive_connected = false;
    $drive_auth_url = '';
    $retention = 0;
    $encrypt_default = false;
    $drive_auto_upload = false;
    $zip_limit = 0;
    $sql_limit = 0;
    $self_integrity = [];
    $security_summary = [];
    $security_checks = [];
    $security_output = [];
    $trusted_signers = [];
    $security_device = [];
    $restore_journal = [];
    $checkpoint_name = '';
    $checkpoint_sha = '';
    $restore_seconds = null;

    if (in_array($page_section, ['overview', 'restore', 'backups', 'schedule'], true)) {
        $backup_dir = mg_saoluu_get_backup_dir();
        $backup_inside_web = !mg_saoluu_storage_path_safe($backup_dir) || is_wp_error(mg_saoluu_storage_preflight());
    }
    if (in_array($active, ['overview', 'restore', 'backups'], true)) {
        $files = mg_saoluu_list_backup_files();
        $latest = $files ? $files[0] : '';
    }
    if ($active === 'backups') {
        $metadata = mg_saoluu_read_metadata($backup_dir . 'metadata.json');
        foreach ($files as $file) $total_size += (int) @filesize($file);
    } elseif ($active === 'overview') {
        $metadata = mg_saoluu_read_metadata($backup_dir . 'metadata.json');
        foreach ($files as $file) $total_size += (int) @filesize($file);
    }
    if (in_array($page_section, ['overview', 'system'], true)) $checks = mg_saoluu_system_checks();
    if ($page_section === 'system') {
        $mysql = mg_saoluu_find_binary('mysql');
        $dump = mg_saoluu_find_binary('mysqldump');
    }
    if (in_array($page_section, ['security','system','restore'], true)) {
        $security_summary = mg_saoluu_security_summary();
        $security_checks = mg_saoluu_security_checks();
    }
    if ($page_section === 'security') {
        $logs = get_option('mg_saoluu_audit_log', []);
        if (!is_array($logs)) $logs = [];
        $security_output = mg_saoluu_security_output_get();
        $trusted_signers = mg_saoluu_security_trusted_signers();
        $security_device = mg_saoluu_security_device();
    } elseif ($active === 'restore') {
        $security_output = mg_saoluu_security_output_get();
        $trusted_signers = mg_saoluu_security_trusted_signers();
    }
    if (in_array($page_section, ['backup', 'backups', 'schedule'], true)) {
        $drive_connected = mg_saoluu_drive_is_connected();
    }
    if ($page_section === 'schedule') {
        $drive_auth_url = mg_saoluu_drive_authorize_url();
        $retention = (int) get_option('mg_saoluu_retention', $env === 'production' ? 7 : 12);
        $encrypt_default = get_option('mg_saoluu_encrypt_default','0') === '1';
        $drive_auto_upload = get_option('mg_saoluu_drive_auto_upload','0') === '1';
    }
    if (in_array($page_section, ['restore','system'], true)) $self_integrity = mg_saoluu_self_integrity();
    if ($active === 'restore') {
        $sql_limit = min(MG_SAOLUU_MAX_SQL_UPLOAD, (int) wp_max_upload_size());
        $zip_limit = min((int) wp_max_upload_size(), 2 * 1024 * 1024 * 1024);
        $restore_journal = mg_saoluu_restore_journal();
        if (!is_array($restore_journal)) $restore_journal = [];
        $name = basename((string) ($restore_journal['pre_backup'] ?? ''));
        $hash = strtolower((string) ($restore_journal['pre_backup_sha256'] ?? ''));
        if ($name !== '' && mg_saoluu_is_backup_filename($name) &&
            preg_match('/^[a-f0-9]{64}$/', $hash) &&
            in_array($backup_dir . $name, $files, true)) {
            // Display journal linkage; the actual file hash is checked on restore.
            $checkpoint_name = $name;
            $checkpoint_sha = $hash;
        }
        if (!empty($restore_journal['started_at']) && !empty($restore_journal['completed_at'])) {
            $start = strtotime((string) $restore_journal['started_at']);
            $end = strtotime((string) $restore_journal['completed_at']);
            if ($start !== false && $end !== false && $end >= $start) $restore_seconds = $end - $start;
        }
    }

    $drive_notice = get_transient('mg_saoluu_drive_notice_' . get_current_user_id());
    if ($drive_notice) delete_transient('mg_saoluu_drive_notice_' . get_current_user_id());
    ?>
    <div class="wrap mgsl-wrap">
        <?php if ($drive_notice): ?><div class="mgsl-message <?php echo ($drive_notice['type']??'error') === 'success' ? 'success' : 'error'; ?>"><?php echo esc_html($drive_notice['message']??''); ?></div><?php endif; ?>
        <?php if ($message): ?><div class="mgsl-message <?php echo $status === 'success' ? 'success' : 'error'; ?>"><?php echo esc_html($message); ?></div><?php endif; ?>
        <section class="mgsl-card mgsl-env" style="--mgsl-env-color:<?php echo esc_attr($info['color']); ?>;--mgsl-env-bg:<?php echo esc_attr($info['bg']); ?>"><div class="mgsl-actions-separated"><div><h1><?php echo esc_html($info['label']); ?></h1><p><strong><?php echo esc_html(home_url('/')); ?></strong></p><p><?php echo esc_html($info['text']); ?></p></div><div><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mg-saoluu-tools&setup=1&step=1')); ?>">Chạy lại thiết lập</a></div></div><?php if ($env !== 'production' && $block_emails): ?><p class="mgsl-green">Email WordPress đang bị chặn.</p><?php endif; ?><?php if ($env !== 'production' && $force_noindex): ?><p class="mgsl-green">Google đang bị chặn lập chỉ mục.</p><?php endif; ?><?php if ($env === 'production'): ?><p class="<?php echo $production_unlocked ? 'mgsl-red' : 'mgsl-green'; ?>">Khôi phục web thật: <?php echo $production_unlocked ? 'ĐANG MỞ KHÓA' : 'ĐANG KHÓA'; ?></p><?php endif; ?></section>
        <nav class="nav-tab-wrapper mgsl-nav" data-mgsl-tabs><?php foreach ($tabs as $key => $label): ?><a class="nav-tab <?php echo $active === $key ? 'nav-tab-active' : ''; ?>" data-mgsl-tab="<?php echo esc_attr($key); ?>" href="<?php echo esc_url(admin_url('admin.php?page=mg-saoluu-tools&tab=' . $key)); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></nav>
        <div id="mgsl-tab-loading" class="mgsl-tab-loading" hidden>Đang tải…</div>
        <div id="mgsl-tab-content">
        <?php if ($active === 'settings'): ?>
            <nav class="mgsl-settings-nav" aria-label="Mục cài đặt sao lưu">
                <?php foreach (['schedule'=>'Lịch & lưu trữ', 'security'=>'Bảo mật & Recovery Key', 'system'=>'Kiểm tra hệ thống'] as $key=>$label): ?>
                    <a class="button <?php echo $settings_section === $key ? 'button-primary' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=mg-saoluu-tools&tab=settings&section=' . $key)); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <?php if ($active === 'overview'): ?>
            <?php
                $latest_name = $latest ? basename($latest) : '';
                $latest_meta = $latest_name ? ($metadata[$latest_name] ?? []) : [];
                $verified_archives = 0;
                $offsite_uploaded = 0;
                $restore_proven = 0;
                foreach ($files as $f) {
                    $m = $metadata[basename($f)] ?? [];
                    if (($m['integrity_status'] ?? '') === 'verified') $verified_archives++;
                    if (in_array(($m['drive_status'] ?? ''), ['uploaded','uploaded_unverified'], true)) $offsite_uploaded++;
                    if (mg_saoluu_catalog_has_recovery_proof($m)) $restore_proven++;
                }
            ?>
            <section class="mgsl-grid-2">
                <div class="mgsl-card"><h2 class="mgsl-title">Backup mới nhất</h2><strong><?php echo $latest ? esc_html(wp_date('H:i d/m/Y',filemtime($latest))) : 'Chưa có'; ?></strong><p class="mgsl-small"><?php echo esc_html($latest_name ?: 'Cần tạo bản backup đầu tiên'); ?></p></div>
                <div class="mgsl-card"><h2 class="mgsl-title">Kiểm tra toàn vẹn</h2><strong><?php echo (int) $verified_archives; ?> bản đã xác minh</strong><p class="mgsl-small">Hash + chữ ký của website hiện tại. Chưa đồng nghĩa restore thành công.</p></div>
                <div class="mgsl-card"><h2 class="mgsl-title">Offsite Google Drive</h2><strong><?php echo (int) $offsite_uploaded; ?> bản upload</strong><p class="mgsl-small">Chưa có xác minh tải ngược hoặc chống xóa từ xa.</p></div>
                <div class="mgsl-card"><h2 class="mgsl-title">Thử khôi phục</h2><strong><?php echo $restore_proven ? esc_html((string)$restore_proven . ' bản có bằng chứng') : 'Chưa có bằng chứng'; ?></strong><p class="mgsl-small">Cần một lần restore end-to-end trên WordPress/DB độc lập.</p></div>
            </section>
            <section class="mgsl-grid"><div class="mgsl-card"><div class="mgsl-muted">Bản đang lưu</div><div class="mgsl-kpi"><?php echo count($files); ?></div><div><?php echo esc_html(size_format($total_size)); ?></div></div><div class="mgsl-card"><div class="mgsl-muted">Bản gần nhất</div><div class="mgsl-kpi" style="font-size:18px"><?php echo $latest ? esc_html(wp_date('H:i d/m/Y', filemtime($latest))) : 'Chưa có'; ?></div><div><?php echo $latest ? esc_html(basename($latest)) : 'Nên tạo bản database đầu tiên'; ?></div></div><div class="mgsl-card"><div class="mgsl-muted">Lịch tự động</div><div class="mgsl-kpi" style="font-size:18px"><?php echo get_option('mg_saoluu_auto_sql','0')==='1' || get_option('mg_saoluu_auto_full','0')==='1' ? 'Đã bật' : 'Đang tắt'; ?></div><div>Database hằng ngày · Toàn bộ hằng tuần</div></div></section>
            <section class="mgsl-grid-2"><div class="mgsl-card"><h2 class="mgsl-title">Quy trình làm việc</h2><p><strong>Backup:</strong> Database + files → checksum/chữ ký → offsite.</p><p><strong>Restore:</strong> Thử trên bản sao WordPress/DB độc lập trước khi phục hồi production.</p><div class="mgsl-note mgsl-warning">Có ZIP không có nghĩa là đã thử restore. Backup full không chứa wp-config.php hoặc bí mật hosting; cần Recovery Kit riêng.</div></div><div class="mgsl-card"><h2 class="mgsl-title">Tình trạng an toàn</h2><?php foreach ($checks as $check): ?><div class="mgsl-check"><span><?php echo esc_html($check['label']); ?></span><span class="<?php echo $check['ok'] ? 'mgsl-green' : 'mgsl-orange'; ?>"><?php echo esc_html($check['detail']); ?></span></div><?php endforeach; ?></div></section>
            <?php if ($backup_inside_web): ?><div class="mgsl-card mgsl-note mgsl-warning"><strong>Bản sao đang nằm trong website.</strong> Hãy tải bản quan trọng về Mac hoặc nơi lưu riêng.</div><?php endif; ?>

        <?php elseif ($active === 'backup'): ?>
            <section class="mgsl-card"><h2 class="mgsl-title">Tạo bản sao</h2><div class="mgsl-note mgsl-warning">Backup full không gồm wp-config.php, .htaccess và Recovery Key. Để phục hồi khi mất hosting, giữ Recovery Kit riêng ngoài host. Lịch tự động bắt buộc mã hóa.</div><p>Chọn đúng mục đích. Mỗi lựa chọn chỉ có một nút riêng để tránh nhầm.</p></section>
            <section class="mgsl-grid-2">
                <div class="mgsl-choice mgsl-card"><h3>Chỉ database</h3><p>Nhanh. Dùng trước thao tác thay đổi nội dung/cấu hình. Database không chứa ảnh và file theme.</p><form method="post" onsubmit="return confirm('Tạo bản sao database?');"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="backup"><input type="hidden" name="backup_type" value="sql"><input type="hidden" name="return_tab" value="backup"><p><input type="text" name="backup_note" placeholder="Ghi chú cho bản này" style="width:100%"></p><details class="mgsl-details"><summary>Bảo vệ và lưu ngoài hosting</summary><p><label><input type="checkbox" name="encrypt_backup" value="1" <?php checked(get_option('mg_saoluu_encrypt_default','1'), '1'); ?>> Mã hóa file (Recovery Key mặc định hoặc mật khẩu riêng)</label></p><div class="mgsl-form-row two"><div class="mgsl-field"><label>Mật khẩu mã hóa</label><input type="password" name="backup_password" autocomplete="new-password" placeholder="Ít nhất 12 ký tự"></div><div class="mgsl-field"><label>Nhập lại mật khẩu</label><input type="password" name="backup_password_confirm" autocomplete="new-password"></div></div><p><label><input type="checkbox" name="upload_drive" value="1" <?php disabled(!$drive_connected); ?>> Tải lên Google Drive sau khi tạo</label><?php if(!$drive_connected): ?> <span class="mgsl-small mgsl-orange">Chưa kết nối Google Drive</span><?php endif; ?></p><div class="mgsl-note mgsl-warning mgsl-small">Plugin không lưu mật khẩu nhập thủ công. Mất mật khẩu sẽ không thể mở bản sao.</div></details><button class="button button-primary">Sao lưu database</button></form></div>
                <div class="mgsl-choice mgsl-card"><h3>Bản phục hồi website</h3><p>Database, theme, plugin và hình ảnh. Dùng trước thay đổi lớn hoặc khi cần khả năng phục hồi; không dùng để đẩy Local lên web thật.</p><form method="post" onsubmit="return confirm('Tạo bản sao toàn bộ an toàn?');"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="backup"><input type="hidden" name="backup_type" value="safe_full"><input type="hidden" name="return_tab" value="backup"><p><input type="text" name="backup_note" placeholder="Ví dụ: Trước khi cập nhật WordPress" style="width:100%"></p><details class="mgsl-details"><summary>Bảo vệ và lưu ngoài hosting</summary><p><label><input type="checkbox" name="encrypt_backup" value="1" <?php checked(get_option('mg_saoluu_encrypt_default','1'), '1'); ?>> Mã hóa file (Recovery Key mặc định hoặc mật khẩu riêng)</label></p><div class="mgsl-form-row two"><div class="mgsl-field"><label>Mật khẩu mã hóa</label><input type="password" name="backup_password" autocomplete="new-password" placeholder="Ít nhất 12 ký tự"></div><div class="mgsl-field"><label>Nhập lại mật khẩu</label><input type="password" name="backup_password_confirm" autocomplete="new-password"></div></div><p><label><input type="checkbox" name="upload_drive" value="1" <?php disabled(!$drive_connected); ?>> Tải lên Google Drive sau khi tạo</label><?php if(!$drive_connected): ?> <span class="mgsl-small mgsl-orange">Chưa kết nối Google Drive</span><?php endif; ?></p><div class="mgsl-note mgsl-warning mgsl-small">Plugin không lưu mật khẩu nhập thủ công. Mất mật khẩu sẽ không thể mở bản sao.</div></details><button class="button button-primary">Tạo bản phục hồi website</button></form></div>
            </section>
            <section class="mgsl-card"><h2>Gói Host → Local (chỉ cho clone)</h2><p>Tạo file mã hóa gồm database, themes, plugins và uploads. Giữ Recovery Key riêng; không thay thế motgu.test đang phát triển.</p>
            <form method="post"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="backup"><input type="hidden" name="backup_type" value="local_copy"><input type="hidden" name="encrypt_backup" value="1"><input type="hidden" name="return_tab" value="backup"><button class="button button-primary">Tạo gói Host → Local mã hóa</button></form></section>
            <div class="mgsl-card mgsl-note">Muốn thử khôi phục dữ liệu production trên Mac, hãy dùng bản sao WordPress/DBngin độc lập và Recovery Kit. Không restore đè motgu.test đang phát triển.</div>

        <?php elseif ($active === 'restore'): ?>
            <?php
            $last_status = (string) ($restore_journal['status'] ?? '');
            $labels = [
                'completed' => 'Đã hoàn tất', 'rolled_back' => 'Đã quay lại',
                'rollback_failed' => 'Cần xử lý lỗi',
                'maintenance_cleanup_failed' => 'Cần kiểm tra bảo trì',
                'journal_invalid' => 'Nhật ký hỏng hoặc không đọc được',
                'running' => 'Chưa hoàn tất', 'database_imported' => 'Chưa hoàn tất',
                'files_copied' => 'Chưa hoàn tất', 'rolling_back' => 'Chưa hoàn tất',
            ];
            $last_label = $labels[$last_status] ?? ($last_status === '' ? 'Chưa có lượt khôi phục' : 'Trạng thái chưa rõ, cần kiểm tra');
            $unfinished = $last_status !== '' && !in_array($last_status, ['completed', 'rolled_back'], true);
            $cleanup = $restore_journal['rollback_file_cleanup'] ?? [];
            ?>
            <section class="mgsl-card" aria-label="Trạng thái khôi phục gần nhất">
                <h2 class="mgsl-title">Lần khôi phục gần nhất</h2>
                <p><strong><?php echo esc_html($last_label); ?></strong>
                <?php if (!empty($restore_journal['completed_at'])): ?> · <?php echo esc_html($restore_journal['completed_at']); ?><?php endif; ?>
                <?php if ($restore_seconds !== null): ?> · <?php echo (int) $restore_seconds; ?> giây<?php endif; ?></p>
                <?php if ($checkpoint_name !== ''): ?>
                    <p class="mgsl-small">Checkpoint liên kết: <code><?php echo esc_html($checkpoint_name); ?></code>
                    · SHA-256 trong nhật ký: <code><?php echo esc_html(substr($checkpoint_sha, 0, 12)); ?>…</code>
                    (plugin kiểm tra đầy đủ SHA trước khi sử dụng).</p>
                <?php endif; ?>
                <?php if (is_array($cleanup) && $cleanup): ?>
                    <p class="mgsl-small">Dọn file khi rollback: <?php echo (int) ($cleanup['requested'] ?? 0); ?> yêu cầu,
                    <?php echo (int) ($cleanup['removed'] ?? 0); ?> đã xóa,
                    <?php echo (int) ($cleanup['failed'] ?? 0); ?> lỗi.</p>
                <?php endif; ?>
                <?php if ($unfinished): ?><div class="mgsl-note mgsl-danger"><strong>Khôi phục chưa kết thúc an toàn.</strong> Không chạy thêm restore cho đến khi kiểm tra Recovery Runbook và checkpoint.</div><?php endif; ?>
            </section>
            <section class="mgsl-card mgsl-note mgsl-warning"><strong>Restore là thao tác ghi dữ liệu.</strong> Luôn thử trên clone có WordPress và DB riêng trước. Có checksum chưa đồng nghĩa đã phục hồi thành công. Khi website hỏng không vào được WP Admin, làm theo RECOVERY_RUNBOOK.md trong gói plugin.</section>
            <?php if (empty($self_integrity['ok'])): ?><div class="mgsl-card mgsl-note mgsl-danger"><strong>Khôi phục đang bị khóa.</strong> File của MG Sao Lưu đang thiếu hoặc lệch phiên bản. Hãy cài lại đúng ZIP rồi kiểm tra lại.</div><?php endif; ?>
            <?php if ($env === 'production' && strpos(MG_SAOLUU_VERSION, '-rc') !== false): ?>
                <section class="mgsl-card mgsl-note mgsl-danger"><strong>Restore production bị khóa trong bản RC.</strong> Không thể mở bằng mã Local hoặc thao tác quản trị. Chỉ thử khôi phục trên WordPress clone với database riêng; khi cần phục hồi website thật, sử dụng Recovery Runbook được duyệt.</section>
            <?php elseif ($env === 'production'): ?>
                <section class="mgsl-card">
                    <div class="mgsl-actions-separated"><div><h2 class="mgsl-title">Phê duyệt khôi phục web thật</h2><p>Không cần sửa <code>wp-config.php</code>. Web thật chỉ mở Restore khi nhận chữ ký từ Local đã ghép nối.</p></div><span class="mgsl-security-pill <?php echo $production_unlocked ? 'is-warn' : 'is-pass'; ?>"><?php echo $production_unlocked ? 'ĐANG MỞ 10 PHÚT' : 'ĐANG KHÓA'; ?></span></div>
                    <?php if (!$trusted_signers): ?>
                        <div class="mgsl-note mgsl-danger"><strong>Chưa ghép nối Local.</strong> Vào tab Bảo mật để ghép nối thiết bị trước.</div>
                    <?php elseif (!$production_unlocked): ?>
                        <div class="mgsl-flow-steps"><span>1. Tạo yêu cầu</span><span>2. Ký trên Local</span><span>3. Nhập mã phê duyệt</span></div>
                        <form method="post" style="margin:14px 0"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="security_restore_challenge"><input type="hidden" name="return_tab" value="restore"><button class="button">1. Tạo mã yêu cầu Restore</button></form>
                        <?php if (($security_output['type'] ?? '') === 'restore_challenge'): ?>
                            <div class="mgsl-code-output"><label>Mã yêu cầu · sao chép sang Local → Bảo mật</label><textarea readonly rows="5"><?php echo esc_textarea($security_output['value'] ?? ''); ?></textarea></div>
                        <?php endif; ?>
                        <form method="post"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="security_apply_restore_approval"><input type="hidden" name="return_tab" value="restore"><div class="mgsl-field"><label>3. Mã phê duyệt từ Local</label><textarea name="restore_approval_code" rows="5" placeholder="MGAPPROVE1..." required></textarea></div><button class="button button-primary">Xác minh &amp; mở Restore 10 phút</button></form>
                    <?php else: $unlock_info=mg_saoluu_security_restore_unlock_info(); ?>
                        <div class="mgsl-note mgsl-danger"><strong>Restore đang được phê duyệt tạm thời.</strong> Hết hạn khoảng <?php echo esc_html(wp_date('H:i:s', (int)($unlock_info['expires_at']??time()))); ?>.</div>
                        <form method="post"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="security_lock_restore"><input type="hidden" name="return_tab" value="restore"><button class="button">Khóa lại ngay</button></form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <section class="mgsl-card"><h2 class="mgsl-title">Khôi phục từ bản đang lưu</h2><p class="mgsl-small">Không cần upload lại nếu file đã có trên máy chủ. Checkpoint liên kết được đánh dấu; vẫn phải xác nhận trước khi ghi đè.</p><?php if (!$files): ?><p>Chưa có bản sao.</p><?php else: ?><form method="post" onsubmit="return confirm('Dữ liệu hiện tại sẽ bị thay thế. Tiếp tục?');"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="restore_saved"><input type="hidden" name="return_tab" value="restore"><div class="mgsl-form-row"><div class="mgsl-field"><label>Chọn bản sao</label><select name="file_name"><?php foreach ($files as $file): ?><option value="<?php echo esc_attr(basename($file)); ?>"><?php echo esc_html(basename($file)); ?><?php if ($checkpoint_name && basename($file) === $checkpoint_name): ?> · Checkpoint liên kết<?php endif; ?></option><?php endforeach; ?></select></div><div class="mgsl-field"><label>Phần cần phục hồi</label><select name="restore_mode"><option value="database">Chỉ database</option><option value="files_database">File website + database</option></select></div><div class="mgsl-field"><label>Nhập câu xác nhận</label><input type="text" name="restore_phrase" placeholder="<?php echo esc_attr($phrase); ?>" required></div><div><button class="button button-secondary" <?php disabled(empty($self_integrity['ok']) || ($env==='production' && (!$production_unlocked || strpos(MG_SAOLUU_VERSION, '-rc') !== false))); ?> style="width:100%">Khôi phục</button></div></div><p class="mgsl-field"><label>Mật khẩu nếu bản sao đã mã hóa</label><input type="password" name="backup_password" autocomplete="current-password" placeholder="Để trống nếu bản sao không mã hóa"></p><?php if ($env === 'production'): ?><div class="mgsl-sensitive-box"><div class="mgsl-field"><label>Mật khẩu tài khoản quản trị hiện tại</label><input type="password" name="admin_password" autocomplete="current-password" required></div><div class="mgsl-field"><label>Nhập lại domain web thật</label><input type="text" name="restore_host" placeholder="<?php echo esc_attr(mg_saoluu_host()); ?>" required></div><p class="mgsl-small mgsl-muted">Ngoài chữ ký Local, thao tác vẫn yêu cầu mật khẩu admin + câu RESTORE đúng domain. Sai 5 lần sẽ khóa 30 phút.</p></div><?php endif; ?><p><label><input type="checkbox" name="change_url" value="1" <?php checked($env !== 'production'); ?>> Tự đổi tên miền nguồn thành tên miền hiện tại</label></p><p><label><input type="checkbox" name="restore_confirm" value="1" required> Tôi hiểu dữ liệu hiện tại sẽ bị thay thế</label></p></form><?php endif; ?></section>
            <?php if ($env !== 'production'): ?>
            <details class="mgsl-card"><summary><strong>Nhập .mgbackup/.zip trên clone Local/Staging</strong></summary>
            <p>Chỉ dùng clone riêng, database riêng và file nguồn đáng tin cậy. Database hiện tại bị thay thế.</p>
            <div class="mgsl-note <?php echo $zip_limit < 100 * 1024 * 1024 ? 'mgsl-warning' : ''; ?>">
                <strong>Giới hạn upload PHP hiện tại: <?php echo esc_html(size_format($zip_limit)); ?>.</strong>
                <?php if ($zip_limit < 100 * 1024 * 1024): ?> Nếu backup lớn hơn, dùng Khôi phục từ bản đang lưu hoặc chỉ chỉnh giới hạn PHP trên clone.<?php endif; ?>
            </div>
            <form method="post" enctype="multipart/form-data">
              <?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?>
              <input type="hidden" name="mg_action" value="restore_zip_upload"><input type="hidden" name="return_tab" value="restore">
              <p><label>Backup (tối đa <?php echo esc_html(size_format($zip_limit)); ?>) <input type="file" name="zip_file" accept=".mgbackup,.zip" data-mgsl-max-upload="<?php echo esc_attr((string) $zip_limit); ?>" required></label></p>
              <p><label>Recovery Key nguồn <input type="password" name="backup_password" autocomplete="off"></label></p>
              <p><label>Khôi phục <select name="restore_mode"><option value="database">Database</option><option value="files_database">Database + files</option></select></label></p>
              <p><label><input type="checkbox" name="change_url" value="1" checked>Đổi URL về tên miền clone, giữ GUID</label></p>
              <p><label>Câu xác nhận <input name="restore_phrase" required placeholder="<?php echo esc_attr($phrase); ?>"></label></p>
              <p><label><input type="checkbox" name="restore_confirm" value="1" required>Đây là clone cách ly và tôi đồng ý ghi đè database</label></p>
              <button class="button button-primary" <?php disabled(empty($self_integrity['ok'])); ?>>Khôi phục clone</button>
            </form></details>
            <details class="mgsl-card"><summary><strong>Nhập file SQL riêng · nâng cao</strong></summary><div class="mgsl-note mgsl-danger">File SQL riêng không có thông tin nguồn và không có file website. Chỉ dùng file đáng tin cậy.</div><form method="post" enctype="multipart/form-data" onsubmit="return confirm('File SQL sẽ thay database hiện tại. Tiếp tục?');"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="restore_sql_upload"><input type="hidden" name="return_tab" value="restore"><div class="mgsl-form-row"><div class="mgsl-field"><label>File SQL · tối đa <?php echo esc_html(size_format($sql_limit)); ?></label><input type="file" name="sql_file" accept=".sql,text/plain" required></div><div class="mgsl-field"><label>Địa chỉ nguồn</label><input type="text" name="source_url" placeholder="https://motgu.com"></div><div class="mgsl-field"><label>Câu xác nhận</label><input type="text" name="restore_phrase" placeholder="<?php echo esc_attr($phrase); ?>" required></div><div><button class="button" style="width:100%">Nhập SQL</button></div></div><p><label><input type="checkbox" name="change_url" value="1" checked> Đổi địa chỉ nguồn thành địa chỉ hiện tại</label></p><p><label><input type="checkbox" name="restore_confirm" value="1" required> Tôi hiểu database hiện tại sẽ bị thay thế</label></p></form></details><?php endif; ?>

        <?php elseif ($active === 'backups'): ?>
            <section class="mgsl-card"><div class="mgsl-actions-separated"><div><h2 class="mgsl-title">Các bản đang lưu</h2><p><?php echo count($files); ?> bản · <?php echo esc_html(size_format($total_size)); ?></p></div><div class="mgsl-small mgsl-code"><?php echo esc_html($backup_dir); ?></div></div><?php if (!$files): ?><p>Chưa có bản sao.</p><?php else: ?><table class="mgsl-table"><thead><tr><th>Thời gian</th><th>Loại · nguồn</th><th>Ghi chú</th><th>Dung lượng</th><th>Thao tác an toàn</th></tr></thead><tbody><?php foreach ($files as $file): $name=basename($file); $m=$metadata[$name]??[]; $type=$m['type']??'không rõ'; $labels=['sql'=>'Database','safe_full'=>'Toàn bộ an toàn','local_copy'=>'Host → Local']; $encrypted=!empty($m['encrypted']) || substr($name,-9)==='.mgbackup'; $drive_status=$m['drive_status']??'not_uploaded'; $download=wp_nonce_url(admin_url('admin.php?page=mg-saoluu-tools&mg_action=download&file='.rawurlencode($name)),'mg_saoluu_download'); ?><tr><td><?php echo esc_html(wp_date('H:i d/m/Y',filemtime($file))); ?></td><td><span class="mgsl-badge"><?php echo esc_html($labels[$type]??$type); ?></span><br><span class="mgsl-small"><?php echo esc_html($m['source_environment']??'bản cũ'); ?> · <?php echo esc_html($m['source_url']??''); ?></span><?php if($encrypted):?><br><span class="mgsl-badge">Đã mã hóa</span><?php endif; ?><?php if(($m['integrity_status']??'')==='verified'):?><br><span class="mgsl-green">Đã kiểm tra checksum và chữ ký</span><?php elseif(($m['integrity_status']??'')==='content_hash_only'):?><br><span class="mgsl-orange">Chỉ kiểm tra nội dung, chưa xác thực nguồn</span><?php else:?><br><span class="mgsl-orange">Chưa kiểm chứng bản sao</span><?php endif; ?><br><span class="mgsl-small">Restore test: <?php echo mg_saoluu_catalog_has_recovery_proof($m)?'Đã có bằng chứng':'Chưa chứng minh'; ?></span><?php if(in_array($drive_status, ['uploaded','uploaded_unverified'], true)):?><br><span class="mgsl-green">Đã upload Drive · chưa kiểm chứng tải lại</span><?php elseif($drive_status==='failed'):?><br><span class="mgsl-orange">Tải Drive thất bại</span><?php endif; ?><?php if(!empty($m['protected'])):?><br><span class="mgsl-green">Đang giữ lại</span><?php endif; ?></td><td><?php echo esc_html($m['note']??'Không có ghi chú'); ?></td><td><?php echo esc_html(size_format(filesize($file))); ?></td><td><div class="mgsl-actions"><a class="button button-small" href="<?php echo esc_url($download); ?>">Tải về</a><form method="post"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="verify_backup"><input type="hidden" name="return_tab" value="backups"><input type="hidden" name="file_name" value="<?php echo esc_attr($name); ?>"><input type="password" name="backup_password" placeholder="Mật khẩu nếu mã hóa" style="max-width:145px"><button class="button button-small">Kiểm tra</button></form><form method="post"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="toggle_protect"><input type="hidden" name="return_tab" value="backups"><input type="hidden" name="file_name" value="<?php echo esc_attr($name); ?>"><button class="button button-small"><?php echo !empty($m['protected'])?'Bỏ giữ':'Giữ lại'; ?></button></form><?php if($drive_connected && !in_array($drive_status, ['uploaded','uploaded_unverified'], true)):?><form method="post"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="drive_upload"><input type="hidden" name="return_tab" value="backups"><input type="hidden" name="file_name" value="<?php echo esc_attr($name); ?>"><button class="button button-small">Gửi Drive</button></form><?php endif; ?></div><details class="mgsl-details"><summary>Thao tác khác</summary><div class="mgsl-danger-zone"><p>Xóa không thể hoàn tác. Nên tải bản về trước.</p><form method="post" onsubmit="return confirm('Xóa vĩnh viễn bản sao này?');"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="delete"><input type="hidden" name="return_tab" value="backups"><input type="hidden" name="file_name" value="<?php echo esc_attr($name); ?>"><button class="button">Xóa bản này</button></form></div></details></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>

        <?php elseif ($page_section === 'schedule'): ?>
            <section class="mgsl-card"><h2 class="mgsl-title">Lịch sao lưu</h2><div class="mgsl-note mgsl-warning">WP-Cron có thể chạy muộn nếu website ít truy cập. Với cPanel, nên thêm lịch hệ thống gọi wp-cron.php.</div><form method="post"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="save_storage"><input type="hidden" name="return_tab" value="schedule"><p><label><input type="checkbox" name="auto_sql" value="1" <?php checked(get_option('mg_saoluu_auto_sql','0'),'1'); ?>> Tự sao lưu database mỗi ngày</label></p><p><label><input type="checkbox" name="auto_full" value="1" <?php checked(get_option('mg_saoluu_auto_full','0'),'1'); ?>> Tự sao lưu toàn bộ an toàn mỗi tuần</label></p><p><label><input type="checkbox" name="encrypt_default" value="1" <?php checked($encrypt_default); ?>> Mã hóa các bản tự động bằng Recovery Key</label></p><p><label><input type="checkbox" name="drive_auto_upload" value="1" <?php checked($drive_auto_upload); ?> <?php disabled(!$drive_connected); ?>> Tự gửi bản mới lên Google Drive</label></p><div class="mgsl-form-row"><div class="mgsl-field"><label>SQL: số bản giữ</label><input type="number" name="retention" min="2" max="50" value="<?php echo esc_attr($retention); ?>"></div><div class="mgsl-field"><label>Full: số bản giữ</label><input type="number" name="retention_full" min="2" max="50" value="<?php echo esc_attr(get_option('mg_saoluu_retention_full', 4)); ?>"></div><div class="mgsl-field"><label>Rollback: số bản giữ</label><input type="number" name="retention_rollback" min="2" max="50" value="<?php echo esc_attr(get_option('mg_saoluu_retention_rollback', 3)); ?>"></div></div><button class="button button-primary">Lưu lịch</button></form></section>
            <section class="mgsl-grid-2"><div class="mgsl-card"><h2 class="mgsl-title">Nơi lưu trên hosting</h2><p class="mgsl-code"><?php echo esc_html($backup_dir); ?></p><p><?php echo $backup_inside_web?'<span class="mgsl-orange">Nằm trong website</span>':'<span class="mgsl-green">Nằm ngoài website</span>'; ?></p></div><div class="mgsl-card"><h2 class="mgsl-title">Mã hóa tự động</h2><p><?php echo mg_saoluu_encryption_key_available()?'<span class="mgsl-green">✓ Recovery Key đã sẵn sàng</span>':'<span class="mgsl-orange">Chưa tạo được Recovery Key</span>'; ?></p><p class="mgsl-small">Bản v1.2.1 vẫn cần xuất Recovery Key ra password manager, không cần sửa <code>wp-config.php</code>. Vào Cài đặt → Bảo mật để xuất một bản Recovery Key và lưu trong password manager.</p></div></section>
            <section class="mgsl-card"><h2 class="mgsl-title">Google Drive</h2><div class="mgsl-note <?php echo $drive_connected?'mgsl-success':'mgsl-warning'; ?>"><?php echo $drive_connected?'Đã kết nối. Plugin dùng quyền chỉ quản lý file do plugin tạo.':'Chưa kết nối. Cần tạo OAuth Client loại Web application trong Google Cloud.'; ?></div><form method="post"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="save_drive_settings"><input type="hidden" name="return_tab" value="schedule"><div class="mgsl-form-row"><div class="mgsl-field"><label>Google Client ID</label><input type="text" name="drive_client_id" value="<?php echo esc_attr(defined('MG_SAOLUU_GOOGLE_CLIENT_ID')?'Đã đặt trong wp-config.php':get_option('mg_saoluu_drive_client_id','')); ?>" <?php disabled(defined('MG_SAOLUU_GOOGLE_CLIENT_ID')); ?>></div><div class="mgsl-field"><label>Google Client Secret</label><input type="password" name="drive_client_secret" placeholder="Để trống nếu không đổi" <?php disabled(defined('MG_SAOLUU_GOOGLE_CLIENT_SECRET')); ?>></div><div class="mgsl-field"><label>Folder ID · có thể để trống</label><input type="text" name="drive_folder_id" value="<?php echo esc_attr(get_option('mg_saoluu_drive_folder_id','')); ?>" placeholder="Plugin sẽ tự tạo thư mục"></div><div><button class="button">Lưu cấu hình</button></div></div><p class="mgsl-small"><strong>Redirect URI cần khai báo ở Google:</strong> <code><?php echo esc_html(mg_saoluu_drive_redirect_uri()); ?></code></p></form><div class="mgsl-actions"><?php if(!$drive_connected && $drive_auth_url):?><a class="button button-primary" href="<?php echo esc_url($drive_auth_url); ?>">Kết nối Google Drive</a><?php elseif($drive_connected):?><form method="post"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="drive_test"><input type="hidden" name="return_tab" value="schedule"><button class="button button-primary">Kiểm tra kết nối</button></form><form method="post" onsubmit="return confirm('Ngắt kết nối Google Drive?');"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="drive_disconnect"><input type="hidden" name="return_tab" value="schedule"><button class="button">Ngắt kết nối</button></form><?php endif; ?></div></section>

        <?php elseif ($page_section === 'security'): ?>
            <section class="mgsl-security-hero <?php echo esc_attr('is-' . ($security_summary['status'] ?? 'warn')); ?>">
                <div>
                    <div class="mgsl-kicker">Security Center</div>
                    <h2><?php echo esc_html($security_summary['label'] ?? 'Đang kiểm tra'); ?></h2>
                    <p><?php echo (int)($security_summary['pass'] ?? 0); ?> kiểm tra đạt · <?php echo (int)($security_summary['warn'] ?? 0); ?> cần cải thiện · <?php echo (int)($security_summary['fail'] ?? 0); ?> lỗi quan trọng</p>
                </div>
                <div class="mgsl-security-score"><?php echo (int)($security_summary['pass'] ?? 0); ?><small>đạt</small></div>
            </section>

            <?php if (!empty($security_summary['issues'])): ?>
                <section class="mgsl-card">
                    <h2 class="mgsl-title">Cần xử lý</h2>
                    <div class="mgsl-security-list">
                        <?php foreach ($security_summary['issues'] as $check): ?>
                            <div class="mgsl-security-row <?php echo $check['status'] === 'fail' ? 'is-fail' : 'is-warn'; ?>">
                                <span class="mgsl-security-icon"><?php echo $check['status'] === 'fail' ? '!' : '⚠'; ?></span>
                                <div><strong><?php echo esc_html($check['label']); ?></strong><p><?php echo esc_html($check['detail']); ?></p></div>
                                <?php if (($check['action'] ?? '') === 'schedule'): ?><a class="button button-small" data-mgsl-tab="schedule" href="<?php echo esc_url(admin_url('admin.php?page=mg-saoluu-tools&tab=schedule')); ?>">Xử lý</a><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php else: ?>
                <section class="mgsl-card mgsl-note mgsl-success"><strong>✓ Không có cảnh báo bảo mật cần xử lý.</strong></section>
            <?php endif; ?>

            <?php if (!empty($security_output) && (strpos(MG_SAOLUU_VERSION, '-rc') === false || ($security_output['type'] ?? '') !== 'restore_approval')): ?>
                <section class="mgsl-card mgsl-code-output">
                    <?php if (($security_output['type'] ?? '') === 'pair_code'): ?>
                        <h2 class="mgsl-title">Mã ghép nối Local</h2><p>Mã hết hạn sau 30 phút. Sao chép sang <strong>web thật → Bảo mật → Ghép nối Local</strong>.</p>
                    <?php elseif (($security_output['type'] ?? '') === 'restore_approval'): ?>
                        <h2 class="mgsl-title">Mã phê duyệt Restore</h2><p>Sao chép mã này trở lại web thật. Mã chỉ dùng cho đúng yêu cầu Restore vừa nhận.</p>
                    <?php elseif (($security_output['type'] ?? '') === 'recovery_key'): ?>
                        <h2 class="mgsl-title">Recovery Key</h2><div class="mgsl-note mgsl-danger"><strong>Chỉ hiển thị tạm thời.</strong> Lưu vào password manager. Không gửi qua email/chat và không để trong Git.</div>
                    <?php endif; ?>
                    <textarea readonly rows="5" onclick="this.select()"><?php echo esc_textarea($security_output['value'] ?? ''); ?></textarea>
                </section>
            <?php endif; ?>

            <section class="mgsl-grid-2">
                <?php if ($env === 'local'): ?>
                    <div class="mgsl-card">
                        <div class="mgsl-actions-separated"><div><h2 class="mgsl-title">Thiết bị phát triển</h2><p>Private key chỉ nằm ở môi trường này. Web thật chỉ nhận public key.</p></div><span class="mgsl-security-pill <?php echo mg_saoluu_security_device_ready() ? 'is-pass' : 'is-warn'; ?>"><?php echo mg_saoluu_security_device_ready() ? '✓ Sẵn sàng' : 'Chưa tạo'; ?></span></div>
                        <?php if (mg_saoluu_security_device_ready()): ?>
                            <div class="mgsl-key-card"><strong><?php echo esc_html($security_device['name'] ?? 'Máy phát triển'); ?></strong><span>Fingerprint: <?php echo esc_html($security_device['fingerprint'] ?? ''); ?></span><span>Tạo: <?php echo esc_html($security_device['created_at'] ?? ''); ?></span></div>
                            <div class="mgsl-actions">
                                <form method="post"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="security_pair_code"><input type="hidden" name="return_tab" value="security"><button class="button button-primary">Tạo mã ghép nối</button></form>
                                <form method="post" onsubmit="return confirm('Tạo private key mới sẽ làm các web đã ghép khóa cũ không còn tin cậy. Tiếp tục?');"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="security_generate_device"><input type="hidden" name="return_tab" value="security"><input type="hidden" name="rotate_device_key" value="1"><button class="button">Đổi khóa thiết bị</button></form>
                            </div>
                        <?php else: ?>
                            <form method="post"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="security_generate_device"><input type="hidden" name="return_tab" value="security"><div class="mgsl-field"><label>Tên thiết bị</label><input type="text" name="device_name" placeholder="MacBook của Dũng"></div><button class="button button-primary">Tạo khóa thiết bị</button></form>
                        <?php endif; ?>
                    </div>
                    <?php if (strpos(MG_SAOLUU_VERSION, '-rc') === false): ?>
                    <div class="mgsl-card">
                        <h2 class="mgsl-title">Ký yêu cầu Restore</h2>
                        <p>Khi web thật cần Restore, sao chép mã <code>MGRESTORE1...</code> từ web thật sang đây. Local sẽ ký bằng private key.</p>
                        <form method="post"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="security_sign_restore_challenge"><input type="hidden" name="return_tab" value="security"><div class="mgsl-field"><label>Mã yêu cầu từ web thật</label><textarea name="restore_challenge_code" rows="5" placeholder="MGRESTORE1..." required></textarea></div><button class="button button-primary" <?php disabled(!mg_saoluu_security_device_ready()); ?>>Ký yêu cầu</button></form>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="mgsl-card">
                        <div class="mgsl-actions-separated"><div><h2 class="mgsl-title">Ghép nối Local</h2><p>Web thật chỉ lưu public key. Không cần DEPLOY_KEY hoặc chỉnh wp-config.php.</p></div><span class="mgsl-security-pill <?php echo $trusted_signers ? 'is-pass' : 'is-warn'; ?>"><?php echo $trusted_signers ? '✓ ' . count($trusted_signers) . ' thiết bị' : 'Chưa ghép'; ?></span></div>
                        <form method="post"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="security_import_pair"><input type="hidden" name="return_tab" value="security"><div class="mgsl-field"><label>Mã ghép nối từ Local</label><textarea name="pair_code" rows="5" placeholder="MGPAIR1..." required></textarea></div><div class="mgsl-sensitive-box"><div class="mgsl-field"><label>Mật khẩu admin hiện tại</label><input type="password" name="admin_password" autocomplete="current-password" required></div><div class="mgsl-form-row two"><div class="mgsl-field"><label>Câu xác nhận</label><input type="text" name="pair_phrase" placeholder="GHEP NOI <?php echo esc_attr(strtoupper(mg_saoluu_host())); ?>" required></div><div class="mgsl-field"><label>Domain hiện tại</label><input type="text" name="pair_host" placeholder="<?php echo esc_attr(mg_saoluu_host()); ?>" required></div></div></div><button class="button button-primary">Ghép nối thiết bị</button></form>
                    </div>
                    <div class="mgsl-card">
                        <h2 class="mgsl-title">Thiết bị được tin cậy</h2>
                        <?php if (!$trusted_signers): ?><p>Chưa có thiết bị Local nào.</p><?php else: ?><div class="mgsl-trusted-list"><?php foreach ($trusted_signers as $device_id => $signer): ?><div class="mgsl-trusted-item"><div><strong><?php echo esc_html($signer['name'] ?? 'Local'); ?></strong><span><?php echo esc_html($signer['fingerprint'] ?? ''); ?></span><small>Ghép: <?php echo esc_html($signer['paired_at'] ?? ''); ?></small></div><form method="post" onsubmit="return confirm('Thu hồi quyền thiết bị này?');"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="security_revoke_signer"><input type="hidden" name="return_tab" value="security"><input type="hidden" name="device_id" value="<?php echo esc_attr($device_id); ?>"><button class="button button-small">Thu hồi</button></form></div><?php endforeach; ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="mgsl-grid-2">
                <div class="mgsl-card">
                    <div class="mgsl-actions-separated"><div><h2 class="mgsl-title">Recovery Key</h2><p>Dùng để mã hóa bản sao tự động. Plugin tự quản lý, không cần thêm khóa vào hosting.</p></div><span class="mgsl-security-pill <?php echo mg_saoluu_encryption_key_available() ? 'is-pass' : 'is-warn'; ?>"><?php echo mg_saoluu_encryption_key_available() ? '✓ Đã có' : 'Chưa có'; ?></span></div>
                    <div class="mgsl-note"><strong>Nên làm một lần:</strong> hiển thị khóa, lưu vào password manager rồi đóng trang. Khóa không được ghi vào file backup.</div>
                    <form method="post" class="mgsl-inline-sensitive"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="security_show_recovery_key"><input type="hidden" name="return_tab" value="security"><div class="mgsl-field"><label>Mật khẩu admin hiện tại</label><input type="password" name="admin_password" autocomplete="current-password" required></div><button class="button">Hiển thị Recovery Key</button></form>
                    <details class="mgsl-details" style="margin-top:12px"><summary><strong>Khôi phục Recovery Key đã lưu</strong></summary><form method="post" style="margin-top:12px"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="security_import_recovery_key"><input type="hidden" name="return_tab" value="security"><div class="mgsl-field"><label>Recovery Key từ password manager</label><textarea name="recovery_key" rows="3" required></textarea></div><div class="mgsl-field"><label>Mật khẩu admin hiện tại</label><input type="password" name="admin_password" autocomplete="current-password" required></div><button class="button">Khôi phục khóa</button></form></details>
                </div>
                <div class="mgsl-card">
                    <h2 class="mgsl-title">Bảo vệ WordPress</h2>
                    <div class="mgsl-security-list compact">
                        <?php foreach (['https','file_editor','debug_display','backup_access'] as $sid): if (empty($security_checks[$sid])) continue; $check=$security_checks[$sid]; ?><div class="mgsl-security-row <?php echo $check['status']==='pass'?'is-pass':($check['status']==='fail'?'is-fail':'is-warn'); ?>"><span class="mgsl-security-icon"><?php echo $check['status']==='pass'?'✓':($check['status']==='fail'?'!':'⚠'); ?></span><div><strong><?php echo esc_html($check['label']); ?></strong><p><?php echo esc_html($check['detail']); ?></p></div></div><?php endforeach; ?>
                    </div>
                    <p class="mgsl-small mgsl-muted">Chỉ số kiểm tra kỹ thuật không thay cho một lần khôi phục thử thành công.</p>
                </div>
            </section>

            <?php if (!empty($security_summary['passed'])): ?>
                <details class="mgsl-card mgsl-details"><summary><strong>✓ <?php echo count($security_summary['passed']); ?> kiểm tra đã đạt</strong></summary><div class="mgsl-security-list compact" style="margin-top:12px"><?php foreach ($security_summary['passed'] as $check): ?><div class="mgsl-security-row is-pass"><span class="mgsl-security-icon">✓</span><div><strong><?php echo esc_html($check['label']); ?></strong><p><?php echo esc_html($check['detail']); ?></p></div></div><?php endforeach; ?></div></details>
            <?php endif; ?>

            <details class="mgsl-card mgsl-details"><summary><strong>Nhật ký bảo mật</strong></summary><?php if (!$logs): ?><p>Chưa có nhật ký.</p><?php else: ?><table class="mgsl-table mgsl-log"><thead><tr><th>Thời gian</th><th>Người dùng</th><th>Sự kiện</th><th>Chi tiết</th></tr></thead><tbody><?php foreach (array_slice(array_reverse($logs),0,80) as $row): ?><tr><td><?php echo esc_html($row['time']??''); ?></td><td><?php echo esc_html($row['user']??''); ?></td><td><?php echo esc_html($row['event']??''); ?></td><td><?php echo esc_html($row['message']??''); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></details>

        <?php else: ?>
            <section class="mgsl-card"><div class="mgsl-actions-separated"><div><h2 class="mgsl-title">Hệ thống</h2><p>Chỉ hiển thị khả năng vận hành. Kiểm tra bảo mật chi tiết nằm trong Cài đặt → Bảo mật.</p></div><a class="mgsl-security-pill <?php echo !empty($security_summary['fail'])?'is-fail':(!empty($security_summary['warn'])?'is-warn':'is-pass'); ?>" data-mgsl-tab="security" href="<?php echo esc_url(admin_url('admin.php?page=mg-saoluu-tools&tab=security')); ?>">Bảo mật: <?php echo esc_html($security_summary['label'] ?? ''); ?></a></div></section>
            <section class="mgsl-card"><h2 class="mgsl-title">Kiểm tra nhanh</h2><?php $system_keys=['zip','storage','outside_web','space','database','plugin_integrity','acf','multisite']; foreach($system_keys as $key): if(empty($checks[$key])) continue; $check=$checks[$key]; ?><div class="mgsl-check"><span><strong><?php echo esc_html($check['label']); ?></strong></span><span class="<?php echo $check['ok']?'mgsl-green':'mgsl-orange'; ?>"><?php echo $check['ok']?'✓ ':''; ?><?php echo esc_html($check['detail']); ?></span></div><?php endforeach;?><form method="post" style="margin-top:15px"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="test_tools"><input type="hidden" name="return_tab" value="system"><button class="button button-primary">Kiểm tra khả năng sao lưu database</button></form></section>
            <section class="mgsl-card"><h2 class="mgsl-title">File plugin</h2><?php if (!empty($self_integrity['ok'])): ?><p class="mgsl-green">✓ MG Sao Lưu v<?php echo esc_html(MG_SAOLUU_VERSION); ?> đầy đủ.</p><?php else: ?><div class="mgsl-note mgsl-danger"><strong>Plugin đang thiếu hoặc lệch file.</strong> Restore bị chặn cho đến khi cài lại đúng ZIP.</div><?php endif; ?><div class="mgsl-check"><span>CSS admin</span><span><?php $a=$self_integrity['assets']['assets/css/admin.css']??[]; echo !empty($a['readable'])?'<span class="mgsl-green">✓ '.esc_html(size_format((int)$a['size'])).'</span>':'<span class="mgsl-red">Thiếu/không đọc được</span>'; ?></span></div><div class="mgsl-check"><span>JavaScript admin</span><span><?php $a=$self_integrity['assets']['assets/js/admin.js']??[]; echo !empty($a['readable'])?'<span class="mgsl-green">✓ '.esc_html(size_format((int)$a['size'])).'</span>':'<span class="mgsl-red">Thiếu/không đọc được</span>'; ?></span></div><?php if(!empty($self_integrity['missing'])):?><p><strong>Thiếu:</strong> <?php echo esc_html(implode(', ',$self_integrity['missing'])); ?></p><?php endif; ?><?php if(!empty($self_integrity['mismatch'])):?><p><strong>Sai phiên bản:</strong> <?php echo esc_html(implode(', ',$self_integrity['mismatch'])); ?></p><?php endif; ?></section>
            <section class="mgsl-card"><h2>Debug backup/restore</h2>
            <p><?php echo mg_saoluu_debug_enabled() ? 'Log đang bật (Local/Staging)' : 'Log đang tắt (Production)'; ?>. Nhật ký JSONL giới hạn dữ liệu, lưu ngoài webroot.</p>
            <?php if (mg_saoluu_debug_enabled()): ?>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=mg-saoluu-tools&mg_action=download_debug'),'mg_saoluu_debug_download')); ?>">Tải debug JSONL</a>
            <details><summary>50 sự kiện gần nhất</summary><pre style="white-space:pre-wrap;max-height:250px;overflow:auto"><?php echo esc_html(implode("\n",mg_saoluu_debug_tail())); ?></pre></details>
            <?php endif; ?></section>
            <details class="mgsl-card"><summary><strong>Công cụ MySQL · chỉ chỉnh khi tự nhận sai</strong></summary><form method="post" style="margin-top:16px"><?php wp_nonce_field('mg_saoluu_secure','mg_nonce'); ?><input type="hidden" name="mg_action" value="save_tools"><input type="hidden" name="return_tab" value="system"><div class="mgsl-form-row two"><div class="mgsl-field"><label>Đường dẫn mysql</label><input type="text" name="mysql_binary" value="<?php echo esc_attr(get_option('mg_saoluu_mysql_binary','')); ?>" placeholder="Để trống để tự tìm"></div><div class="mgsl-field"><label>Đường dẫn mysqldump</label><input type="text" name="mysqldump_binary" value="<?php echo esc_attr(get_option('mg_saoluu_mysqldump_binary','')); ?>" placeholder="Để trống để tự tìm"></div></div><button class="button">Lưu đường dẫn</button></form><hr><p><strong>Nhập database:</strong> <?php echo $mysql?'<span class="mgsl-green">mysql</span> · '.esc_html($mysql):'<span class="mgsl-red">Không có — Backup/Restore bị chặn</span>'; ?></p><p><strong>Sao lưu database:</strong> <?php echo $dump?'<span class="mgsl-green">mysqldump</span> · '.esc_html($dump):'<span class="mgsl-red">Không có — Backup/Restore bị chặn</span>'; ?></p></details>
        <?php endif; ?>
        <p class="mgsl-credit">MG Sao Lưu v<?php echo esc_html(MG_SAOLUU_VERSION); ?> · Built by Thiên Dũng</p>
        </div>
    </div>
    <?php
}

