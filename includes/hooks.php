<?php
/**
 * TD Booking Hooks and Filters System
 * 
 * Provides comprehensive customization points for developers to extend
 * and modify the booking system behavior.
 */

defined('ABSPATH') || exit;

/**
 * Initialize hooks and filters
 */
function td_bkg_init_hooks() {
    // Initialize all hook points during plugins_loaded
    add_action('plugins_loaded', 'td_bkg_register_hooks', 5);
}
add_action('init', 'td_bkg_init_hooks');

/**
 * Register all available hooks and filters
 */
function td_bkg_register_hooks() {
    // Booking lifecycle hooks
    add_action('td_bkg_booking_created', 'td_bkg_handle_booking_created', 10, 2);
    add_action('td_bkg_booking_confirmed', 'td_bkg_handle_booking_confirmed', 10, 2);
    add_action('td_bkg_booking_cancelled', 'td_bkg_handle_booking_cancelled', 10, 3);
    add_action('td_bkg_booking_rescheduled', 'td_bkg_handle_booking_rescheduled', 10, 4);
    
    // CalDAV integration hooks
    add_action('td_bkg_caldav_sync_failed', 'td_bkg_handle_caldav_sync_failed', 10, 3);
    add_action('td_bkg_caldav_sync_success', 'td_bkg_handle_caldav_sync_success', 10, 2);
    
    // Service management hooks
    add_action('td_bkg_service_created', 'td_bkg_handle_service_created', 10, 1);
    add_action('td_bkg_service_updated', 'td_bkg_handle_service_updated', 10, 2);
    add_action('td_bkg_service_deleted', 'td_bkg_handle_service_deleted', 10, 1);
    
    // Staff assignment hooks
    add_action('td_bkg_staff_assigned', 'td_bkg_handle_staff_assigned', 10, 3);
    add_action('td_bkg_staff_unassigned', 'td_bkg_handle_staff_unassigned', 10, 3);
}

/**
 * BOOKING LIFECYCLE FILTERS
 */

/**
 * Filter availability slots before returning to frontend
 * 
 * @param array $slots Array of available time slots
 * @param int $service_id Service ID
 * @param string $from Start date
 * @param string $to End date
 * @param int $duration Duration in minutes
 * @return array Modified slots array
 */
function td_bkg_filter_availability_slots($slots, $service_id, $from, $to, $duration) {
    return apply_filters('td_bkg_availability_slots', $slots, $service_id, $from, $to, $duration);
}

/**
 * Filter booking data before creation
 * 
 * @param array $booking_data Booking data to be inserted
 * @param array $request_data Original request data
 * @return array Modified booking data
 */
function td_bkg_filter_booking_data($booking_data, $request_data) {
    return apply_filters('td_bkg_booking_data', $booking_data, $request_data);
}

/**
 * Filter staff assignment candidates
 * 
 * @param array $candidates Array of qualified staff IDs
 * @param int $service_id Service ID
 * @param array $service_data Service data
 * @return array Modified candidates array
 */
function td_bkg_filter_assignment_candidates($candidates, $service_id, $service_data) {
    return apply_filters('td_bkg_assignment_candidates', $candidates, $service_id, $service_data);
}

/**
 * Filter selected staff member before assignment
 * 
 * @param int $staff_id Selected staff ID
 * @param array $candidates Available candidates
 * @param int $service_id Service ID
 * @return int Modified staff ID
 */
function td_bkg_filter_assigned_staff($staff_id, $candidates, $service_id) {
    return apply_filters('td_bkg_assigned_staff', $staff_id, $candidates, $service_id);
}

/**
 * Filter email subject before sending
 * 
 * @param string $subject Email subject
 * @param string $email_type Type of email (confirmation, cancellation, etc.)
 * @param array $booking_data Booking data
 * @return string Modified subject
 */
function td_bkg_filter_email_subject($subject, $email_type, $booking_data) {
    return apply_filters('td_bkg_email_subject', $subject, $email_type, $booking_data);
}

/**
 * Filter email content before sending
 * 
 * @param string $content Email content
 * @param string $email_type Type of email (confirmation, cancellation, etc.)
 * @param array $booking_data Booking data
 * @return string Modified content
 */
function td_bkg_filter_email_content($content, $email_type, $booking_data) {
    return apply_filters('td_bkg_email_content', $content, $email_type, $booking_data);
}

/**
 * Filter ICS content before sending
 * 
 * @param string $ics_content ICS calendar content
 * @param array $booking_data Booking data
 * @param string $method ICS method (REQUEST, CANCEL, etc.)
 * @return string Modified ICS content
 */
function td_bkg_filter_ics_content($ics_content, $booking_data, $method) {
    return apply_filters('td_bkg_ics_content', $ics_content, $booking_data, $method);
}

/**
 * Filter service validation rules
 * 
 * @param array $validation_rules Validation rules
 * @param int $service_id Service ID
 * @return array Modified validation rules
 */
function td_bkg_filter_service_validation($validation_rules, $service_id) {
    return apply_filters('td_bkg_service_validation', $validation_rules, $service_id);
}

/**
 * Filter global opening hours
 * 
 * @param array $global_hours Global opening hours by day
 * @param string $date Date being checked
 * @return array Modified global hours
 */
function td_bkg_filter_global_hours($global_hours, $date) {
    return apply_filters('td_bkg_global_hours', $global_hours, $date);
}

/**
 * Filter staff working hours
 * 
 * @param array $staff_hours Staff working hours
 * @param int $staff_id Staff ID
 * @param string $date Date being checked
 * @return array Modified staff hours
 */
function td_bkg_filter_staff_hours($staff_hours, $staff_id, $date) {
    return apply_filters('td_bkg_staff_hours', $staff_hours, $staff_id, $date);
}

/**
 * Filter booking form fields
 * 
 * @param array $fields Form field configuration
 * @param array $atts Shortcode attributes
 * @return array Modified fields
 */
function td_bkg_filter_form_fields($fields, $atts) {
    return apply_filters('td_bkg_form_fields', $fields, $atts);
}

/**
 * BOOKING LIFECYCLE ACTION HANDLERS
 */

/**
 * Handle booking created event
 * 
 * @param int $booking_id Booking ID
 * @param array $booking_data Booking data
 */
function td_bkg_handle_booking_created($booking_id, $booking_data) {
    // Log the event
    td_bkg_log_audit('info', 'booking', 'Booking created', '', $booking_id, $booking_data['staff_id'] ?? null);
    
    // Clear availability cache
    if (isset($booking_data['staff_id']) && isset($booking_data['start_utc'])) {
        $date = substr($booking_data['start_utc'], 0, 10);
        td_bkg()->availability()->cache_invalidate($booking_data['staff_id'], $date, $date);
    }
}

/**
 * Handle booking confirmed event
 * 
 * @param int $booking_id Booking ID
 * @param array $booking_data Booking data
 */
function td_bkg_handle_booking_confirmed($booking_id, $booking_data) {
    // Send confirmation email
    if (function_exists('td_bkg_send_booking_confirmation')) {
        td_bkg_send_booking_confirmation($booking_id);
    }
    
    // Log the event
    td_bkg_log_audit('info', 'booking', 'Booking confirmed', '', $booking_id, $booking_data['staff_id'] ?? null);
}

/**
 * Handle booking cancelled event
 * 
 * @param int $booking_id Booking ID
 * @param array $booking_data Booking data
 * @param string $reason Cancellation reason
 */
function td_bkg_handle_booking_cancelled($booking_id, $booking_data, $reason = '') {
    // Send cancellation email
    if (function_exists('td_bkg_send_booking_cancellation')) {
        td_bkg_send_booking_cancellation($booking_id, $reason);
    }
    
    // Clear availability cache
    if (isset($booking_data['staff_id']) && isset($booking_data['start_utc'])) {
        $date = substr($booking_data['start_utc'], 0, 10);
        td_bkg()->availability()->cache_invalidate($booking_data['staff_id'], $date, $date);
    }
    
    // Log the event
    td_bkg_log_audit('info', 'booking', 'Booking cancelled', $reason, $booking_id, $booking_data['staff_id'] ?? null);
}

/**
 * Handle booking rescheduled event
 * 
 * @param int $booking_id Booking ID
 * @param array $old_booking_data Old booking data
 * @param array $new_booking_data New booking data
 * @param string $reason Reschedule reason
 */
function td_bkg_handle_booking_rescheduled($booking_id, $old_booking_data, $new_booking_data, $reason = '') {
    // Clear availability cache for both old and new dates
    if (isset($old_booking_data['staff_id']) && isset($old_booking_data['start_utc'])) {
        $old_date = substr($old_booking_data['start_utc'], 0, 10);
        td_bkg()->availability()->cache_invalidate($old_booking_data['staff_id'], $old_date, $old_date);
    }
    
    if (isset($new_booking_data['staff_id']) && isset($new_booking_data['start_utc'])) {
        $new_date = substr($new_booking_data['start_utc'], 0, 10);
        td_bkg()->availability()->cache_invalidate($new_booking_data['staff_id'], $new_date, $new_date);
    }
    
    // Log the event
    td_bkg_log_audit('info', 'booking', 'Booking rescheduled', $reason, $booking_id, $new_booking_data['staff_id'] ?? null);
}

/**
 * Handle CalDAV sync failure
 * 
 * @param int $booking_id Booking ID
 * @param string $error Error message
 * @param array $context Additional context
 */
function td_bkg_handle_caldav_sync_failed($booking_id, $error, $context = []) {
    // Log the failure
    td_bkg_log_audit('error', 'caldav', 'CalDAV sync failed', $error, $booking_id);
    
    // Mark booking for retry
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'td_booking',
        ['status' => 'failed_sync'],
        ['id' => $booking_id]
    );
}

/**
 * Handle CalDAV sync success
 * 
 * @param int $booking_id Booking ID
 * @param array $context Additional context
 */
function td_bkg_handle_caldav_sync_success($booking_id, $context = []) {
    // Log the success
    td_bkg_log_audit('info', 'caldav', 'CalDAV sync successful', '', $booking_id);
}

/**
 * Handle service created event
 * 
 * @param int $service_id Service ID
 */
function td_bkg_handle_service_created($service_id) {
    // Clear availability cache
    td_bkg()->availability()->cache_clear_all();
    
    // Log the event
    td_bkg_log_audit('info', 'service', 'Service created', '', null, null);
}

/**
 * Handle service updated event
 * 
 * @param int $service_id Service ID
 * @param array $changes Changed fields
 */
function td_bkg_handle_service_updated($service_id, $changes) {
    // Clear availability cache if scheduling-related changes
    $scheduling_fields = ['duration_min', 'buffer_before_min', 'buffer_after_min', 'skills_json'];
    if (array_intersect(array_keys($changes), $scheduling_fields)) {
        td_bkg()->availability()->cache_clear_all();
    }
    
    // Log the event
    td_bkg_log_audit('info', 'service', 'Service updated', json_encode($changes), null, null);
}

/**
 * Handle service deleted event
 * 
 * @param int $service_id Service ID
 */
function td_bkg_handle_service_deleted($service_id) {
    // Clear availability cache
    td_bkg()->availability()->cache_clear_all();
    
    // Log the event
    td_bkg_log_audit('info', 'service', 'Service deleted', '', null, null);
}

/**
 * Handle staff assigned to service
 * 
 * @param int $service_id Service ID
 * @param int $staff_id Staff ID
 * @param array $context Additional context
 */
function td_bkg_handle_staff_assigned($service_id, $staff_id, $context = []) {
    // Clear availability cache for this staff
    td_bkg()->availability()->cache_clear_all();
    
    // Log the event
    td_bkg_log_audit('info', 'assignment', 'Staff assigned to service', '', null, $staff_id);
}

/**
 * Handle staff unassigned from service
 * 
 * @param int $service_id Service ID
 * @param int $staff_id Staff ID
 * @param array $context Additional context
 */
function td_bkg_handle_staff_unassigned($service_id, $staff_id, $context = []) {
    // Clear availability cache for this staff
    td_bkg()->availability()->cache_clear_all();
    
    // Log the event
    td_bkg_log_audit('info', 'assignment', 'Staff unassigned from service', '', null, $staff_id);
}

/**
 * UTILITY FUNCTIONS FOR TRIGGERING HOOKS
 */

/**
 * Trigger booking created hook
 * 
 * @param int $booking_id Booking ID
 * @param array $booking_data Booking data
 */
function td_bkg_trigger_booking_created($booking_id, $booking_data) {
    do_action('td_bkg_booking_created', $booking_id, $booking_data);
}

/**
 * Trigger booking confirmed hook
 * 
 * @param int $booking_id Booking ID
 * @param array $booking_data Booking data
 */
function td_bkg_trigger_booking_confirmed($booking_id, $booking_data) {
    do_action('td_bkg_booking_confirmed', $booking_id, $booking_data);
}

/**
 * Trigger booking cancelled hook
 * 
 * @param int $booking_id Booking ID
 * @param array $booking_data Booking data
 * @param string $reason Cancellation reason
 */
function td_bkg_trigger_booking_cancelled($booking_id, $booking_data, $reason = '') {
    do_action('td_bkg_booking_cancelled', $booking_id, $booking_data, $reason);
}

/**
 * Trigger booking rescheduled hook
 * 
 * @param int $booking_id Booking ID
 * @param array $old_booking_data Old booking data
 * @param array $new_booking_data New booking data
 * @param string $reason Reschedule reason
 */
function td_bkg_trigger_booking_rescheduled($booking_id, $old_booking_data, $new_booking_data, $reason = '') {
    do_action('td_bkg_booking_rescheduled', $booking_id, $old_booking_data, $new_booking_data, $reason);
}

/**
 * Trigger CalDAV sync failed hook
 * 
 * @param int $booking_id Booking ID
 * @param string $error Error message
 * @param array $context Additional context
 */
function td_bkg_trigger_caldav_sync_failed($booking_id, $error, $context = []) {
    do_action('td_bkg_caldav_sync_failed', $booking_id, $error, $context);
}

/**
 * Trigger CalDAV sync success hook
 * 
 * @param int $booking_id Booking ID
 * @param array $context Additional context
 */
function td_bkg_trigger_caldav_sync_success($booking_id, $context = []) {
    do_action('td_bkg_caldav_sync_success', $booking_id, $context);
}

/**
 * EXAMPLE USAGE AND DOCUMENTATION
 */

/**
 * Example: Custom availability logic
 * 
 * Add this to your theme's functions.php or a custom plugin:
 * 
 * function my_custom_availability_filter($slots, $service_id, $from, $to, $duration) {
 *     // Only show slots between 9 AM and 5 PM
 *     return array_filter($slots, function($slot) {
 *         $hour = (int) date('H', strtotime($slot['start_utc']));
 *         return $hour >= 9 && $hour < 17;
 *     });
 * }
 * add_filter('td_bkg_availability_slots', 'my_custom_availability_filter', 10, 5);
 */

/**
 * Example: Custom booking validation
 * 
 * function my_custom_booking_validation($booking_data, $request_data) {
 *     // Require phone number for certain services
 *     if ($booking_data['service_id'] == 123 && empty($booking_data['customer_phone'])) {
 *         wp_die('Phone number is required for this service.');
 *     }
 *     return $booking_data;
 * }
 * add_filter('td_bkg_booking_data', 'my_custom_booking_validation', 10, 2);
 */

/**
 * Example: Custom email content
 * 
 * function my_custom_email_content($content, $email_type, $booking_data) {
 *     if ($email_type === 'confirmation') {
 *         $content .= "\n\nSpecial instructions: Please bring your ID.";
 *     }
 *     return $content;
 * }
 * add_filter('td_bkg_email_content', 'my_custom_email_content', 10, 3);
 */

/**
 * Example: Custom booking created action
 * 
 * function my_booking_created_handler($booking_id, $booking_data) {
 *     // Send notification to Slack
 *     wp_remote_post('https://hooks.slack.com/your-webhook', [
 *         'body' => json_encode([
 *             'text' => "New booking created: #{$booking_id}"
 *         ])
 *     ]);
 * }
 * add_action('td_bkg_booking_created', 'my_booking_created_handler', 10, 2);
 */
