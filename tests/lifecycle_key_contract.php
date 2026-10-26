<?php
/** P2B-R1: lifecycle must never mint a Recovery Key on install or upgrade. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
define('ABSPATH', '/synthetic-wordpress/');
define('MG_SAOLUU_FILE', '/synthetic-wordpress/wp-content/plugins/mg-saoluu-2/mg-saoluu.php');
define('MG_SAOLUU_VERSION', '1.3.2-rc2');
define('MG_SAOLUU_DATA_VERSION', '6');
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['fixture_options'] = [];
$GLOBALS['fixture_writes'] = [];
$GLOBALS['fixture_mint_calls'] = 0;
$GLOBALS['fixture_tests'] = 0;
function check_lifecycle($label, $ok) {
    $GLOBALS['fixture_tests']++;
    if (!$ok) { fwrite(STDERR, "FAIL $label\n"); exit(1); }
    echo "PASS $label\n";
}
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['fixture_options'])
        ? $GLOBALS['fixture_options'][$name] : $default;
}
function update_option($name, $value, $autoload = null) {
    if (str_starts_with($name, 'mg_saoluu_managed_encryption_key')) {
        throw new RuntimeException('Unexpected Recovery Key update');
    }
    $GLOBALS['fixture_writes'][] = ['update', $name];
    $GLOBALS['fixture_options'][$name] = $value;
    return true;
}
function add_option($name, $value, $deprecated = '', $autoload = null) {
    if (array_key_exists($name, $GLOBALS['fixture_options'])) return false;
    if (str_starts_with($name, 'mg_saoluu_managed_encryption_key')) {
        throw new RuntimeException('Unexpected Recovery Key creation');
    }
    $GLOBALS['fixture_writes'][] = ['add', $name];
    $GLOBALS['fixture_options'][$name] = $value;
    return true;
}
function delete_transient($name) {}
function wp_clear_scheduled_hook($hook) {}
function wp_schedule_event(...$args) { throw new RuntimeException('Unexpected schedule enabled'); }
function mg_saoluu_managed_encryption_key($create = true) {
    $GLOBALS['fixture_mint_calls']++;
    throw new RuntimeException('Lifecycle invoked managed-key accessor (create=' . ($create ? 'true':'false') . ')');
}
$root = rtrim((string) (getenv('MGSL_TEST_PLUGIN_DIR') ?: dirname(__DIR__)), '/');
require_once $root . '/includes/lifecycle.php';

// Targeted OCR blocker: upgrade an rc1 site that has no managed key at all.
$GLOBALS['fixture_options'] = ['mg_saoluu_installed_version' => '1.3.2-rc1'];
mg_saoluu_maybe_upgrade();
check_lifecycle('rc1 to rc2 updates version', get_option('mg_saoluu_installed_version') === '1.3.2-rc2');
check_lifecycle('rc1 to rc2 leaves missing key missing',
    get_option('mg_saoluu_managed_encryption_key', null) === null
    && get_option('mg_saoluu_managed_encryption_key_created_at', null) === null
    && $GLOBALS['fixture_mint_calls'] === 0);

// Repeated wp-admin requests must be idempotent.
$state = $GLOBALS['fixture_options'];
$writes = count($GLOBALS['fixture_writes']);
mg_saoluu_maybe_upgrade();
check_lifecycle('repeat admin_init does not mutate state',
    $GLOBALS['fixture_options'] === $state
    && count($GLOBALS['fixture_writes']) === $writes
    && $GLOBALS['fixture_mint_calls'] === 0);

// Existing opaque key must not be rotated, overwritten or touched.
$GLOBALS['fixture_options'] = [
    'mg_saoluu_installed_version' => '1.3.2-rc1',
    'mg_saoluu_managed_encryption_key' => 'gcm2:fixture-existing-ciphertext',
    'mg_saoluu_managed_encryption_key_created_at' => '2026-10-25 10:00:00'
];
mg_saoluu_maybe_upgrade();
check_lifecycle('existing key and key timestamp preserved on upgrade',
    get_option('mg_saoluu_managed_encryption_key') === 'gcm2:fixture-existing-ciphertext'
    && get_option('mg_saoluu_managed_encryption_key_created_at') === '2026-10-25 10:00:00'
    && $GLOBALS['fixture_mint_calls'] === 0);

// Older pre-P2A source with no key also must fail closed.
$GLOBALS['fixture_options'] = ['mg_saoluu_installed_version' => '1.3.1'];
mg_saoluu_maybe_upgrade();
check_lifecycle('1.3.1 to rc2 does not mint key',
    get_option('mg_saoluu_managed_encryption_key', null) === null
    && $GLOBALS['fixture_mint_calls'] === 0);

// Fresh activation may set ordinary setup/version options but cannot provision secrets silently.
$GLOBALS['fixture_options'] = [];
mg_saoluu_activate();
check_lifecycle('fresh activation keeps key missing',
    get_option('mg_saoluu_installed_version') === '1.3.2-rc2'
    && get_option('mg_saoluu_managed_encryption_key', null) === null
    && $GLOBALS['fixture_mint_calls'] === 0);

// Activation of an existing installation must not overwrite stored key.
$GLOBALS['fixture_options'] = [
    'mg_saoluu_managed_encryption_key' => 'gcm2:fixture-existing-ciphertext',
    'mg_saoluu_managed_encryption_key_created_at' => '2026-10-25 10:00:00'
];
mg_saoluu_activate();
check_lifecycle('reactivation preserves existing key and timestamp',
    get_option('mg_saoluu_managed_encryption_key') === 'gcm2:fixture-existing-ciphertext'
    && get_option('mg_saoluu_managed_encryption_key_created_at') === '2026-10-25 10:00:00'
    && $GLOBALS['fixture_mint_calls'] === 0);

echo 'LIFECYCLE KEY TESTS ' . $GLOBALS['fixture_tests'] . '/' . $GLOBALS['fixture_tests'] . " PASS\n";
