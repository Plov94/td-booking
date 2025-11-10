<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

// Only drop tables if option is set
global $wpdb;
if (get_option('td_bkg_allow_hard_uninstall')) {
    $tables = [
        'td_service', 'td_service_staff', 'td_booking', 'td_calendar_cache', 'td_exception', 'td_audit'
    ];
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}$table");
    }
}
