<?php
defined('ABSPATH') || exit;

/**
 * TD Booking Debug Helpers
 */

/**
 * Enhanced debug logging for TD Booking
 */
function td_bkg_debug_log($message, $context = []) {
    // Enabled when WP_DEBUG is true or plugin Debug Mode is on
    $enabled = (defined('WP_DEBUG') && WP_DEBUG) || get_option('td_bkg_debug_mode');
    if (!$enabled) {
        return;
    }
    
    $log_message = '[TD Booking Enhanced] ' . $message;
    
    if (!empty($context)) {
        $log_message .= ' | Context: ' . json_encode($context);
    }
    
    error_log($log_message);
}

/**
 * Log integration events
 */
function td_bkg_log_integration_event($event, $staff_id = null, $data = []) {
    $context = ['event' => $event];
    
    if ($staff_id) {
        $context['staff_id'] = $staff_id;
    }
    
    if (!empty($data)) {
        $context['data'] = $data;
    }
    
    td_bkg_debug_log("Integration event: $event", $context);
}

/**
 * Log skills matching results
 */
function td_bkg_log_skills_matching($service_id, $required_skills, $qualified_staff) {
    td_bkg_debug_log("Skills matching completed", [
        'service_id' => $service_id,
        'required_skills' => $required_skills,
        'qualified_staff_count' => count($qualified_staff),
        'qualified_staff_ids' => $qualified_staff
    ]);
}

/**
 * Log assignment decisions
 */
function td_bkg_log_assignment($service_id, $candidates, $selected_staff_id, $scores = []) {
    td_bkg_debug_log("Staff assignment completed", [
        'service_id' => $service_id,
        'candidates' => $candidates,
        'selected_staff_id' => $selected_staff_id,
        'scores' => $scores
    ]);
}
