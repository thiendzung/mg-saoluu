<?php
/** Static admin UI/upload contracts; no WordPress bootstrap. */
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

$lifecycle = src($plugin, 'includes/lifecycle.php');
$packs = src($plugin, 'includes/packs.php');
$actions = src($plugin, 'admin/actions.php');
$render = src($plugin, 'admin/render.php');
$js = src($plugin, 'assets/js/admin.js');
$css = src($plugin, 'assets/css/admin.css');

ui_check('admin menu stores the real WordPress hook suffix',
    str_contains($lifecycle, '$GLOBALS[\'mg_saoluu_admin_hook_suffix\'] = add_menu_page(')
    && str_contains($lifecycle, '$hook === $registered'));

ui_check('admin assets use filemtime cache busting with critical CSS fallback',
    str_contains($lifecycle, 'filemtime($path)')
    && str_contains($lifecycle, "wp_add_inline_style(")
    && str_contains($lifecycle, "assets/css/admin.css")
    && str_contains($lifecycle, "assets/js/admin.js"));

ui_check('admin CSS and JS are non-empty packaged assets',
    strlen($css) > 5000 && strlen($js) > 3000);

ui_check('restore UI offers saved Pack and local upload sources',
    str_contains($render, 'Bản đã lưu')
    && str_contains($render, 'Upload từ máy')
    && str_contains($render, 'name="mg_action" value="pack_restore_upload"')
    && str_contains($render, 'enctype="multipart/form-data"')
    && str_contains($render, 'data-mgsl-max-upload'));

ui_check('local Pack upload is bounded by WordPress/PHP upload limit',
    str_contains($packs, 'function mg_saoluu_pack_upload_limit()')
    && str_contains($packs, 'wp_max_upload_size()')
    && str_contains($packs, '2 * 1024 * 1024 * 1024'));

ui_check('local Pack upload is accepted only through native PHP upload semantics',
    str_contains($packs, 'is_uploaded_file($tmp)')
    && str_contains($packs, 'move_uploaded_file($tmp, $target)')
    && str_contains($packs, 'mg_saoluu_private_temp_dir()')
    && str_contains($packs, '@chmod($target, 0600)')
    && str_contains($packs, 'mg_saoluu_is_pack_backup($target)')
    && !str_contains($packs, 'wp_handle_upload('));

$prod = strpos($actions, "if (mg_saoluu_environment() === 'production')");
$stage = strpos($actions, 'mg_saoluu_stage_pack_upload(');
ui_check('RC build blocks Production before staging local upload',
    $prod !== false && $stage !== false && $prod < $stage);

ui_check('uploaded Pack is deleted after staging restore job',
    str_contains($actions, "finally {")
    && str_contains($actions, '@unlink($uploaded)')
    && str_contains($actions, 'mg_saoluu_restore_pack($uploaded, $password, $lock)'));

ui_check('local upload has real browser size validation and operation feedback',
    str_contains($js, "pack_restore_upload:")
    && str_contains($js, 'data-mgsl-max-upload')
    && str_contains($js, 'picked.size > limit')
    && str_contains($js, 'mgslSizeDisabled')
    && str_contains($js, 'submit.disabled = true')
    && str_contains($render, 'data-mgsl-upload-warning'));

ui_check('responsive CSS styles the two restore sources and upload box',
    str_contains($css, '.mgsl-restore-source-grid')
    && str_contains($css, '.mgsl-upload-box')
    && str_contains($css, '@media(max-width:900px)'));

ui_check('restore upload boxes implement delegated drag and drop',
    substr_count($render, 'data-mgsl-dropzone') >= 3
    && str_contains($js, "document.addEventListener('dragover'")
    && str_contains($js, "document.addEventListener('drop'")
    && str_contains($js, 'new DataTransfer()')
    && str_contains($js, 'field.dispatchEvent(new Event(\'change\''));

ui_check('client upload validation checks extension and size',
    str_contains($render, 'data-mgsl-extensions=".mgbackup"')
    && substr_count($render, 'data-mgsl-extensions=".zip,.mgbackup"') >= 2
    && str_contains($js, 'picked.size > limit')
    && str_contains($js, 'wrongType')
    && str_contains($js, 'allowed.some'));

$verify_pos = strpos($actions, "if ($action === 'verify_restore_upload')");
$restore_pos = strpos($actions, "if ($action === 'restore_zip_upload')");
ui_check('Production-visible ZIP upload uses verify-only action before restore action',
    str_contains($render, 'name="mg_action" value="verify_restore_upload"')
    && str_contains($render, 'Production RC13 chỉ xác minh file')
    && $verify_pos !== false && $restore_pos !== false && $verify_pos < $restore_pos
    && str_contains($actions, 'mg_saoluu_verify_uploaded_backup_for_current_site($staged, $password)')
    && str_contains($actions, '@unlink($staged)'));

ui_check('uploaded backup verification is same-host fail-closed on Production',
    str_contains($plugin ? src($plugin, 'includes/restore.php') : '', "mg_saoluu_environment() === 'production'")
    && str_contains(src($plugin, 'includes/restore.php'), "'upload_verify_host'")
    && str_contains(src($plugin, 'includes/restore.php'), "'upload_verify_prefix'")
    && str_contains(src($plugin, 'includes/restore.php'), "'upload_verify_signature'"));

echo "ADMIN UI TESTS $count/$count PASS\n";
