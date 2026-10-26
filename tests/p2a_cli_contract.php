<?php
/** Standalone CLI adapter tests; no WordPress, network or database mutations. */
if (PHP_SAPI !== 'cli') exit(1);
define('ABSPATH', '/nonexistent-mgsl-qa/');
define('WP_CLI', true);
define('ARRAY_A', 'ARRAY_A');
class WP_Error {
    private string $code;
    public function __construct($code, $message = '') {$this->code = $code;}
    public function get_error_code() { return $this->code; }
}
class WP_CLI {
    public static array $commands = [];
    public static array $output = [];
    public static function add_command($name, $callable) {self::$commands[$name] = $callable;}
    public static function line($value) { self::$output[] = $value; }
    public static function halt($code) { throw new RuntimeException('HALT ' . $code); }
}
function is_wp_error($error) { return $error instanceof WP_Error; }
function wp_json_encode($value, $options = 0) { return json_encode($value, $options); }
$plugin_dir = rtrim((string) (getenv('MGSL_TEST_PLUGIN_DIR') ?: dirname(__DIR__)), '/');
require $plugin_dir . '/includes/cli.php';
$n = 0;
function t($label, $pass) { global $n; $n++; if (!$pass) {fwrite(STDERR,"FAIL $label\n"); exit(1);} echo "PASS $label\n"; }
t('register exact three commands', array_keys(WP_CLI::$commands) === ['mg-saoluu backup', 'mg-saoluu list', 'mg-saoluu verify']);
t('id is deterministic opaque digest', preg_match('/^c[a-f0-9]{32}$/', mg_saoluu_cli_backup_id('backup_predeploy_sql_20261026_111111_abcdef.zip')) === 1);
t('reject absent format', is_wp_error(mg_saoluu_cli_options([], [], ['format'])));
t('reject unknown extra flag', is_wp_error(mg_saoluu_cli_options([], ['format'=>'json','unsafe'=>'yes'], ['format'])));
t('reject positional', is_wp_error(mg_saoluu_cli_options(['file'], ['format'=>'json'], ['format'])));
t('reject unsupported format', is_wp_error(mg_saoluu_cli_options([], ['format'=>'table'], ['format'])));
t('accept exact JSON format', mg_saoluu_cli_options([], ['format'=>'json'], ['format']) === true);
class FakeDb {
    public $last_error='';
    public array $rows=[];
    public function get_results($query,$type) { return $this->rows; }
}
$GLOBALS['wpdb']=new FakeDb();
$GLOBALS['wpdb']->rows=[['table_name'=>'wp_posts', 'engine'=>'InnoDB'],['table_name'=>'custom_orders','engine'=>'InnoDB']];
$r=mg_saoluu_cli_engine_inventory();
t('inventory includes any prefix', $r['table_count']===2 && $r['engines']['INNODB']===2);
$GLOBALS['wpdb']->rows=[['table_name'=>'old_meta','engine'=>'MyISAM']];
$r=mg_saoluu_cli_engine_inventory();
t('unsafe engine fails closed', is_wp_error($r) && $r->get_error_code()==='unsafe_storage_engine');
$GLOBALS['wpdb']->rows=[];
t('empty inventory fails closed', is_wp_error(mg_saoluu_cli_engine_inventory()));
try { mg_saoluu_cli_backup([],['components'=>'database','reason'=>'pre-deploy','ref'=>'latest','format'=>'json']); t('reject invalid ref',false); }
catch(RuntimeException $e) { $j=json_decode(WP_CLI::$output[0],true); t('invalid ref emits machine-readable error',$j['error_code']==='invalid_git_ref' && $e->getMessage()==='HALT 1'); }
echo "TESTS $n/$n PASS\n";
