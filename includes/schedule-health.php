<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Read-only health and bounded outcome recording for scheduled backups.
 * These records contain only timestamps, fixed statuses, and error codes.
 * Never store credentials, filenames, filesystem paths, or backup contents.
 */
function mg_saoluu_schedule_health_record($type, $backup_result, $offsite_requested = false) {
    if (!in_array($type, ['sql', 'safe_full'], true)) return false;

    $all = get_option('mg_saoluu_schedule_health', []);
    if (!is_array($all)) $all = [];
    $row = isset($all[$type]) && is_array($all[$type]) ? $all[$type] : [];
    $now = time();
    $row['last_attempt_at'] = $now;
    $row['last_code'] = '';
    $row['last_offsite'] = $offsite_requested ? 'unknown' : 'not_requested';

    if (is_wp_error($backup_result)) {
        $row['last_result'] = 'error';
        $row['last_code'] = sanitize_key((string) $backup_result->get_error_code());
    } elseif (!is_string($backup_result) || $backup_result === '') {
        $row['last_result'] = 'error';
        $row['last_code'] = 'invalid_backup_result';
    } else {
        // create_backup() has verified and catalogued this exact artifact.
        $row['last_local_success_at'] = $now;
        $row['last_result'] = 'ok';
        if ($offsite_requested) {
            $metadata = mg_saoluu_read_metadata(mg_saoluu_get_backup_dir() . 'metadata.json');
            $name = basename($backup_result);
            $drive = is_array($metadata) && isset($metadata[$name]) && is_array($metadata[$name])
                ? (string) ($metadata[$name]['drive_status'] ?? '') : '';
            if (in_array($drive, ['uploaded', 'uploaded_unverified'], true)) {
                $row['last_result'] = 'offsite_unverified';
                $row['last_offsite'] = 'uploaded_unverified';
            } else {
                $row['last_result'] = 'offsite_failed';
                $row['last_offsite'] = 'failed';
                $row['last_code'] = 'offsite_not_uploaded';
            }
        }
    }
    $all[$type] = $row;
    update_option('mg_saoluu_schedule_health', $all, false);
    if (function_exists('mg_saoluu_debug_log')) {
        mg_saoluu_debug_log('cron_backup_result', [
            'type' => $type, 'result' => $row['last_result'], 'code' => $row['last_code'],
        ]);
    }
    return $row;
}

/** Pure report; viewing the admin dashboard never starts/repairs a job. */
function mg_saoluu_schedule_health_report($type, $now = null) {
    $settings = [
        'sql' => ['enabled' => 'mg_saoluu_auto_sql', 'hook' => 'mg_saoluu_cron_daily', 'max_age' => 36 * HOUR_IN_SECONDS],
        'safe_full' => ['enabled' => 'mg_saoluu_auto_full', 'hook' => 'mg_saoluu_cron_weekly', 'max_age' => 9 * DAY_IN_SECONDS],
    ];
    if (!isset($settings[$type])) return [];
    $cfg = $settings[$type];
    $now = $now === null ? time() : (int) $now;
    $all = get_option('mg_saoluu_schedule_health', []);
    $row = is_array($all) && isset($all[$type]) && is_array($all[$type]) ? $all[$type] : [];
    $enabled = get_option($cfg['enabled'], '0') === '1';
    $next = $enabled ? wp_next_scheduled($cfg['hook']) : false;
    $last_success = max(0, (int) ($row['last_local_success_at'] ?? 0));
    $last_attempt = max(0, (int) ($row['last_attempt_at'] ?? 0));
    $last_result = (string) ($row['last_result'] ?? '');

    if (!$enabled) $state = 'disabled';
    elseif ($next === false) $state = 'schedule_missing';
    elseif ((int) $next < $now - 15 * MINUTE_IN_SECONDS) $state = 'cron_late';
    elseif ($last_success > 0 && $now - $last_success > $cfg['max_age']) $state = 'backup_overdue';
    elseif ($last_result === 'error') $state = 'last_attempt_failed';
    elseif ($last_result === 'offsite_failed') $state = 'offsite_failed';
    elseif ($last_success === 0) $state = 'awaiting_first_run';
    elseif ($last_result === 'offsite_unverified') $state = 'offsite_unverified';
    else $state = 'ok';

    $labels = [
        'disabled' => 'Đang tắt',
        'schedule_missing' => 'Thiếu lịch WP-Cron',
        'cron_late' => 'WP-Cron chạy trễ',
        'backup_overdue' => 'Backup quá hạn',
        'last_attempt_failed' => 'Lần sao lưu gần nhất thất bại',
        'offsite_failed' => 'Backup host OK, gửi Drive lỗi',
        'awaiting_first_run' => 'Chưa có lượt sao lưu tự động thành công',
        'offsite_unverified' => 'Backup host OK, Drive chưa kiểm chứng tải lại',
        'ok' => 'Đúng lịch',
    ];

    return [
        'state' => $state,
        'label' => $labels[$state],
        'ok' => $state === 'ok',
        'enabled' => $enabled,
        'next_at' => $next === false ? 0 : (int) $next,
        'last_attempt_at' => $last_attempt,
        'last_local_success_at' => $last_success,
        'last_code' => sanitize_key((string) ($row['last_code'] ?? '')),
    ];
}
