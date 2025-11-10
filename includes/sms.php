<?php
defined('ABSPATH') || exit;

/**
 * Retrieve SMS API key, decrypting if stored as an envelope.
 */
function td_bkg_sms_get_api_key() {
    $stored = get_option('td_bkg_sms_api_key');
    if (!$stored) return '';
    if (function_exists('td_bkg_is_encrypted_envelope') && td_bkg_is_encrypted_envelope($stored)) {
        $pt = function_exists('td_bkg_decrypt') ? td_bkg_decrypt($stored, 'td-booking:sms') : null;
        return is_string($pt) ? $pt : '';
    }
    return $stored;
}

// SMS provider interface
function td_bkg_sms_send($to, $msg) {
    $provider = get_option('td_bkg_sms_provider', 'twilio');
    if ($provider === 'twilio') {
        return td_bkg_sms_send_twilio($to, $msg);
    }
    // Add more providers as needed
    return false;
}

function td_bkg_sms_send_twilio($to, $msg) {
    $api_key = td_bkg_sms_get_api_key();
    $sender = get_option('td_bkg_sms_sender');
    if (!$api_key || !$sender) return false;
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . urlencode($api_key) . '/Messages.json';
    $args = [
        'body' => [
            'From' => $sender,
            'To' => $to,
            'Body' => $msg
        ],
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($api_key . ':')
        ]
    ];
    $resp = wp_remote_post($url, $args);
    if (is_wp_error($resp)) return false;
    $code = wp_remote_retrieve_response_code($resp);
    return $code >= 200 && $code < 300;
}
