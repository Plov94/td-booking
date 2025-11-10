<?php
defined('ABSPATH') || exit;

function td_bkg_ics($args, $booking_data = []) {
    // Example usage:
    // $ics = td_bkg_ics(['uid' => '...', 'summary' => '...', ...]);
    $uid = $args['uid'] ?? uniqid('tdbkg');
    $dtstart = $args['dtstart'] ?? '';
    $dtend = $args['dtend'] ?? '';
    $summary = $args['summary'] ?? '';
    $desc = $args['description'] ?? '';
    $location = $args['location'] ?? '';
    $method = $args['method'] ?? 'REQUEST';
    
    $ics_content = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//TD Booking//EN\nMETHOD:$method\nBEGIN:VEVENT\nUID:$uid\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\nDTSTART:$dtstart\nDTEND:$dtend\nSUMMARY:$summary\nDESCRIPTION:$desc\nLOCATION:$location\nEND:VEVENT\nEND:VCALENDAR\n";
    
    // Apply ICS content filter
    if (function_exists('td_bkg_filter_ics_content')) {
        $ics_content = td_bkg_filter_ics_content($ics_content, $booking_data, $method);
    }
    
    return $ics_content;
}
