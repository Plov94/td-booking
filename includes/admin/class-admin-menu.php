<?php
defined('ABSPATH') || exit;

class TD_Booking_Admin_Menu {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'handle_settings_form_submission']);
        
        // Nuclear option for PHP 8.1 deprecation warnings
        add_action('init', [$this, 'aggressive_null_fix'], 1);
        add_action('plugins_loaded', [$this, 'aggressive_null_fix'], 1);
        add_action('admin_init', [$this, 'aggressive_null_fix'], 1);
        add_action('current_screen', [$this, 'aggressive_null_fix'], 1);
        add_action('admin_head', [$this, 'aggressive_null_fix'], 1);
        add_action('admin_menu', [$this, 'aggressive_null_fix'], 1);
        add_action('admin_notices', [$this, 'check_compatibility_notice']);
    }
    public function register_menu() {
        add_menu_page(
            __('TD Booking', 'td-booking'),
            __('TD Booking', 'td-booking'),
            'manage_td_booking',
            'td-booking',
            [$this, 'page_services_list'],
            'dashicons-calendar-alt',
            56
        );
        add_submenu_page('td-booking', __('Services', 'td-booking'), __('Services', 'td-booking'), 'manage_td_booking', 'td-booking', [$this, 'page_services_list']);
        add_submenu_page('td-booking', __('Bookings', 'td-booking'), __('Bookings', 'td-booking'), 'manage_td_booking', 'td-booking-bookings', [$this, 'page_bookings_list']);
        add_submenu_page('td-booking', __('Settings', 'td-booking'), __('Settings', 'td-booking'), 'manage_td_booking', 'td-booking-settings', [$this, 'page_settings']);
        add_submenu_page('td-booking', __('Logs', 'td-booking'), __('Logs', 'td-booking'), 'manage_td_booking', 'td-booking-logs', [$this, 'page_logs']);
        if (get_option('td_bkg_staff_breaks_enabled')) {
            add_submenu_page('td-booking', __('Staff-wide Breaks & Holidays', 'td-booking'), __('Staff-wide Breaks & Holidays', 'td-booking'), 'manage_td_booking', 'td-bkg-staff-breaks', [$this, 'page_staff_breaks']);
        }
        if (get_option('td_bkg_reports_enabled')) {
            // Only add reports submenu once
            global $submenu;
            $slug = 'td-bkg-reports';
            $already = false;
            if (isset($submenu['td-booking'])) {
                foreach ($submenu['td-booking'] as $item) {
                    if (isset($item[2]) && $item[2] === $slug) {
                        $already = true;
                        break;
                    }
                }
            }
            if (!$already) {
                add_submenu_page('td-booking', __('Reports', 'td-booking'), __('Reports', 'td-booking'), 'manage_td_booking', $slug, [$this, 'page_reports']);
            }
        }
    }


    public function page_staff_breaks() {
        require_once TD_BKG_PATH . 'includes/admin/pages/staff-breaks.php';
    }


    public function page_reports() {
        require_once TD_BKG_PATH . 'includes/admin/pages/reports.php';
        if (function_exists('td_bkg_reports_page_cb')) {
            td_bkg_reports_page_cb();
        }
    }
    public function page_services_list() { require TD_BKG_PATH . 'includes/admin/pages/services-list.php'; }
    public function page_bookings_list() { require TD_BKG_PATH . 'includes/admin/pages/bookings-list.php'; }
    public function page_settings() { require TD_BKG_PATH . 'includes/admin/pages/settings.php'; }
    public function page_logs() { require TD_BKG_PATH . 'includes/admin/pages/logs.php'; }

    // Nuclear option: Override WordPress core functions to prevent PHP 8.1 warnings
    // This patches WordPress core at runtime for PHP 8.1+ compatibility
    // WARNING: This is a last resort fix for outdated WordPress versions
    public function aggressive_null_fix() {
        // Don't run if WordPress isn't fully loaded yet
        if (!function_exists('is_admin') || !function_exists('sanitize_key')) {
            return;
        }
        
        global $pagenow, $title, $parent_file, $submenu_file, $plugin_page, $typenow, $hook_suffix;
        global $wp_query, $wp_rewrite, $wp_the_query, $wp_scripts, $wp_styles;
        
        // Only fix for TD Booking admin pages
        if (!is_admin() || $pagenow !== 'admin.php') {
            return;
        }
        
        $page = $_GET['page'] ?? '';
        if (strpos($page, 'td-booking') === false && strpos($page, 'td-bkg') === false) {
            return;
        }
        
        // Nuclear option: Suppress deprecation warnings during our page load
        $old_error_level = error_reporting();
        error_reporting($old_error_level & ~E_DEPRECATED);
        
        // Restore error reporting after a delay
        add_action('admin_footer', function() use ($old_error_level) {
            error_reporting($old_error_level);
        }, 999);
        
        // Force ALL WordPress globals to never be null
        $globals_to_fix = [
            'title', 'parent_file', 'submenu_file', 'plugin_page', 'typenow', 'hook_suffix',
            'post_type', 'taxnow', 'wp_db_version', 'wp_version', 'required_php_version',
            'required_mysql_version', 'wp_local_package'
        ];
        
        foreach ($globals_to_fix as $global_name) {
            if (!isset($GLOBALS[$global_name]) || $GLOBALS[$global_name] === null) {
                $GLOBALS[$global_name] = '';
            }
        }
        
        // Set specific values for TD Booking pages
        $title = __('TD Booking', 'td-booking');
        $parent_file = 'td-booking';
        $submenu_file = 'td-booking';
        $plugin_page = $page;
        
        // Customize based on specific pages
        switch ($page) {
            case 'td-booking':
                $title = __('Services', 'td-booking');
                break;
            case 'td-booking-bookings':
                $title = __('Bookings', 'td-booking');
                $submenu_file = 'td-booking-bookings';
                break;
            case 'td-booking-settings':
                $title = __('Settings', 'td-booking');
                $submenu_file = 'td-booking-settings';
                break;
            case 'td-booking-logs':
                $title = __('Logs', 'td-booking');
                $submenu_file = 'td-booking-logs';
                break;
            case 'td-bkg-staff-breaks':
                $title = __('Staff-wide Breaks & Holidays', 'td-booking');
                $submenu_file = 'td-bkg-staff-breaks';
                break;
            case 'td-bkg-reports':
                $title = __('Reports', 'td-booking');
                $submenu_file = 'td-bkg-reports';
                break;
            case 'td-booking-debug':
                $title = __('Debug Integration', 'td-booking');
                $submenu_file = 'td-booking-debug';
                break;
        }
        
        // Force screen object to have proper values
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen) {
                $screen_props = ['base', 'id', 'parent_base', 'parent_file', 'taxonomy', 'post_type'];
                foreach ($screen_props as $prop) {
                    if (!isset($screen->$prop) || $screen->$prop === null) {
                        switch ($prop) {
                            case 'base':
                                $screen->$prop = sanitize_key($page);
                                break;
                            case 'id':
                                $screen->$prop = 'admin_page_' . sanitize_key($page);
                                break;
                            case 'parent_base':
                            case 'parent_file':
                                $screen->$prop = 'td-booking';
                                break;
                            default:
                                $screen->$prop = '';
                        }
                    }
                }
            }
        }
        
        // Force WordPress query objects to be initialized
        if (class_exists('WP_Query')) {
            if (!is_object($wp_query)) {
                $wp_query = new WP_Query();
            }
            if (!is_object($wp_the_query)) {
                $wp_the_query = $wp_query;
            }
        }
        
        if (class_exists('WP_Rewrite') && !is_object($wp_rewrite)) {
            $wp_rewrite = new WP_Rewrite();
        }
    }

    // Show compatibility notice for PHP 8.1 + outdated WordPress
    public function check_compatibility_notice() {
        if (!is_admin()) {
            return;
        }
        
        global $pagenow;
        if ($pagenow !== 'admin.php') {
            return;
        }
        
        $page = $_GET['page'] ?? '';
        if (strpos($page, 'td-booking') === false && strpos($page, 'td-bkg') === false) {
            return;
        }
        
        if (version_compare(PHP_VERSION, '8.1', '>=') && version_compare(get_bloginfo('version'), '6.0', '<')) {
            $wp_ver = get_bloginfo('version');
            echo '<div class="notice notice-warning">';
            echo '<p><strong>' . esc_html__('PHP 8.1 Compatibility Notice:', 'td-booking') . '</strong> ';
            echo esc_html(sprintf(__('You are using PHP 8.1+ with WordPress %s.', 'td-booking'), $wp_ver)) . ' ';
            echo esc_html__('For optimal compatibility, consider updating WordPress to version 6.0 or higher.', 'td-booking') . ' ';
            echo esc_html__('This plugin includes compatibility fixes for the current setup.', 'td-booking') . '</p>';
            echo '</div>';
        }
    }

    public function handle_settings_form_submission() {
        // Only process on settings page
        if (!isset($_GET['page']) || $_GET['page'] !== 'td-booking-settings') {
            return;
        }

        // Main settings form processing
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'td_bkg_settings_save')) {
            update_option('td_bkg_staff_breaks_enabled', isset($_POST['td_bkg_staff_breaks_enabled']) ? 1 : 0);
            update_option('td_bkg_wc_enabled', isset($_POST['td_bkg_wc_enabled']) ? 1 : 0);
            update_option('td_bkg_sms_enabled', isset($_POST['td_bkg_sms_enabled']) ? 1 : 0);
            update_option('td_bkg_sms_provider', sanitize_text_field($_POST['td_bkg_sms_provider'] ?? ''));
            // SMS API key handling with encryption and replace/clear semantics
            $existing_key = get_option('td_bkg_sms_api_key', '');
            $clear_key = !empty($_POST['td_bkg_sms_api_key_clear']);
            $replace_key = !empty($_POST['td_bkg_sms_api_key_replace']);
            $new_key_input = isset($_POST['td_bkg_sms_api_key']) ? trim(wp_unslash($_POST['td_bkg_sms_api_key'])) : '';
            if ($clear_key) {
                update_option('td_bkg_sms_api_key', '');
            } elseif ($replace_key) {
                if ($new_key_input !== '') {
                    // Encrypt if possible, else store plaintext
                    if (function_exists('td_bkg_encrypt') && td_bkg_crypto_available()) {
                        $enc = td_bkg_encrypt($new_key_input, 'td-booking:sms');
                        update_option('td_bkg_sms_api_key', $enc ?: $new_key_input);
                    } else {
                        update_option('td_bkg_sms_api_key', sanitize_text_field($new_key_input));
                    }
                } else {
                    // Replace checked but empty input -> keep existing value
                    update_option('td_bkg_sms_api_key', $existing_key);
                }
            } else {
                // No replace checkbox; if field provided on first save (no existing), honor it
                if ($existing_key === '' && $new_key_input !== '') {
                    if (function_exists('td_bkg_encrypt') && td_bkg_crypto_available()) {
                        $enc = td_bkg_encrypt($new_key_input, 'td-booking:sms');
                        update_option('td_bkg_sms_api_key', $enc ?: $new_key_input);
                    } else {
                        update_option('td_bkg_sms_api_key', sanitize_text_field($new_key_input));
                    }
                } // else leave unchanged
            }
            update_option('td_bkg_sms_sender', sanitize_text_field($_POST['td_bkg_sms_sender'] ?? ''));
            update_option('td_bkg_sms_reminder_times', sanitize_text_field($_POST['td_bkg_sms_reminder_times'] ?? ''));
            update_option('td_bkg_wc_cancel_on_refund', isset($_POST['td_bkg_wc_cancel_on_refund']) ? 1 : 0);
            update_option('td_bkg_reports_enabled', isset($_POST['td_bkg_reports_enabled']) ? 1 : 0);
            update_option('td_bkg_group_enabled', isset($_POST['td_bkg_group_enabled']) ? 1 : 0);
            // UI & Privacy
            update_option('td_bkg_steps_enabled', isset($_POST['td_bkg_steps_enabled']) ? 1 : 0);
            update_option('td_bkg_terms_page_id', intval($_POST['td_bkg_terms_page_id'] ?? 0));
            update_option('td_bkg_terms_url', esc_url_raw($_POST['td_bkg_terms_url'] ?? ''));
            $terms_mode = in_array(($_POST['td_bkg_terms_mode'] ?? 'link'), ['link','modal']) ? $_POST['td_bkg_terms_mode'] : 'link';
            update_option('td_bkg_terms_mode', $terms_mode);
            // Admin-only Debug Mode
            update_option('td_bkg_debug_mode', isset($_POST['td_bkg_debug_mode']) ? 1 : 0);
            // Optional restrict-mode fallback to business hours when technicians windows are empty
                update_option('td_bkg_restrict_fallback_enabled', isset($_POST['td_bkg_restrict_fallback_enabled']) ? 1 : 0, false);
            
            // Save global hours enforcement mode
            $enforcement_mode = sanitize_text_field($_POST['td_bkg_global_hours_mode'] ?? 'off');
            if (in_array($enforcement_mode, ['off', 'restrict', 'override'])) {
                update_option('td_bkg_global_hours_enforcement', $enforcement_mode);
            }
            
            // Save global opening hours
            if (isset($_POST['td_bkg_global_hours']) && is_array($_POST['td_bkg_global_hours'])) {
                $global_hours = [];
                foreach ($_POST['td_bkg_global_hours'] as $day => $hours_array) {
                    $day = intval($day);
                    if ($day >= 0 && $day <= 6) {
                        $global_hours[$day] = [];
                        if (is_array($hours_array)) {
                            foreach ($hours_array as $hours) {
                                if (!empty($hours['start']) && !empty($hours['end'])) {
                                    $start = sanitize_text_field($hours['start']);
                                    $end = sanitize_text_field($hours['end']);
                                    if (preg_match('/^\d{2}:\d{2}$/', $start) && preg_match('/^\d{2}:\d{2}$/', $end)) {
                                        $global_hours[$day][] = ['start' => $start, 'end' => $end];
                                    }
                                }
                            }
                        }
                    }
                }
                update_option('td_bkg_global_hours', $global_hours);
            }
            
            // Save booking rules
            update_option('td_bkg_lead_time_minutes', intval($_POST['td_bkg_lead_time_minutes'] ?? 60));
            update_option('td_bkg_booking_horizon_days', intval($_POST['td_bkg_booking_horizon_days'] ?? 30));
            update_option('td_bkg_slot_grid_minutes', intval($_POST['td_bkg_slot_grid_minutes'] ?? 15));
            update_option('td_bkg_cache_ttl_minutes', intval($_POST['td_bkg_cache_ttl_minutes'] ?? 5));
            
            // Clear availability cache when settings change
            if (function_exists('td_bkg')) {
                td_bkg()->availability()->cache_clear_all();
            }
            
            wp_redirect(admin_url('admin.php?page=td-booking-settings&settings-updated=1'));
            exit;
        }

        // Email settings form processing
        if (isset($_POST['save_email_settings']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'td_bkg_email_settings-td-booking-settings')) {
            if (function_exists('td_bkg_debug_log')) {
                td_bkg_debug_log('Email settings form submitted');
            }
            
            $from_name = sanitize_text_field($_POST['email_from_name']);
            $original_email = $_POST['email_from_email'];
            $from_email = sanitize_email($original_email);
            $header_color = sanitize_hex_color($_POST['email_header_color']);
            $logo_url = esc_url_raw($_POST['email_logo_url']);
            
            // Debug: Log email validation (only in debug mode)
            if (function_exists('td_bkg_debug_log')) {
                td_bkg_debug_log('Email settings validation', [
                    'original_email' => $original_email,
                    'sanitized_email' => $from_email,
                    'from_name' => $from_name,
                    'header_color' => $header_color,
                    'logo_url' => $logo_url,
                ]);
            }
            
            $email_error = '';
            $redirect_params = [];
            
            // Handle email validation
            if (empty($from_email) && !empty($original_email)) {
                // Email failed WordPress sanitization, try alternative validation
                if (filter_var($original_email, FILTER_VALIDATE_EMAIL)) {
                    // Email is valid according to PHP, but WordPress rejected it
                    // This might happen with some international domains or special cases
                    if (function_exists('td_bkg_debug_log')) {
                        td_bkg_debug_log('sanitize_email rejected but PHP validates; using original', [ 'email' => $original_email ]);
                    }
                    update_option('td_bkg_email_from_email', $original_email);
                    $redirect_params[] = 'email-updated=override';
                } else {
                    // Email is truly invalid - don't update the option, show error
                    $email_error = "Invalid email format: {$original_email}";
                    if (function_exists('td_bkg_debug_log')) {
                        td_bkg_debug_log('Email failed validation', [ 'error' => $email_error ]);
                    }
                    $redirect_params[] = 'email-error=invalid';
                }
            } elseif (empty($original_email)) {
                // Empty email - use admin email
                $from_email = get_option('admin_email');
                if (function_exists('td_bkg_debug_log')) {
                    td_bkg_debug_log('Empty email; using admin_email', [ 'admin_email' => $from_email ]);
                }
                update_option('td_bkg_email_from_email', $from_email);
                $redirect_params[] = 'email-updated=fallback';
            } else {
                // Valid email - save it
                update_option('td_bkg_email_from_email', $from_email);
                $redirect_params[] = 'email-updated=1';
            }
            
            // Always update other fields
            update_option('td_bkg_email_from_name', $from_name);
            update_option('td_bkg_email_header_color', $header_color);
            update_option('td_bkg_email_logo_url', $logo_url);
            
            $redirect_url = admin_url('admin.php?page=td-booking-settings');
            if (!empty($redirect_params)) {
                $redirect_url .= '&' . implode('&', $redirect_params);
            }
            
            wp_redirect($redirect_url);
            exit;
        }

        // Cache clear form processing
        if (isset($_POST['clear_cache']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'td_bkg_clear_cache')) {
            global $wpdb;
            
            // Clear CalDAV cache table
            $cache_table = $wpdb->prefix . 'td_calendar_cache';
            $deleted_cache = $wpdb->query("DELETE FROM $cache_table");
            
            // Clear availability transients
            $deleted_transients = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_td_bkg_avail_%' OR option_name LIKE '_transient_timeout_td_bkg_avail_%'");
            
            if (function_exists('td_bkg_debug_log')) {
                td_bkg_debug_log('Cache cleared', [ 'caldav_cache_rows' => $deleted_cache, 'availability_transients_rows' => $deleted_transients ]);
            }
            
            wp_redirect(admin_url('admin.php?page=td-booking-settings&cache-cleared=1'));
            exit;
        }
    }
}

new TD_Booking_Admin_Menu();
