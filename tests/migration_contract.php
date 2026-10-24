<?php
/** MGSL migration safety gates; standalone PHP 8.3, no WordPress instance. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('ABSPATH', __DIR__ . '/');
define('DB_HOST', 'mysql.internal');
define('MG_SAOLUU_VERSION', '1.3.0-rc3');
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['mgsl_test_home'] = 'https://new.example.com/';
$GLOBALS['mgsl_test_options'] = [];
$GLOBALS['mgsl_migration_tests'] = 0;
function add_action(...$args) {}
function add_filter(...$args) {}
function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
function home_url($path = '/') { return rtrim($GLOBALS['mgsl_test_home'], '/') . $path; }
function get_option($k, $default = null) { return $GLOBALS['mgsl_test_options'][$k] ?? $default; }
function update_option($k, $value, $autoload = false) { $GLOBALS['mgsl_test_options'][$k] = $value; return true; }
function sanitize_text_field($value) { return trim((string) $value); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
class WP_Error {
    public function __construct(public string $code, public string $message) {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function is_ssl() { return true; }
function check($name, $condition) {
    ++$GLOBALS['mgsl_migration_tests'];
    if (!$condition) { fwrite(STDERR, "FAIL " . $name . "\n"); exit(1); }
    echo "PASS " . $name . "\n";
}
$plugin = rtrim((string) (getenv('MGSL_TEST_PLUGIN_DIR') ?: dirname(__DIR__)), '/');
require_once $plugin . '/includes/environment.php';
require_once $plugin . '/includes/security.php';
require_once $plugin . '/includes/migration.php';
require_once $plugin . '/includes/restore.php';
check('ordinary production restore does not rewrite URL',
    mg_saoluu_restore_should_change_url(true, 'production', null) === false);
check('signed migration rewrites domain on production',
    mg_saoluu_restore_should_change_url(true, 'production', ['nonce' => 'verified']) === true);
check('disable URL checkbox always prevents rewriting',
    mg_saoluu_restore_should_change_url(false, 'production', ['nonce' => 'verified']) === false);

$_SERVER['HTTP_HOST'] = 'new.example.com';
check('canonical host from WordPress settings', mg_saoluu_host() === 'new.example.com');
check('matching request host accepted', mg_saoluu_request_host_is_canonical());
$_SERVER['HTTP_HOST'] = 'abc.test';
check('spoofed .test Host cannot reclassify production', !mg_saoluu_request_host_is_canonical()
    && mg_saoluu_environment() === 'production');
$_SERVER['HTTP_HOST'] = 'new.example.com';

$target = mg_saoluu_migration_target_url('https://new.example.com/');
check('target HTTPS canonicalized', $target === 'https://new.example.com');
check('HTTP target refused', is_wp_error(mg_saoluu_migration_target_url('http://new.example.com')));
check('local target refused', is_wp_error(mg_saoluu_migration_target_url('https://abc.test')));
check('IP target refused', is_wp_error(mg_saoluu_migration_target_url('https://192.168.1.10')));
check('invalid DNS hostname refused', is_wp_error(mg_saoluu_migration_target_url('https://bad_host.example.com')));
check('target URL with path refused', is_wp_error(mg_saoluu_migration_target_url('https://new.example.com/test')));
check('query target refused', is_wp_error(mg_saoluu_migration_target_url('https://new.example.com/?x=1')));
$manifest = [
    'backup_type' => 'local_copy', 'purpose' => 'migration',
    'source_environment' => 'local', 'source_url' => 'https://abc.test/',
    'source_host' => 'abc.test', 'db_table_prefix' => 'wp_',
    'hashes' => ['database_backup.sql' => str_repeat('a', 64)],
    'file_count' => 3,
];
check('Local signed migration manifest accepted', !is_wp_error(mg_saoluu_migration_manifest_preflight($manifest, 'https://new.example.com/', 'wp_')));
check('prefix mismatch blocked', is_wp_error(mg_saoluu_migration_manifest_preflight($manifest, 'https://new.example.com/', 'other_')));
check('ordinary backup not a migration', is_wp_error(mg_saoluu_migration_manifest_preflight(array_replace($manifest, ['purpose'=>'transfer']), 'https://new.example.com/', 'wp_')));
check('production source not a Local migration', is_wp_error(mg_saoluu_migration_manifest_preflight(array_replace($manifest, ['source_environment'=>'production']), 'https://new.example.com/', 'wp_')));
check('migration to same host blocked', is_wp_error(mg_saoluu_migration_manifest_preflight($manifest, 'https://abc.test/', 'wp_')));

class MigrationFixtureDB {
    public string $prefix = 'wp_';
    public int $users = 1;
    public int $comments = 0;
    public int $content = 0;
    public int $attachments = 0;
    public int $orders = 0;
    public int $hpos = 0;
    public function prepare($query, $param) { return str_replace('%s', "'".addslashes($param)."'", $query); }
    public function get_var($sql) {
        if (str_starts_with($sql, 'SHOW TABLES LIKE')) return 'wp_wc_orders';
        if (str_contains($sql, 'wp_wc_orders')) return $this->hpos;
        if (str_contains($sql, 'wp_users')) return $this->users;
        if (str_contains($sql, 'wp_comments')) return $this->comments;
        if (str_contains($sql, "post_type = 'attachment'")) return $this->attachments;
        if (str_contains($sql, 'shop_order')) return $this->orders;
        if (str_contains($sql, 'wp_posts')) return $this->content;
        return null;
    }
}
$GLOBALS['wpdb'] = new MigrationFixtureDB();
check('fresh target preflight passes', !is_wp_error(mg_saoluu_migration_target_fresh()));
$GLOBALS['wpdb']->hpos = 1;
check('HPOS order protects existing store', is_wp_error(mg_saoluu_migration_target_fresh()));
$GLOBALS['wpdb']->hpos = 0;
$GLOBALS['wpdb']->users = 2;
check('existing users protect production target', is_wp_error(mg_saoluu_migration_target_fresh()));
$GLOBALS['wpdb']->users = 1;
$GLOBALS['wpdb']->content = 1;
check('existing content protects production target', is_wp_error(mg_saoluu_migration_target_fresh()));
$GLOBALS['wpdb']->content = 0;
$GLOBALS['wpdb']->attachments = 1;
check('existing media protects production target', is_wp_error(mg_saoluu_migration_target_fresh()));
$GLOBALS['wpdb']->attachments = 0;

if (!extension_loaded('sodium')) { fwrite(STDERR, "SODIUM REQUIRED\n"); exit(1); }
$pair = sodium_crypto_sign_keypair();
$public = sodium_crypto_sign_publickey($pair);
$private = sodium_crypto_sign_secretkey($pair);
$signer = 'fixture-local';
$GLOBALS['mgsl_test_options']['mg_saoluu_trusted_signers'] = [
    $signer => ['source_host'=>'abc.test', 'public_key'=>base64_encode($public)]
];
$sha = hash('sha256', 'encrypted backup fixture');
$payload = [
    'format'=>'mgsl-migration-grant','version'=>1,'archive_sha256'=>$sha,
    'source_host'=>'abc.test','source_url'=>'https://abc.test/',
    'target_url'=>'https://new.example.com','nonce'=>bin2hex(random_bytes(24)),
    'created_at'=>time(),'expires_at'=>time()+600,
];
$sig = sodium_crypto_sign_detached(mg_saoluu_security_canonical_json($payload), $private);
$code = 'MGMIGRATE1.' . mg_saoluu_b64url_encode(mg_saoluu_security_canonical_json([
    'payload'=>$payload,'device_id'=>$signer,'signature'=>base64_encode($sig)
]));
$verified = mg_saoluu_migration_verify_grant($code, $sha, $manifest, 'https://new.example.com/');
check('Ed25519 signed exact archive and target accepted', !is_wp_error($verified));
check('different archive SHA rejected', is_wp_error(mg_saoluu_migration_verify_grant($code, hash('sha256','tampered'), $manifest, 'https://new.example.com/')));
check('different target domain rejected', is_wp_error(mg_saoluu_migration_verify_grant($code, $sha, $manifest, 'https://different.example.com')));
$untrusted = $GLOBALS['mgsl_test_options']['mg_saoluu_trusted_signers'];
$GLOBALS['mgsl_test_options']['mg_saoluu_trusted_signers'] = [];
check('unpaired Local source rejected', is_wp_error(mg_saoluu_migration_verify_grant($code, $sha, $manifest, 'https://new.example.com')));
$GLOBALS['mgsl_test_options']['mg_saoluu_trusted_signers'] = $untrusted;
check('one-time migration grant can be consumed', mg_saoluu_migration_consume_grant($verified) === true);
check('replayed signed grant rejected', is_wp_error(mg_saoluu_migration_verify_grant($code, $sha, $manifest, 'https://new.example.com')));
check('second consume is blocked', is_wp_error(mg_saoluu_migration_consume_grant($verified)));
check('RC production mutation always blocked', is_wp_error(mg_saoluu_migration_production_gate())
    && mg_saoluu_migration_production_gate()->get_error_code() === 'migration_rc_locked');

echo "MIGRATION TESTS " . $GLOBALS['mgsl_migration_tests'] . "/" . $GLOBALS['mgsl_migration_tests'] . " PASS\n";
