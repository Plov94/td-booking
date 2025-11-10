<?php
defined('ABSPATH') || exit;

// Retry failed_sync bookings with backoff
function td_bkg_retry_failed_bookings() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}td_booking WHERE status='failed_sync'", ARRAY_A);
    foreach ($rows as $booking) {
        $attempts = intval(get_post_meta($booking['id'], '_td_bkg_retry_attempts', true));
        $wait = min(pow(2, $attempts) * 60, 3600); // exponential backoff, max 1h
        $last = intval(get_post_meta($booking['id'], '_td_bkg_retry_last', true));
        if (time() - $last < $wait) continue;
        // Try CalDAV PUT again
        $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_service WHERE id=%d", $booking['service_id']), ARRAY_A);
        if (function_exists('td_tech') && method_exists(td_tech(), 'caldav')) {
            $creds = td_tech()->caldav()->get_credentials($booking['staff_id']);
            if (function_exists('td_bkg_normalize_caldav_creds')) { $creds = td_bkg_normalize_caldav_creds($creds); }
            if ($creds && !empty($creds['url']) && !empty($creds['user']) && !empty($creds['pass'])) {
                $ics = function_exists('td_bkg_caldav_ics') ? td_bkg_caldav_ics($booking, $service, 'REQUEST') : '';
                $event_url = rtrim($creds['url'], '/') . '/' . $booking['caldav_uid'] . '.ics';
                $res = td_bkg_caldav_put($event_url, $ics, $creds['user'], $creds['pass']);
                if ($res['status'] >= 200 && $res['status'] < 300) {
                    $wpdb->update($wpdb->prefix . 'td_booking', [
                        'status' => 'confirmed',
                        'caldav_etag' => $res['etag'] ?? ''
                    ], ['id' => $booking['id']]);
                    continue;
                }
            }
        }
        update_post_meta($booking['id'], '_td_bkg_retry_attempts', $attempts + 1);
        update_post_meta($booking['id'], '_td_bkg_retry_last', time());
        $wpdb->insert($wpdb->prefix . 'td_audit', [
            'ts' => current_time('mysql', 1),
            'level' => 'error',
            'source' => 'retry',
            'booking_id' => $booking['id'],
            'staff_id' => $booking['staff_id'],
            'message' => 'Retry failed',
            'context' => 'CalDAV retry failed',
        ]);
    }
}
