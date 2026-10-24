<?php
/** Standalone narrow regression harness for MG Sao Luu 1.2.1, no WordPress or live database. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = sys_get_temp_dir() . '/mgsl-v12-' . bin2hex(random_bytes(4));
mkdir($root . '/public_html/wp-content', 0700, true);
define('ABSPATH', $root . '/public_html/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('DB_NAME', 'mg_fixture');
define('DB_HOST', '127.0.0.1:3306');
define('DB_USER', 'fixture_user');
define('DB_PASSWORD', 'fixture_secret_only');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('ARRAY_A', 'ARRAY_A');
if (in_array('--unsafe-config', $argv ?? [], true)) define('MG_SAOLUU_BACKUP_DIR', ABSPATH . 'wp-content/private-backups');
$_SERVER['DOCUMENT_ROOT'] = ABSPATH;
$GLOBALS['test_options'] = [];
$GLOBALS['test_transients'] = [];
$GLOBALS['test_count'] = 0;
class WP_Error {
    public string $code; public string $message;
    public function __construct($code, $message) { $this->code=$code; $this->message=$message; }
    public function get_error_message(){return $this->message;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x) {return $x instanceof WP_Error;}
function wp_normalize_path($s) {return str_replace('\\','/',(string)$s);}
function trailingslashit($s) {return rtrim((string)$s,'/') . '/';}
function untrailingslashit($s) {return rtrim((string)$s,'/');}
function sanitize_file_name($s) {return preg_replace('/[^a-zA-Z0-9._-]/','-',(string)$s);}
function sanitize_key($s) {return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$s));}
function wp_mkdir_p($s) {return is_dir($s)||mkdir($s,0700,true);}
function wp_generate_password($n=12,$special=false,$extra=false){return substr(bin2hex(random_bytes((int)ceil($n/2))),0,$n);}
function get_option($key,$fallback=null) {return $GLOBALS['test_options'][$key]??$fallback;}
function update_option($key,$value,$autoload=false) {$GLOBALS['test_options'][$key]=$value; return true;}
function get_transient($key) {return $GLOBALS['test_transients'][$key]??false;}
function set_transient($key,$value,$ttl=0) {$GLOBALS['test_transients'][$key]=$value;return true;}
function delete_transient($key){unset($GLOBALS['test_transients'][$key]);}
function wp_json_encode($v,$options=0){return json_encode($v,$options);}
function home_url($p='/'){return 'https://motgu.com'.(str_starts_with($p,'/')?'':'/').$p;}
function get_post_meta($id,$key,$single=false){return $id === 2?'12345678-1234-4234-a234-123456789abc':'';}
function get_term_meta($id,$key,$single=false){return '';}
function update_post_meta(){throw new Exception('EXPORT MUTATED POST META');}
function update_term_meta(){throw new Exception('EXPORT MUTATED TERM META');}
function is_serialized($s){return is_string($s)&&preg_match('/^(?:a|s|i|b|d|O|C):/', $s);}
function test($name,$condition){
 $GLOBALS['test_count']++;
 if(!$condition){fwrite(STDERR,"FAIL $name\n");exit(1);}echo "PASS $name\n";
}
function cleanup($dir){
 if(!file_exists($dir))return;
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($dir);
}
register_shutdown_function(function()use($root){cleanup($root);});
$base = rtrim((string) (getenv('MGSL_TEST_PLUGIN_DIR') ?: dirname(__DIR__)), '/') . '/includes/';
define('MG_SAOLUU_DIR', dirname(rtrim($base, '/')) . '/');
define('MG_SAOLUU_VERSION', '1.2.1-rc3');
function add_action(){}
function mg_saoluu_environment(){return 'local';}
function mg_saoluu_is_local_environment(){return true;}
require_once $base.'core.php';
require_once $base.'diagnostics.php';
require_once $base.'crypto.php';
require_once $base.'google-drive.php';
require_once $base.'catalog.php';
require_once $base.'backup.php';
require_once $base.'restore.php';
require_once $base.'integrity.php';

// Explicit in-webroot configuration must FAIL CLOSED without writing there.
if (in_array('--unsafe-config', $argv ?? [], true)) {
    $storage = mg_saoluu_storage_preflight();
    test('unsafe configured backup returns WP_Error', is_wp_error($storage) && $storage->get_error_code() === 'unsafe_backup_location');
    test('unsafe configured path not created', !file_exists(MG_SAOLUU_BACKUP_DIR));
    test('safe fallback never points at webroot', mg_saoluu_storage_path_safe(mg_saoluu_get_backup_dir()));
    test('unsafe configuration rejects decrypted/private temp', is_wp_error(mg_saoluu_private_temp_dir()));
    echo "TESTS ".$GLOBALS['test_count']."/".$GLOBALS['test_count']." PASS\n";
    exit(0);
}
test('plugin integrity hashes match packaged source',mg_saoluu_self_integrity(true)['ok']===true);

// Storage isolation: multiple filesystem paths including symlinks.
$external=mg_saoluu_get_backup_dir();
test('storage default outside docroot',mg_saoluu_storage_path_safe($external)&&is_dir($external));
test('storage preflight passes',mg_saoluu_storage_preflight()===true);
$private = mg_saoluu_private_temp_dir();
test('private temp is outside all webroots',!is_wp_error($private)&&str_starts_with($private,$external)&&mg_saoluu_storage_path_safe($private));
test('private temp has mode 0700',substr(sprintf('%o',fileperms($private)),-4)==='0700');
$cnf=mg_saoluu_create_mysql_config();
test('MySQL credentials outside webroot and mode 0600',!is_wp_error($cnf)&&str_starts_with($cnf,$private)&&substr(sprintf('%o',fileperms($cnf)),-4)==='0600');
if(!is_wp_error($cnf))@unlink($cnf);
test('reject webroot path',!mg_saoluu_storage_path_safe(ABSPATH.'wp-content/backups'));
mkdir($root.'/outside',0700);
symlink(ABSPATH.'wp-content', $root.'/outside/link-uploads');
test('reject symlink pointing into webroot',!mg_saoluu_storage_path_safe($root.'/outside/link-uploads/private'));
test('accept real external directory',mg_saoluu_storage_path_safe($root.'/outside/vault'));
test('reject relative path',!mg_saoluu_storage_path_safe('wp-content/backups'));

test('debug enabled on clone',mg_saoluu_debug_enabled()===true);
test('diagnostics redacts secrets',!isset(mg_saoluu_debug_context(['password'=>'secret','type'=>'local_copy'])['password']));
test('debug writes private JSONL',mg_saoluu_debug_log('test_checkpoint',['code'=>'ok','password'=>'secret'])===true);
test('debug file outside webroot',mg_saoluu_storage_path_safe(mg_saoluu_debug_file()));
test('debug log excludes password',!str_contains(file_get_contents(mg_saoluu_debug_file()),'secret'));
// Legacy Data Transfer and Deploy code are not shipped; options are preserved.
test('no dormant data-engine in distribution', !is_file(MG_SAOLUU_DIR.'includes/data-engine.php'));
test('no dormant deploy engine in distribution', !is_file(MG_SAOLUU_DIR.'includes/deploy.php'));
test('no disabled PHP SQL exporter', !function_exists('mg_saoluu_php_export_database'));
test('no disabled PHP SQL importer', !function_exists('mg_saoluu_php_import_database'));
// The operation lock must be exclusive without polling.
$lock1=mg_saoluu_acquire_lock();
test('first nonblocking lock succeeds',is_string($lock1)&&strlen($lock1)>0);
$lock2=mg_saoluu_acquire_lock();
test('second nonblocking lock denied',is_wp_error($lock2)&&$lock2->get_error_code()==='operation_locked');
mg_saoluu_release_lock($lock1);
$lock3=mg_saoluu_acquire_lock();
test('release allows next job',is_string($lock3)&&$lock3!==$lock1);
mg_saoluu_release_lock($lock3);

// Retention: 12 daily SQL + 7 full + 6 rollback. Protect oldest full copy.
$metadata=[];$full=[];
foreach ([['sql',12],['safe_full',7],['rollback',6]] as [$kind,$max]){
  for($i=1;$i<=$max;$i++){
    $prefix=$kind==='rollback'?'backup_pre_restore_sql':'backup_auto_'.$kind;
    $name=sprintf('%s_202610%02d_120000_%06d.zip',$prefix,$i,$i);
    file_put_contents($external.$name,'fixture');touch($external.$name,time()+$i*10);
    $metadata[$name]=['type'=>$kind==='rollback'?'sql':$kind,'purpose'=>$kind==='rollback'?'rollback':'scheduled'];
    if($kind==='safe_full')$full[]=$name;
  }
}
$metadata[$full[0]]['protected']=true;
mg_saoluu_save_metadata($external.'metadata.json',$metadata);
$rv=mg_saoluu_apply_retention();
test('retention operation passes',$rv===true);
$remain=array_map('basename',mg_saoluu_list_backup_files(true));
$meta=mg_saoluu_read_metadata($external.'metadata.json');$count=['sql'=>0,'safe_full'=>0,'rollback'=>0];
foreach($remain as $name){$kind=mg_saoluu_retention_group($name,$meta[$name]??[]);if(isset($count[$kind]))$count[$kind]++;}
test('SQL retention 7 independently',$count['sql']===7);
test('full retention 4 plus separately protected',$count['safe_full']===5&&file_exists($external.$full[0]));
test('rollback retention 3 independently',$count['rollback']===3);

// Database URL migration must process a table containing the old URL at most once.
class FixtureDB {
 public $prefix='wp_';public $rows=[];public $updates=[];
 public function __construct(){
  for($i=1;$i<=151;$i++){
   $this->rows[$i]=['id'=>$i,'guid'=>'https://a.test/?p='.$i,
    'post_content'=>$i===1?serialize(['url'=>'https://a.test/p/1']):'https://a.test/p/'.$i];
  }
 }
 public function get_col($q){return ['wp_posts','external_orders'];}
 public function get_results($sql,$mode=null){
  if(str_starts_with($sql,'SHOW COLUMNS'))return [
   ['Field'=>'id','Key'=>'PRI','Type'=>'bigint'],
   ['Field'=>'guid','Key'=>'','Type'=>'varchar(255)'],
   ['Field'=>'post_content','Key'=>'','Type'=>'longtext']];
  if(!str_starts_with($sql,'SELECT'))return [];
  preg_match("/`id` > '([0-9]+)'/",$sql,$m);$min=isset($m[1])?(int)$m[1]:0;
  return array_slice(array_values(array_filter($this->rows,fn($r)=>$r['id']>$min&&str_contains($r['post_content'],'https://a.test'))),0,150);
 }
 public function prepare($q,$args){foreach((array)$args as $a){$safe="'".str_replace("'","''",(string)$a)."'";$at=strpos($q,'%s');if($at!==false)$q=substr_replace($q,$safe,$at,2);}return $q;}
 public function esc_like($s){return $s;}
 public function update($table,$data,$where){$id=(int)$where['id'];$this->rows[$id]=array_merge($this->rows[$id],$data);$this->updates[$id]=($this->updates[$id]??0)+1;return 1;}
}
$wpdb=new FixtureDB();
$rep=mg_saoluu_search_replace_database('https://a.test','https://a.test.preview');
test('URL replace handles >150 rows in two batches',!is_wp_error($rep)&&$rep['rows']===151);
test('URL replace each row only once',count($wpdb->updates)===151&&max($wpdb->updates)===1);
test('WordPress post guid unchanged',$wpdb->rows[3]['guid']==='https://a.test/?p=3');
test('serialized data portable',unserialize($wpdb->rows[1]['post_content'])['url']==='https://a.test.preview/p/1');

// New backups carry source table prefix; incompatible clone imports fail before DB writes.
$ok=mg_saoluu_backup_prefix_preflight(['db_table_prefix'=>'wp_'],'wp_',true);
test('matching prefix allowed',$ok===true);
$mismatch=mg_saoluu_backup_prefix_preflight(['db_table_prefix'=>'mg_'],'wp_',true);
test('mismatched table prefix blocked',is_wp_error($mismatch)&&$mismatch->get_error_code()==='backup_prefix_mismatch');
$unknown=mg_saoluu_backup_prefix_preflight([],'wp_',true);
test('legacy cross-site unknown prefix blocked',is_wp_error($unknown)&&$unknown->get_error_code()==='backup_prefix_unknown');
test('legacy same-site prefix still allowed',mg_saoluu_backup_prefix_preflight([],'wp_',false)===true);

// v1.2.1: backend safety and honest archive statuses.
test('catalog requires actual restore-drill proof', !mg_saoluu_catalog_has_recovery_proof([
 'type'=>'safe_full','integrity_status'=>'verified','restore_tested'=>false
]));
test('catalog restore test requires evidence id', !mg_saoluu_catalog_has_recovery_proof([
 'type'=>'safe_full','integrity_status'=>'verified','restore_tested'=>true
]));
test('catalog accepts only explicit full recovery proof', mg_saoluu_catalog_has_recovery_proof([
 'type'=>'safe_full','integrity_status'=>'verified','restore_tested'=>true,'restore_evidence_id'=>'local-qa-01'
]));
$before=mg_saoluu_read_metadata($external.'metadata.json');
test('catalog metadata recorded before atomic rewrite',is_array($before)&&count($before)>0);
$before['v121_fixture']=['integrity_status'=>'content_hash_only'];
test('catalog atomic replace success', mg_saoluu_save_metadata($external.'metadata.json',$before));
$after=mg_saoluu_read_metadata($external.'metadata.json');
test('catalog atomic update complete',($after['v121_fixture']['integrity_status']??'')==='content_hash_only');
test('catalog metadata mode 0600',substr(sprintf('%o', fileperms($external.'metadata.json')),-4)==='0600');
$plaintext=$external.'backup_manual_sql_fixture.zip';
file_put_contents($plaintext,'mock plaintext');
$drive=mg_saoluu_drive_upload_file($plaintext,'fixture.zip');
test('Drive rejects plaintext local backup',is_wp_error($drive)&&$drive->get_error_code()==='drive_requires_encryption');
$patherror=mg_saoluu_catalog_verify('../outside.zip','');
test('catalog rejects traversal filename',is_wp_error($patherror)&&$patherror->get_error_code()==='invalid_backup_name');
$missing=mg_saoluu_catalog_verify('backup_missing_sql_20261024_000000.zip','');
test('catalog blocks missing backup',is_wp_error($missing)&&$missing->get_error_code()==='missing_backup');
// This environment has no mysql/mysqldump binary; forbid partial-import fallback.
if (!mg_saoluu_find_binary('mysql')) {
 $sql=$external.'mysql-fixture.sql';file_put_contents($sql,'SELECT 1;');
 $r=mg_saoluu_run_mysql_import($sql);
 test('missing mysql CLI fails closed',is_wp_error($r)&&$r->get_error_code()==='mysql_required');
}
if (!mg_saoluu_find_binary('mysqldump')) {
 $r=mg_saoluu_run_mysqldump($external.'dump-fixture.sql');
 test('missing mysqldump CLI fails closed',is_wp_error($r)&&$r->get_error_code()==='mysqldump_required');
}
// Old URL/hidden action cannot invoke transfer or deployment even with nonce.
function current_user_can($cap){return true;}
function check_admin_referer($a,$b){return true;}
function wp_unslash($v){return $v;}
require_once MG_SAOLUU_DIR.'admin/actions.php';
$_SERVER['REQUEST_METHOD']='POST';
foreach(['data_export','data_import','deploy_create','deploy_install','delete_data_package','delete_deploy_package'] as $legacy){
 $_POST=['mg_action'=>$legacy];$message='';$status='';
 mg_saoluu_handle_actions($message,$status);
 test('legacy POST blocked: '.$legacy,$status==='error'&&str_contains($message,'tạm khóa'));
}
$_SERVER['REQUEST_METHOD']='GET';$_POST=[];

// WordPress-independent AES-GCM decryption: binary roundtrip + wrong-key block.
define('MGSL_RECOVERY_LIB_ONLY',true);
require_once MG_SAOLUU_DIR.'tools/recovery-verify.php';
if (!defined('MG_SAOLUU_ENCRYPT_CHUNK')) define('MG_SAOLUU_ENCRYPT_CHUNK',4*1024*1024);
$fixture=$external.'archive-source.zip';
$fixture_cipher=$external.'archive-encrypted.mgbackup';
$fixture_plain=$external.'archive-decoded.zip';
$fixture_wrong=$external.'archive-wrong.zip';
file_put_contents($fixture,str_repeat('MGSL_RECOVERY_TEST',5000));
$enc=mg_saoluu_encrypt_backup_file($fixture,$fixture_cipher,'strong-offline-test-key-123456');
test('AES-GCM test artifact encryption',!is_wp_error($enc));
test('offline helper decrypts independently',mgsl_recovery_decrypt($fixture_cipher,$fixture_plain,'strong-offline-test-key-123456')===true);
test('offline recovery roundtrip matches input',hash_file('sha256',$fixture)===hash_file('sha256',$fixture_plain));
$wrong=false;
try { mgsl_recovery_decrypt($fixture_cipher,$fixture_wrong,'different-incorrect-key'); }
catch (Throwable $e) { $wrong=true; }
test('offline wrong key fail closed and deletes output',$wrong&&!file_exists($fixture_wrong));
// Setup wizard should never create plaintext test backups by accident or
// advertise the removed PHP SQL fallback as if it were runnable.
$setup_actions = file_get_contents(MG_SAOLUU_DIR.'admin/actions.php');
$setup_ui = file_get_contents(MG_SAOLUU_DIR.'admin/render.php');
test('Setup trial SQL backup explicitly encrypted', str_contains($setup_actions, "'backup_setup_test', true, ['encrypt' => true, 'upload_drive' => false]"));
test('Admin never advertises disabled PHP fallback', !str_contains($setup_ui, 'PHP dự phòng'));
// No real WordPress/DBngin/cPanel integration was performed by this mock harness.
echo "TESTS ".$GLOBALS['test_count']."/".$GLOBALS['test_count']." PASS\n";
