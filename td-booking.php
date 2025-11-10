<?php
/*
Plugin Name: TD Booking
Description: Booking engine with technician assignment and Nextcloud CalDAV sync. Requires TD Technicians plugin.
Version: 0.9.0
Author: Gabriel K. Sagaard
Text Domain: td-booking
Domain Path: /languages
*/

// Plugin constants
define('TD_BKG_VER', '0.1.0');
define('TD_BKG_API_VERSION', '1.0.0');
define('TD_BKG_MIN_TECH_API', '1.0.0');

define('TD_BKG_PATH', plugin_dir_path(__FILE__));
define('TD_BKG_URL', plugin_dir_url(__FILE__));




// Load text domain
function td_bkg_load_textdomain() {
    load_plugin_textdomain('td-booking', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'td_bkg_load_textdomain');
// Bootstrap loader
add_action('plugins_loaded', function() {
    require_once TD_BKG_PATH . 'includes/loader.php';
    
    // Load hooks system
    require_once TD_BKG_PATH . 'includes/hooks.php';
    
    // Load WP-CLI commands
    if (defined('WP_CLI') && WP_CLI) {
        require_once TD_BKG_PATH . 'includes/cli.php';
    }
});

// Activation/Deactivation
register_activation_hook(__FILE__, function() {
    require_once TD_BKG_PATH . 'includes/activator.php';
    td_bkg_activate();
});
register_deactivation_hook(__FILE__, function() {
    // Optionally add deactivation logic
});
