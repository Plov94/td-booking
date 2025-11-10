<?php
defined('ABSPATH') || exit;

// Reconcile Nextcloud events with WP bookings
function td_bkg_reconcile_bookings($from, $to) {
    global $wpdb;
    
    td_bkg_log_audit('info', 'reconcile', 'Starting reconciliation', json_encode(['from' => $from, 'to' => $to]));
    
    $staff_ids = $wpdb->get_col("SELECT DISTINCT staff_id FROM {$wpdb->prefix}td_service_staff");
    $total_processed = 0;
    $total_conflicts = 0;
    $total_updates = 0;
    
    foreach ($staff_ids as $staff_id) {
        if (!function_exists('td_tech') || !method_exists(td_tech(), 'caldav')) continue;
        
        $creds = td_tech()->caldav()->get_credentials($staff_id);
        if (function_exists('td_bkg_normalize_caldav_creds')) { $creds = td_bkg_normalize_caldav_creds($creds); }
        if (!$creds || empty($creds['url']) || empty($creds['user']) || empty($creds['pass'])) {
            td_bkg_log_audit('warn', 'reconcile', 'No CalDAV credentials for staff', '', null, $staff_id);
            continue;
        }
        
        $calendar_url = rtrim($creds['url'], '/');
        $xml = td_bkg_build_caldav_query($from, $to);
        $res = td_bkg_caldav_report($calendar_url, $xml, $creds['user'], $creds['pass']);
        
        if ($res['status'] >= 200 && $res['status'] < 300) {
            $events = td_bkg_parse_caldav_events($res['body']);
            $staff_results = td_bkg_reconcile_staff_events($staff_id, $events, $from, $to);
            
            $total_processed += $staff_results['processed'];
            $total_conflicts += $staff_results['conflicts'];
            $total_updates += $staff_results['updates'];
            
            td_bkg_log_audit('info', 'reconcile', 'Staff reconciliation completed', json_encode($staff_results), null, $staff_id);
        } else {
            td_bkg_log_audit('error', 'reconcile', 'CalDAV reconcile failed', $res['error'] ?: ('HTTP ' . $res['status']), null, $staff_id);
        }
    }
    
    // Invalidate availability cache for the reconciled period
    td_bkg_invalidate_availability_cache_range($from, $to);
    
    td_bkg_log_audit('info', 'reconcile', 'Reconciliation completed', json_encode([
        'processed' => $total_processed,
        'conflicts' => $total_conflicts,
        'updates' => $total_updates
    ]));
    
    return [
        'processed' => $total_processed,
        'conflicts' => $total_conflicts,
        'updates' => $total_updates
    ];
}

// Build CalDAV REPORT query XML
function td_bkg_build_caldav_query($from, $to) {
    $start = gmdate('Ymd\THis\Z', strtotime($from));
    $end = gmdate('Ymd\THis\Z', strtotime($to));
    
    return '<?xml version="1.0"?>
<c:calendar-query xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:">
    <d:prop>
        <d:getetag/>
        <c:calendar-data/>
    </d:prop>
    <c:filter>
        <c:comp-filter name="VCALENDAR">
            <c:comp-filter name="VEVENT">
                <c:time-range start="' . $start . '" end="' . $end . '"/>
            </c:comp-filter>
        </c:comp-filter>
    </c:filter>
</c:calendar-query>';
}

// Parse CalDAV response and extract events
function td_bkg_parse_caldav_events($xml_body) {
    $events = [];
    
    if (empty($xml_body)) {
        return $events;
    }
    
    // Load XML and handle errors
    libxml_use_internal_errors(true);
    $xml = new DOMDocument();
    if (!$xml->loadXML($xml_body)) {
        td_bkg_log_audit('error', 'reconcile', 'Failed to parse CalDAV XML', implode('; ', libxml_get_errors()));
        return $events;
    }
    
    $xpath = new DOMXPath($xml);
    $xpath->registerNamespace('d', 'DAV:');
    $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
    
    $responses = $xpath->query('//d:response');
    
    foreach ($responses as $response) {
        $href = $xpath->query('.//d:href', $response)->item(0);
        $etag = $xpath->query('.//d:getetag', $response)->item(0);
        $calendar_data = $xpath->query('.//c:calendar-data', $response)->item(0);
        
        if ($href && $calendar_data) {
            $event_data = td_bkg_parse_ics_event($calendar_data->textContent);
            if ($event_data) {
                $event_data['href'] = $href->textContent;
                $event_data['etag'] = $etag ? trim($etag->textContent, '"') : '';
                $events[] = $event_data;
            }
        }
    }
    
    return $events;
}

// Parse individual ICS event from calendar data
function td_bkg_parse_ics_event($ics_content) {
    if (empty($ics_content)) {
        return null;
    }
    
    $lines = explode("\n", str_replace("\r\n", "\n", $ics_content));
    $event = [];
    $in_event = false;
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        if ($line === 'BEGIN:VEVENT') {
            $in_event = true;
            continue;
        }
        
        if ($line === 'END:VEVENT') {
            break;
        }
        
        if (!$in_event || empty($line)) {
            continue;
        }
        
        // Handle line folding (lines starting with space or tab)
        if (preg_match('/^[ \t](.+)$/', $line, $matches) && !empty($prev_property)) {
            $event[$prev_property] .= $matches[1];
            continue;
        }
        
        if (preg_match('/^([^:;]+)(?:;[^:]*)?:(.*)$/', $line, $matches)) {
            $prop = $matches[1];
            $value = $matches[2];
            $prev_property = $prop;
            
            switch ($prop) {
                case 'UID':
                    $event['uid'] = $value;
                    break;
                case 'DTSTART':
                    $event['start_utc'] = td_bkg_parse_ics_datetime($value);
                    break;
                case 'DTEND':
                    $event['end_utc'] = td_bkg_parse_ics_datetime($value);
                    break;
                case 'SUMMARY':
                    $event['summary'] = $value;
                    break;
                case 'DESCRIPTION':
                    $event['description'] = $value;
                    break;
                case 'STATUS':
                    $event['status'] = strtoupper($value);
                    break;
                case 'SEQUENCE':
                    $event['sequence'] = intval($value);
                    break;
            }
        }
    }
    
    // Validate required fields
    if (empty($event['uid']) || empty($event['start_utc']) || empty($event['end_utc'])) {
        return null;
    }
    
    return $event;
}

// Parse ICS datetime to MySQL format
function td_bkg_parse_ics_datetime($ics_datetime) {
    // Handle both DTSTART:20250909T140000Z and DTSTART;VALUE=DATE:20250909 formats
    $datetime = preg_replace('/^[^:]*:/', '', $ics_datetime);
    
    if (preg_match('/^(\d{8})T(\d{6})Z?$/', $datetime, $matches)) {
        // YYYYMMDDTHHMMSS format
        $date_part = $matches[1];
        $time_part = $matches[2];
        
        $year = substr($date_part, 0, 4);
        $month = substr($date_part, 4, 2);
        $day = substr($date_part, 6, 2);
        
        $hour = substr($time_part, 0, 2);
        $minute = substr($time_part, 2, 2);
        $second = substr($time_part, 4, 2);
        
        return "$year-$month-$day $hour:$minute:$second";
    }
    
    if (preg_match('/^(\d{8})$/', $datetime, $matches)) {
        // YYYYMMDD format (all-day event)
        $date_part = $matches[1];
        $year = substr($date_part, 0, 4);
        $month = substr($date_part, 4, 2);
        $day = substr($date_part, 6, 2);
        
        return "$year-$month-$day 00:00:00";
    }
    
    return null;
}

// Reconcile events for a specific staff member
function td_bkg_reconcile_staff_events($staff_id, $events, $from, $to) {
    global $wpdb;
    
    $results = [
        'processed' => 0,
        'conflicts' => 0,
        'updates' => 0,
        'cancelled' => 0
    ];
    
    // Get existing bookings for this staff in the time range
    $existing_bookings = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}td_booking 
         WHERE staff_id = %d 
         AND ((start_utc BETWEEN %s AND %s) OR (end_utc BETWEEN %s AND %s))
         AND status IN ('confirmed', 'pending', 'conflicted')",
        $staff_id, $from, $to, $from, $to
    ), ARRAY_A);
    
    $booking_by_uid = [];
    foreach ($existing_bookings as $booking) {
        if (!empty($booking['caldav_uid'])) {
            $booking_by_uid[$booking['caldav_uid']] = $booking;
        }
    }
    
    // Process each CalDAV event
    foreach ($events as $event) {
        $results['processed']++;
        
        // Skip non-TD Booking events (UIDs not starting with 'tdbkg-')
        if (strpos($event['uid'], 'tdbkg-') !== 0) {
            continue;
        }
        
        $booking = $booking_by_uid[$event['uid']] ?? null;
        
        if (!$booking) {
            // Event exists in CalDAV but not in our database - potential conflict
            td_bkg_log_audit('warn', 'reconcile', 'Orphaned CalDAV event found', json_encode($event), null, $staff_id);
            continue;
        }
        
        // Check if event was cancelled
        if (isset($event['status']) && $event['status'] === 'CANCELLED') {
            if ($booking['status'] !== 'cancelled') {
                $wpdb->update(
                    $wpdb->prefix . 'td_booking',
                    ['status' => 'cancelled', 'updated_at' => current_time('mysql', 1)],
                    ['id' => $booking['id']]
                );
                
                td_bkg_log_audit('info', 'reconcile', 'Booking cancelled due to external CalDAV cancellation', '', $booking['id'], $staff_id);
                $results['cancelled']++;
            }
            continue;
        }
        
        // Check if event was moved (different start/end times)
        $event_moved = (
            $booking['start_utc'] !== $event['start_utc'] ||
            $booking['end_utc'] !== $event['end_utc']
        );
        
        if ($event_moved) {
            // Check for conflicts with other bookings at the new time
            $conflicts = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}td_booking 
                 WHERE staff_id = %d 
                 AND id != %d
                 AND status IN ('confirmed', 'pending')
                 AND ((start_utc < %s AND end_utc > %s) OR (start_utc < %s AND end_utc > %s))",
                $staff_id, $booking['id'], 
                $event['end_utc'], $event['start_utc'],
                $event['end_utc'], $event['start_utc']
            ));
            
            if ($conflicts > 0) {
                // Mark as conflicted
                $wpdb->update(
                    $wpdb->prefix . 'td_booking',
                    ['status' => 'conflicted', 'updated_at' => current_time('mysql', 1)],
                    ['id' => $booking['id']]
                );
                
                td_bkg_log_audit('warn', 'reconcile', 'Booking marked as conflicted due to external move', json_encode([
                    'old_start' => $booking['start_utc'],
                    'old_end' => $booking['end_utc'],
                    'new_start' => $event['start_utc'],
                    'new_end' => $event['end_utc']
                ]), $booking['id'], $staff_id);
                
                $results['conflicts']++;
            } else {
                // Update booking with new times
                $wpdb->update(
                    $wpdb->prefix . 'td_booking',
                    [
                        'start_utc' => $event['start_utc'],
                        'end_utc' => $event['end_utc'],
                        'updated_at' => current_time('mysql', 1)
                    ],
                    ['id' => $booking['id']]
                );
                
                td_bkg_log_audit('info', 'reconcile', 'Booking updated due to external move', json_encode([
                    'old_start' => $booking['start_utc'],
                    'old_end' => $booking['end_utc'],
                    'new_start' => $event['start_utc'],
                    'new_end' => $event['end_utc']
                ]), $booking['id'], $staff_id);
                
                $results['updates']++;
            }
        }
        
        // Update ETag if different
        if (!empty($event['etag']) && $booking['caldav_etag'] !== $event['etag']) {
            $wpdb->update(
                $wpdb->prefix . 'td_booking',
                ['caldav_etag' => $event['etag']],
                ['id' => $booking['id']]
            );
        }
    }
    
    // Check for bookings that exist in WP but not in CalDAV (externally deleted)
    foreach ($existing_bookings as $booking) {
        if (empty($booking['caldav_uid'])) {
            continue;
        }
        
        $found_in_caldav = false;
        foreach ($events as $event) {
            if ($event['uid'] === $booking['caldav_uid']) {
                $found_in_caldav = true;
                break;
            }
        }
        
        if (!$found_in_caldav && $booking['status'] !== 'cancelled') {
            // Event was deleted externally, cancel in WP
            $wpdb->update(
                $wpdb->prefix . 'td_booking',
                ['status' => 'cancelled', 'updated_at' => current_time('mysql', 1)],
                ['id' => $booking['id']]
            );
            
            td_bkg_log_audit('info', 'reconcile', 'Booking cancelled due to external CalDAV deletion', '', $booking['id'], $staff_id);
            $results['cancelled']++;
        }
    }
    
    return $results;
}

// Invalidate availability cache for a date range
function td_bkg_invalidate_availability_cache_range($from, $to) {
    global $wpdb;
    
    // Delete transients for the affected date range
    $from_date = date('Y-m-d', strtotime($from));
    $to_date = date('Y-m-d', strtotime($to));
    
    $transient_keys = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} 
         WHERE option_name LIKE %s 
         AND option_name LIKE %s",
        '_transient_td_bkg_avail_%',
        '%' . str_replace('-', '', $from_date) . '%'
    ));
    
    foreach ($transient_keys as $key) {
        $transient_name = str_replace('_transient_', '', $key);
        delete_transient($transient_name);
    }
    
    td_bkg_log_audit('info', 'reconcile', 'Availability cache invalidated', json_encode(['from' => $from, 'to' => $to]));
}

// Schedule reconcile job
function td_bkg_schedule_reconcile() {
    if (!wp_next_scheduled('td_bkg_reconcile')) {
        wp_schedule_event(time(), 'hourly', 'td_bkg_reconcile');
    }
}
add_action('init', 'td_bkg_schedule_reconcile');

// Run reconcile job
add_action('td_bkg_reconcile', function() {
    $from = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
    $to = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
    td_bkg_reconcile_bookings($from, $to);
});

// Manual reconcile trigger for admin
function td_bkg_trigger_reconcile($from = null, $to = null) {
    if (!$from) {
        $from = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
    }
    if (!$to) {
        $to = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
    }
    
    return td_bkg_reconcile_bookings($from, $to);
}
