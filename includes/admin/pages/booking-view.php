<?php
echo '<h1>' . esc_html__('Booking Details', 'td-booking') . '</h1>';

global $wpdb;
$booking_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$booking_id) {
    echo '<div class="notice notice-error"><p>' . esc_html__('Invalid booking ID.', 'td-booking') . '</p></div>';
    return;
}

$booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_booking WHERE id=%d", $booking_id), ARRAY_A);
if ($booking && function_exists('td_bkg_booking_decrypt_row')) {
    $booking = td_bkg_booking_decrypt_row($booking);
}

if (!$booking) {
    echo '<div class="notice notice-error"><p>' . esc_html__('Booking not found.', 'td-booking') . '</p></div>';
    return;
}

// Get service name
$service_name = '';
if (!empty($booking['service_id'])) {
    $service = $wpdb->get_row($wpdb->prepare("SELECT name FROM {$wpdb->prefix}td_service WHERE id=%d", $booking['service_id']), ARRAY_A);
    $service_name = $service ? $service['name'] : '';
}
if (!$service_name) { $service_name = __('Custom / Specifically requested', 'td-booking'); }

// Add back to list link
echo '<p><a href="' . admin_url('admin.php?page=td-booking-bookings') . '" class="button">&larr; ' . esc_html__('Back to Bookings List', 'td-booking') . '</a></p>';

// Display booking details in a nice table
echo '<table class="form-table">';
echo '<tr><th>' . esc_html__('Booking ID', 'td-booking') . '</th><td>' . esc_html($booking['id']) . '</td></tr>';
echo '<tr><th>' . esc_html__('Service', 'td-booking') . '</th><td>' . esc_html($service_name) . '</td></tr>';
echo '<tr><th>' . esc_html__('Customer Name', 'td-booking') . '</th><td>' . esc_html($booking['customer_name']) . '</td></tr>';
echo '<tr><th>' . esc_html__('Customer Email', 'td-booking') . '</th><td>' . esc_html($booking['customer_email']) . '</td></tr>';
if ($booking['customer_phone']) {
    echo '<tr><th>' . esc_html__('Customer Phone', 'td-booking') . '</th><td>' . esc_html($booking['customer_phone']) . '</td></tr>';
}
// Staff display: name plus ID
$staff_display = '';
if (!empty($booking['staff_id'])) {
    if (function_exists('td_bkg_get_staff_safe')) {
        $st = td_bkg_get_staff_safe(intval($booking['staff_id']));
        if ($st && !empty($st['display_name'])) { $staff_display = $st['display_name']; }
    }
    $staff_display = trim($staff_display);
    if ($staff_display !== '') { $staff_display .= ' '; }
    $staff_display .= '(' . sprintf(esc_html__('ID: %d', 'td-booking'), intval($booking['staff_id'])) . ')';
}
echo '<tr><th>' . esc_html__('Staff', 'td-booking') . '</th><td>' . esc_html($staff_display) . '</td></tr>';

echo '<tr><th>' . esc_html__('Start Time', 'td-booking') . '</th><td>' . esc_html($booking['start_utc']) . ' UTC</td></tr>';
echo '<tr><th>' . esc_html__('End Time', 'td-booking') . '</th><td>' . esc_html($booking['end_utc']) . ' UTC</td></tr>';
echo '<tr><th>' . esc_html__('Status', 'td-booking') . '</th><td><span class="booking-status-' . esc_attr($booking['status']) . '">' . esc_html($booking['status']) . '</span></td></tr>';
if ($booking['notes']) {
    echo '<tr><th>' . esc_html__('Notes', 'td-booking') . '</th><td>' . esc_html($booking['notes']) . '</td></tr>';
}
echo '<tr><th>' . esc_html__('Created', 'td-booking') . '</th><td>' . esc_html($booking['created_at']) . '</td></tr>';
echo '<tr><th>' . esc_html__('Last Updated', 'td-booking') . '</th><td>' . esc_html($booking['updated_at']) . '</td></tr>';

// CalDAV information
if ($booking['caldav_uid']) {
    echo '<tr><th>' . esc_html__('CalDAV UID', 'td-booking') . '</th><td>' . esc_html($booking['caldav_uid']) . '</td></tr>';
}

// Group booking information
if (get_option('td_bkg_group_enabled')) {
    echo '<tr><th>' . esc_html__('Group Size', 'td-booking') . '</th><td>' . intval($booking['group_size']) . '</td></tr>';
}

echo '</table>';

// WooCommerce integration
if ($booking && get_option('td_bkg_wc_enabled')) {
    $order_id = $wpdb->get_var($wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items oi JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id WHERE oim.meta_key = 'td_bkg_booking_id' AND oim.meta_value = %d LIMIT 1", $booking_id));
    if ($order_id) {
        $order_url = admin_url('post.php?post=' . intval($order_id) . '&action=edit');
        echo '<h2>' . esc_html__('WooCommerce Order', 'td-booking') . '</h2>';
    echo '<p><a href="' . esc_url($order_url) . '" class="button-primary">' . esc_html(sprintf(__('View Order #%d', 'td-booking'), $order_id)) . '</a></p>';
    }
}

// Admin cancel action
if (function_exists('td_bkg_can_manage') && td_bkg_can_manage() && $booking && $booking['status'] !== 'cancelled') {
    echo '<h2>' . esc_html__('Admin Actions', 'td-booking') . '</h2>';
    echo '<div id="td-bkg-cancel-box" class="postbox" style="max-width:720px;padding:16px;">';
    echo '<p>' . esc_html__('Cancel this booking. This will attempt to remove the CalDAV event and email a cancellation ICS to the customer.', 'td-booking') . '</p>';
    echo '<label for="td-bkg-cancel-reason">' . esc_html__('Reason (optional)', 'td-booking') . '</label><br/>';
    echo '<input type="text" id="td-bkg-cancel-reason" class="regular-text" placeholder="' . esc_attr__('Optional note for audit log', 'td-booking') . '" />';
    echo '<p><button id="td-bkg-cancel-btn" class="button button-secondary" data-booking-id="' . intval($booking_id) . '">' . esc_html__('Cancel Booking', 'td-booking') . '</button> ';
    echo '<span id="td-bkg-cancel-status" style="margin-left:10px;"></span></p>';
    echo '</div>';
    echo '<script>jQuery(function($){\n' .
        '$("#td-bkg-cancel-btn").on("click", function(){\n' .
        '  var btn = $(this);\n' .
        '  var id = btn.data("booking-id");\n' .
        '  var reason = $("#td-bkg-cancel-reason").val();\n' .
        '  if(!confirm(' . json_encode(__('Are you sure you want to cancel this booking?', 'td-booking')) . ')) return;\n' .
        '  btn.prop("disabled", true).text(' . json_encode(__('Cancelling...', 'td-booking')) . ');\n' .
        '  $("#td-bkg-cancel-status").text("");\n' .
        '  $.ajax({\n' .
        '    url: ' . json_encode(rest_url('td/v1/booking/')) . ' + id + "/cancel",\n' .
        '    method: "POST",\n' .
        '    beforeSend: function(xhr){ xhr.setRequestHeader("X-WP-Nonce", ' . json_encode(wp_create_nonce('wp_rest')) . '); },\n' .
        '    data: { reason: reason },\n' .
        '    success: function(resp){\n' .
        '      $("#td-bkg-cancel-status").css("color","#22863a").text(' . json_encode(__('Cancelled', 'td-booking')) . ');\n' .
        '      location.reload();\n' .
        '    },\n' .
        '    error: function(xhr){\n' .
        '      var code = xhr.status || 0;\n' .
        '      var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : ' . json_encode(__('Unknown error', 'td-booking')) . ';\n' .
        '      $("#td-bkg-cancel-status").css("color","#d63638").text(' . json_encode(__('Failed: ', 'td-booking')) . ' + msg + " (HTTP " + code + ")");\n' .
        '    },\n' .
        '    complete: function(){\n' .
        '      btn.prop("disabled", false).text(' . json_encode(__('Cancel Booking', 'td-booking')) . ');\n' .
        '    }\n' .
        '  });\n' .
        '});\n' .
    '});</script>';
}
