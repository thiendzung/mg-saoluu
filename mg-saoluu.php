<?php
/**
 * Plugin Name: MG Sao Lưu & Khôi Phục An Toàn
 * Description: Sao lưu, xác minh, lưu ngoài hosting và khôi phục WordPress có kiểm soát.
 * Version: 1.2.1-rc6
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Update URI: https://github.com/thiendzung/mg-saoluu
 * Author: Trần Đăng Dũng
 * License: GPL2
 */
if (!defined('ABSPATH')) { exit; }
if (!defined('MG_SAOLUU_VERSION')) define('MG_SAOLUU_VERSION', '1.2.1-rc6');
if (!defined('MG_SAOLUU_DATA_VERSION')) define('MG_SAOLUU_DATA_VERSION', '5'); // No destructive migration from RC1.
if (!defined('MG_SAOLUU_MAX_SQL_UPLOAD')) define('MG_SAOLUU_MAX_SQL_UPLOAD', 300 * 1024 * 1024);
if (!defined('MG_SAOLUU_MAX_ZIP_UNCOMPRESSED')) define('MG_SAOLUU_MAX_ZIP_UNCOMPRESSED', 8 * 1024 * 1024 * 1024);
if (!defined('MG_SAOLUU_MAX_ZIP_RATIO')) define('MG_SAOLUU_MAX_ZIP_RATIO', 250);
if (!defined('MG_SAOLUU_ENCRYPT_CHUNK')) define('MG_SAOLUU_ENCRYPT_CHUNK', 4 * 1024 * 1024);

define('MG_SAOLUU_FILE', __FILE__);
define('MG_SAOLUU_DIR', plugin_dir_path(__FILE__));
define('MG_SAOLUU_URL', plugin_dir_url(__FILE__));

require_once MG_SAOLUU_DIR . 'includes/lifecycle.php';
require_once MG_SAOLUU_DIR . 'includes/environment.php';
require_once MG_SAOLUU_DIR . 'includes/core.php';
require_once MG_SAOLUU_DIR . 'includes/diagnostics.php';
require_once MG_SAOLUU_DIR . 'includes/crypto.php';
require_once MG_SAOLUU_DIR . 'includes/security.php';
require_once MG_SAOLUU_DIR . 'includes/migration.php';
require_once MG_SAOLUU_DIR . 'includes/google-drive.php';
require_once MG_SAOLUU_DIR . 'includes/backup.php';
require_once MG_SAOLUU_DIR . 'includes/restore.php';
require_once MG_SAOLUU_DIR . 'includes/catalog.php';
require_once MG_SAOLUU_DIR . 'includes/integrity.php';
// Legacy Data Transfer and Deploy code has been removed from this distribution.
// Old options remain untouched; old POST actions are explicitly blocked in admin/actions.php.
require_once MG_SAOLUU_DIR . 'admin/actions.php';
require_once MG_SAOLUU_DIR . 'admin/render.php';
