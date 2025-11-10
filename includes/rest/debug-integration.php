<?php
defined('ABSPATH') || exit;

// Debug endpoint for testing TD Technicians integration
add_action('rest_api_init', function() {
    register_rest_route('td/v1', '/debug/integration', [
        'methods' => 'GET',
        'callback' => 'td_bkg_debug_integration',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
});

function td_bkg_debug_integration($request) {
    $debug_info = [];
    
    // Check if TD Technicians is available
    $debug_info['td_tech_available'] = function_exists('td_tech');
    
    if ($debug_info['td_tech_available']) {
        $debug_info['td_tech_api_version'] = defined('TD_TECH_API_VERSION') ? TD_TECH_API_VERSION : 'unknown';
        $debug_info['compatibility'] = td_bkg_check_technicians_compatibility();
        
        // Test fallback method first (safer)
        try {
            $fallback_technicians = td_bkg_get_technicians_fallback();
            $debug_info['fallback_technicians_count'] = count($fallback_technicians);
            $debug_info['fallback_sample'] = !empty($fallback_technicians) ? $fallback_technicians[0] : null;
        } catch (Exception $e) {
            $debug_info['fallback_error'] = $e->getMessage();
        }
        
        // Test getting technicians using our safe method
        try {
            $technicians = td_bkg_get_active_technicians();
            $debug_info['safe_technicians_count'] = count($technicians);
            $debug_info['safe_sample_technician'] = !empty($technicians) ? $technicians[0] : null;
        } catch (Exception $e) {
            $debug_info['safe_technicians_error'] = $e->getMessage();
        }
        
        // Test repository access (this might fail)
        try {
            $repo = td_tech()->repo();
            $debug_info['repo_available'] = true;
            
            // Try to get first technician safely
            global $wpdb;
            $first_staff_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}td_staff LIMIT 1");
            if ($first_staff_id) {
                // Test both methods
                $fallback_staff = td_bkg_get_staff_fallback($first_staff_id);
                $debug_info['fallback_staff_retrieval'] = $fallback_staff ? 'success' : 'failed';
                
                $safe_staff = td_bkg_get_staff_safe($first_staff_id, $repo);
                $debug_info['safe_staff_retrieval'] = $safe_staff ? 'success' : 'failed';
                $debug_info['staff_sample'] = $safe_staff ?: $fallback_staff;
            }
            
        } catch (Exception $e) {
            $debug_info['repo_error'] = $e->getMessage();
        }
    }
    
    // Test our integration functions
    $debug_info['integration_functions'] = [
        'td_bkg_get_active_technicians' => function_exists('td_bkg_get_active_technicians'),
        'td_bkg_normalize_technician_data' => function_exists('td_bkg_normalize_technician_data'),
        'td_bkg_get_staff_safe' => function_exists('td_bkg_get_staff_safe'),
        'td_bkg_get_staff_fallback' => function_exists('td_bkg_get_staff_fallback'),
        'td_bkg_get_technicians_fallback' => function_exists('td_bkg_get_technicians_fallback'),
        'td_bkg_get_qualified_staff' => function_exists('td_bkg_get_qualified_staff'),
    ];
    
    // Database status
    global $wpdb;
    $debug_info['database_status'] = [
        'td_staff_table_exists' => (bool)$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}td_staff'"),
        'td_service_table_exists' => (bool)$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}td_service'"),
        'td_booking_table_exists' => (bool)$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}td_booking'"),
    ];
    
    return rest_ensure_response($debug_info);
}
