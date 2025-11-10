<?php
defined('ABSPATH') || exit;

function td_bkg_activate() {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    require_once TD_BKG_PATH . 'includes/schema.php';
    td_bkg_create_tables();
    
    // Update database version
    update_option('td_bkg_db_version', TD_BKG_VER);
}

// Check for database updates on plugin load
function td_bkg_check_database_version() {
    $installed_version = get_option('td_bkg_db_version', '0.0.0');
    if (version_compare($installed_version, TD_BKG_VER, '<')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        require_once TD_BKG_PATH . 'includes/schema.php';
        td_bkg_create_tables();
        update_option('td_bkg_db_version', TD_BKG_VER);
    }
}
add_action('admin_init', 'td_bkg_check_database_version');

// Also ensure schema is up-to-date on public requests (throttled)
function td_bkg_maybe_fix_schema() {
    // Avoid running on every request: throttle via transient
    if (get_transient('td_bkg_schema_checked')) return;
    set_transient('td_bkg_schema_checked', 1, 10 * MINUTE_IN_SECONDS);
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    require_once TD_BKG_PATH . 'includes/schema.php';
    td_bkg_create_tables();
}
add_action('init', 'td_bkg_maybe_fix_schema', 20);
