<?php
/**
 * Uninstall handler for WP LLMS.
 *
 * Only runs when the user explicitly deletes the plugin via the WP admin
 * UI - not on simple deactivation. Honors the user's setting for whether
 * to remove all data or preserve it for future reactivation.
 *
 * @package WPLlms
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('wpllms_settings', []);
$remove_data = is_array($settings) && !empty($settings['remove_data_on_uninstall']);

// Always remove physical files on uninstall - they're useless without the plugin.
foreach (['llms.txt', 'llms-full.txt'] as $static_file) {
    $path = rtrim(ABSPATH, '/\\') . DIRECTORY_SEPARATOR . $static_file;
    if (file_exists($path) && is_writable($path)) {
        @unlink($path);
    }
}

if (!$remove_data) {
    return;
}

global $wpdb;

$tables = [
    $wpdb->prefix . 'wpllms_sections',
    $wpdb->prefix . 'wpllms_overrides',
    $wpdb->prefix . 'wpllms_audit_issues',
    $wpdb->prefix . 'wpllms_bot_hits',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

$options = [
    'wpllms_settings',
    'wpllms_license',
    'wpllms_dirty',
    'wpllms_schema_version',
    'wpllms_setup_completed',
];

foreach ($options as $option) {
    delete_option($option);
}

wp_clear_scheduled_hook('wpllms_daily_regen');
wp_clear_scheduled_hook('wpllms_regen_llms_txt');
wp_clear_scheduled_hook('wpllms_regen_llms_full');
