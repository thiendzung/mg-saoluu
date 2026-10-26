<?php
/** Standalone R1 disclosure controls; no WordPress bootstrap or customer data. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('ABSPATH', __DIR__ . '/');
$GLOBALS['fixture_cap'] = true;
$GLOBALS['fixture_env'] = 'local';
$GLOBALS['fixture_pass'] = true;
$GLOBALS['fixture_key'] = 'fixture-recovery-key-0123456789-abcdefghijklmnopqrstuvwxyz';
$GLOBALS['fixture_create_calls'] = [];
$GLOBALS['test_count'] = 0;
class WP_Error {
    private $code; private $message;
    function __construct($code, $message) { $this->code=$code; $this->message=$message; }
    function get_error_code() { return $this->code; }
    function get_error_message() { return $this->message; }
}
function is_wp_error($x) {return $x instanceof WP_Error;}
function current_user_can($cap) {return $cap === 'manage_options' && $GLOBALS['fixture_cap'];}
function mg_saoluu_environment() {return $GLOBALS['fixture_env'];}
function mg_saoluu_security_verify_admin_password($password, $scope) {
    return $scope === 'recovery_key_once' && $GLOBALS['fixture_pass'] && $password === 'fixture-admin-password'
        ? true : new WP_Error('password_invalid','Sai mật khẩu');
}
function mg_saoluu_encryption_constant_available() {return false;}
function mg_saoluu_managed_encryption_key($create = true) {
    $GLOBALS['fixture_create_calls'][] = $create;
    if ($create) throw new Exception('KEY CREATED');
    return $GLOBALS['fixture_key'];
}
function esc_textarea($s) {return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');}
function esc_url($s) {return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');}
function add_action($hook, $callback) {
    if ($hook !== 'admin_post_mg_saoluu_recovery_key_once' || $callback !== 'mg_saoluu_admin_post_recovery_key_once') {
        throw new Exception('Unexpected hook');
    }
}
function check($name, $ok) {
    $GLOBALS['test_count']++;
    if (!$ok) { fwrite(STDERR, "FAIL $name\n"); exit(1); }
    echo "PASS $name\n";
}
require (getenv('MGSL_TEST_PLUGIN_DIR') ?: dirname(__DIR__)) . '/admin/recovery-key.php';
$key = mg_saoluu_recovery_key_read_once('fixture-admin-password');
check('correct password retrieves existing key only', $key === $GLOBALS['fixture_key'] && $GLOBALS['fixture_create_calls'] === [false]);
$GLOBALS['fixture_cap'] = false;
check('capability denied', is_wp_error(mg_saoluu_recovery_key_read_once('fixture-admin-password')));
$GLOBALS['fixture_cap'] = true;
$GLOBALS['fixture_env'] = 'production';
check('production denied', is_wp_error(mg_saoluu_recovery_key_read_once('fixture-admin-password')));
$GLOBALS['fixture_env'] = 'local';
$GLOBALS['fixture_pass'] = false;
check('wrong password denied before key lookup', is_wp_error(mg_saoluu_recovery_key_read_once('bad')) && $GLOBALS['fixture_create_calls'] === [false]);
$GLOBALS['fixture_pass'] = true;
$GLOBALS['fixture_key'] = '';
check('missing key fails without generation', is_wp_error(mg_saoluu_recovery_key_read_once('fixture-admin-password')));
check('all key lookups read-only', $GLOBALS['fixture_create_calls'] === [false,false]);
$malicious = 'abc</textarea><script>alert(1)</script>';
$html = mg_saoluu_recovery_key_once_html($malicious, 'https://motgu.test/wp-admin/admin.php?a=1&b=2');
check('textarea escapes payload', !str_contains($html,'</textarea><script>') && str_contains($html,'&lt;/textarea&gt;'));
check('back link escapes ampersand', str_contains($html,'a=1&amp;b=2'));
check('no JS/external assets in output', !str_contains($html,'<script') && !str_contains($html,'src='));
$source = file_get_contents((getenv('MGSL_TEST_PLUGIN_DIR') ?: dirname(__DIR__)) . '/admin/recovery-key.php');
check('POST method and nonce enforced', str_contains($source,"'REQUEST_METHOD'") && str_contains($source,'check_admin_referer('));
check('no-store security headers', str_contains($source,'no-store') && str_contains($source,'Referrer-Policy: no-referrer') && str_contains($source,'frame-ancestors'));
check('no transient/plaintext persistence in new handler', !str_contains($source,'set_transient(') && !str_contains($source,'update_option(') && !str_contains($source,'mg_saoluu_encryption_password('));
echo "P2B R1 SECURITY CONTRACT {$GLOBALS['test_count']}/{$GLOBALS['test_count']} PASS\n";
