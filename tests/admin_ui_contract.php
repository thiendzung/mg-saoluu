<?php
/** v1.3.2-rc2 simple Backup/Restore static contracts; no WordPress bootstrap. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$plugin = rtrim((string)(getenv('MGSL_TEST_PLUGIN_DIR') ?: dirname(__DIR__)), '/');
$count = 0;
function ui_check($name, $ok) {
    global $count; $count++;
    if (!$ok) { fwrite(STDERR, "FAIL $name\n"); exit(1); }
    echo "PASS $name\n";
}
function src($plugin, $path) {
    $data = file_get_contents($plugin . '/' . $path);
    if (!is_string($data)) { fwrite(STDERR, "FAIL read $path\n"); exit(1); }
    return $data;
}

$main = src($plugin, 'mg-saoluu.php');
$lifecycle = src($plugin, 'includes/lifecycle.php');
$backup = src($plugin, 'includes/backup.php');
$restore = src($plugin, 'includes/restore.php');
$environment = src($plugin, 'includes/environment.php');
$diagnostics = src($plugin, 'includes/diagnostics.php');
$actions = src($plugin, 'admin/actions.php');
$render = src($plugin, 'admin/render.php');
$recovery = src($plugin, 'admin/recovery-key.php');
$js = src($plugin, 'assets/js/admin.js');
$css = src($plugin, 'assets/css/admin.css');

ui_check('v1.3.2-rc2 version is explicit',
    str_contains($main, "Version: 1.3.2-rc2")
    && str_contains($main, "define('MG_SAOLUU_VERSION', '1.3.2-rc2')"));

ui_check('new install skips environment/security wizard',
    str_contains($lifecycle, "add_option('mg_saoluu_setup_complete', '1'")
    && !str_contains($lifecycle, "set_transient('mg_saoluu_activation_redirect', 1, 60)"));

$crypto = src($plugin, 'includes/crypto.php');
ui_check('activation and version upgrade never create missing Recovery Keys',
    !str_contains($lifecycle, 'mg_saoluu_managed_encryption_key(true)')
    && str_contains($lifecycle, 'function mg_saoluu_activate()')
    && str_contains($lifecycle, 'function mg_saoluu_maybe_upgrade()'));

ui_check('default encryption password requires an existing key',
    str_contains($crypto, 'return mg_saoluu_managed_encryption_key(false);')
    && !str_contains($crypto, 'return mg_saoluu_managed_encryption_key(true);'));

ui_check('active UI has exactly the simple three-tab product surface',
    str_contains($render, "['backup' => 'Sao lưu', 'restore' => 'Khôi phục', 'backups' => 'Bản đã lưu']")
    && str_contains($render, 'mg_saoluu_render_simple_page();')
    && str_contains($render, 'Full Backup → Full Restore'));

ui_check('three-tab POST dispatch denies all hidden/unknown actions before handlers',
    str_contains($actions, "['backup', 'restore_saved', 'restore_zip_upload']")
    && ($gate = strpos($actions, 'if (!mg_saoluu_simple_post_action_allowed($action))')) !== false
    && ($legacy = strpos($actions, "if (\$action === 'setup_environment')")) !== false
    && $gate < $legacy
    && ($debug = strpos($actions, "mg_saoluu_debug_log('action_received'")) !== false
    && $gate < $debug
    && str_contains($actions, 'is_string($submitted_action)'));

ui_check('simple backup action always creates safe_full without Pack mode',
    str_contains($actions, "mg_saoluu_create_backup('safe_full'")
    && str_contains($actions, "'encrypt' => false")
    && str_contains($actions, "'upload_drive' => false"));

ui_check('legacy Pack signer SQL restore actions are backend-blocked',
    str_contains($actions, "'pack_backup','pack_restore','pack_restore_upload','verify_restore_upload','restore_sql_upload'")
    && str_contains($actions, "'security_restore_challenge','security_sign_restore_challenge','security_apply_restore_approval'"));

ui_check('environment modes have no active admin email or noindex side effects',
    !str_contains($environment, "add_action('admin_bar_menu', 'mg_saoluu_admin_bar_environment'")
    && !str_contains($environment, "add_filter('pre_wp_mail'")
    && !str_contains($lifecycle, "add_filter('pre_option_blog_public'")
    && str_contains($actions, "'save_security','save_settings'"));

ui_check('diagnostics are explicit opt-in and do not auto-enable by environment',
    str_contains($diagnostics, "defined('MG_SAOLUU_DEBUG') && MG_SAOLUU_DEBUG === true")
    && !str_contains($diagnostics, "mg_saoluu_environment() !== 'production'"));

ui_check('restore action requires only explicit checkbox plus plugin integrity',
    str_contains($actions, "if (!isset(\$_POST['restore_confirm']))")
    && str_contains($actions, 'mg_saoluu_self_integrity()')
    && !str_contains(substr($actions, strpos($actions, "if (in_array(\$action, ['restore_saved'"), 1200), 'mg_saoluu_security_sensitive_confirmation'));

ui_check('saved and uploaded restore force full files plus automatic rewrite',
    substr_count($actions, 'mg_saoluu_restore_zip($path, true, true, $password)') >= 1
    && substr_count($actions, 'mg_saoluu_restore_zip($staged, true, true, $password)') >= 1);

ui_check('restore engine ignores environment mode and always restores Full Site',
    str_contains($restore, '$restore_files = true;')
    && str_contains($restore, '$change_url = true;')
    && !str_contains(substr($restore, strpos($restore, 'function mg_saoluu_restore_zip'), 5000), "'cross_env_production'"));

ui_check('restore automatically rewrites both source URL schemes to current target URLs',
    str_contains($restore, 'function mg_saoluu_domain_rewrite_pairs')
    && str_contains($restore, "foreach (['https://', 'http://'] as \$scheme)")
    && str_contains($restore, "mg_saoluu_search_replace_database(\$old_url, \$new_url)")
    && str_contains($restore, "'domain_rewrite'")
    && str_contains($restore, "'passes' => \$rewrite_passes")
    && str_contains($restore, "update_option('home', untrailingslashit(\$target_home))")
    && str_contains($restore, "update_option('siteurl', untrailingslashit(\$target_site))"));

ui_check('DB prefix mismatch still fails closed',
    str_contains($restore, "'backup_prefix_unknown'")
    && str_contains($restore, "'backup_prefix_mismatch'"));

ui_check('checkpoint rollback and exact snapshot prune remain active',
    str_contains($restore, "mg_saoluu_create_backup(")
    && str_contains($restore, "'purpose' => 'rollback'")
    && str_contains($restore, "'snapshot_prune'")
    && str_contains($restore, "'snapshot_remaining_count'"));

ui_check('runtime backup uses fast Deflate level 1',
    str_contains($backup, 'function mg_saoluu_zip_fast_compress_index')
    && str_contains($backup, 'ZipArchive::CM_DEFLATE, 1')
    && substr_count($backup, 'mg_saoluu_zip_fast_compress_index(') >= 4);

ui_check('upload UI keeps drag-drop and bounded size validation',
    str_contains($render, 'data-mgsl-dropzone')
    && str_contains($render, 'data-mgsl-extensions=".zip,.mgbackup"')
    && str_contains($js, "document.addEventListener('drop'")
    && str_contains($js, 'picked.size > limit'));

ui_check('pack and signer concepts are absent from active simple UI function',
    ($start = strpos($render, 'function mg_saoluu_render_simple_page')) !== false
    && ($end = strpos($render, 'function mg_saoluu_render_page', $start)) !== false
    && !str_contains(substr($render, $start, $end - $start), 'Pack Restore')
    && !str_contains(substr($render, $start, $end - $start), 'Local')
    && !str_contains(substr($render, $start, $end - $start), 'Production')
    && !str_contains(substr($render, $start, $end - $start), 'MGAPPROVE1'));

ui_check('simple Recovery Key form has separate authenticated same-origin POST',
    str_contains($render, 'mg_saoluu_reveal_recovery_key')
    && str_contains($render, "wp_nonce_field('mg_saoluu_reveal_recovery_key', 'mg_recovery_nonce')")
    && str_contains($render, "admin_url('admin-post.php')")
    && str_contains($render, '<?php if (is_ssl()): ?>')
    && str_contains($render, 'name="admin_password"')
    && str_contains($recovery, "current_user_can('manage_options')")
    && str_contains($recovery, "check_admin_referer('mg_saoluu_reveal_recovery_key', 'mg_recovery_nonce')"));

ui_check('disclosure uses only existing key and no plaintext transient',
    str_contains($recovery, 'mg_saoluu_managed_encryption_key(false)')
    && !str_contains($recovery, 'mg_saoluu_managed_encryption_key(true)')
    && !str_contains($recovery, 'set_transient(')
    && !str_contains($recovery, 'update_option(')
    && str_contains($recovery, 'no-store')
    && str_contains($recovery, 'is_ssl()')
    && str_contains($actions, "'security_show_recovery_key','security_import_recovery_key'"));

ui_check('admin CSS and JS packaged assets remain non-empty',
    strlen($css) > 5000 && strlen($js) > 3000);

echo "ADMIN UI TESTS $count/$count PASS\n";
