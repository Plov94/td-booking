
<?php
defined('ABSPATH') || exit;

echo '<h1>' . esc_html__('TD Booking Settings', 'td-booking') . '</h1>';

// Show success messages based on URL parameters
if (isset($_GET['settings-updated'])) {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved successfully!', 'td-booking') . '</p></div>';
}
if (isset($_GET['email-updated'])) {
    if ($_GET['email-updated'] === 'fallback') {
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Email was empty, using admin email as fallback.', 'td-booking') . '</p></div>';
    } elseif ($_GET['email-updated'] === 'override') {
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Email saved with override (WordPress validation was too strict).', 'td-booking') . '</p></div>';
    } else {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Email settings saved successfully!', 'td-booking') . '</p></div>';
    }
}
if (isset($_GET['email-error'])) {
    if ($_GET['email-error'] === 'invalid') {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Invalid email format. Please enter a valid email address. Settings were not saved.', 'td-booking') . '</p></div>';
    }
}
if (isset($_GET['cache-cleared'])) {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cache cleared successfully!', 'td-booking') . '</p></div>';
}

// Advanced Availability Settings
echo '<hr><h2>' . esc_html__('Availability Settings', 'td-booking') . '</h2>';

// DEBUG: Show what's actually saved vs defaults
$global_hours_raw = get_option('td_bkg_global_hours', []);
$default_hours = [
    '0' => [], // Sunday
    '1' => [['start' => '09:00', 'end' => '17:00']], // Monday
    '2' => [['start' => '09:00', 'end' => '17:00']], // Tuesday
    '3' => [['start' => '09:00', 'end' => '17:00']], // Wednesday
    '4' => [['start' => '09:00', 'end' => '17:00']], // Thursday
    '5' => [['start' => '09:00', 'end' => '17:00']], // Friday
    '6' => [], // Saturday
];



// Global opening hours - use saved data or defaults for display only
$global_hours = !empty($global_hours_raw) ? $global_hours_raw : $default_hours;

echo '<form method="post">';
wp_nonce_field('td_bkg_settings_save');

echo '<h3>' . esc_html__('Advanced Availability Settings', 'td-booking') . '</h3>';

// Global hours enforcement mode (use the same key as the engine/handler)
$enforce_mode = get_option('td_bkg_global_hours_enforcement', 'restrict');
echo '<h4>' . esc_html__('Global Business Hours', 'td-booking') . '</h4>';
echo '<p>' . esc_html__('Control when customers can book appointments across all technicians.', 'td-booking') . '</p>';

echo '<table class="form-table">';
echo '<tr><th scope="row">' . esc_html__('Enforcement Mode', 'td-booking') . '</th><td>';
echo '<select name="td_bkg_global_hours_mode" style="width: 250px;">';
echo '<option value="off" ' . selected($enforce_mode, 'off', false) . '>' . esc_html__('Off - Use technician schedules only', 'td-booking') . '</option>';
echo '<option value="restrict" ' . selected($enforce_mode, 'restrict', false) . '>' . esc_html__('Restrict - Limit to business hours', 'td-booking') . '</option>';
echo '<option value="override" ' . selected($enforce_mode, 'override', false) . '>' . esc_html__('Override - Use business hours only', 'td-booking') . '</option>';
echo '</select>';
echo '<p class="description">' . esc_html__('Choose how business hours interact with technician schedules.', 'td-booking') . '</p>';
echo '</td></tr>';

// Restrict-mode fallback option
$restrict_fallback = (int) get_option('td_bkg_restrict_fallback_enabled', 1);
echo '<tr><th scope="row">' . esc_html__('Restrict Fallback', 'td-booking') . '</th><td>';
echo '<label><input type="checkbox" name="td_bkg_restrict_fallback_enabled" value="1"' . ($restrict_fallback ? ' checked' : '') . '> ' . esc_html__('If technician schedules return no availability, allow business hours as fallback (Restrict mode).', 'td-booking') . '</label>';
echo '<p class="description">' . esc_html__('Recommended ON. Prevents empty availability when staff schedules are missing or not yet configured in the Technicians plugin.', 'td-booking') . '</p>';
echo '</td></tr>';

echo '<tr><th scope="row">' . esc_html__('Quick Setup', 'td-booking') . '</th><td>';
echo '<button type="button" class="button quick-setup-btn" data-hours="business">' . esc_html__('Business (9-5 M-F)', 'td-booking') . '</button> ';
echo '<button type="button" class="button quick-setup-btn" data-hours="retail">' . esc_html__('Retail (10-8 M-Sat)', 'td-booking') . '</button> ';
echo '<button type="button" class="button quick-setup-btn" data-hours="24-7">' . esc_html__('24/7', 'td-booking') . '</button> ';
echo '<button type="button" class="button" id="clear-all-hours">' . esc_html__('Clear All', 'td-booking') . '</button>';
echo '<p class="description">' . esc_html__('Click a preset to quickly configure common business hour patterns.', 'td-booking') . '</p>';
echo '</td></tr>';
echo '</table>';

$days = [
    '0' => __('Sunday', 'td-booking'),
    '1' => __('Monday', 'td-booking'),
    '2' => __('Tuesday', 'td-booking'),
    '3' => __('Wednesday', 'td-booking'),
    '4' => __('Thursday', 'td-booking'),
    '5' => __('Friday', 'td-booking'),
    '6' => __('Saturday', 'td-booking')
];

echo '</table>';

echo '<h4>' . esc_html__('Weekly Business Hours', 'td-booking') . '</h4>';
echo '<p class="description">' . esc_html__('Set specific hours for each day of the week. Use multiple time ranges for lunch breaks or split shifts.', 'td-booking') . '</p>';

echo '<table class="form-table">';
foreach ($days as $day_num => $day_name) {
    echo '<tr><th>' . $day_name . '</th><td>';
    
    $day_hours = $global_hours[$day_num] ?? [];
    if (empty($day_hours)) {
        echo '<div class="time-range-row">';
        echo '<input type="time" name="td_bkg_global_hours[' . $day_num . '][0][start]" placeholder="09:00" style="width: 100px; margin-right: 8px;"> ';
        echo '<span style="margin: 0 8px;">' . __('to', 'td-booking') . '</span> ';
        echo '<input type="time" name="td_bkg_global_hours[' . $day_num . '][0][end]" placeholder="17:00" style="width: 100px; margin-right: 8px;"> ';
        echo '<small style="color: #666;">' . esc_html__('(leave empty for closed)', 'td-booking') . '</small>';
        echo '</div>';
    } else {
        foreach ($day_hours as $i => $hours) {
            echo '<div class="time-range-row" style="margin-bottom: 8px;">';
            echo '<input type="time" name="td_bkg_global_hours[' . $day_num . '][' . $i . '][start]" value="' . esc_attr($hours['start']) . '" style="width: 100px; margin-right: 8px;"> ';
            echo '<span style="margin: 0 8px;">' . __('to', 'td-booking') . '</span> ';
            echo '<input type="time" name="td_bkg_global_hours[' . $day_num . '][' . $i . '][end]" value="' . esc_attr($hours['end']) . '" style="width: 100px; margin-right: 8px;"> ';
            if ($i > 0) {
                echo '<button type="button" class="button-secondary remove-hours" style="padding: 2px 8px; font-size: 12px;">' . esc_html__('Remove', 'td-booking') . '</button>';
            }
            echo '</div>';
        }
        echo '<div style="margin-top: 8px;">';
        echo '<button type="button" class="button-secondary add-hours" data-day="' . $day_num . '" style="padding: 2px 8px; font-size: 12px;">+ ' . esc_html__('Add Time Range', 'td-booking') . '</button>';
        echo '</div>';
    }
    
    echo '</td></tr>';
}
echo '</table>';

// Lead time and booking horizon  
$lead_time = get_option('td_bkg_lead_time_minutes', 60);
$booking_horizon = get_option('td_bkg_booking_horizon_days', 30);
$slot_grid = get_option('td_bkg_slot_grid_minutes', 15);
$cache_ttl = get_option('td_bkg_cache_ttl_minutes', 5);

echo '<h4>' . esc_html__('Booking Rules', 'td-booking') . '</h4>';
echo '<table class="form-table">';
echo '<tr><th scope="row">' . esc_html__('Lead Time (minutes)', 'td-booking') . '</th>';
echo '<td><input type="number" name="td_bkg_lead_time_minutes" value="' . esc_attr($lead_time) . '" min="0" step="15" style="width: 100px;"> ';
echo '<p class="description">' . esc_html__('Minimum time before booking can be made', 'td-booking') . '</p></td></tr>';

echo '<tr><th scope="row">' . esc_html__('Booking Horizon (days)', 'td-booking') . '</th>';
echo '<td><input type="number" name="td_bkg_booking_horizon_days" value="' . esc_attr($booking_horizon) . '" min="1" max="365" style="width: 100px;"> ';
echo '<p class="description">' . esc_html__('How far in advance bookings can be made', 'td-booking') . '</p></td></tr>';

echo '<tr><th scope="row">' . esc_html__('Slot Grid (minutes)', 'td-booking') . '</th>';
echo '<td><input type="number" name="td_bkg_slot_grid_minutes" value="' . esc_attr($slot_grid) . '" min="5" max="60" step="5" style="width: 100px;"> ';
echo '<p class="description">' . esc_html__('Spacing between available time slots', 'td-booking') . '</p></td></tr>';

echo '<tr><th scope="row">' . esc_html__('Cache TTL (minutes)', 'td-booking') . '</th>';
echo '<td><input type="number" name="td_bkg_cache_ttl_minutes" value="' . esc_attr($cache_ttl) . '" min="1" max="60" style="width: 100px;"> ';
echo '<p class="description">' . esc_html__('How long to cache availability data', 'td-booking') . '</p></td></tr>';
echo '</table>';




$wc_enabled = get_option('td_bkg_wc_enabled');
$sms_enabled = get_option('td_bkg_sms_enabled');
$sms_provider = get_option('td_bkg_sms_provider', 'twilio');
$sms_api_key = get_option('td_bkg_sms_api_key', '');
$sms_sender = get_option('td_bkg_sms_sender', '');
$sms_reminder_times = get_option('td_bkg_sms_reminder_times', '24,2');

// Admin-only Debug Mode toggle and UI options
$debug_mode = (int) get_option('td_bkg_debug_mode', 0);
$steps_enabled = (int) get_option('td_bkg_steps_enabled', 0);
$terms_page_id = intval(get_option('td_bkg_terms_page_id', 0));
$terms_url = esc_url(get_option('td_bkg_terms_url', ''));

echo '<hr><h2>' . esc_html__('UI & Privacy', 'td-booking') . '</h2>';
echo '<table class="form-table">';
echo '<tr><th scope="row">' . esc_html__('Step-by-step booking UI', 'td-booking') . '</th><td>';
echo '<label><input type="checkbox" name="td_bkg_steps_enabled" value="1"' . ($steps_enabled ? ' checked' : '') . '> ' . esc_html__('Hide next step until previous is completed', 'td-booking') . '</label>';
echo '</td></tr>';

// Terms selection (page dropdown) and/or direct URL
echo '<tr><th scope="row">' . esc_html__('Terms & Conditions', 'td-booking') . '</th><td>';
// Page dropdown
echo '<label style="display:block; margin-bottom:6px;">' . esc_html__('Select Terms page (optional)', 'td-booking') . ': ';
wp_dropdown_pages([
    'name' => 'td_bkg_terms_page_id',
    'echo' => 1,
    'show_option_none' => __('— None —', 'td-booking'),
    'option_none_value' => '0',
    'selected' => $terms_page_id,
]);
echo '</label>';
// URL input
echo '<label style="display:block;">' . esc_html__('Or Terms URL', 'td-booking') . ': <input type="url" name="td_bkg_terms_url" value="' . esc_attr($terms_url) . '" class="regular-text" placeholder="https://example.com/terms" /></label>';
// Terms display mode
$terms_mode = get_option('td_bkg_terms_mode', 'link');
echo '<div style="margin-top:8px;">';
echo '<label>' . esc_html__('Display', 'td-booking') . ': ';
echo '<select name="td_bkg_terms_mode">';
echo '<option value="link"' . selected($terms_mode, 'link', false) . '>' . esc_html__('Open as link (new tab)', 'td-booking') . '</option>';
echo '<option value="modal"' . selected($terms_mode, 'modal', false) . '>' . esc_html__('Open in modal', 'td-booking') . '</option>';
echo '</select></label>';
echo '</div>';
echo '<p class="description">' . esc_html__('Shortcode will link the terms checkbox label to this page/URL. If both are set, the page selection is used. Choose whether to open it as a link or inside a modal.', 'td-booking') . '</p>';
echo '</td></tr>';

// Debug Mode control
echo '<tr><th scope="row">' . esc_html__('Debug Mode (admin-only)', 'td-booking') . '</th><td>';
echo '<label><input type="checkbox" name="td_bkg_debug_mode" value="1"' . ($debug_mode ? ' checked' : '') . '> ' . esc_html__('Enable debug tools and advanced sections', 'td-booking') . '</label>';
echo '<p class="description">' . esc_html__('When disabled, hides Debug Tools and advanced sections such as Payments/SMS to keep settings simple.', 'td-booking') . '</p>';
echo '</td></tr>';
echo '</table>';

// Payments/SMS sections are hidden unless Debug Mode is enabled
if ($debug_mode) {
    echo '<h2>' . esc_html__('Payments', 'td-booking') . '</h2>';
    echo '<label><input type="checkbox" name="td_bkg_wc_enabled" value="1"' . ($wc_enabled ? ' checked' : '') . '> ' . esc_html__('Enable WooCommerce payments', 'td-booking') . '</label><br>';
    echo '<label><input type="checkbox" name="td_bkg_wc_cancel_on_refund" value="1"' . (get_option('td_bkg_wc_cancel_on_refund') ? ' checked' : '') . '> ' . esc_html__('Cancel booking on refund', 'td-booking') . '</label>';
    echo '<h2>' . esc_html__('SMS Reminders', 'td-booking') . '</h2>';
    // Crypto availability notice
    if (!function_exists('td_bkg_crypto_available') || !td_bkg_crypto_available()) {
        $hasSodium = function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
        $msg = '';
        if (!$hasSodium) {
            $msg .= __('libsodium extension is missing. ', 'td-booking');
        }
        if (function_exists('td_bkg_crypto_keys')) {
            $keys = td_bkg_crypto_keys();
            if (empty($keys)) {
                $msg .= __('No encryption keys found. Define one in wp-config.php (e.g., TD_BKG_KMS_KEY_V1 and TD_BKG_HMAC_KEY_V1 as base64-encoded 32-byte keys). ', 'td-booking');
            }
        }
        if ($msg === '') {
            $msg = __('Encryption is not fully configured. ', 'td-booking');
        }
        $msg .= __('Until crypto is available, the SMS API key will be stored in plaintext. Cloud KMS is optional; static keys in wp-config.php are supported.', 'td-booking');
        echo '<div class="notice notice-warning" style="margin:10px 0;"><p>' . esc_html($msg) . '</p></div>';
    }
    echo '<label><input type="checkbox" name="td_bkg_sms_enabled" value="1"' . ($sms_enabled ? ' checked' : '') . '> ' . esc_html__('Enable SMS reminders', 'td-booking') . '</label><br>';
    echo '<label>' . esc_html__('Provider', 'td-booking') . ': <select name="td_bkg_sms_provider">';
    echo '<option value="twilio"' . ($sms_provider=='twilio'?' selected':'') . '>' . esc_html__('Twilio', 'td-booking') . '</option>';
    echo '<option value="nexmo"' . ($sms_provider=='nexmo'?' selected':'') . '>' . esc_html__('Nexmo (placeholder)', 'td-booking') . '</option>';
    echo '<option value="plivo"' . ($sms_provider=='plivo'?' selected':'') . '>' . esc_html__('Plivo (placeholder)', 'td-booking') . '</option>';
    echo '<option value="custom"' . ($sms_provider=='custom'?' selected':'') . '>' . esc_html__('Custom (placeholder)', 'td-booking') . '</option>';
    echo '</select></label><br>';
    // Masked API key field: show placeholder dots if set, allow replacing or clearing
    $has_key = !empty($sms_api_key);
    echo '<fieldset style="margin:8px 0 12px; padding:8px; border:1px solid #ddd; max-width:600px;">';
    echo '<legend>' . esc_html__('API Key', 'td-booking') . '</legend>';
    if ($has_key) {
        echo '<div style="margin-bottom:6px;">' . esc_html__('A key is stored.', 'td-booking') . ' <code>••••••••</code></div>';
        echo '<label><input type="checkbox" name="td_bkg_sms_api_key_replace" value="1"> ' . esc_html__('Replace with new key', 'td-booking') . '</label><br>';
        echo '<label style="display:block; margin-top:6px;">' . esc_html__('New Key', 'td-booking') . ': <input type="password" name="td_bkg_sms_api_key" value="" autocomplete="new-password" style="width: 340px;"></label>';
        echo '<label style="display:block; margin-top:6px; color:#a00;"><input type="checkbox" name="td_bkg_sms_api_key_clear" value="1"> ' . esc_html__('Clear stored key', 'td-booking') . '</label>';
        echo '<p class="description">' . esc_html__('Leave "New Key" empty to keep the existing key. Use "Clear" to remove it.', 'td-booking') . '</p>';
    } else {
        echo '<label>' . esc_html__('Enter Key', 'td-booking') . ': <input type="password" name="td_bkg_sms_api_key" value="" autocomplete="new-password" style="width: 340px;"></label>';
    }
    echo '</fieldset>';
    echo '<label>' . esc_html__('Sender', 'td-booking') . ': <input type="text" name="td_bkg_sms_sender" value="' . esc_attr($sms_sender) . '"></label><br>';
    echo '<label>' . esc_html__('Reminder times (hours, comma separated)', 'td-booking') . ': <input type="text" name="td_bkg_sms_reminder_times" value="' . esc_attr($sms_reminder_times) . '"></label>';
}


// Group bookings feature flag checkbox (in form)
echo '<hr><label><input type="checkbox" name="td_bkg_group_enabled" value="1"' . (get_option('td_bkg_group_enabled') ? ' checked' : '') . '> ' . esc_html__('Enable Group Bookings (multiple participants per booking)', 'td-booking') . '</label><br>';

// Reports feature flag checkbox (in form)
echo '<hr><label><input type="checkbox" name="td_bkg_reports_enabled" value="1"' . (get_option('td_bkg_reports_enabled') ? ' checked' : '') . '> ' . esc_html__('Enable Reports Page', 'td-booking') . '</label><br>';

// Staff-wide breaks/holidays feature flag checkbox (in form)
$staff_breaks_enabled = get_option('td_bkg_staff_breaks_enabled');
echo '<hr><label><input type="checkbox" name="td_bkg_staff_breaks_enabled" value="1"' . ($staff_breaks_enabled ? ' checked' : '') . '> ' . esc_html__('Enable Staff-wide Breaks & Holidays (block all staff during these periods)', 'td-booking') . '</label><br>';

// --- Place SMS admin notices and test logic below the settings form ---
if ($sms_enabled) {
    // Show admin notice if SMS enabled but not configured
    $missing = [];
    if (!$sms_api_key) $missing[] = __('API Key', 'td-booking');
    if (!$sms_sender) $missing[] = __('Sender', 'td-booking');
    if ($missing) {
        echo '<div class="notice notice-warning" style="margin-top:10px;"><p>' . esc_html__('SMS reminders are enabled but missing: ', 'td-booking') . implode(', ', $missing) . '</p></div>';
    }
    // Test SMS connection button logic
    if (isset($_POST['td_bkg_sms_test'])) {
        $to = sanitize_text_field($_POST['td_bkg_sms_test_to'] ?? '');
        $msg = __('Test SMS from TD Booking', 'td-booking');
        $ok = td_bkg_sms_send($to, $msg);
        if ($ok) {
            echo '<div class="notice notice-success" style="margin-top:10px;"><p>' . esc_html__('Test SMS sent successfully.', 'td-booking') . '</p></div>';
            if (function_exists('td_bkg_log')) td_bkg_log('sms', 'Test SMS sent to ' . $to);
        } else {
            echo '<div class="notice notice-error" style="margin-top:10px;"><p>' . esc_html__('Failed to send test SMS.', 'td-booking') . '</p></div>';
            if (function_exists('td_bkg_log')) td_bkg_log('sms', 'Test SMS failed to ' . $to);
        }
    }
    // Test SMS form
    echo '<form method="post" style="margin-top:10px;">';
    echo '<input type="text" name="td_bkg_sms_test_to" placeholder="' . esc_attr__('Test phone number', 'td-booking') . '"> ';
    echo '<button type="submit" name="td_bkg_sms_test" class="button">' . esc_html__('Send Test SMS', 'td-booking') . '</button>';
    echo '</form>';
}
echo '<p class="submit"><input type="submit" class="button-primary" value="' . esc_attr__('Save Settings', 'td-booking') . '"></p>';
echo '</form>';

// Email Settings (separate form)
echo '<hr>';
echo '<h2>' . esc_html__('Email Settings', 'td-booking') . '</h2>';
echo '<form method="post">';
wp_nonce_field('td_bkg_email_settings-td-booking-settings');

echo '<table class="form-table">';

// From Name
$from_name = get_option('td_bkg_email_from_name', get_bloginfo('name'));
echo '<tr>';
echo '<th scope="row">' . esc_html__('From Name', 'td-booking') . '</th>';
echo '<td><input type="text" name="email_from_name" value="' . esc_attr($from_name) . '" class="regular-text" /></td>';
echo '</tr>';

// From Email
$from_email = get_option('td_bkg_email_from_email', get_option('admin_email'));
$admin_email = get_option('admin_email');
echo '<tr>';
echo '<th scope="row">' . esc_html__('From Email', 'td-booking') . '</th>';
echo '<td>';
echo '<input type="email" name="email_from_email" value="' . esc_attr($from_email) . '" class="regular-text" />';
echo '<p class="description">';
echo sprintf(
    __('Email address for outgoing booking notifications. If left empty or invalid, will use admin email: %s', 'td-booking'),
    '<code>' . esc_html($admin_email) . '</code>'
);
echo '<br><strong>' . esc_html__('Note:', 'td-booking') . '</strong> ' . esc_html__('Some hosting providers may require emails to be sent from verified domains or specific addresses.', 'td-booking');
echo '</p>';
echo '</td>';
echo '</tr>';

// Header Color
$header_color = get_option('td_bkg_email_header_color', '#0073aa');
echo '<tr>';
echo '<th scope="row">' . esc_html__('Header Color', 'td-booking') . '</th>';
echo '<td><input type="color" name="email_header_color" value="' . esc_attr($header_color) . '" /></td>';
echo '</tr>';

// Logo URL
$logo_url = get_option('td_bkg_email_logo_url', '');
echo '<tr>';
echo '<th scope="row">' . esc_html__('Logo URL', 'td-booking') . '</th>';
echo '<td><input type="url" name="email_logo_url" value="' . esc_attr($logo_url) . '" class="regular-text" />';
echo '<p class="description">' . esc_html__('Optional logo to display in email headers (recommended: 200x60px)', 'td-booking') . '</p></td>';
echo '</tr>';

echo '</table>';

echo '<p class="submit"><input type="submit" name="save_email_settings" class="button-primary" value="' . esc_html__('Save Email Settings', 'td-booking') . '" /></p>';
echo '</form>';



// Cache Management
echo '<hr>';
echo '<h2>' . esc_html__('Cache Management', 'td-booking') . '</h2>';
echo '<form method="post">';
wp_nonce_field('td_bkg_clear_cache');
echo '<p>' . esc_html__('Clear availability cache to force recalculation.', 'td-booking') . '</p>';
echo '<button type="submit" name="clear_cache" class="button">' . esc_html__('Clear Cache', 'td-booking') . '</button>';
echo '</form>';

// Debug section for testing staff breaks (only when Debug Mode is on)
if ((int) get_option('td_bkg_debug_mode', 0)) {
echo '<div class="card" style="margin-top: 20px;">';
echo '<h2>' . esc_html__('Debug Tools', 'td-booking') . '</h2>';
// CalDAV Test Connection
echo '<div style="margin-bottom: 20px;">';
echo '<h3>' . esc_html__('Test CalDAV Connection', 'td-booking') . '</h3>';
echo '<p>' . esc_html__('Verify that the selected staff member\'s CalDAV credentials work (OPTIONS request).', 'td-booking') . '</p>';
echo '<label for="caldav-test-staff">' . esc_html__('Staff Name:', 'td-booking') . '</label> ';
echo '<input type="text" id="caldav-test-staff" placeholder="' . esc_attr__('e.g., Jane Doe', 'td-booking') . '" style="width: 220px; margin: 0 10px;"> ';
echo '<button type="button" id="caldav-test-btn" class="button button-secondary">' . esc_html__('Run Test', 'td-booking') . '</button>';
echo '<div id="caldav-test-result" style="margin-top: 12px; padding: 10px; background: #f9f9f9; border-left: 4px solid #ddd; display: none;"></div>';
echo '</div>';

// CalDAV Diagnostics (PROPFIND + Test PUT)
echo '<div style="margin-bottom: 20px;">';
echo '<h3>' . esc_html__('CalDAV Diagnostics', 'td-booking') . '</h3>';
echo '<p>' . esc_html__('Runs a PROPFIND on the calendar collection to detect supported components, then attempts a temporary VEVENT PUT and deletes it. Helps diagnose 415 errors.', 'td-booking') . '</p>';
echo '<div style="margin:6px 0;">';
echo '<label for="caldav-diag-staff" style="min-width:110px;display:inline-block;">' . esc_html__('Staff Name:', 'td-booking') . '</label> ';
echo '<input type="text" id="caldav-diag-staff" placeholder="' . esc_attr__('e.g., Jane Doe', 'td-booking') . '" style="width: 220px; margin-right: 10px;"> ';
echo '</div>';
echo '<details style="margin:6px 0;"><summary>' . esc_html__('Advanced: Override base URL and calendar path', 'td-booking') . '</summary>';
echo '<div style="margin-top:8px;">';
echo '<label for="caldav-diag-base" style="min-width:110px;display:inline-block;">' . esc_html__('Base URL:', 'td-booking') . '</label> ';
echo '<input type="url" id="caldav-diag-base" placeholder="https://cloud.example.com/remote.php/dav/calendars/Name/Calendar" style="width: 420px;">';
echo '<br><small>' . esc_html__('If you provide a root-ish URL (e.g., /remote.php/dav), also provide Calendar Path below.', 'td-booking') . '</small>';
echo '</div>';
echo '<div style="margin-top:8px;">';
echo '<label for="caldav-diag-path" style="min-width:110px;display:inline-block;">' . esc_html__('Calendar Path:', 'td-booking') . '</label> ';
echo '<input type="text" id="caldav-diag-path" placeholder="calendars/Name/Personal" style="width: 420px;">';
echo '</div>';
echo '</details>';
echo '<button type="button" id="caldav-diag-btn" class="button button-secondary" style="margin-top:8px;">' . esc_html__('Run Diagnostics', 'td-booking') . '</button>';
echo '<div id="caldav-diag-result" style="margin-top: 12px; padding: 10px; background: #f9f9f9; border-left: 4px solid #ddd; display: none;"></div>';
echo '</div>';

// Booking Smoke Test
echo '<div style="margin-bottom: 20px;">';
echo '<h3>' . esc_html__('Booking Smoke Test', 'td-booking') . '</h3>';
echo '<p>' . esc_html__('Creates a test booking and attempts to sync to CalDAV. Uses next available slot if start time is omitted.', 'td-booking') . '</p>';
echo '<label for="book-smoke-service">' . esc_html__('Service ID:', 'td-booking') . '</label> ';
echo '<input type="number" id="book-smoke-service" min="1" style="width: 100px; margin: 0 10px;"> ';
echo '<label for="book-smoke-start">' . esc_html__('Start (UTC):', 'td-booking') . '</label> ';
echo '<input type="text" id="book-smoke-start" placeholder="YYYY-mm-dd HH:MM:SS" style="width: 200px; margin: 0 10px;"> ';
echo '<button type="button" id="book-smoke-btn" class="button button-secondary">' . esc_html__('Create Test Booking', 'td-booking') . '</button>';
echo '<div id="book-smoke-result" style="margin-top: 12px; padding: 10px; background: #f9f9f9; border-left: 4px solid #ddd; display: none;"></div>';
echo '</div>';
// Booking Links Tester
echo '<div style="margin-bottom: 20px;">';
echo '<h3>' . esc_html__('Booking Links Tester', 'td-booking') . '</h3>';
echo '<p>' . esc_html__('Enter a Booking ID to fetch the Reschedule and Cancel links (admin-only).', 'td-booking') . '</p>';
echo '<label for="booking-links-id">' . esc_html__('Booking ID:', 'td-booking') . '</label> ';
echo '<input type="number" id="booking-links-id" min="1" style="width: 120px; margin: 0 10px;"> ';
echo '<button type="button" id="booking-links-btn" class="button button-secondary">' . esc_html__('Get Links', 'td-booking') . '</button>';
echo '<div id="booking-links-result" style="margin-top: 12px; padding: 10px; background: #f9f9f9; border-left: 4px solid #ddd; display: none;"></div>';
echo '</div>';
echo '<div style="margin-bottom: 20px;">';
echo '<h3>' . esc_html__('Test Staff-Wide Breaks', 'td-booking') . '</h3>';
echo '<p>' . esc_html__('Test how staff-wide breaks affect availability calculation.', 'td-booking') . '</p>';
echo '<label for="debug-service-id">' . esc_html__('Service ID:', 'td-booking') . '</label>';
echo '<input type="number" id="debug-service-id" value="1" min="1" style="width: 80px; margin: 0 10px;">';
echo '<label for="debug-from-date">' . esc_html__('From:', 'td-booking') . '</label>';
echo '<input type="date" id="debug-from-date" value="' . date('Y-m-d') . '" style="margin: 0 10px;">';
echo '<label for="debug-to-date">' . esc_html__('To:', 'td-booking') . '</label>';
echo '<input type="date" id="debug-to-date" value="' . date('Y-m-d', strtotime('+7 days')) . '" style="margin: 0 10px;">';
echo '<button type="button" id="test-breaks" class="button button-secondary">' . esc_html__('Test Breaks', 'td-booking') . '</button>';
echo '<div id="test-breaks-result" style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-left: 4px solid #ddd; display: none;"></div>';
echo '</div>';

echo '<div style="margin-bottom: 20px;">';
echo '<h3>' . esc_html__('Debug Specific Time Slot', 'td-booking') . '</h3>';
echo '<p>' . esc_html__('Test if a specific time slot should be blocked by staff breaks.', 'td-booking') . '</p>';
echo '<label for="debug-slot-datetime">' . esc_html__('Date & Time:', 'td-booking') . '</label>';
echo '<input type="datetime-local" id="debug-slot-datetime" style="margin: 0 10px;">';
echo '<label for="debug-slot-duration">' . esc_html__('Duration (min):', 'td-booking') . '</label>';
echo '<input type="number" id="debug-slot-duration" value="60" min="15" max="480" style="width: 80px; margin: 0 10px;">';
echo '<button type="button" id="debug-slot" class="button button-secondary">' . esc_html__('Debug Slot', 'td-booking') . '</button>';
echo '<div id="debug-slot-result" style="margin-top: 15px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; display: none;"></div>';
echo '</div>';

echo '<div style="margin-bottom: 20px;">';
echo '<h3>' . esc_html__('Test Email Settings', 'td-booking') . '</h3>';
echo '<p>' . esc_html__('Check current email configuration and test email validation.', 'td-booking') . '</p>';
echo '<input type="email" id="test-email-input" placeholder="' . esc_attr__('Enter email to test...', 'td-booking') . '" style="width: 250px; margin-right: 10px;">';
echo '<button type="button" id="test-email-validation" class="button button-secondary">' . esc_html__('Test Email Validation', 'td-booking') . '</button>';
echo '<button type="button" id="debug-email" class="button button-secondary" style="margin-left: 10px;">' . esc_html__('Show Current Settings', 'td-booking') . '</button>';
echo '<div style="margin-top:8px;">';
echo '<label style="margin-right:10px;"><input type="checkbox" id="test-email-include-ics" /> ' . esc_html__('Include ICS attachment', 'td-booking') . '</label>';
echo '<button type="button" id="send-test-email" class="button">' . esc_html__('Send Test Email', 'td-booking') . '</button>';
echo '</div>';
echo '<div id="email-test-result" style="margin-top: 15px; padding: 10px; background: #f0f8ff; border-left: 4px solid #0073aa; display: none;"></div>';
echo '<div id="email-debug-result" style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-left: 4px solid #ddd; display: none;">';
echo '<h4>' . esc_html__('Current Email Settings:', 'td-booking') . '</h4>';
echo '<p><strong>' . esc_html__('From Name:', 'td-booking') . '</strong> ' . esc_html(get_option('td_bkg_email_from_name', get_bloginfo('name'))) . '</p>';
echo '<p><strong>' . esc_html__('From Email:', 'td-booking') . '</strong> ' . esc_html(get_option('td_bkg_email_from_email', get_option('admin_email'))) . '</p>';
echo '<p><strong>' . esc_html__('Admin Email (fallback):', 'td-booking') . '</strong> ' . esc_html(get_option('admin_email')) . '</p>';
echo '<p><strong>' . esc_html__('Site Name:', 'td-booking') . '</strong> ' . esc_html(get_bloginfo('name')) . '</p>';
echo '<p><em>' . esc_html__('Note: If your custom "From Email" keeps reverting, it may be failing WordPress email validation. Check the error log for details.', 'td-booking') . '</em></p>';
echo '</div>';
echo '</div>';

echo '</div>';
}

// Add JavaScript for global hours functionality
?>
<script>
jQuery(document).ready(function($) {
    $(".add-hours").click(function() {
        var day = $(this).data("day");
        var container = $(this).parent().parent();
        var existingRanges = container.find(".time-range-row").length;
        
        var newRange = $('<div class="time-range-row" style="margin-bottom: 8px;">' +
            '<input type="time" name="td_bkg_global_hours[' + day + '][' + existingRanges + '][start]" style="width: 100px; margin-right: 8px;"> ' +
            '<span style="margin: 0 8px;"><?php echo esc_js(__('to', 'td-booking')); ?></span> ' +
            '<input type="time" name="td_bkg_global_hours[' + day + '][' + existingRanges + '][end]" style="width: 100px; margin-right: 8px;"> ' +
            '<button type="button" class="button-secondary remove-hours" style="padding: 2px 8px; font-size: 12px;"><?php echo esc_js(__('Remove', 'td-booking')); ?></button>' +
            '</div>');
        
        $(this).parent().before(newRange);
    });
    
    $(document).on("click", ".remove-hours", function() {
        $(this).closest('.time-range-row').remove();
    });
    
    // Quick setup buttons
    $(".quick-setup-btn").click(function(e) {
        e.preventDefault();
        var hours = $(this).data("hours");
        
        // Clear all existing input values first
        $("input[name*='td_bkg_global_hours']").val("");
        
        // Apply the hours based on the preset
        if (hours === "business") {
            // Monday-Friday 9:00-17:00
            for (var day = 1; day <= 5; day++) {
                $("input[name='td_bkg_global_hours[" + day + "][0][start]']").val("09:00");
                $("input[name='td_bkg_global_hours[" + day + "][0][end]']").val("17:00");
            }
        } else if (hours === "retail") {
            // Monday-Saturday 10:00-20:00, Sunday 12:00-18:00
            for (var day = 1; day <= 6; day++) {
                $("input[name='td_bkg_global_hours[" + day + "][0][start]']").val("10:00");
                $("input[name='td_bkg_global_hours[" + day + "][0][end]']").val("20:00");
            }
            // Sunday
            $("input[name='td_bkg_global_hours[0][0][start]']").val("12:00");
            $("input[name='td_bkg_global_hours[0][0][end]']").val("18:00");
        } else if (hours === "24-7") {
            // All days 00:00-23:59
            for (var day = 0; day <= 6; day++) {
                $("input[name='td_bkg_global_hours[" + day + "][0][start]']").val("00:00");
                $("input[name='td_bkg_global_hours[" + day + "][0][end]']").val("23:59");
            }
        }
        
        // Show success message
    $(this).after('<span style="color: #46b450; margin-left: 10px;">✓ <?php echo esc_js(__('Applied!', 'td-booking')); ?></span>');
        var self = this;
        setTimeout(function() {
            $(self).next("span").fadeOut(function() {
                $(this).remove();
            });
        }, 2000);
    });
    
    // Clear all hours button
    $("#clear-all-hours").click(function(e) {
        e.preventDefault();
        $("input[name*='td_bkg_global_hours']").val("");
    $(this).after('<span style="color: #666; margin-left: 10px;">✓ <?php echo esc_js(__('Cleared!', 'td-booking')); ?></span>');
        var self = this;
        setTimeout(function() {
            $(self).next("span").fadeOut(function() {
                $(this).remove();
            });
        }, 2000);
    });
    
    // Collapsible sections
    $('.settings-section-toggle').click(function(e) {
        e.preventDefault();
        var target = $(this).data('target');
        $('#' + target).slideToggle();
        $(this).find('.dashicons').toggleClass('dashicons-arrow-down dashicons-arrow-up');
    });
    
    // Test breaks functionality
    $('#test-breaks').click(function() {
        var button = $(this);
        var resultDiv = $('#test-breaks-result');
        
    button.prop('disabled', true).text('<?php echo esc_js(__('Testing...', 'td-booking')); ?>');
        resultDiv.hide();
        
        $.ajax({
            url: '<?php echo esc_js(rest_url('td/v1/admin/test-breaks')); ?>',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
            },
            data: {
                service_id: $('#debug-service-id').val(),
                from: $('#debug-from-date').val(),
                to: $('#debug-to-date').val()
            },
            success: function(response) {
                var html = '<h4><?php echo esc_js(__('Test Results:', 'td-booking')); ?></h4>';
                html += '<p><strong><?php echo esc_js(__('Staff Breaks Enabled:', 'td-booking')); ?></strong> ' + (response.staff_breaks_enabled ? '<?php echo esc_js(__('Yes', 'td-booking')); ?>' : '<?php echo esc_js(__('No', 'td-booking')); ?>') + '</p>';
                html += '<p><strong><?php echo esc_js(__('Date Range:', 'td-booking')); ?></strong> ' + response.date_range.from + ' <?php echo esc_js(__('to', 'td-booking')); ?> ' + response.date_range.to + '</p>';
                html += '<p><strong><?php echo esc_js(__('Staff-Wide Breaks Found:', 'td-booking')); ?></strong> ' + response.breaks_found + '</p>';
                
                if (response.breaks_found > 0) {
                    html += '<h5><?php echo esc_js(__('Breaks:', 'td-booking')); ?></h5><ul>';
                    response.breaks.forEach(function(break_item) {
                        var break_title = break_item.notes || break_item.type;
                        html += '<li><strong>' + break_title + '</strong>: ' + break_item.start_utc + ' <?php echo esc_js(__('to', 'td-booking')); ?> ' + break_item.end_utc + '</li>';
                    });
                    html += '</ul>';
                }
                
                html += '<p><strong><?php echo esc_js(__('Available Slots Found:', 'td-booking')); ?></strong> ' + response.available_slots_count + '</p>';
                if (response.first_5_slots.length > 0) {
                    html += '<h5><?php echo esc_js(__('First 5 Available Slots:', 'td-booking')); ?></h5><ul>';
                    response.first_5_slots.forEach(function(slot) {
                        html += '<li>' + slot.start_utc + ' <?php echo esc_js(__('to', 'td-booking')); ?> ' + slot.end_utc + '</li>';
                    });
                    html += '</ul>';
                }
                
                html += '<p><em>' + response.message + '</em></p>';
                
                resultDiv.html(html).show();
            },
            error: function(xhr) {
                var error = xhr.responseJSON ? xhr.responseJSON.message : '<?php echo esc_js(__('Unknown error', 'td-booking')); ?>';
                resultDiv.html('<div style="color: #d63638;"><h4><?php echo esc_js(__('Error:', 'td-booking')); ?></h4><p>' + error + '</p></div>').show();
            },
            complete: function() {
                button.prop('disabled', false).text('<?php echo esc_js(__('Test Breaks', 'td-booking')); ?>');
            }
        });
    });
    
    // Email debug functionality
    $('#debug-email').click(function() {
        $('#email-debug-result').slideToggle();
    });
    
    // Email validation testing
    $('#test-email-validation').click(function() {
        var testEmail = $('#test-email-input').val().trim();
        var resultDiv = $('#email-test-result');
        
        if (!testEmail) {
            resultDiv.html('<p style="color: #d63638;"><?php echo esc_js(__('Please enter an email address to test.', 'td-booking')); ?></p>').show();
            return;
        }
        
        // Test with browser validation
        var browserValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(testEmail);
        
    var html = '<h4><?php echo esc_js(__('Email Validation Test Results for:', 'td-booking')); ?> <code>' + testEmail + '</code></h4>';
    html += '<p><strong><?php echo esc_js(__('Browser Validation:', 'td-booking')); ?></strong> ' + (browserValid ? '✓ <?php echo esc_js(__('Valid', 'td-booking')); ?>' : '✗ <?php echo esc_js(__('Invalid', 'td-booking')); ?>') + '</p>';
        
        // Test what WordPress sanitize_email would do
        // We can't call PHP from JavaScript, so we'll provide guidance
    html += '<p><strong><?php echo esc_js(__('WordPress sanitize_email():', 'td-booking')); ?></strong> ';
        if (testEmail.indexOf('<') !== -1 || testEmail.indexOf('>') !== -1) {
            html += '✗ <?php echo esc_js(__('Likely to fail (contains < or >)', 'td-booking')); ?>';
        } else if (testEmail.length > 254) {
            html += '✗ <?php echo esc_js(__('Likely to fail (too long, max 254 chars)', 'td-booking')); ?>';
        } else if (!browserValid) {
            html += '✗ <?php echo esc_js(__('Likely to fail (invalid format)', 'td-booking')); ?>';
        } else {
            html += '✓ <?php echo esc_js(__('Likely to pass', 'td-booking')); ?>';
        }
        html += '</p>';
        
    html += '<p><strong><?php echo esc_js(__('Recommendation:', 'td-booking')); ?></strong> ';
        if (browserValid && testEmail.length <= 254 && testEmail.indexOf('<') === -1 && testEmail.indexOf('>') === -1) {
            html += '<?php echo esc_js(__('This email should work fine.', 'td-booking')); ?>';
        } else {
            html += '<?php echo esc_js(__('This email may cause issues. Try a simpler format.', 'td-booking')); ?>';
        }
        html += '</p>';
        
    html += '<p><em><?php echo esc_js(__('Note: The actual WordPress validation happens on the server. Save the settings to see the real result.', 'td-booking')); ?></em></p>';
        
        resultDiv.html(html).show();
    });

    // Send Test Email via REST endpoint
    $('#send-test-email').click(function() {
        var btn = $(this);
        var toEmail = ($('#test-email-input').val() || '').trim();
        var includeIcs = $('#test-email-include-ics').is(':checked');
        var resultDiv = $('#email-test-result');
        btn.prop('disabled', true).text(<?php echo json_encode(__('Sending…', 'td-booking')); ?>);
        resultDiv.hide();
        $.ajax({
            url: <?php echo json_encode(rest_url('td/v1/admin/test-mail')); ?>,
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>'); },
            data: { to: toEmail, include_ics: includeIcs ? 1 : 0 },
            success: function(resp) {
                var html = '<div style="color:#22863a;"><strong>' + <?php echo json_encode(__('Email sent', 'td-booking')); ?> + '</strong></div>';
                html += '<div><?php echo esc_js(__('To:', 'td-booking')); ?> ' + resp.to + ' · <?php echo esc_js(__('ICS:', 'td-booking')); ?> ' + (resp.with_ics ? '<?php echo esc_js(__('Yes', 'td-booking')); ?>' : '<?php echo esc_js(__('No', 'td-booking')); ?>') + '</div>';
                resultDiv.css('background', '#f0fff4').css('border-left-color', '#28a745').html(html).show();
            },
            error: function(xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : <?php echo json_encode(__('Unknown error', 'td-booking')); ?>;
                var html = '<div style="color:#d63638;"><strong>' + <?php echo json_encode(__('Email failed', 'td-booking')); ?> + '</strong></div>';
                html += '<div>' + msg + '</div>';
                resultDiv.css('background', '#fff5f5').css('border-left-color', '#d63638').html(html).show();
            },
            complete: function() {
                btn.prop('disabled', false).text(<?php echo json_encode(__('Send Test Email', 'td-booking')); ?>);
            }
        });
    });
    
    // Allow Enter key to trigger email test
    $('#test-email-input').keypress(function(e) {
        if (e.which === 13) {
            $('#test-email-validation').click();
        }
    });

    // CalDAV Test Connection
    $('#caldav-test-btn').click(function() {
        var btn = $(this);
        var staffName = ($('#caldav-test-staff').val() || '').trim();
        var resultDiv = $('#caldav-test-result');
        if (!staffName) {
            resultDiv.html('<div style="color:#d63638;">' + <?php echo json_encode(__('Please enter a Staff Name.', 'td-booking')); ?> + '</div>').show();
            return;
        }
        btn.prop('disabled', true).text(<?php echo json_encode(__('Testing...', 'td-booking')); ?>);
        resultDiv.hide();
        $.ajax({
            url: <?php echo json_encode(rest_url('td/v1/admin/test-connection')); ?>,
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>'); },
            data: { staff: staffName },
            success: function(response, statusText, xhr) {
                var http = xhr.status || 200;
                var html = '<div style="color:#22863a;"><strong>' + <?php echo json_encode(__('Connection OK', 'td-booking')); ?> + '</strong> (HTTP ' + http + ')</div>';
                resultDiv.html(html).show();
            },
            error: function(xhr) {
                var code = xhr.status || 0;
                var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : <?php echo json_encode(__('Unknown error', 'td-booking')); ?>;
                var ctxt = '';
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    ctxt = '<pre style="white-space:pre-wrap;">' + JSON.stringify(xhr.responseJSON.data, null, 2) + '</pre>';
                }
                resultDiv.html('<div style="color:#d63638;"><strong>' + <?php echo json_encode(__('CalDAV connection failed', 'td-booking')); ?> + '</strong> (HTTP ' + code + ')<br>' + msg + '</div>' + ctxt).show();
            },
            complete: function() {
                btn.prop('disabled', false).text(<?php echo json_encode(__('Run Test', 'td-booking')); ?>);
            }
        });
    });

    // Booking Smoke Test
    $('#book-smoke-btn').click(function() {
        var btn = $(this);
        var svc = parseInt($('#book-smoke-service').val(), 10) || 0;
        var start = ($('#book-smoke-start').val() || '').trim();
        var resultDiv = $('#book-smoke-result');
        if (!svc) {
            resultDiv.html('<div style="color:#d63638;">' + <?php echo json_encode(__('Please enter a Service ID.', 'td-booking')); ?> + '</div>').show();
            return;
        }
        btn.prop('disabled', true).text(<?php echo json_encode(__('Creating...', 'td-booking')); ?>);
        resultDiv.hide();

        function createBooking(startUtc) {
            $.ajax({
                url: <?php echo json_encode(rest_url('td/v1/book')); ?>,
                method: 'POST',
                data: {
                    service_id: svc,
                    start_utc: startUtc,
                    customer: { name: 'Admin Smoke Test', email: '<?php echo esc_js(get_option('admin_email')); ?>' }
                },
                success: function(response) {
                    var html = '<div style="color:#22863a;"><strong>' + <?php echo json_encode(__('Booking created', 'td-booking')); ?> + '</strong></div>';
                    html += '<pre style="white-space:pre-wrap;">' + JSON.stringify(response, null, 2) + '</pre>';
                    resultDiv.html(html).show();
                },
                error: function(xhr) {
                    var code = xhr.status || 0;
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : <?php echo json_encode(__('Unknown error', 'td-booking')); ?>;
                    var ctxt = '';
                    if (xhr.responseJSON && xhr.responseJSON.data) {
                        ctxt = '<pre style="white-space:pre-wrap;">' + JSON.stringify(xhr.responseJSON.data, null, 2) + '</pre>';
                    }
                    resultDiv.html('<div style="color:#d63638;"><strong>' + <?php echo json_encode(__('Booking failed', 'td-booking')); ?> + '</strong> (HTTP ' + code + ')<br>' + msg + '</div>' + ctxt).show();
                },
                complete: function() {
                    btn.prop('disabled', false).text(<?php echo json_encode(__('Create Test Booking', 'td-booking')); ?>);
                }
            });
        }

        // If start is provided, use it directly; otherwise fetch next available
        if (start) {
            createBooking(start);
        } else {
            var from = new Date();
            var to = new Date();
            to.setDate(to.getDate() + 7);
            function fmt(dt) {
                // YYYY-mm-dd HH:MM:SS
                var pad = n => (n < 10 ? '0' + n : '' + n);
                return dt.getUTCFullYear() + '-' + pad(dt.getUTCMonth()+1) + '-' + pad(dt.getUTCDate()) + ' ' + pad(dt.getUTCHours()) + ':' + pad(dt.getUTCMinutes()) + ':' + pad(dt.getUTCSeconds());
            }
            $.ajax({
                url: <?php echo json_encode(rest_url('td/v1/availability')); ?>,
                method: 'GET',
                data: { service_id: svc, from: fmt(from), to: fmt(to) },
                success: function(slots) {
                    if (Array.isArray(slots) && slots.length > 0) {
                        var startIso = slots[0].start_utc; // 2025-09-25T14:00:00Z
                        var startNorm = startIso.replace('T', ' ').substring(0, 19);
                        createBooking(startNorm);
                    } else {
                        resultDiv.html('<div style="color:#d63638;">' + <?php echo json_encode(__('No available slots found in the next 7 days.', 'td-booking')); ?> + '</div>').show();
                        btn.prop('disabled', false).text(<?php echo json_encode(__('Create Test Booking', 'td-booking')); ?>);
                    }
                },
                error: function() {
                    resultDiv.html('<div style="color:#d63638;">' + <?php echo json_encode(__('Failed to fetch availability.', 'td-booking')); ?> + '</div>').show();
                    btn.prop('disabled', false).text(<?php echo json_encode(__('Create Test Booking', 'td-booking')); ?>);
                }
            });
        }
    });

    // Booking Links Tester
    $('#booking-links-btn').click(function() {
        var btn = $(this);
        var id = parseInt($('#booking-links-id').val(), 10) || 0;
        var resultDiv = $('#booking-links-result');
        if (!id) {
            resultDiv.html('<div style="color:#d63638;">' + <?php echo json_encode(__('Please enter a Booking ID.', 'td-booking')); ?> + '</div>').show();
            return;
        }
        btn.prop('disabled', true).text(<?php echo json_encode(__('Fetching…', 'td-booking')); ?>);
        resultDiv.hide();
        $.ajax({
            url: <?php echo json_encode(rest_url('td/v1/admin/booking-links')); ?>,
            method: 'GET',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>'); },
            data: { booking_id: id },
            success: function(resp) {
                var addDebug = resp.reschedule_url.indexOf('debug=1') === -1 ? (resp.reschedule_url.indexOf('?') === -1 ? '?debug=1' : '&debug=1') : '';
                var addDebug2 = resp.cancel_url.indexOf('debug=1') === -1 ? (resp.cancel_url.indexOf('?') === -1 ? '?debug=1' : '&debug=1') : '';
                var html = '<div><strong>ID:</strong> ' + resp.booking_id + '</div>';
                html += '<div><a target="_blank" href="' + resp.reschedule_url + addDebug + '"><?php echo esc_js(__('Open Reschedule Page', 'td-booking')); ?></a></div>';
                html += '<div><a target="_blank" href="' + resp.cancel_url + addDebug2 + '"><?php echo esc_js(__('Trigger Cancel (opens link)', 'td-booking')); ?></a></div>';
                resultDiv.html(html).show();
            },
            error: function(xhr) {
                var code = xhr.status || 0;
                var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : <?php echo json_encode(__('Unknown error', 'td-booking')); ?>;
                resultDiv.html('<div style="color:#d63638;"><strong><?php echo esc_js(__('Failed to fetch links', 'td-booking')); ?></strong> (HTTP ' + code + ')<br>' + msg + '</div>').show();
            },
            complete: function() {
                btn.prop('disabled', false).text(<?php echo json_encode(__('Get Links', 'td-booking')); ?>);
            }
        });
    });
    
    // CalDAV Diagnostics handler
    $('#caldav-diag-btn').click(function() {
        var btn = $(this);
        var staffName = ($('#caldav-diag-staff').val() || '').trim();
        var baseUrl = ($('#caldav-diag-base').val() || '').trim();
        var calPath = ($('#caldav-diag-path').val() || '').trim();
        var resultDiv = $('#caldav-diag-result');
        if (!staffName) {
            resultDiv.html('<div style="color:#d63638;">' + <?php echo json_encode(__('Please enter a Staff Name.', 'td-booking')); ?> + '</div>').show();
            return;
        }
        btn.prop('disabled', true).text(<?php echo json_encode(__('Running…', 'td-booking')); ?>);
        resultDiv.hide();
        $.ajax({
            url: <?php echo json_encode(rest_url('td/v1/admin/caldav-diagnostics')); ?>,
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>'); },
            data: { staff: staffName, base_url: baseUrl, calendar_path: calPath },
            success: function(resp) {
                var html = '';
                html += '<div><strong><?php echo esc_js(__('Collection URL:', 'td-booking')); ?></strong> ' + (resp.collection_url || '') + '</div>';
                if (resp.propfind) {
                    html += '<div style="margin-top:6px;"><strong>PROPFIND:</strong> HTTP ' + resp.propfind.status + '</div>';
                    if (Array.isArray(resp.propfind.supported_components)) {
                        html += '<div><strong><?php echo esc_js(__('Supported Components:', 'td-booking')); ?></strong> ' + resp.propfind.supported_components.join(', ') + '</div>';
                    }
                    if (resp.propfind.redirect_url && resp.propfind.redirect_url !== resp.collection_url) {
                        html += '<div><small><?php echo esc_js(__('Redirected to:', 'td-booking')); ?> ' + resp.propfind.redirect_url + '</small></div>';
                    }
                }
                if (resp.put) {
                    html += '<div style="margin-top:8px;"><strong>PUT Test:</strong> ' + (resp.put.resource_url || '') + '</div>';
                    if (resp.put.attempt1) {
                        html += '<div>Attempt 1 (component=VEVENT): HTTP ' + resp.put.attempt1.status + '</div>';
                    }
                    if (resp.put.attempt2) {
                        html += '<div>Attempt 2 (no component): HTTP ' + resp.put.attempt2.status + '</div>';
                    }
                    if (resp.put.used_fallback) {
                        html += '<div><small><?php echo esc_js(__('Fallback without component header was used.', 'td-booking')); ?></small></div>';
                    }
                }
                if (resp.cleanup) {
                    html += '<div style="margin-top:6px;"><strong><?php echo esc_js(__('Cleanup (DELETE):', 'td-booking')); ?></strong> HTTP ' + resp.cleanup.status + '</div>';
                }
                resultDiv.html(html).show();
            },
            error: function(xhr) {
                var code = xhr.status || 0;
                var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : <?php echo json_encode(__('Unknown error', 'td-booking')); ?>;
                var ctxt = '';
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    ctxt = '<pre style="white-space:pre-wrap;">' + JSON.stringify(xhr.responseJSON.data, null, 2) + '</pre>';
                }
                resultDiv.html('<div style="color:#d63638;"><strong><?php echo esc_js(__('Diagnostics failed', 'td-booking')); ?></strong> (HTTP ' + code + ')<br>' + msg + '</div>' + ctxt).show();
            },
            complete: function() {
                btn.prop('disabled', false).text(<?php echo json_encode(__('Run Diagnostics', 'td-booking')); ?>);
            }
        });
    });
    // Debug specific slot functionality
    $('#debug-slot').click(function() {
        var button = $(this);
        var resultDiv = $('#debug-slot-result');
        var slotDatetime = $('#debug-slot-datetime').val();
        var duration = $('#debug-slot-duration').val();
        
        if (!slotDatetime) {
            resultDiv.html('<p style="color: #d63638;"><?php echo esc_js(__('Please select a date and time to test.', 'td-booking')); ?></p>').show();
            return;
        }
        
    button.prop('disabled', true).text('<?php echo esc_js(__('Testing...', 'td-booking')); ?>');
        resultDiv.hide();
        
        $.ajax({
            url: '<?php echo esc_js(rest_url('td/v1/admin/debug-slot')); ?>',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
            },
            data: {
                slot_datetime: slotDatetime,
                duration: duration
            },
            success: function(response) {
                var html = '<h4><?php echo esc_js(__('Slot Debug Results:', 'td-booking')); ?></h4>';
                html += '<p><strong><?php echo esc_js(__('Testing Slot:', 'td-booking')); ?></strong> ' + response.slot_datetime + ' (<?php echo esc_js(__('duration:', 'td-booking')); ?> ' + response.duration_minutes + ' <?php echo esc_js(__('min', 'td-booking')); ?>)</p>';
                html += '<p><strong><?php echo esc_js(__('Staff Breaks Enabled:', 'td-booking')); ?></strong> ' + (response.staff_breaks_enabled ? '<?php echo esc_js(__('Yes', 'td-booking')); ?>' : '<?php echo esc_js(__('No', 'td-booking')); ?>') + '</p>';
                html += '<p><strong><?php echo esc_js(__('Total Staff Breaks in DB:', 'td-booking')); ?></strong> ' + response.total_breaks_in_db + '</p>';
                html += '<p><strong><?php echo esc_js(__('Conflicts Found:', 'td-booking')); ?></strong> ' + response.conflicts_found + '</p>';
                
                if (response.conflicts_found > 0) {
                    html += '<h5><?php echo esc_js(__('Conflicting Breaks:', 'td-booking')); ?></h5><ul>';
                    response.conflicts.forEach(function(conflict) {
                        html += '<li><strong>' + conflict.title + '</strong>: ' + conflict.start_utc + ' <?php echo esc_js(__('to', 'td-booking')); ?> ' + conflict.end_utc;
                        html += '<br><small><?php echo esc_js(__('Logic:', 'td-booking')); ?> ' + conflict.overlap_logic + '</small></li>';
                    });
                    html += '</ul>';
                }
                
                html += '<p><strong><?php echo esc_js(__('Should Be Blocked:', 'td-booking')); ?></strong> ';
                if (response.should_be_blocked) {
                    html += '<span style="color: #d63638; font-weight: bold;"><?php echo esc_js(__('YES - This slot should NOT be available', 'td-booking')); ?></span>';
                } else {
                    html += '<span style="color: #46b450; font-weight: bold;"><?php echo esc_js(__('NO - This slot should be available', 'td-booking')); ?></span>';
                }
                html += '</p>';
                
                if (response.all_breaks.length > 0) {
                    html += '<details><summary><?php echo esc_js(__('All Staff Breaks in Database:', 'td-booking')); ?></summary><ul>';
                    response.all_breaks.forEach(function(break_item) {
                        html += '<li>' + break_item.title + ': ' + break_item.start_utc + ' <?php echo esc_js(__('to', 'td-booking')); ?> ' + break_item.end_utc + '</li>';
                    });
                    html += '</ul></details>';
                }
                
                resultDiv.html(html).show();
            },
            error: function(xhr) {
                var error = xhr.responseJSON ? xhr.responseJSON.message : '<?php echo esc_js(__('Unknown error', 'td-booking')); ?>';
                resultDiv.html('<div style="color: #d63638;"><h4><?php echo esc_js(__('Error:', 'td-booking')); ?></h4><p>' + error + '</p></div>').show();
            },
            complete: function() {
                button.prop('disabled', false).text('<?php echo esc_js(__('Debug Slot', 'td-booking')); ?>');
            }
        });
    });
});
</script>
<?php
