<?php
/** Standalone worker regression with fake mysql/mysqldump; no WordPress, no real DB. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$plugin = rtrim((string)(getenv('MGSL_TEST_PLUGIN_DIR') ?: dirname(__DIR__)), '/');
$worker = $plugin . '/bin/pack-restore-worker.php';
$count = 0;
function wt($name, $ok) {
    global $count; $count++;
    if (!$ok) { fwrite(STDERR, "FAIL $name\n"); exit(1); }
    echo "PASS $name\n";
}
function wjson($path, $data) {
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    chmod($path, 0600);
}
function make_worker_fixture($root, $initial, $target, $expected_hash) {
    $work = $root . '/job-' . bin2hex(random_bytes(3));
    mkdir($work, 0700, true);
    $state = $root . '/state-' . basename($work) . '.txt';
    file_put_contents($state, $initial);
    $dump = $root . '/fake-dump-' . basename($work) . '.sh';
    $mysql = $root . '/fake-mysql-' . basename($work) . '.sh';
    file_put_contents($dump, "#!/bin/sh\ncat " . escapeshellarg($state) . "\n");
    file_put_contents($mysql, "#!/bin/sh\ncat > " . escapeshellarg($state) . "\n");
    chmod($dump, 0700); chmod($mysql, 0700);
    $config = $work . '/mysql.cnf'; file_put_contents($config, "[client]\n"); chmod($config,0600);
    $target_sql = $work . '/target.sql'; file_put_contents($target_sql, $target); chmod($target_sql,0600);
    $journal = $work . '/journal.json';
    $jobfile = $work . '/job.json';
    $job = [
        'version'=>1,
        'job_id'=>basename($work),
        'journal'=>$journal,
        'work_dir'=>$work,
        'pack'=>'full_db',
        'database'=>'fixture',
        'mysql_binary'=>$mysql,
        'mysqldump_binary'=>$dump,
        'mysql_config'=>$config,
        'target_sql'=>$target_sql,
        'expected_state_hash'=>$expected_hash,
        'dump_options'=>[],
        'tables'=>[],
        'cleanup_sql'=>'',
        'qa_force_post_verify_mismatch'=>false,
    ];
    wjson($jobfile,$job);
    return [$work,$state,$dump,$mysql,$journal,$jobfile];
}
function run_worker($worker,$jobfile) {
    $out=[];$status=1;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($worker).' '.escapeshellarg($jobfile).' 2>&1',$out,$status);
    return [$status,$out];
}

$src=file_get_contents($worker);
wt('worker is CLI-only', str_contains($src, "PHP_SAPI !== 'cli'"));
wt('worker never bootstraps WordPress', !str_contains($src,'wp-load.php') && !str_contains($src,'ABSPATH') && !str_contains($src,'update_option(') && !str_contains($src,'set_transient('));

$packs_src=file_get_contents($plugin . '/includes/packs.php');
wt('RC9 worker launch is synchronous shutdown execution',
    str_contains($packs_src, "register_shutdown_function(static function()")
    && str_contains($packs_src, "fastcgi_finish_request")
    && !str_contains($packs_src, "nohup")
    && !str_contains($packs_src, "2>&1 < /dev/null &"));
wt('worker no longer acquires a second global flock',
    !str_contains($src, "global_lock") && !str_contains($src, "flock("));

wt('worker uses pack-aware logical Settings hash',
    str_contains($src, 'function mgw_state_hash_line')
    && str_contains($src, '<OPTION_ID>')
    && str_contains($src, "mgw_state_hash($verify, (string)$job['pack'])"));

$root=sys_get_temp_dir().'/mgsl-worker-contract-'.bin2hex(random_bytes(4));
mkdir($root,0700,true);

// Simulate a third-party shutdown write before worker starts. Worker must be final DB writer.
$target='baseline-target-state';
[$work,$state,$dump,$mysql,$journal,$jobfile]=make_worker_fixture($root,'third-party-shutdown-drift',$target,hash('sha256',$target));
[$status,$out]=run_worker($worker,$jobfile);
$j=json_decode((string)file_get_contents($journal),true);
wt('worker restores exact target after prior shutdown drift', $status===0 && ($j['status']??'')==='completed' && file_get_contents($state)===$target && ($j['post_verify']??false)===true);

// Force a post-verify mismatch. Worker must restore the exact pre-apply checkpoint.
[$work2,$state2,$dump2,$mysql2,$journal2,$jobfile2]=make_worker_fixture($root,'checkpoint-before-restore','imported-but-unexpected',hash('sha256','expected-other-state'));
[$status2,$out2]=run_worker($worker,$jobfile2);
$j2=json_decode((string)file_get_contents($journal2),true);
wt('post-verify mismatch never returns success', $status2!==0 && ($j2['status']??'')==='restore_post_verify_failed_reverted');
wt('post-verify mismatch restores exact checkpoint', file_get_contents($state2)==='checkpoint-before-restore' && ($j2['rollback_verified']??false)===true);

foreach (glob($root.'/*') ?: [] as $p) {
    if (is_dir($p)) { foreach (glob($p.'/*') ?: [] as $q) @unlink($q); @rmdir($p); }
    else @unlink($p);
}
@rmdir($root);
echo "PACK WORKER TESTS $count/$count PASS\n";
