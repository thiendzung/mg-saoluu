<?php
/** Standalone Pack v2 contracts: no live WordPress DB or network. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

define('ABSPATH', __DIR__ . '/');
define('ARRAY_N', 'ARRAY_N');
define('SODIUM_TEST_PASSWORD', 'fixture-pack-password-123456789');
$GLOBALS['pack_tests'] = 0;

class WP_Error {
    public function __construct(public string $code, public string $message) {}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$s));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function check_pack($name,$ok){
    $GLOBALS['pack_tests']++;
    if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(1);}
    echo "PASS $name\n";
}
class PackFixtureDB {
    public string $prefix='wp_';
    public string $options='wp_options';
    public string $posts='wp_posts';
    public string $postmeta='wp_postmeta';
    public string $terms='wp_terms';
    public string $term_taxonomy='wp_term_taxonomy';
    public string $term_relationships='wp_term_relationships';
    public string $termmeta='wp_termmeta';
    public string $comments='wp_comments';
    public string $commentmeta='wp_commentmeta';
    public string $users='wp_users';
    public string $usermeta='wp_usermeta';
}
$GLOBALS['wpdb']=new PackFixtureDB();

$plugin = rtrim((string) (getenv('MGSL_TEST_PLUGIN_DIR') ?: dirname(__DIR__)), '/');
require_once $plugin . '/includes/crypto.php';
require_once $plugin . '/includes/packs.php';

$registry=mg_saoluu_pack_registry();
check_pack('pack registry stays small and explicit',
    array_keys($registry)===['settings','content','users','full_db']);

check_pack('settings pack excludes WordPress transient rows',
    str_contains(mg_saoluu_pack_settings_predicate(), "LEFT(option_name,11) <> '_transient_'")
    && str_contains(mg_saoluu_pack_settings_predicate(), "LEFT(option_name,16) <> '_site_transient_'"));

$content_tables=mg_saoluu_pack_tables('content');
check_pack('content pack keeps complete WordPress content dependency group',
    is_array($content_tables)
    && $content_tables===['wp_posts','wp_postmeta','wp_terms','wp_term_taxonomy','wp_term_relationships','wp_termmeta','wp_comments','wp_commentmeta']);

check_pack('settings pack is only wp_options', mg_saoluu_pack_tables('settings')===['wp_options']);
check_pack('users pack is users plus usermeta', mg_saoluu_pack_tables('users')===['wp_users','wp_usermeta']);
check_pack('unsafe SQL identifier rejected', mg_saoluu_pack_quote_identifier('wp_options;DROP')==='');

if (!mg_saoluu_pack_crypto_available()) {
    fwrite(STDERR,"FAIL Pack v2 requires PHP Sodium in CI\n");
    exit(1);
}
$dir=sys_get_temp_dir().'/mgsl-pack-contract-'.bin2hex(random_bytes(4));
mkdir($dir,0700,true);
$plain=$dir.'/plain.bin';
$encrypted=$dir.'/pack.mgbackup';
$decrypted=$dir.'/plain.out';
$payload=random_bytes(2*1024*1024+333);
file_put_contents($plain,$payload);

$enc=mg_saoluu_encrypt_pack_file($plain,$encrypted,SODIUM_TEST_PASSWORD,['pack'=>'settings','compression'=>'zstd-1']);
check_pack('secretstream encryption succeeds', !is_wp_error($enc) && is_file($encrypted));
$dec=mg_saoluu_decrypt_pack_file($encrypted,$decrypted,SODIUM_TEST_PASSWORD);
check_pack('secretstream decrypt roundtrip exact',
    !is_wp_error($dec) && hash('sha256',file_get_contents($decrypted))===hash('sha256',$payload));
check_pack('Pack v2 metadata roundtrips',
    ($dec['meta']['pack']??'')==='settings' && ($dec['meta']['compression']??'')==='zstd-1');

$tampered=$dir.'/tampered.mgbackup';
copy($encrypted,$tampered);
$h=fopen($tampered,'r+b');
fseek($h,-1,SEEK_END);
$last=ord(fread($h,1));
fseek($h,-1,SEEK_END);
fwrite($h,chr($last^0x01));
fclose($h);
$tampered_out=$dir.'/tampered.out';
$bad=mg_saoluu_decrypt_pack_file($tampered,$tampered_out,SODIUM_TEST_PASSWORD);
check_pack('tampered Pack v2 fails authentication', is_wp_error($bad) && !file_exists($tampered_out));

$wrong_out=$dir.'/wrong.out';
$wrong=mg_saoluu_decrypt_pack_file($encrypted,$wrong_out,'wrong-password-123456');
check_pack('wrong Recovery Key fails closed', is_wp_error($wrong) && !file_exists($wrong_out));

foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
@rmdir($dir);

echo "PACK TESTS {$GLOBALS['pack_tests']}/{$GLOBALS['pack_tests']} PASS\n";
