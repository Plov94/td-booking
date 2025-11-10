<?php
defined('ABSPATH') || exit;

add_action('rest_api_init', function() {
    register_rest_route('td/v1', '/availability', [
        'methods' => 'GET',
        'callback' => 'td_bkg_rest_availability',
        'permission_callback' => '__return_true',
    ]);
});

function td_bkg_rest_availability($request) {
    if (get_option('td_bkg_degraded')) {
        return new WP_Error('technicians_missing', __('Technicians plugin missing', 'td-booking'), ['status' => 503]);
    }
    $service_id = intval($request['service_id'] ?? 0);
    $from = sanitize_text_field($request['from'] ?? '');
    $to = sanitize_text_field($request['to'] ?? '');
    $duration = intval($request['duration'] ?? 0);
    $with_staff = !empty($request['with_staff']);
    $staff_id_filter = intval($request['staff_id'] ?? 0);
    $agnostic = !empty($request['agnostic']);
    // Debug log removed
    if ( (!$service_id && !($agnostic && $staff_id_filter > 0)) || !$from || !$to ) {
        if (function_exists('td_bkg_log_audit')) {
            td_bkg_log_audit('warn', 'availability', 'Invalid availability request', json_encode([
                'service_id' => $service_id,
                'from' => $from,
                'to' => $to,
                'duration' => $duration
            ]));
        }
        return new WP_Error('invalid_params', __('Missing required parameters', 'td-booking'), ['status' => 400]);
    }
    if ($duration <= 0) {
        global $wpdb;
        $duration = 0;
        if ($service_id > 0) {
            $svc = $wpdb->get_row($wpdb->prepare("SELECT duration_min FROM {$wpdb->prefix}td_service WHERE id=%d AND active=1", $service_id), ARRAY_A);
            if ($svc) { $duration = intval($svc['duration_min']); }
            if ($duration <= 0) { $duration = 0; }
        }
        if ($duration <= 0) {
            $duration = intval(get_option('td_bkg_default_duration_minutes', 30));
            if ($duration <= 0) { $duration = 30; }
        }
    }
    // Accept from/to as either local "Y-m-d H:i:s" or UTC; engine handles both.
    // For clarity, ensure they are in "Y-m-d H:i:s" format when provided as ISO.
    $from = str_replace('T', ' ', substr($from, 0, 19));
    $to = str_replace('T', ' ', substr($to, 0, 19));

    // If staff filtering is requested, we must return staff info
    $options = [];
    if ($with_staff || $staff_id_filter > 0) { $options['return_staff'] = true; }
    if ($staff_id_filter > 0) {
        $options['override_staff_ids'] = [$staff_id_filter];
        if ($agnostic) { $options['ignore_mapping'] = true; }
    }
    $slots = td_bkg_availability_engine($service_id, $from, $to, $duration, $options);
    // Optional server-side filter to a specific staff member
    // No need for additional filtering; engine already constrained when override applied.
    if (function_exists('td_bkg_log_audit')) {
        td_bkg_log_audit('info', 'availability', 'Availability requested', json_encode([
            'service_id' => $service_id,
            'from' => $from,
            'to' => $to,
            'duration' => $duration,
            'slot_count' => is_array($slots) ? count($slots) : 0
        ]));
    }
    // Debug log removed
    return rest_ensure_response($slots);
}
