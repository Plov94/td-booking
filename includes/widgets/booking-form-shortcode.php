<?php
defined('ABSPATH') || exit;

// [td_booking_form service="slug" address="on|off" title="Custom Title"]
function td_bkg_booking_form_shortcode($atts) {
    $atts = shortcode_atts([
        'service' => '',
        'address' => 'on',
        'title' => '',
        // time_format: auto (use browser/OS), '12', or '24'
        'time_format' => 'auto',
        // api_base: override REST base URL (useful for proxies/embeds), e.g. https://example.com/wp-json/td/v1/
        'api_base' => '',
        // optionally show a staff selector filtered by the selected service
        'staff_select' => 'off',
        // show a customer notes field
        'notes' => 'on',
    ], $atts);
    
    // Fetch all active services for dropdown
    global $wpdb;
    $services = $wpdb->get_results("SELECT id, name, slug FROM {$wpdb->prefix}td_service WHERE active=1 ORDER BY name ASC", ARRAY_A);
    $wc_enabled = get_option('td_bkg_wc_enabled');
    $group_enabled = (bool) get_option('td_bkg_group_enabled');
    $steps_enabled = (bool) get_option('td_bkg_steps_enabled');
    $terms_page_id = intval(get_option('td_bkg_terms_page_id', 0));
    $terms_url = esc_url(get_option('td_bkg_terms_url', ''));
    $terms_mode = get_option('td_bkg_terms_mode', 'link');
    $terms_href = '';
    if ($terms_page_id) { $terms_href = get_permalink($terms_page_id); }
    elseif (!empty($terms_url)) { $terms_href = $terms_url; }
    
    // Apply form fields filter
    $default_fields = [
        'name' => ['required' => true, 'type' => 'text', 'label' => __('Full Name', 'td-booking')],
        'email' => ['required' => true, 'type' => 'email', 'label' => __('Email Address', 'td-booking')],
        'phone' => ['required' => false, 'type' => 'tel', 'label' => __('Phone Number', 'td-booking')],
        'address' => ['required' => false, 'type' => 'text', 'label' => __('Address', 'td-booking'), 'show' => $atts['address'] === 'on'],
    ];
    
    if (function_exists('td_bkg_filter_form_fields')) {
        $form_fields = td_bkg_filter_form_fields($default_fields, $atts);
    } else {
        $form_fields = $default_fields;
    }
    
    // Find service by slug if specified
    $selected_service_id = '';
    if (!empty($atts['service'])) {
        foreach ($services as $service) {
            if ($service['slug'] === $atts['service']) {
                $selected_service_id = $service['id'];
                break;
            }
        }
        // If slug not found, try as ID
        if (empty($selected_service_id) && is_numeric($atts['service'])) {
            $selected_service_id = $atts['service'];
        }
    }
    
    // Enqueue assets
    wp_enqueue_style('td-booking-public', TD_BKG_URL . 'assets/css/public.css', [], TD_BKG_VER);
    wp_enqueue_script('td-booking-public', TD_BKG_URL . 'assets/js/public.js', ['jquery'], TD_BKG_VER, true);
    
    // Localize script with enhanced labels and timezone info
    $business_hours = get_option('td_bkg_global_hours', []);
    $hours_enforcement = get_option('td_bkg_global_hours_enforcement', 'restrict');
    // Site timezone details
    if (function_exists('wp_timezone')) {
        $site_tz_obj = wp_timezone();
        $tz_string = $site_tz_obj->getName();
    } else {
        $tz_string = get_option('timezone_string');
        if (!$tz_string || $tz_string === '') {
            $tz_string = 'UTC';
        }
        $site_tz_obj = new DateTimeZone($tz_string ?: 'UTC');
    }
    $offset_min = 0;
    try {
        $now_dt = new DateTimeImmutable('now', $site_tz_obj);
        $offset_min = intval($now_dt->getOffset() / 60);
    } catch (Exception $e) {}
    // Optional staff restriction via shortcodes
    $staff_limit = isset($GLOBALS['td_bkg_form_staff_limit']) ? intval($GLOBALS['td_bkg_form_staff_limit']) : 0;
    $staff_select_enabled = ($atts['staff_select'] === 'on' || $atts['staff_select'] === '1') && !$staff_limit;
    // Read page-level meta toggle for staff agnostic (set by demo helper)
    $page_agnostic = false;
    if (function_exists('get_queried_object_id')) {
        $page_id = get_queried_object_id();
        if ($page_id) {
            $page_agnostic = (bool) get_post_meta($page_id, 'td_bkg_staff_agnostic', true);
        }
    }
    // Locale info
    $site_locale = function_exists('get_locale') ? get_locale() : 'en_US';
    $site_locale = str_replace('_', '-', $site_locale);
    $start_of_week = (int) get_option('start_of_week', 1); // 1 = Monday default for many locales
    $rest_base = trim($atts['api_base']);
    if ($rest_base !== '') {
        // Ensure trailing slash
        if (substr($rest_base, -1) !== '/') { $rest_base .= '/'; }
    } else {
        $rest_base = rest_url('td/v1/');
    }
    wp_localize_script('td-booking-public', 'tdBooking', [
        'restUrl' => esc_url_raw($rest_base),
        'nonce' => wp_create_nonce('wp_rest'),
        'wcEnabled' => (bool) $wc_enabled,
    'stepsEnabled' => (bool) $steps_enabled,
        'staffLimit' => $staff_limit,
    'staffAgnostic' => (!empty($GLOBALS['td_bkg_form_staff_agnostic'])) || $page_agnostic,
    'staffSelectEnabled' => (bool) $staff_select_enabled,
        'locale' => $site_locale,
        'startOfWeek' => $start_of_week,
        // Display timezone preference: site (business) timezone for consistent hours
        'displayTz' => 'site',
        'siteTz' => $tz_string,
        'siteTzOffsetMin' => $offset_min,
        'rules' => [
            'leadTimeMin' => (int) get_option('td_bkg_lead_time_minutes', 60),
            'horizonDays' => (int) get_option('td_bkg_booking_horizon_days', 30),
            'businessHours' => is_array($business_hours) ? $business_hours : [],
            'hoursEnforcement' => $hours_enforcement,
            'defaultDurationMin' => (int) get_option('td_bkg_default_duration_minutes', 30),
        ],
        // Time display preference: 'auto' (browser/OS), '12', or '24'
        'timeFormat' => in_array($atts['time_format'], ['auto','12','24'], true) ? $atts['time_format'] : 'auto',
        'terms' => [
            'href' => $terms_href,
            'mode' => $terms_mode,
        ],
        'labels' => [
            'customerInfo' => __('Customer Information', 'td-booking'),
            'loading' => __('Loading...', 'td-booking'),
            'book' => $wc_enabled ? __('Continue to Checkout', 'td-booking') : __('Book Appointment', 'td-booking'),
            'loadingSlots' => __('Loading available times...', 'td-booking'),
            'noSlots' => __('No available time slots found. Please try different dates or contact us directly.', 'td-booking'),
            'selectTime' => __('Select appointment time', 'td-booking'),
            'selectSlot' => __('Choose a time slot', 'td-booking'),
            'errorLoadingSlots' => __('Error loading available times. Please try again.', 'td-booking'),
            'service' => __('Service', 'td-booking'),
            'staff' => __('Staff', 'td-booking'),
            'chooseStaff' => __('Choose a staff member', 'td-booking'),
            'timeSlot' => __('Time slot', 'td-booking'),
            'name' => __('Name', 'td-booking'),
            'email' => __('Email', 'td-booking'),
            'phone' => __('Phone', 'td-booking'),
            'terms' => __('Terms acceptance', 'td-booking'),
            'missingFields' => __('Please fill in the following required fields: ', 'td-booking'),
            'invalidEmail' => __('Please enter a valid email address.', 'td-booking'),
            'redirectingCheckout' => __('Redirecting to checkout...', 'td-booking'),
            'bookingSuccess' => __('Booking successful! You will receive a confirmation email shortly.', 'td-booking'),
            'bookingError' => __('Error creating booking. Please try again.', 'td-booking'),
            'networkError' => __('Network error. Please check your connection and try again.', 'td-booking'),
            'selectDatePrompt' => __('Select a date to see available times', 'td-booking'),
            'slots' => __('slots', 'td-booking'),
            'prevMonth' => __('Previous month', 'td-booking'),
            'nextMonth' => __('Next month', 'td-booking'),
            'calendar' => __('Calendar', 'td-booking'),
            'selectDate' => __('Select date', 'td-booking'),
            'participant' => __('Participant', 'td-booking'),
            'termsTitle' => __('Terms & Conditions', 'td-booking'),
            'loadingText' => __('Loading…', 'td-booking'),
            'close' => __('Close', 'td-booking'),
            'decreaseParticipants' => __('Decrease participants', 'td-booking'),
            'increaseParticipants' => __('Increase participants', 'td-booking'),
            'notesPrompt' => __('Do you want to leave a comment for the person doing the job?', 'td-booking'),
            'notesPlaceholder' => __('Optional comments for your technician', 'td-booking'),
        ]
    ]);
    
    ob_start();
    ?>
    <div class="td-booking-wrapper">
        <?php if (!empty($atts['title'])): ?>
            <h3 class="td-booking-title"><?php echo esc_html($atts['title']); ?></h3>
        <?php endif; ?>
        
        <form class="td-booking-form" data-wc="<?php echo esc_attr($wc_enabled ? '1' : '0'); ?>" data-steps="<?php echo esc_attr($steps_enabled ? '1' : '0'); ?>">
            <?php if ($group_enabled): ?>
                <div class="group-size-field">
                    <label for="td-booking-participants">
                        <?php esc_html_e('Number of participants', 'td-booking'); ?>
                    </label>
                    <div style="display:flex;align-items:center;gap:8px;max-width:240px;">
                        <button type="button" class="td-qty-btn" data-op="-" aria-label="<?php echo esc_attr__('Decrease participants', 'td-booking'); ?>">&minus;</button>
                        <input type="number" 
                               id="td-booking-participants"
                               name="participants" 
                               min="1" 
                               max="99" 
                               value="1">
                        <button type="button" class="td-qty-btn" data-op="+" aria-label="<?php echo esc_attr__('Increase participants', 'td-booking'); ?>">+</button>
                    </div>
                    <small class="description"><?php esc_html_e('Set how many people will attend', 'td-booking'); ?></small>
                </div>
            <?php endif; ?>
            
            <?php $hide_service_ui = (!empty($staff_limit) && $page_agnostic); ?>
            <div class="service-selection"<?php echo $hide_service_ui ? ' style="display:none;"' : ''; ?>>    
                <?php if (empty($selected_service_id)): ?>
                    <label for="td-booking-service">
                        <?php esc_html_e('Select service', 'td-booking'); ?> <span class="required">*</span>
                    </label>
                    <select name="service_id" id="td-booking-service" <?php echo $hide_service_ui ? '' : 'required'; ?>>
                        <option value=""><?php esc_html_e('Choose a service', 'td-booking'); ?></option>
                        <?php foreach ($services as $svc): ?>
                            <option value="<?php echo esc_attr($svc['id']); ?>">
                                <?php echo esc_html($svc['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="service_id" value="<?php echo esc_attr($selected_service_id); ?>">
                    <?php
                    // Show selected service name
                    foreach ($services as $svc) {
                        if ($svc['id'] == $selected_service_id) {
                            echo '<p class="selected-service"><strong>' . esc_html($svc['name']) . '</strong></p>';
                            break;
                        }
                    }
                    ?>
                <?php endif; ?>
            </div>
            <?php if ($staff_select_enabled): ?>
                <div class="staff-selection">
                    <label for="td-booking-staff">
                        <?php esc_html_e('Select staff', 'td-booking'); ?>
                    </label>
                    <select name="staff_id" id="td-booking-staff" disabled>
                        <option value=""><?php esc_html_e('Choose a staff member', 'td-booking'); ?></option>
                    </select>
                </div>
            <?php endif; ?>
            <?php
            // If the form is restricted to a specific staff member, show their name for clarity
            if (!empty($staff_limit) && function_exists('td_bkg_get_staff_safe')) {
                $staff = td_bkg_get_staff_safe((int)$staff_limit);
                if (is_array($staff) && !empty($staff['name'])) {
                    echo '<p class="td-assigned-staff"><em>' . esc_html(sprintf(__('Assigned technician: %s', 'td-booking'), $staff['name'])) . '</em></p>';
                }
            }
            ?>
            
            <div class="td-booking-calendar" aria-label="<?php echo esc_attr__('Select date', 'td-booking'); ?>"></div>
            
            <div class="td-booking-slots"></div>
            
            <div class="customer-info">
                <h3><?php esc_html_e('Customer Information', 'td-booking'); ?></h3>
                
                <label for="td-booking-name">
                    <?php esc_html_e('Full Name', 'td-booking'); ?> <span class="required">*</span>
                </label>
                <input type="text" 
                       id="td-booking-name"
                       name="name" 
                       placeholder="<?php esc_attr_e('Enter your full name', 'td-booking'); ?>" 
                       required>
                
                <label for="td-booking-email">
                    <?php esc_html_e('Email Address', 'td-booking'); ?> <span class="required">*</span>
                </label>
                <input type="email" 
                       id="td-booking-email"
                       name="email" 
                       placeholder="<?php esc_attr_e('Enter your email address', 'td-booking'); ?>" 
                       required>
                
                <label for="td-booking-phone">
                    <?php esc_html_e('Phone Number', 'td-booking'); ?>
                </label>
                <input type="tel" 
                       id="td-booking-phone"
                       name="phone" 
                       placeholder="<?php esc_attr_e('Enter your phone number', 'td-booking'); ?>">
                
                <?php if ($atts['address'] === 'on'): ?>
                    <label for="td-booking-address">
                        <?php esc_html_e('Address', 'td-booking'); ?>
                    </label>
                    <input type="text" 
                           id="td-booking-address"
                           name="address" 
                           placeholder="<?php esc_attr_e('Enter your address', 'td-booking'); ?>">
                <?php endif; ?>

                <?php if ($atts['notes'] === 'on'): ?>
                    <label for="td-booking-notes" class="td-notes-label">
                        <?php esc_html_e('Do you want to leave a comment for the person doing the job?', 'td-booking'); ?>
                    </label>
                    <textarea id="td-booking-notes" name="notes" rows="3" placeholder="<?php esc_attr_e('Optional comments for your technician', 'td-booking'); ?>" style="width:100%;"></textarea>
                <?php endif; ?>

                <?php if ($group_enabled): ?>
                    <div class="td-participants" style="display:none;"></div>
                <?php endif; ?>
            </div>
            
            <div class="checkbox-label">
                <input type="checkbox" id="td-booking-terms" name="terms" required>
                <label for="td-booking-terms">
                    <?php 
                    $terms_text = get_option('td_bkg_terms_text', __('I accept the terms and conditions', 'td-booking'));
                    if (!empty($terms_href)) {
                        if ($terms_mode === 'modal') {
                            echo '<a href="#" class="td-terms-link" data-terms-href="' . esc_attr($terms_href) . '">' . esc_html($terms_text) . '</a>';
                        } else {
                            echo '<a href="' . esc_url($terms_href) . '" target="_blank" rel="noopener">' . esc_html($terms_text) . '</a>';
                        }
                    } else {
                        echo esc_html($terms_text);
                    }
                    ?>
                    <span class="required">*</span>
                </label>
            </div>
            
            <button type="submit">
                <?php echo $wc_enabled ? esc_html__('Continue to Checkout', 'td-booking') : esc_html__('Book Appointment', 'td-booking'); ?>
            </button>
            
            <div class="td-booking-message"></div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('td_booking_form', 'td_bkg_booking_form_shortcode');

// Convenience shortcodes
// [td_booking_service service="slug-or-id" title="..."]
function td_bkg_booking_service_shortcode($atts) {
    // Delegate to main with preselected service
    $atts = shortcode_atts([
        'service' => '',
        'address' => 'on',
        'title' => '',
        'time_format' => 'auto',
        'api_base' => '',
        'staff_select' => 'off',
    ], $atts);
    return td_bkg_booking_form_shortcode($atts);
}
add_shortcode('td_booking_service', 'td_bkg_booking_service_shortcode');

// [td_booking_staff staff="ID" title="..."] - restrict to a specific staff member
function td_bkg_booking_staff_shortcode($atts) {
    $atts = shortcode_atts([
        'service' => '',
        'staff' => '',
        'address' => 'on',
        'title' => '',
        'time_format' => 'auto',
        // allow booking this staff member for any service (ignore service mapping)
        'agnostic' => '0',
        'api_base' => '',
    ], $atts);
    // Persist staff restriction via data attribute and localized config
    add_filter('td_bkg_form_fields', function($fields) { return $fields; }, 10, 1); // placeholder if needed later
    // Temporarily stash staff in a global for render pass
    $GLOBALS['td_bkg_form_staff_limit'] = $atts['staff'];
    $GLOBALS['td_bkg_form_staff_agnostic'] = ($atts['agnostic'] === '1' || $atts['agnostic'] === 'on');
    $html = td_bkg_booking_form_shortcode($atts);
    unset($GLOBALS['td_bkg_form_staff_limit']);
    unset($GLOBALS['td_bkg_form_staff_agnostic']);
    return $html;
}
add_shortcode('td_booking_staff', 'td_bkg_booking_staff_shortcode');

// [td_booking_service_staff service="..." staff="..."]
function td_bkg_booking_service_staff_shortcode($atts) {
    $atts = shortcode_atts([
        'service' => '',
        'staff' => '',
        'address' => 'on',
        'title' => '',
        'time_format' => 'auto',
        'agnostic' => '0',
        'api_base' => '',
        'staff_select' => 'off',
    ], $atts);
    $GLOBALS['td_bkg_form_staff_limit'] = $atts['staff'];
    $GLOBALS['td_bkg_form_staff_agnostic'] = ($atts['agnostic'] === '1' || $atts['agnostic'] === 'on');
    $html = td_bkg_booking_form_shortcode($atts);
    unset($GLOBALS['td_bkg_form_staff_limit']);
    unset($GLOBALS['td_bkg_form_staff_agnostic']);
    return $html;
}
add_shortcode('td_booking_service_staff', 'td_bkg_booking_service_staff_shortcode');
