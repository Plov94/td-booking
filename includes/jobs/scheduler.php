<?php
defined('ABSPATH') || exit;

// Register WP-Cron events for reminders and other scheduled jobs.
add_action('td_bkg_hourly', 'td_bkg_send_reminders');
if (!wp_next_scheduled('td_bkg_hourly')) {
    wp_schedule_event(time(), 'hourly', 'td_bkg_hourly');
}

function td_bkg_send_reminders() {
    global $wpdb;
    $reminder_minutes = intval(get_option('td_bkg_reminder_minutes', 60));
    $now = current_time('timestamp', 1);
    $window_start = gmdate('Y-m-d H:i:s', $now + $reminder_minutes * 60);
    $window_end = gmdate('Y-m-d H:i:s', $now + ($reminder_minutes + 60) * 60);
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_booking WHERE status='confirmed' AND start_utc BETWEEN %s AND %s", $window_start, $window_end), ARRAY_A);
    foreach ($rows as $booking) {
        $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_service WHERE id=%d", $booking['service_id']), ARRAY_A);
        $ics = function_exists('td_bkg_caldav_ics') ? td_bkg_caldav_ics($booking, $service, 'REQUEST') : '';
        $token = function_exists('td_bkg_generate_token') ? td_bkg_generate_token($booking['id']) : '';
        $links = '<a href="' . esc_url(add_query_arg(['id'=>$booking['id'],'token'=>$token], rest_url('td/v1/booking/'.$booking['id'].'/cancel'))) . '">' . __('Cancel', 'td-booking') . '</a> | ';
        $links .= '<a href="' . esc_url(add_query_arg(['id'=>$booking['id'],'token'=>$token], rest_url('td/v1/booking/'.$booking['id'].'/reschedule'))) . '">' . __('Reschedule', 'td-booking') . '</a>';
        $msg = __('This is a reminder for your upcoming booking.', 'td-booking') . '<br>' . $links;
        if (function_exists('td_bkg_mailer')) {
            td_bkg_mailer($booking['customer_email'], __('Booking Reminder', 'td-booking'), $msg, $ics);
        }
        td_bkg_log_audit('info', 'reminder', 'Reminder sent', '', $booking['id'], $booking['staff_id']);
    }
}
