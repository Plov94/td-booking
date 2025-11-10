<?php
defined('ABSPATH') || exit;

function td_bkg_json_encode($data) {
    $json = json_encode($data);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }
    return $json;
}

function td_bkg_json_decode($json, $assoc = true) {
    $data = json_decode($json, $assoc);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }
    return $data;
}

function td_bkg_sanitize_text($text) {
    return sanitize_text_field($text);
}

function td_bkg_log_audit($level, $source, $msg, $context = '', $booking_id = null, $staff_id = null) {
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'td_audit', [
        'ts' => current_time('mysql', 1),
        'level' => $level,
        'source' => $source,
        'booking_id' => $booking_id,
        'staff_id' => $staff_id,
        'message' => $msg,
        'context' => is_array($context) ? td_bkg_json_encode($context) : $context,
    ]);
}

function td_bkg_generate_token($booking_id) {
    return hash_hmac('sha256', $booking_id, wp_salt('auth'));
}
function td_bkg_validate_token($booking_id, $token) {
    // Accept either the HMAC token (deterministic) or the stored per-booking ics_token
    if (hash_equals(td_bkg_generate_token($booking_id), (string)$token)) {
        return true;
    }
    // Check DB-stored ics_token for this booking
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT ics_token FROM {$wpdb->prefix}td_booking WHERE id=%d", $booking_id), ARRAY_A);
    if ($row && !empty($row['ics_token']) && hash_equals((string)$row['ics_token'], (string)$token)) {
        return true;
    }
    return false;
}

/**
 * Normalize CalDAV credentials array into canonical keys: url, user, pass
 * Accepts multiple potential key names from upstream integrations.
 * @param mixed $creds
 * @return array|null
 */
function td_bkg_normalize_caldav_creds($creds) {
    if (!is_array($creds)) return null;
    $norm = $creds;
    // Username
    if (empty($norm['user'])) {
        foreach (['username','nc_user','nc_username','login','email','user_name'] as $k) {
            if (!empty($norm[$k])) { $norm['user'] = $norm[$k]; break; }
        }
    }
    // Password / App password
    if (empty($norm['pass'])) {
        foreach (['password','nc_pass','nc_password','app_password','appPassword','nc_app_password'] as $k) {
            if (!empty($norm[$k])) { $norm['pass'] = $norm[$k]; break; }
        }
    }
    // Calendar URL parts: base_url + calendar_path support
    $base_url = null; $calendar_path = null;
    foreach (['calendar_url','base_url','caldav_url','nextcloud_url','dav_url','url'] as $k) {
        if (!$base_url && !empty($norm[$k]) && is_string($norm[$k])) { $base_url = trim($norm[$k]); }
    }
    foreach (['calendar_path','calendar','path'] as $k) {
        if (!$calendar_path && !empty($norm[$k]) && is_string($norm[$k])) { $calendar_path = trim($norm[$k]); }
    }
    // Helper: safely join base URL with a path, URL-encoding path segments
    $join_url = function($base, $path) {
        if (!$base) return null;
        if (!$path) return rtrim($base, '/');
        $base = rtrim($base, '/');
        $path = ltrim($path, '/');
        // Encode each path segment individually
        $segments = array_map('rawurlencode', array_filter(explode('/', $path), function($s){ return $s !== ''; }));
        return $base . '/' . implode('/', $segments);
    };
    // If explicit url missing, synthesize from base_url + calendar_path
    if (empty($norm['url'])) {
        $norm['url'] = $join_url($base_url, $calendar_path);
    } else {
        // If url seems to be a root (e.g., ends with /dav) and we have a calendar_path, append it
        $u = trim($norm['url']);
        $is_rootish = (bool) preg_match('#/(dav|dav/|dav\\/?|caldav|remote\.php/dav)/?$#i', $u);
        if ($is_rootish && $calendar_path) {
            $norm['url'] = $join_url($u, $calendar_path);
        }
    }
    // Trim spaces
    foreach (['user','pass','url'] as $k) {
        if (isset($norm[$k]) && is_string($norm[$k])) {
            $norm[$k] = trim($norm[$k]);
        }
    }
    // Basic validation
    if (empty($norm['url']) || empty($norm['user']) || empty($norm['pass'])) {
        return null;
    }
    return $norm;
}

/**
 * Heuristically detect if a CalDAV URL points to a root DAV endpoint rather than a specific calendar collection.
 */
function td_bkg_caldav_url_is_root($url) {
    if (!is_string($url) || $url === '') return true;
    $u = strtolower(trim($url));
    // Common root endpoints seen on Nextcloud/CalDAV servers
    $root_patterns = [
        '/remote.php/dav',
        '/dav',
        '/caldav',
    ];
    foreach ($root_patterns as $p) {
        if (substr($u, -strlen($p)) === $p || strpos($u, $p . '/') !== false) {
            // If it contains 'calendars/' afterwards, we consider it not root
            if (strpos($u, '/calendars/') !== false) return false;
            // If it contains '/calendar/' segment, also considered not root
            if (strpos($u, '/calendar/') !== false) return false;
            return true;
        }
    }
    return false;
}

/**
 * Set PII fields (email, phone, address_json, notes) on a booking row for insert/update.
 * Applies encryption if configured and HMAC indexing for email/phone.
 *
 * @param array $fields Existing DB fields map to be merged into
 * @param array $input  Keys: email, phone, address (array or JSON), notes
 * @return array Fields including encrypted/plain values and hash columns
 */
function td_bkg_booking_apply_pii(array $fields, array $input) {
    $email = isset($input['email']) ? (string) $input['email'] : '';
    $phone = isset($input['phone']) ? (string) $input['phone'] : '';
    $address_json = isset($input['address']) ? (is_string($input['address']) ? $input['address'] : td_bkg_json_encode($input['address'])) : '';
    $notes = isset($input['notes']) ? (string) $input['notes'] : '';

    $use_crypto = function_exists('td_bkg_crypto_available') && td_bkg_crypto_available();
    if ($use_crypto && function_exists('td_bkg_encrypt')) {
        if ($email !== '') { $fields['customer_email'] = td_bkg_encrypt($email, 'td-booking:email') ?: $email; }
        if ($phone !== '') { $fields['customer_phone'] = td_bkg_encrypt($phone, 'td-booking:phone') ?: $phone; }
        if ($address_json !== '') { $fields['customer_address_json'] = td_bkg_encrypt($address_json, 'td-booking:address') ?: $address_json; }
        if ($notes !== '') { $fields['notes'] = td_bkg_encrypt($notes, 'td-booking:notes') ?: $notes; }
    } else {
        if ($email !== '') { $fields['customer_email'] = $email; }
        if ($phone !== '') { $fields['customer_phone'] = $phone; }
        if ($address_json !== '') { $fields['customer_address_json'] = $address_json; }
        if ($notes !== '') { $fields['notes'] = $notes; }
    }

    // HMAC deterministic hashes for exact search (only if key available and columns exist)
    if (function_exists('td_bkg_hmac_index') && td_bkg_booking_table_has_hash_columns()) {
        $fields['email_hash'] = ($email !== '') ? td_bkg_hmac_index(strtolower($email)) : null;
        $fields['phone_hash'] = ($phone !== '') ? td_bkg_hmac_index(preg_replace('/\s+/', '', $phone)) : null;
    }
    return $fields;
}

/**
 * Check if booking table has email_hash/phone_hash columns. Cached for a short period.
 */
function td_bkg_booking_table_has_hash_columns() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $flag = get_transient('td_bkg_has_hash_cols');
    if ($flag !== false) { $cached = (bool)$flag; return $cached; }
    global $wpdb;
    $table = $wpdb->prefix . 'td_booking';
    $email = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'email_hash'");
    $phone = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'phone_hash'");
    $ok = !empty($email) && !empty($phone);
    set_transient('td_bkg_has_hash_cols', $ok ? 1 : 0, 10 * MINUTE_IN_SECONDS);
    $cached = $ok;
    return $ok;
}

/**
 * Decrypt PII fields on a booking row in-place (non-destructive: returns a copy).
 * @param array $row
 * @return array row with decrypted customer_email/customer_phone/customer_address_json/notes when applicable
 */
function td_bkg_booking_decrypt_row(array $row) {
    if (!function_exists('td_bkg_is_encrypted_envelope') || !function_exists('td_bkg_decrypt')) return $row;
    foreach (['customer_email' => 'td-booking:email', 'customer_phone' => 'td-booking:phone', 'customer_address_json' => 'td-booking:address', 'notes' => 'td-booking:notes'] as $key => $aad) {
        if (!empty($row[$key]) && td_bkg_is_encrypted_envelope($row[$key])) {
            $pt = td_bkg_decrypt($row[$key], $aad);
            if (is_string($pt)) { $row[$key] = $pt; }
        }
    }
    return $row;
}

/**
 * Build public reschedule URL used in emails. By default serves the plugin asset under wp-content/plugins/td-booking/assets/reschedule.html
 * Can be overridden via option 'td_bkg_reschedule_page_url'.
 */
function td_bkg_public_reschedule_url($booking_id, $token) {
    $override = get_option('td_bkg_reschedule_page_url');
    if ($override) {
        return add_query_arg([
            'id' => $booking_id,
            'token' => $token,
            'rest' => rtrim(rest_url(''), '/'),
            'src' => 'email',
        ], $override);
    }
    // Derive plugin URL to assets/reschedule.html
    if (defined('TD_BKG_URL')) {
        $base = rtrim(TD_BKG_URL, '/');
    } else {
        $base = plugins_url('td-booking');
    }
    $url = $base . '/assets/reschedule.html';
    return add_query_arg([
        'id' => $booking_id,
        'token' => $token,
        'rest' => rtrim(rest_url(''), '/'),
        'src' => 'email',
    ], $url);
}

/** Build public cancel URL for friendly landing page instead of raw REST link. */
function td_bkg_public_cancel_url($booking_id, $token) {
    $override = get_option('td_bkg_cancel_page_url');
    if ($override) {
        return add_query_arg([
            'id' => $booking_id,
            'token' => $token,
            'rest' => rtrim(rest_url(''), '/'),
            'src' => 'email',
        ], $override);
    }
    if (defined('TD_BKG_URL')) {
        $base = rtrim(TD_BKG_URL, '/');
    } else {
        $base = plugins_url('td-booking');
    }
    $url = $base . '/assets/cancel.html';
    return add_query_arg([
        'id' => $booking_id,
        'token' => $token,
        'rest' => rtrim(rest_url(''), '/'),
        'src' => 'email',
    ], $url);
}

/**
 * Get global hours for a specific day
 * @param int $day_of_week 0=Sunday, 1=Monday, etc.
 * @return array Array of time ranges [['start' => '09:00', 'end' => '17:00'], ...]
 */
function td_bkg_get_global_hours_for_day($day_of_week) {
    $global_hours = get_option('td_bkg_global_hours', []);
    return isset($global_hours[$day_of_week]) ? $global_hours[$day_of_week] : [];
}

/**
 * Format global hours for display
 * @param int $day_of_week 0=Sunday, 1=Monday, etc.
 * @return string Formatted hours string like "9:00 AM - 5:00 PM, 7:00 PM - 9:00 PM" or "Closed"
 */
function td_bkg_format_global_hours_for_day($day_of_week) {
    $hours = td_bkg_get_global_hours_for_day($day_of_week);
    
    if (empty($hours)) {
        return __('Closed', 'td-booking');
    }
    
    $formatted_ranges = [];
    foreach ($hours as $range) {
        if (isset($range['start']) && isset($range['end'])) {
            $start_formatted = date('g:i A', strtotime($range['start']));
            $end_formatted = date('g:i A', strtotime($range['end']));
            $formatted_ranges[] = $start_formatted . ' - ' . $end_formatted;
        }
    }
    
    return implode(', ', $formatted_ranges);
}

/**
 * Convert UTC datetime to local date
 * @param string $utc_datetime UTC datetime string
 * @return string Local date in Y-m-d format
 */
function td_bkg_utc_to_local_date($utc_datetime) {
    $timezone = wp_timezone();
    $date = new DateTime($utc_datetime, new DateTimeZone('UTC'));
    $date->setTimezone($timezone);
    return $date->format('Y-m-d');
}

/**
 * Convert UTC datetime to local time
 * @param string $utc_datetime UTC datetime string
 * @return string Local time in g:i A format
 */
function td_bkg_utc_to_local_time($utc_datetime) {
    $timezone = wp_timezone();
    $date = new DateTime($utc_datetime, new DateTimeZone('UTC'));
    $date->setTimezone($timezone);
    return $date->format('g:i A');
}

/**
 * Get day of week from UTC datetime (0=Sunday, 1=Monday, etc.)
 * @param string $utc_datetime UTC datetime string
 * @return int Day of week
 */
function td_bkg_get_day_of_week_from_utc($utc_datetime) {
    $timezone = wp_timezone();
    $date = new DateTime($utc_datetime, new DateTimeZone('UTC'));
    $date->setTimezone($timezone);
    return (int) $date->format('w');
}
