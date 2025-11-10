<?php
defined('ABSPATH') || exit;

function td_bkg_caldav_uid($booking_id) {
    // Use a filename-safe UID for both ICS UID and resource name. Avoid characters like '@' that can break PUT URLs.
    // Keep it stable and simple: 'tdbkg-<booking_id>'
    return 'tdbkg-' . $booking_id;
}

/**
 * Convert an ICS UID into a safe CalDAV resource basename.
 * Restrict to [A-Za-z0-9._-] and replace anything else with underscore.
 */
function td_bkg_caldav_resource_name($uid) {
    $uid = (string) $uid;
    if ($uid === '') return '';
    return preg_replace('/[^A-Za-z0-9._-]/', '_', $uid);
}

function td_bkg_caldav_ics($booking, $service, $method = 'REQUEST') {
    $uid = $booking['caldav_uid'] ?? td_bkg_caldav_uid($booking['id'] ?? uniqid());
    // Use literal 'Z' to denote UTC (RFC5545). Previous format used 'Z' (offset seconds) erroneously.
    $dtstart = gmdate('Ymd\THis\Z', strtotime($booking['start_utc']));
    $dtend = gmdate('Ymd\THis\Z', strtotime($booking['end_utc']));
    $summary = $service['name'] . ' – ' . $booking['customer_name'];
    // Build a helpful DESCRIPTION so staff can see customer notes quickly
    $desc = '';
    if (!empty($booking['notes'])) {
        $desc .= 'Customer note: ' . trim((string)$booking['notes']);
    }
    if (!empty($booking['customer_address_json'])) {
        $addrStr = '';
        $raw = $booking['customer_address_json'];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                // Render common address keys if present
                $parts = [];
                foreach (['address','street','line1','line2','postal','zip','city','state','country'] as $k) {
                    if (!empty($decoded[$k])) { $parts[] = $decoded[$k]; }
                }
                if (!empty($parts)) { $addrStr = implode(', ', $parts); }
            }
        }
        if ($addrStr === '') { $addrStr = (string)$raw; }
        $desc .= ($desc !== '' ? "\n" : '') . 'Address: ' . $addrStr;
    }
    $location = '';
    if (!empty($service['location_json'])) {
        $location = is_string($service['location_json']) ? $service['location_json'] : json_encode($service['location_json']);
    }
    // RFC5545 requires CRLF (\r\n) line endings. Some servers are strict.
    $eol = "\r\n";
    $lines = [];
    $lines[] = 'BEGIN:VCALENDAR';
    $lines[] = 'VERSION:2.0';
    $lines[] = 'PRODID:-//TD Booking//EN';
    $lines[] = 'CALSCALE:GREGORIAN';
    // Only include METHOD for iTIP flows (emails). For CalDAV storage, we omit METHOD.
    $includeMethod = in_array($method, ['REQUEST','CANCEL','PUBLISH'], true);
    if ($includeMethod) {
        $lines[] = 'METHOD:' . $method;
    }
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:' . $uid;
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    $lines[] = 'DTSTART:' . $dtstart;
    $lines[] = 'DTEND:' . $dtend;
    // Escape commas and backslashes in SUMMARY/DESCRIPTION/LOCATION per RFC
    $sumEsc = strtr($summary, ["\\" => "\\\\", "," => "\\,", ";" => "\\;", "\n" => "\\n"]);
    $descEsc = strtr($desc, ["\\" => "\\\\", "," => "\\,", ";" => "\\;", "\n" => "\\n"]);
    $locEsc = strtr($location, ["\\" => "\\\\", "," => "\\,", ";" => "\\;", "\n" => "\\n"]);
    $lines[] = 'SUMMARY:' . $sumEsc;
    if ($descEsc !== '') $lines[] = 'DESCRIPTION:' . $descEsc;
    if ($locEsc !== '') $lines[] = 'LOCATION:' . $locEsc;
    // Simple display alarm
    $lines[] = 'BEGIN:VALARM';
    $lines[] = 'TRIGGER:-PT15M';
    $lines[] = 'ACTION:DISPLAY';
    $lines[] = 'DESCRIPTION:Reminder';
    $lines[] = 'END:VALARM';
    if ($method === 'CANCEL') {
        $lines[] = 'STATUS:CANCELLED';
        $lines[] = 'SEQUENCE:1';
    }
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';
    return implode($eol, $lines) . $eol;
}
