<?php
defined('ABSPATH') || exit;

// Minimal WebDAV/CalDAV client using cURL
function td_bkg_caldav_request($url, $method = 'GET', $body = '', $headers = [], $user = '', $pass = '') {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if ($user && $pass) curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");

    // Default headers
    $default_headers = [
        'User-Agent: TD-Booking/1.0',
        'Accept: */*',
        'Expect:' // disable 100-continue to avoid delays/incompatibilities
    ];
    $headers = array_merge($default_headers, $headers ?: []);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Timeout (configurable)
    $timeout = intval(get_option('td_bkg_caldav_timeout', 15));
    if ($timeout <= 0) $timeout = 15;
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

    // Follow redirects (some CalDAV servers redirect collection URLs)
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    // Optional insecure TLS for self-signed endpoints (beta/testing)
    $insecure = (bool) get_option('td_bkg_caldav_insecure', false);
    if ($insecure) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    curl_setopt($ch, CURLOPT_HEADER, true);
    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response ?: '', 0, $header_size ?: 0);
    $body = substr($response ?: '', $header_size ?: 0);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    // Parse ETag
    $etag = null;
    if ($header && preg_match('/ETag: ([^\r\n]+)/i', $header, $m)) {
        $etag = trim($m[1], '"');
    }
    return [ 'status' => intval($info['http_code'] ?? 0), 'body' => $body, 'etag' => $etag, 'error' => $err, 'redirect_url' => $info['url'] ?? $url ];
}

function td_bkg_caldav_put($url, $ics, $user, $pass) {
    $headers = [
        // Some CalDAV servers (e.g., SabreDAV/Nextcloud) require the component parameter
        // to match the calendar collection's supported components, otherwise they return 415.
        'Content-Type: text/calendar; charset=utf-8; component=VEVENT',
        'If-None-Match: *'
    ];
    $res = td_bkg_caldav_request($url, 'PUT', $ics, $headers, $user, $pass);
    if (intval($res['status']) === 415) {
        // Retry without component parameter for servers that reject it on event resources
        $fallbackHeaders = [
            'Content-Type: text/calendar; charset=utf-8',
            'If-None-Match: *'
        ];
        $res2 = td_bkg_caldav_request($url, 'PUT', $ics, $fallbackHeaders, $user, $pass);
        // Prefer successful fallback
        if (intval($res2['status']) >= 200 && intval($res2['status']) < 300) {
            return $res2;
        }
        $res = $res2; // continue evaluation on latest attempt
    }
    // If resource unexpectedly exists (412 Precondition Failed with If-None-Match: *),
    // retry without the precondition to allow overwrite. This can happen if previous attempts
    // succeeded but we didn't persist ETag fast enough, or if the server created a placeholder.
    if (intval($res['status']) === 412) {
        $retryHeaders = [
            'Content-Type: text/calendar; charset=utf-8'
        ];
        $res3 = td_bkg_caldav_request($url, 'PUT', $ics, $retryHeaders, $user, $pass);
        if (intval($res3['status']) >= 200 && intval($res3['status']) < 300) {
            return $res3;
        }
        return $res3; // return final attempt for diagnostics
    }
    return $res;
}

// Optional: lightweight PROPFIND to detect calendar properties and supported components
function td_bkg_caldav_propfind($collectionUrl, $user, $pass) {
    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        . '<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
        . '<d:prop>'
        . '<d:resourcetype/>'
        . '<cal:supported-calendar-component-set/>'
        . '</d:prop>'
        . '</d:propfind>';
    $headers = [
        'Depth: 0',
        'Content-Type: application/xml; charset=utf-8'
    ];
    return td_bkg_caldav_request($collectionUrl, 'PROPFIND', $xml, $headers, $user, $pass);
}

function td_bkg_caldav_delete($url, $user, $pass) {
    return td_bkg_caldav_request($url, 'DELETE', '', [], $user, $pass);
}

function td_bkg_caldav_report($url, $xml, $user, $pass) {
    $headers = [
        'Content-Type: application/xml; charset=utf-8',
        'Depth: 1'
    ];
    return td_bkg_caldav_request($url, 'REPORT', $xml, $headers, $user, $pass);
}
