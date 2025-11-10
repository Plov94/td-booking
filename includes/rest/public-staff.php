<?php
defined('ABSPATH') || exit;

add_action('rest_api_init', function() {
    register_rest_route('td/v1', '/staff', [
        'methods' => 'GET',
        'callback' => 'td_bkg_rest_get_staff',
        'permission_callback' => '__return_true', // Public
    ]);
});

function td_bkg_rest_get_staff($request) {
    if (!function_exists('td_bkg_get_active_technicians')) {
        return new WP_Error('unavailable', __('Technicians integration not available', 'td-booking'), ['status' => 503]);
    }
    global $wpdb;
    $service_id = intval($request['service_id'] ?? 0);
    $staff_ids = [];
    if ($service_id > 0) {
        // Only staff mapped to this service
        $staff_ids = $wpdb->get_col($wpdb->prepare("SELECT staff_id FROM {$wpdb->prefix}td_service_staff WHERE service_id=%d", $service_id));
        $staff_ids = array_values(array_filter(array_map('intval', (array)$staff_ids)));
    }

    $result = [];
    if ($service_id > 0) {
        // When a service is specified, only return mapped staff (or empty if none)
        foreach ($staff_ids as $sid) {
            if (function_exists('td_bkg_get_staff_safe')) {
                $st = td_bkg_get_staff_safe($sid);
                $name = is_array($st) ? ($st['name'] ?? (string)$sid) : (string)$sid;
            } else {
                $name = (string)$sid;
            }
            $result[] = [ 'id' => $sid, 'name' => $name ];
        }
    } else if (!empty($staff_ids)) {
        foreach ($staff_ids as $sid) {
            if (function_exists('td_bkg_get_staff_safe')) {
                $st = td_bkg_get_staff_safe($sid);
                $name = is_array($st) ? ($st['name'] ?? (string)$sid) : (string)$sid;
            } else {
                $name = (string)$sid;
            }
            $result[] = [ 'id' => $sid, 'name' => $name ];
        }
    } else {
        // Return all active technicians
        $techs = td_bkg_get_active_technicians();
        if (is_array($techs)) {
            foreach ($techs as $t) {
                $sid = is_array($t) ? intval($t['id'] ?? 0) : (is_object($t) ? intval($t->id ?? 0) : 0);
                if (!$sid) continue;
                $name = is_array($t) ? ($t['name'] ?? (string)$sid) : (is_object($t) ? ($t->name ?? (string)$sid) : (string)$sid);
                $result[] = [ 'id' => $sid, 'name' => $name ];
            }
        }
    }

    // Sort by name ASC
    usort($result, function($a, $b){ return strcasecmp($a['name'], $b['name']); });

    return rest_ensure_response($result);
}
