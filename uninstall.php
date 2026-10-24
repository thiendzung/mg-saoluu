<?php
/**
 * Chỉ xóa cài đặt khi chủ website chủ động cho phép trong wp-config.php:
 * define('MG_SAOLUU_DELETE_DATA_ON_UNINSTALL', true);
 * File sao lưu không bao giờ bị xóa tự động khi gỡ plugin.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

wp_clear_scheduled_hook('mg_saoluu_cron_daily');
wp_clear_scheduled_hook('mg_saoluu_cron_weekly');

if (!defined('MG_SAOLUU_DELETE_DATA_ON_UNINSTALL') || MG_SAOLUU_DELETE_DATA_ON_UNINSTALL !== true) {
    return;
}

$options = [
    'mg_saoluu_setup_complete',
    'mg_saoluu_installed_version',
    'mg_saoluu_data_version',
    'mg_saoluu_environment',
    'mg_saoluu_environment_verified_host',
    'mg_saoluu_block_emails',
    'mg_saoluu_force_noindex',
    'mg_saoluu_auto_sql',
    'mg_saoluu_auto_full',
    'mg_saoluu_encrypt_default',
    'mg_saoluu_drive_auto_upload',
    'mg_saoluu_drive_client_id',
    'mg_saoluu_drive_client_secret',
    'mg_saoluu_drive_token',
    'mg_saoluu_drive_folder_id',
    'mg_saoluu_retention',
    'mg_saoluu_mysql_binary',
    'mg_saoluu_mysqldump_binary',
    'mg_saoluu_audit_log',
    'mg_saoluu_managed_encryption_key',
    'mg_saoluu_managed_encryption_key_created_at',
    'mg_saoluu_recovery_key_exported_at',
    'mg_saoluu_device_id',
    'mg_saoluu_device_name',
    'mg_saoluu_device_public_key',
    'mg_saoluu_device_private_key',
    'mg_saoluu_device_created_at',
    'mg_saoluu_trusted_signers',
    'mg_saoluu_used_deploy_packages',
];
foreach ($options as $option) {
    delete_option($option);
}

delete_transient('mg_saoluu_operation_lock');
delete_transient('mg_saoluu_production_restore_unlocked');
