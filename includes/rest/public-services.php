<?php
defined('ABSPATH') || exit;

// REST endpoint for getting services list
add_action('rest_api_init', function() {
    register_rest_route('td/v1', '/services', [
        'methods' => 'GET',
        'callback' => 'td_bkg_rest_get_services',
        'permission_callback' => '__return_true', // Public endpoint
    ]);
});

function td_bkg_rest_get_services($request) {
    global $wpdb;
    
    // Check which columns exist in the table
    $columns = $wpdb->get_col("DESCRIBE {$wpdb->prefix}td_service");
    $has_price = in_array('price', $columns);
    
    $select_fields = 'id, name, slug, description, duration_min';
    if ($has_price) {
        $select_fields .= ', price';
    }
    
    $services = $wpdb->get_results(
        "SELECT {$select_fields}
         FROM {$wpdb->prefix}td_service 
         WHERE active = 1 
         ORDER BY name ASC", 
        ARRAY_A
    );
    
    if ($wpdb->last_error) {
        return new WP_Error('db_error', $wpdb->last_error, ['status' => 500]);
    }
    
    // Ensure price is a number and add default if missing
    foreach ($services as &$service) {
        $service['price'] = $has_price ? floatval($service['price'] ?? 0) : 75.0; // Default price
        $service['duration_min'] = intval($service['duration_min'] ?? 30);
    }
    
    return rest_ensure_response($services);
}
