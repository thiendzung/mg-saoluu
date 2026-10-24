<?php
/** Scheduled backup health regression harness: pure fixtures; no WordPress or network. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('ABSPATH', sys_get_temp_dir() . '/mgsl-health-fixture/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['mgsl_options'] = [];
$GLOBALS['mgsl_next'] = [];
$GLOBALS['mgsl_metadata'] = [];
$GLOBALS['mgsl_events'] = [];
$GLOBALS['mgsl_tests'] = 0;

class WP_Error {
    private string $code;
    public function __construct($code, $message = '') { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_option($key, $fallback = false) { return $GLOBALS['mgsl_options'][$key] ?? $fallback; }
function update_option($key, $value, $autoload = false) { $GLOBALS['mgsl_options'][$key] = $value; return true; }
function wp_next_scheduled($hook) { return $GLOBALS['mgsl_next'][$hook] ?? false; }
function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_-]/', '', (string) $key)); }
function mg_saoluu_get_backup_dir() { return '/not-used-for-io/'; }
function mg_saoluu_read_metadata($path) { return $GLOBALS['mgsl_metadata']; }
function mg_saoluu_debug_log($event, $context = []) { $GLOBALS['mgsl_events'][] = [$event, $context]; return true; }
function check_health($label, $pass) {
    $GLOBALS['mgsl_tests']++;
    if (!$pass) { fwrite(STDERR, "FAIL $label\n"); exit(1); }
    echo "PASS $label\n";
}

$base = rtrim((string) (getenv('MGSL_TEST_PLUGIN_DIR') ?: dirname(__DIR__)), '/');
require_once $base . '/includes/schedule-health.php';

$now = time();
check_health('disabled schedule is not called healthy',
    mg_saoluu_schedule_health_report('sql', $now)['state'] === 'disabled');
check_health('unsupported type is refused', mg_saoluu_schedule_health_record('other', 'backup.zip') === false);
$GLOBALS['mgsl_options']['mg_saoluu_auto_sql'] = '1';
check_health('enabled but missing WP-Cron event is flagged',
    mg_saoluu_schedule_health_report('sql', $now)['state'] === 'schedule_missing');
$GLOBALS['mgsl_next']['mg_saoluu_cron_daily'] = $now + 3600;
check_health('before first cron run never claims success',
    mg_saoluu_schedule_health_report('sql', $now)['state'] === 'awaiting_first_run');

$file = 'backup_auto_sql_20261024_120000.mgbackup';
$r = mg_saoluu_schedule_health_record('sql', $file);
check_health('local success recorded independently of Drive',
    $r['last_result'] === 'ok' && $r['last_local_success_at'] > 0 &&
    mg_saoluu_schedule_health_report('sql', time())['state'] === 'ok');
$success_time = $r['last_local_success_at'];

$r = mg_saoluu_schedule_health_record('sql', new WP_Error('mysql_cli_missing', 'Sensitive path and password'));
check_health('failure preserves last known good local backup',
    $r['last_result'] === 'error' && $r['last_code'] === 'mysql_cli_missing' &&
    $r['last_local_success_at'] === $success_time &&
    mg_saoluu_schedule_health_report('sql', time())['state'] === 'last_attempt_failed');
check_health('audit status contains no sensitive error message',
    strpos(json_encode($GLOBALS['mgsl_options']['mg_saoluu_schedule_health']), 'Sensitive') === false);

$GLOBALS['mgsl_metadata'][$file] = ['drive_status' => 'uploaded_unverified'];
$r = mg_saoluu_schedule_health_record('sql', $file, true);
check_health('uploaded Drive is not treated as verified offsite restore',
    $r['last_result'] === 'offsite_unverified' &&
    mg_saoluu_schedule_health_report('sql', time())['state'] === 'offsite_unverified');

$GLOBALS['mgsl_metadata'][$file] = ['drive_status' => 'failed'];
$r = mg_saoluu_schedule_health_record('sql', $file, true);
check_health('failed Drive upload does not become green backup status',
    $r['last_result'] === 'offsite_failed' &&
    mg_saoluu_schedule_health_report('sql', time())['state'] === 'offsite_failed');
check_health('no private archive filename in persisted health',
    strpos(json_encode($GLOBALS['mgsl_options']['mg_saoluu_schedule_health']), $file) === false);

unset($GLOBALS['mgsl_metadata'][$file]);
$r = mg_saoluu_schedule_health_record('sql', $file, true);
check_health('missing Drive catalog fails closed', $r['last_result'] === 'offsite_failed');

$GLOBALS['mgsl_next']['mg_saoluu_cron_daily'] = $now - 3600;
check_health('overdue WP-Cron event is detected on read',
    mg_saoluu_schedule_health_report('sql', $now)['state'] === 'cron_late');

$GLOBALS['mgsl_next']['mg_saoluu_cron_daily'] = $now + 3600;
$GLOBALS['mgsl_options']['mg_saoluu_schedule_health']['sql']['last_local_success_at'] = $now - 3 * DAY_IN_SECONDS;
check_health('daily successful backup older than 36h is overdue',
    mg_saoluu_schedule_health_report('sql', $now)['state'] === 'backup_overdue');

$GLOBALS['mgsl_options']['mg_saoluu_auto_full'] = '1';
$GLOBALS['mgsl_next']['mg_saoluu_cron_weekly'] = $now + 86400;
$GLOBALS['mgsl_options']['mg_saoluu_schedule_health']['safe_full'] = [
    'last_attempt_at' => $now - 10 * DAY_IN_SECONDS,
    'last_local_success_at' => $now - 10 * DAY_IN_SECONDS,
    'last_result' => 'ok',
];
check_health('weekly backup older than 9d is overdue',
    mg_saoluu_schedule_health_report('safe_full', $now)['state'] === 'backup_overdue');
check_health('debug events expose only allowed status fields',
    count($GLOBALS['mgsl_events']) >= 4 &&
    array_keys($GLOBALS['mgsl_events'][0][1]) === ['type', 'result', 'code']);
echo "TESTS {$GLOBALS['mgsl_tests']}/{$GLOBALS['mgsl_tests']} PASS\n";
