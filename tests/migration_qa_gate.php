<?php
/** Isolated staged-QA migration gate. No WordPress instance and no writes. */
if (PHP_SAPI !== 'cli') exit(1);
define('ABSPATH', __DIR__.'/');
define('DB_HOST', 'mysql.test.internal');
define('WP_ENVIRONMENT_TYPE', 'staging');
define('MG_SAOLUU_MIGRATION_QA_MODE', true);
define('DISABLE_WP_CRON', true);
define('MG_SAOLUU_VERSION', '1.3.0-rc1');
$GLOBALS['qa_url'] = 'https://staging.example.com';
$GLOBALS['qa_count'] = 0;
function add_action(...$args) {}
function wp_parse_url($url, $component=-1) {return parse_url($url,$component);}
function home_url($path='/') {return rtrim($GLOBALS['qa_url'],'/').$path;}
function get_option($key,$default=null) {return $default;}
function is_ssl() {return true;}
class WP_Error {function __construct(public string $code,public string $message) {} function get_error_code() {return $this->code;}}
function is_wp_error($v) {return $v instanceof WP_Error;}
function qa($name,$condition){$GLOBALS['qa_count']++;if(!$condition){fwrite(STDERR,"FAIL ".$name."\n");exit(1);}echo "PASS ".$name."\n";}
$base=rtrim((string)(getenv('MGSL_TEST_PLUGIN_DIR') ?: dirname(__DIR__)),'/');
require_once $base.'/includes/environment.php';
require_once $base.'/includes/migration.php';
$_SERVER['HTTP_HOST']='staging.example.com';
qa('isolated staged clone can exercise signed migration engine',mg_saoluu_migration_production_gate()===true);
$_SERVER['HTTP_HOST']='motgu.com';
qa('host-spoof request fails staged QA gate',is_wp_error(mg_saoluu_migration_production_gate()));
$_SERVER['HTTP_HOST']='motgu.com';
$GLOBALS['qa_url']='https://motgu.com';
qa('public production hostname cannot use QA grant',is_wp_error(mg_saoluu_migration_production_gate()));
echo "QA GATE TESTS ".$GLOBALS['qa_count']."/".$GLOBALS['qa_count']." PASS\n";
