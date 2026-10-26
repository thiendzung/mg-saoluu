<?php
/** R1 disclosure-only contract; synthetic WordPress stubs, no site/bootstrap/DB. */
if (PHP_SAPI !== 'cli') exit(1);
$case = $argv[1] ?? 'driver';
$fake = 'R1fixture-abc<kbd>&Z0123456789';
$plugin_dir = rtrim((string)(getenv('MGSL_TEST_PLUGIN_DIR') ?: dirname(__DIR__)), '/');
if ($case === 'driver') {
    $cases = ['success'=>0, 'constant'=>0, 'no_key'=>42, 'bad_password'=>42,
        'rate_limit'=>42, 'bad_nonce'=>42, 'no_cap'=>42, 'http'=>42,
        'bad_integrity'=>42, 'get'=>42];
    $count = 0;
    foreach ($cases as $name => $expected) {
        $proc = proc_open([PHP_BINARY, __FILE__, $name], [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        if (!is_resource($proc)) { fwrite(STDERR, "FAIL spawn $name\n"); exit(1); }
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($proc);
        $hasKey = $name === 'constant' ? 'R1constant-abc<kbd>&Z0123456789' : $fake;
        $escaped = htmlspecialchars($hasKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ok = $exit === $expected;
        if ($expected === 0) {
            $ok = $ok && str_contains($out, $escaped)
                && !str_contains($out, $hasKey)
                && !str_contains($out, '<script')
                && !str_contains($err, $hasKey);
        } else {
            $ok = $ok && !str_contains($out . $err, $fake)
                && !str_contains($out . $err, 'R1constant-abc')
                && str_contains($err, 'DENIED:');
        }
        if (!$ok) { fwrite(STDERR, "FAIL $name exit=$exit stderr=" . substr($err,0,220) . "\n"); exit(1); }
        $count++;
        echo "PASS $name\n";
    }
    $code = file_get_contents($plugin_dir . '/admin/recovery-key.php');
    foreach (['Cache-Control: private, no-store', 'Referrer-Policy: no-referrer',
        'Content-Security-Policy:', 'mg_saoluu_managed_encryption_key(false)',
        "add_action('admin_post_mg_saoluu_reveal_recovery_key'"] as $required) {
        if (!str_contains((string) $code, $required)) { fwrite(STDERR,"FAIL source invariant $required\n"); exit(1); }
        $count++;
    }
    echo "RECOVERY-KEY TESTS $count/$count PASS\n";
    exit(0);
}

define('ABSPATH', '/synthetic-wordpress/');
$_SERVER['REQUEST_METHOD'] = $case === 'get' ? 'GET' : 'POST';
$_POST = ['admin_password'=>'fixture-good-password','mg_recovery_nonce'=>'fixture-nonce'];
if ($case === 'bad_password') $_POST['admin_password'] = 'wrong';
if ($case === 'rate_limit') $_POST['admin_password'] = 'limited';
if ($case === 'bad_nonce') $_POST['mg_recovery_nonce'] = 'bad';
if ($case === 'constant') define('MG_SAOLUU_ENCRYPTION_KEY', 'R1constant-abc<kbd>&Z0123456789');
function add_action($hook,$func) {if ($hook !== 'admin_post_mg_saoluu_reveal_recovery_key') exit(77);}
function status_header($code) {}
function wp_die($text,$title = '',$args = []) {fwrite(STDERR,"DENIED:".strip_tags($text)."\n");exit(42);}
function current_user_can($cap) {global $case;return $cap === 'manage_options' && $case !== 'no_cap';}
function check_admin_referer($action,$nonce_name) {if (($_POST[$nonce_name] ?? '') !== 'fixture-nonce') wp_die('Bad nonce');return 1;}
function is_ssl() {global $case;return $case !== 'http';}
function nocache_headers() {}
function esc_html($s) {return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');}
function esc_url($s) {return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');}
function admin_url($path) {return 'https://motgu.test/wp-admin/'.$path;}
function wp_unslash($s) {return $s;}
function mg_saoluu_self_integrity($force=false) {global $case;return ['ok'=>$case !== 'bad_integrity'];}
class WP_Error {
    public function __construct(private string $message) {}
    public function get_error_message() {return $this->message;}
}
function is_wp_error($x) {return $x instanceof WP_Error;}
function mg_saoluu_security_verify_admin_password($password,$scope) {
    if ($scope !== 'recovery_key_view') return new WP_Error('Unexpected scope');
    if ($password === 'limited') return new WP_Error('Too many attempts');
    return $password === 'fixture-good-password' ? true : new WP_Error('Bad password');
}
function mg_saoluu_encryption_constant_available() {return defined('MG_SAOLUU_ENCRYPTION_KEY');}
function mg_saoluu_managed_encryption_key($create=true) {
    global $case, $fake;
    if ($create) {fwrite(STDERR,'MINT_ATTEMPT'); exit(78);}
    return $case === 'no_key' ? '' : $fake;
}
function update_option() {fwrite(STDERR,'UNEXPECTED_OPTION_WRITE');exit(79);}
function set_transient() {fwrite(STDERR,'UNEXPECTED_TRANSIENT_WRITE');exit(79);}
function mg_saoluu_log_event() {fwrite(STDERR,'UNEXPECTED_AUDIT');exit(79);}
require_once $plugin_dir . '/admin/recovery-key.php';
mg_saoluu_reveal_recovery_key();
