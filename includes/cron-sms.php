<?php
defined('ABSPATH') || exit;

// Cron job for sending SMS reminders
add_action('td_bkg_sms_reminder_cron', 'td_bkg_sms_reminder_cron_cb');

function td_bkg_sms_reminder_cron_cb() {
    global $wpdb;
    $table = $wpdb->prefix . 'td_booking';
    $now = current_time('mysql');
    $reminder_window = 60 * 60; // 1 hour before
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE status = %s AND sms_reminder_sent = 0 AND start_time > %s AND start_time < %s",
        'confirmed', $now, date('Y-m-d H:i:s', strtotime($now) + $reminder_window)
    ));
    foreach ($results as $booking) {
        $phone = get_user_meta($booking->user_id, 'phone', true);
        if ($phone) {
            $msg = sprintf(__('Reminder: Your booking #%d is at %s', 'td-booking'), $booking->id, $booking->start_time);
            if (td_bkg_sms_send($phone, $msg)) {
                $wpdb->update($table, ['sms_reminder_sent' => 1], ['id' => $booking->id]);
                td_bkg_log('sms', 'Sent reminder for booking #' . $booking->id . ' to ' . $phone);
            }
        }
    }
}

// Schedule cron if not already
if (!wp_next_scheduled('td_bkg_sms_reminder_cron')) {
    wp_schedule_event(time(), 'hourly', 'td_bkg_sms_reminder_cron');
}
