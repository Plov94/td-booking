<?php
defined('ABSPATH') || exit;

add_action('rest_api_init', function() {
    register_rest_route('td/v1', '/admin/test-connection', [
        'methods' => 'POST',
        'callback' => 'td_bkg_rest_admin_test_connection',
        'permission_callback' => 'td_bkg_can_manage',
    ]);
    register_rest_route('td/v1', '/admin/reconcile', [
        'methods' => 'POST',
        'callback' => 'td_bkg_rest_admin_reconcile',
        'permission_callback' => 'td_bkg_can_manage',
    ]);
    register_rest_route('td/v1', '/admin/retry-failed', [
        'methods' => 'POST',
        'callback' => 'td_bkg_rest_admin_retry_failed',
        'permission_callback' => 'td_bkg_can_manage',
    ]);
    register_rest_route('td/v1', '/admin/test-breaks', [
        'methods' => 'POST',
        'callback' => 'td_bkg_rest_admin_test_breaks',
        'permission_callback' => 'td_bkg_can_manage',
    ]);
    register_rest_route('td/v1', '/admin/debug-slot', [
        'methods' => 'POST',
        'callback' => 'td_bkg_rest_admin_debug_slot',
        'permission_callback' => 'td_bkg_can_manage',
    ]);
    register_rest_route('td/v1', '/admin/staff-hours', [
        'methods' => 'GET',
        'callback' => 'td_bkg_rest_admin_staff_hours',
        'permission_callback' => 'td_bkg_can_manage',
    ]);
    register_rest_route('td/v1', '/admin/tech-windows', [
        'methods' => 'GET',
        'callback' => 'td_bkg_rest_admin_tech_windows',
        'permission_callback' => 'td_bkg_can_manage',
    ]);
    register_rest_route('td/v1', '/admin/caldav-diagnostics', [
        'methods' => 'POST',
        'callback' => 'td_bkg_rest_admin_caldav_diagnostics',
        'permission_callback' => 'td_bkg_can_manage',
    ]);
    register_rest_route('td/v1', '/admin/booking-links', [
        'methods' => 'GET',
        'callback' => 'td_bkg_rest_admin_booking_links',
        'permission_callback' => 'td_bkg_can_manage',
    ]);
    register_rest_route('td/v1', '/admin/pii-backfill', [
        'methods' => 'POST',
        'callback' => 'td_bkg_rest_admin_pii_backfill',
        'permission_callback' => 'td_bkg_can_manage',
    ]);
    register_rest_route('td/v1', '/admin/test-mail', [
        'methods' => 'POST',
        'callback' => 'td_bkg_rest_admin_test_mail',
        'permission_callback' => 'td_bkg_can_manage',
    ]);
});

function td_bkg_rest_admin_test_connection($request) {
    $staff_id = intval($request['staff_id'] ?? 0);
    $staff_name = isset($request['staff']) ? sanitize_text_field($request['staff']) : '';
    if (!$staff_id && $staff_name && function_exists('td_bkg_find_staff_id_by_name')) {
        $staff_id = td_bkg_find_staff_id_by_name($staff_name);
    }
    if (!$staff_id || !function_exists('td_tech') || !method_exists(td_tech(), 'caldav')) {
        return new WP_Error('invalid', __('Missing staff or CalDAV', 'td-booking'), ['status' => 400]);
    }
    // Stored-credential fallback: if request omits fields, use saved values
    $incoming = [
        'url' => sanitize_text_field($request['base_url'] ?? $request['url'] ?? ''),
        'user' => sanitize_text_field($request['username'] ?? $request['user'] ?? ''),
        'pass' => sanitize_text_field($request['app_password'] ?? $request['pass'] ?? ''),
        'calendar_path' => sanitize_text_field($request['calendar_path'] ?? ''),
    ];
    $stored = td_tech()->caldav()->get_credentials($staff_id);
    $merged = array_merge((array)$stored, array_filter($incoming));
    if (function_exists('td_bkg_normalize_caldav_creds')) {
        $merged = td_bkg_normalize_caldav_creds($merged);
    }
    $missing = [];
    if (!$merged || empty($merged['url'])) $missing[] = 'url/base_url';
    // If only a base URL is provided and it looks root-ish, suggest calendar_path
    if (!empty($incoming['url']) && function_exists('td_bkg_caldav_url_is_root') && td_bkg_caldav_url_is_root($incoming['url']) && empty($incoming['calendar_path'])) {
        $missing[] = 'calendar_path';
    }
    if (!$merged || empty($merged['user'])) $missing[] = 'username';
    if (!$merged || empty($merged['pass'])) $missing[] = 'app_password';
    if (!empty($missing)) {
        return new WP_Error('invalid', sprintf(__('Missing CalDAV fields: %s', 'td-booking'), implode(', ', $missing)), ['status' => 400, 'missing' => $missing]);
    }
    $res = td_bkg_caldav_request($merged['url'], 'OPTIONS', '', [], $merged['user'], $merged['pass']);
    if ($res['status'] >= 200 && $res['status'] < 300) {
        return rest_ensure_response(['ok' => true, 'message' => __('Connection OK', 'td-booking')]);
    }
    return new WP_Error('caldav', __('CalDAV connection failed', 'td-booking'), ['status' => $res['status'], 'error' => $res['error']]);
}

function td_bkg_rest_admin_booking_links($request) {
    $booking_id = intval($request['booking_id'] ?? 0);
    if (!$booking_id) {
        return new WP_Error('invalid', __('booking_id is required', 'td-booking'), ['status' => 400]);
    }
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT id, ics_token FROM {$wpdb->prefix}td_booking WHERE id=%d", $booking_id), ARRAY_A);
    if (!$booking) return new WP_Error('not_found', __('Booking not found', 'td-booking'), ['status' => 404]);
    $token = $booking['ics_token'] ?: td_bkg_generate_token($booking_id);
    $cancel_url = td_bkg_public_cancel_url($booking_id, $token);
    $reschedule_url = td_bkg_public_reschedule_url($booking_id, $token);
    return rest_ensure_response([
        'booking_id' => $booking_id,
        'cancel_url' => $cancel_url,
        'reschedule_url' => $reschedule_url
    ]);
}
function td_bkg_rest_admin_reconcile($request) {
    // For future: trigger reconcile job now.
    return rest_ensure_response(['ok' => true]);
}
function td_bkg_rest_admin_retry_failed($request) {
    // For future: trigger retry job now.
    return rest_ensure_response(['ok' => true]);
}

function td_bkg_rest_admin_test_breaks($request) {
    global $wpdb;
    
    $service_id = intval($request['service_id'] ?? 1);
    $from = sanitize_text_field($request['from'] ?? date('Y-m-d'));
    $to = sanitize_text_field($request['to'] ?? date('Y-m-d', strtotime('+7 days')));
    
    // Check if staff breaks are enabled
    $staff_breaks_enabled = get_option('td_bkg_staff_breaks_enabled');
    
    // Get all staff-wide breaks in the date range
    $breaks = $wpdb->get_results($wpdb->prepare(
        "SELECT id, type, notes, start_utc, end_utc FROM {$wpdb->prefix}td_staff_breaks 
         WHERE staff_id = 0 
         AND ((start_utc BETWEEN %s AND %s) OR (end_utc BETWEEN %s AND %s) OR (start_utc <= %s AND end_utc >= %s))
         ORDER BY start_utc",
        $from . ' 00:00:00', $to . ' 23:59:59', 
        $from . ' 00:00:00', $to . ' 23:59:59',
        $from . ' 00:00:00', $to . ' 23:59:59'
    ), ARRAY_A);
    
    // Test availability for the service to see how breaks affect it
    require_once dirname(__DIR__) . '/availability/engine.php';
    $slots_without_debug = td_bkg_availability_engine($service_id, $from, $to, 60);
    
    return rest_ensure_response([
        'staff_breaks_enabled' => (bool) $staff_breaks_enabled,
        'date_range' => ['from' => $from, 'to' => $to],
        'breaks_found' => count($breaks),
        'breaks' => array_map(function($break) {
            return [
                'id' => $break['id'],
                'title' => !empty($break['notes']) ? $break['notes'] : $break['type'],
                'type' => $break['type'],
                'notes' => $break['notes'],
                'start_utc' => $break['start_utc'],
                'end_utc' => $break['end_utc']
            ];
        }, $breaks),
        'available_slots_count' => count($slots_without_debug),
        'first_5_slots' => array_slice($slots_without_debug, 0, 5),
        'message' => __('Check error log for detailed availability debug information', 'td-booking')
    ]);
}

function td_bkg_rest_admin_debug_slot($request) {
    global $wpdb;
    
    $slot_datetime = sanitize_text_field($request['slot_datetime'] ?? '');
    $duration = intval($request['duration'] ?? 60);
    
    if (!$slot_datetime) {
        return new WP_Error('invalid', __('Slot datetime required', 'td-booking'), ['status' => 400]);
    }
    
    // Convert slot to timestamp
    $slot_ts = strtotime($slot_datetime);
    $slot_end_ts = $slot_ts + ($duration * 60);
    
    // Check staff breaks status
    $staff_breaks_enabled = get_option('td_bkg_staff_breaks_enabled');
    
    // Get all staff-wide breaks
    $all_breaks = $wpdb->get_results(
        "SELECT id, type, notes, start_utc, end_utc FROM {$wpdb->prefix}td_staff_breaks WHERE staff_id = 0 ORDER BY start_utc",
        ARRAY_A
    );
    
    $conflicts = [];
    foreach ($all_breaks as $break) {
        $break_start_ts = strtotime($break['start_utc']);
        $break_end_ts = strtotime($break['end_utc']);
        
        // Use notes as title, fallback to type
        $break_title = !empty($break['notes']) ? $break['notes'] : $break['type'];
        
        // Check if slot overlaps with this break
        if ($slot_ts < $break_end_ts && $slot_end_ts > $break_start_ts) {
            $conflicts[] = [
                'break_id' => $break['id'],
                'title' => $break_title,
                'type' => $break['type'],
                'start_utc' => $break['start_utc'],
                'end_utc' => $break['end_utc'],
                'break_start_ts' => $break_start_ts,
                'break_end_ts' => $break_end_ts,
                'overlap_logic' => "slot_start({$slot_ts}) < break_end({$break_end_ts}) && slot_end({$slot_end_ts}) > break_start({$break_start_ts})"
            ];
        }
    }
    
    return rest_ensure_response([
        'slot_datetime' => $slot_datetime,
        'slot_timestamp' => $slot_ts,
        'slot_end_timestamp' => $slot_end_ts,
        'duration_minutes' => $duration,
        'staff_breaks_enabled' => (bool) $staff_breaks_enabled,
        'total_breaks_in_db' => count($all_breaks),
        'conflicts_found' => count($conflicts),
        'conflicts' => $conflicts,
        'should_be_blocked' => $staff_breaks_enabled && count($conflicts) > 0,
        'all_breaks' => array_map(function($break) {
            return [
                'id' => $break['id'],
                'title' => !empty($break['notes']) ? $break['notes'] : $break['type'],
                'type' => $break['type'],
                'start_utc' => $break['start_utc'],
                'end_utc' => $break['end_utc']
            ];
        }, $all_breaks)
    ]);
}

function td_bkg_rest_admin_staff_hours($request) {
    $staff_id = intval($request['staff_id'] ?? 0);
    $full_week = isset($request['full_week']) ? filter_var($request['full_week'], FILTER_VALIDATE_BOOLEAN) : true;
    if (!$staff_id) {
        return new WP_Error('invalid', __('Staff ID is required', 'td-booking'), ['status' => 400]);
    }
    if (!function_exists('td_bkg_get_staff_hours')) {
        return new WP_Error('unavailable', __('Technicians integration not available', 'td-booking'), ['status' => 500]);
    }
    // Attempt both: direct raw from schedule() if possible, and normalized via helper
    $normalized = td_bkg_get_staff_hours($staff_id, $full_week);
    $raw = [];
    try {
        if (function_exists('td_tech') && method_exists(td_tech(), 'schedule')) {
            $schedule = td_tech()->schedule();
            if (method_exists($schedule, 'get_hours')) {
                $raw = $schedule->get_hours($staff_id);
            }
        }
    } catch (Exception $e) {}
    return rest_ensure_response([
        'staff_id' => $staff_id,
        'hours' => $normalized,
        'raw' => $raw,
        'note' => __('Keys are 0=Sun..6=Sat; each value is array of {start_time, end_time}', 'td-booking')
    ]);
}

/**
 * Temporary: Inspect TD Technicians raw schedule windows for a staff member.
 * GET /wp-json/td/v1/admin/tech-windows?staff_id=1&from=2025-09-01&to=2025-09-07
 */
function td_bkg_rest_admin_tech_windows($request) {
    $staff_id = intval($request['staff_id'] ?? 0);
    $from = sanitize_text_field($request['from'] ?? date('Y-m-d'));
    $to = sanitize_text_field($request['to'] ?? date('Y-m-d', strtotime('+7 days')));

    if (!$staff_id) {
        return new WP_Error('invalid', __('Staff ID is required', 'td-booking'), ['status' => 400]);
    }
    if (!function_exists('td_tech') || !method_exists(td_tech(), 'schedule')) {
        return new WP_Error('unavailable', __('Technicians schedule API not available', 'td-booking'), ['status' => 500]);
    }

    // Resolve site and staff timezones
    if (function_exists('wp_timezone')) {
        $site_tz = wp_timezone();
    } else {
        $tz_string = get_option('timezone_string');
        $site_tz = new DateTimeZone($tz_string ?: 'UTC');
    }
    $staff_tz = function_exists('td_bkg_get_staff_timezone') ? td_bkg_get_staff_timezone($staff_id) : $site_tz;
    if (!($staff_tz instanceof DateTimeZone)) {
        $staff_tz = $site_tz;
    }
    $utc_tz = new DateTimeZone('UTC');

    $from_day = (strlen($from) > 10) ? substr($from, 0, 10) : $from;
    $to_day = (strlen($to) > 10) ? substr($to, 0, 10) : $to;
    $from_dt = new DateTime($from_day . ' 00:00:00', $staff_tz);
    $to_dt = new DateTime($to_day . ' 23:59:59', $staff_tz);

    $schedule = td_tech()->schedule();
    $raw_daily = null;
    $raw_windows = null;
    $error = null;
    try {
        if (method_exists($schedule, 'get_daily_work_windows')) {
            $raw_daily = $schedule->get_daily_work_windows($staff_id, $from_dt, $to_dt);
        }
        if (empty($raw_daily) && method_exists($schedule, 'get_work_windows')) {
            $raw_windows = $schedule->get_work_windows($staff_id, $from_dt, $to_dt);
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }

    // Normalize any windows into readable arrays
    $normalize = function($windows) use ($staff_tz, $utc_tz) {
        if (!is_array($windows)) return [];
        $out = [];
        foreach ($windows as $w) {
            $start_ts = null; $end_ts = null;
            if (is_array($w) && count($w) === 2 && $w[0] instanceof DateTimeInterface && $w[1] instanceof DateTimeInterface) {
                $start_ts = $w[0]->getTimestamp();
                $end_ts = $w[1]->getTimestamp();
            } elseif (is_object($w)) {
                $sv = $w->start_utc ?? ($w->start ?? ($w->from_utc ?? ($w->from ?? null)));
                $ev = $w->end_utc ?? ($w->end ?? ($w->to_utc ?? ($w->to ?? null)));
                if ($sv instanceof DateTimeInterface) { $start_ts = $sv->getTimestamp(); }
                elseif (!empty($sv)) { $start_ts = strtotime((string)$sv); }
                if ($ev instanceof DateTimeInterface) { $end_ts = $ev->getTimestamp(); }
                elseif (!empty($ev)) { $end_ts = strtotime((string)$ev); }
            } elseif (is_array($w)) {
                $sv = $w['start_utc'] ?? ($w['start'] ?? ($w['from_utc'] ?? ($w['from'] ?? null)));
                $ev = $w['end_utc'] ?? ($w['end'] ?? ($w['to_utc'] ?? ($w['to'] ?? null)));
                if (!empty($sv)) { $start_ts = strtotime((string)$sv); }
                if (!empty($ev)) { $end_ts = strtotime((string)$ev); }
            }
            if ($start_ts && $end_ts && $end_ts > $start_ts) {
                $out[] = [
                    'start_utc' => gmdate('Y-m-d\TH:i:s\Z', $start_ts),
                    'end_utc' => gmdate('Y-m-d\TH:i:s\Z', $end_ts),
                    'start_local' => (new DateTime('@' . $start_ts))->setTimezone($staff_tz)->format('Y-m-d H:i:s T'),
                    'end_local' => (new DateTime('@' . $end_ts))->setTimezone($staff_tz)->format('Y-m-d H:i:s T'),
                ];
            }
        }
        return $out;
    };

    $daily_norm = $normalize($raw_daily);
    $windows_norm = $normalize($raw_windows);

    // Weekly hours as additional signal
    $hours = function_exists('td_bkg_get_staff_hours') ? td_bkg_get_staff_hours($staff_id) : [];

    // Exceptions within range
    $exceptions = [];
    if (function_exists('td_bkg_get_staff_exceptions')) {
        try {
            $exs = td_bkg_get_staff_exceptions($staff_id, $from_dt, $to_dt);
            if (is_array($exs)) {
                foreach ($exs as $ex) {
                    $s = null; $e = null;
                    if (is_object($ex)) {
                        $sv = $ex->start ?? ($ex->start_utc ?? ($ex->from ?? ($ex->from_utc ?? null)));
                        $ev = $ex->end ?? ($ex->end_utc ?? ($ex->to ?? ($ex->to_utc ?? null)));
                        if ($sv instanceof DateTimeInterface) { $s = $sv->getTimestamp(); } elseif (!empty($sv)) { $s = strtotime((string)$sv); }
                        if ($ev instanceof DateTimeInterface) { $e = $ev->getTimestamp(); } elseif (!empty($ev)) { $e = strtotime((string)$ev); }
                    } elseif (is_array($ex)) {
                        $sv = $ex['start'] ?? ($ex['start_utc'] ?? ($ex['from'] ?? ($ex['from_utc'] ?? null)));
                        $ev = $ex['end'] ?? ($ex['end_utc'] ?? ($ex['to'] ?? ($ex['to_utc'] ?? null)));
                        if (!empty($sv)) { $s = strtotime((string)$sv); }
                        if (!empty($ev)) { $e = strtotime((string)$ev); }
                    }
                    if ($s && $e) {
                        $exceptions[] = [
                            'start_utc' => gmdate('Y-m-d\TH:i:s\Z', $s),
                            'end_utc' => gmdate('Y-m-d\TH:i:s\Z', $e),
                            'start_local' => (new DateTime('@' . $s))->setTimezone($staff_tz)->format('Y-m-d H:i:s T'),
                            'end_local' => (new DateTime('@' . $e))->setTimezone($staff_tz)->format('Y-m-d H:i:s T'),
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    return rest_ensure_response([
        'staff_id' => $staff_id,
        'site_timezone' => $site_tz->getName(),
        'staff_timezone' => $staff_tz->getName(),
        'range' => [
            'from_local' => $from_dt->format('Y-m-d H:i:s T'),
            'to_local' => $to_dt->format('Y-m-d H:i:s T'),
            'from_utc' => $from_dt->setTimezone($utc_tz)->format('Y-m-d H:i:s') . 'Z',
            'to_utc' => $to_dt->setTimezone($utc_tz)->format('Y-m-d H:i:s') . 'Z',
        ],
        'daily_method' => [
            'method' => 'get_daily_work_windows',
            'count' => is_array($daily_norm) ? count($daily_norm) : 0,
            'windows' => $daily_norm,
        ],
        'windows_method' => [
            'method' => 'get_work_windows',
            'count' => is_array($windows_norm) ? count($windows_norm) : 0,
            'windows' => $windows_norm,
        ],
        'weekly_hours' => $hours,
        'exceptions' => $exceptions,
        'error' => $error,
    ]);
}

/**
 * Backfill PII: populate email/phone HMAC hashes and optionally encrypt PII fields for existing rows.
 * POST /wp-json/td/v1/admin/pii-backfill
 * Body: { mode: 'hash-only'|'encrypt-and-hash', limit: 200, resume_from_id: 0 }
 */
function td_bkg_rest_admin_pii_backfill($request) {
    global $wpdb;
    $mode = sanitize_text_field($request['mode'] ?? 'hash-only');
    if (!in_array($mode, ['hash-only', 'encrypt-and-hash'], true)) {
        $mode = 'hash-only';
    }
    $limit = intval($request['limit'] ?? 200);
    if ($limit < 10) $limit = 10; if ($limit > 1000) $limit = 1000;
    $resume = max(0, intval($request['resume_from_id'] ?? 0));

    $can_encrypt = function_exists('td_bkg_crypto_available') && td_bkg_crypto_available() && function_exists('td_bkg_encrypt');
    if ($mode === 'encrypt-and-hash' && !$can_encrypt) {
        return new WP_Error('crypto_unavailable', __('Encryption not available or keys not configured.', 'td-booking'), ['status' => 400]);
    }
    $have_hmac = function_exists('td_bkg_hmac_index') && td_bkg_hmac_index('test') !== null;

    // Fetch a batch by ID window
    $table = $wpdb->prefix . 'td_booking';
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $resume, $limit), ARRAY_A);
    $scanned = is_array($rows) ? count($rows) : 0;
    $processed = 0; $updated_ids = [];
    $last_id = $resume;

    foreach ((array)$rows as $row) {
        $last_id = max($last_id, intval($row['id']));
        $update = [];
        // Derive plaintext values (decrypt if necessary)
        $email_val = is_string($row['customer_email']) && function_exists('td_bkg_is_encrypted_envelope') && td_bkg_is_encrypted_envelope($row['customer_email'])
            ? (function_exists('td_bkg_decrypt') ? td_bkg_decrypt($row['customer_email'], 'td-booking:email') : '')
            : $row['customer_email'];
        $phone_val = is_string($row['customer_phone']) && function_exists('td_bkg_is_encrypted_envelope') && td_bkg_is_encrypted_envelope($row['customer_phone'])
            ? (function_exists('td_bkg_decrypt') ? td_bkg_decrypt($row['customer_phone'], 'td-booking:phone') : '')
            : $row['customer_phone'];
        $addr_val = is_string($row['customer_address_json']) && function_exists('td_bkg_is_encrypted_envelope') && td_bkg_is_encrypted_envelope($row['customer_address_json'])
            ? (function_exists('td_bkg_decrypt') ? td_bkg_decrypt($row['customer_address_json'], 'td-booking:address') : '')
            : $row['customer_address_json'];
        $notes_val = is_string($row['notes']) && function_exists('td_bkg_is_encrypted_envelope') && td_bkg_is_encrypted_envelope($row['notes'])
            ? (function_exists('td_bkg_decrypt') ? td_bkg_decrypt($row['notes'], 'td-booking:notes') : '')
            : $row['notes'];

        // Hashes (if available)
        if ($have_hmac) {
            if (empty($row['email_hash']) && !empty($email_val)) {
                $update['email_hash'] = td_bkg_hmac_index(strtolower($email_val));
            }
            if (empty($row['phone_hash']) && !empty($phone_val)) {
                $update['phone_hash'] = td_bkg_hmac_index(preg_replace('/\s+/', '', (string)$phone_val));
            }
        }

        // Encrypt where missing (only in encrypt-and-hash mode)
        if ($mode === 'encrypt-and-hash' && $can_encrypt) {
            if (!empty($email_val) && !(function_exists('td_bkg_is_encrypted_envelope') && td_bkg_is_encrypted_envelope($row['customer_email']))) {
                $enc = td_bkg_encrypt((string)$email_val, 'td-booking:email'); if ($enc) $update['customer_email'] = $enc;
            }
            if (!empty($phone_val) && !(function_exists('td_bkg_is_encrypted_envelope') && td_bkg_is_encrypted_envelope($row['customer_phone']))) {
                $enc = td_bkg_encrypt((string)$phone_val, 'td-booking:phone'); if ($enc) $update['customer_phone'] = $enc;
            }
            if (!empty($addr_val) && !(function_exists('td_bkg_is_encrypted_envelope') && td_bkg_is_encrypted_envelope($row['customer_address_json']))) {
                $enc = td_bkg_encrypt((string)$addr_val, 'td-booking:address'); if ($enc) $update['customer_address_json'] = $enc;
            }
            if (!empty($notes_val) && !(function_exists('td_bkg_is_encrypted_envelope') && td_bkg_is_encrypted_envelope($row['notes']))) {
                $enc = td_bkg_encrypt((string)$notes_val, 'td-booking:notes'); if ($enc) $update['notes'] = $enc;
            }
        }

        if (!empty($update)) {
            $update['updated_at'] = current_time('mysql', 1);
            $ok = $wpdb->update($table, $update, ['id' => intval($row['id'])]);
            if ($ok !== false) { $processed++; $updated_ids[] = intval($row['id']); }
        }
    }

    $done = $scanned < $limit;
    return rest_ensure_response([
        'mode' => $mode,
        'limit' => $limit,
        'resume_from_id' => $resume,
        'last_id' => $last_id,
        'next_resume_from_id' => $last_id,
        'scanned' => $scanned,
        'updated' => $processed,
        'updated_ids' => $updated_ids,
        'hmac_available' => $have_hmac,
        'crypto_available' => $can_encrypt,
        'done' => (bool) $done,
        'message' => $processed > 0 ? __('Backfill updated some rows. Run again until done.', 'td-booking') : ($done ? __('No updates needed.', 'td-booking') : __('Continue running to process remaining rows.', 'td-booking')),
    ]);
}

/**
 * Send a test email to verify WordPress mail configuration.
 * POST /wp-json/td/v1/admin/test-mail
 * Body: { to?: string, subject?: string, body?: string, include_ics?: bool }
 */
function td_bkg_rest_admin_test_mail($request) {
    $to = sanitize_email($request['to'] ?? get_option('admin_email'));
    $subject = sanitize_text_field($request['subject'] ?? __('TD Booking Test Email', 'td-booking'));
    $body = wp_kses_post($request['body'] ?? ('This is a TD Booking test email sent from ' . home_url('/') . ' at ' . gmdate('c') . ' UTC.'));
    $include_ics = isset($request['include_ics']) ? (bool)$request['include_ics'] : false;

    if (!$to) {
        return new WP_Error('invalid_to', __('Invalid recipient email address.', 'td-booking'), ['status' => 400]);
    }

    // Capture PHPMailer failure for this request
    $last_error = '';
    add_action('wp_mail_failed', function($wp_error) use (&$last_error) {
        if (is_wp_error($wp_error)) {
            $last_error = $wp_error->get_error_message();
        }
    }, 10, 1);

    // Optional tiny ICS to also test attachments
    $ics = '';
    if ($include_ics && function_exists('td_bkg_ics')) {
        $start = gmdate('Ymd\THis\Z', time() + 600);
        $end = gmdate('Ymd\THis\Z', time() + 1800);
        $ics = td_bkg_ics([
            'uid' => 'tdbkg-mailtest-' . wp_generate_password(8, false, false),
            'dtstart' => $start,
            'dtend' => $end,
            'summary' => 'TD Booking Mail Test',
            'description' => 'Test event to verify ICS attachments.'
        ]);
    }

    if (!function_exists('td_bkg_mailer')) {
        require_once dirname(__DIR__) . '/mailer.php';
    }
    $sent = td_bkg_mailer($to, $subject, $body, $ics ?: null, 'test-mail', []);

    if ($sent) {
        if (function_exists('td_bkg_log_audit')) {
            td_bkg_log_audit('info', 'mailer', 'Test email sent', json_encode(['to' => $to, 'with_ics' => (bool)$ics]));
        }
        return rest_ensure_response([
            'ok' => true,
            'to' => $to,
            'with_ics' => (bool)$ics,
            'message' => __('Email sent successfully (wp_mail returned true).', 'td-booking')
        ]);
    }
    $msg = $last_error ?: __('wp_mail returned false (no additional error available).', 'td-booking');
    if (function_exists('td_bkg_log_audit')) {
        td_bkg_log_audit('error', 'mailer', 'Test email failed', $msg);
    }
    return new WP_Error('mail_failed', $msg, ['status' => 500, 'to' => $to]);
}

/**
 * Run CalDAV diagnostics against a staff member's calendar collection.
 * - Normalizes credentials (supports base_url + calendar_path)
 * - PROPFIND Depth:0 to read supported components
 * - PUT a temporary VEVENT (tries with component param, then fallback) and DELETE it for cleanup
 * POST /wp-json/td/v1/admin/caldav-diagnostics
 * Body: { staff: "Name", base_url|url, username|user, app_password|pass, calendar_path, cleanup=true }
 */
function td_bkg_rest_admin_caldav_diagnostics($request) {
    $staff_id = intval($request['staff_id'] ?? 0);
    $staff_name = isset($request['staff']) ? sanitize_text_field($request['staff']) : '';
    if (!$staff_id && $staff_name && function_exists('td_bkg_find_staff_id_by_name')) {
        $staff_id = td_bkg_find_staff_id_by_name($staff_name);
    }
    if (!$staff_id || !function_exists('td_tech') || !method_exists(td_tech(), 'caldav')) {
        return new WP_Error('invalid', __('Missing staff or CalDAV', 'td-booking'), ['status' => 400]);
    }

    // Merge incoming overrides with stored credentials
    $incoming = [
        'url' => sanitize_text_field($request['base_url'] ?? $request['url'] ?? ''),
        'user' => sanitize_text_field($request['username'] ?? $request['user'] ?? ''),
        'pass' => sanitize_text_field($request['app_password'] ?? $request['pass'] ?? ''),
        'calendar_path' => sanitize_text_field($request['calendar_path'] ?? ''),
    ];
    $stored = td_tech()->caldav()->get_credentials($staff_id);
    $merged = array_merge((array)$stored, array_filter($incoming));
    if (function_exists('td_bkg_normalize_caldav_creds')) {
        $merged = td_bkg_normalize_caldav_creds($merged);
    }
    $missing = [];
    if (!$merged || empty($merged['url'])) $missing[] = 'url/base_url';
    if (!empty($incoming['url']) && function_exists('td_bkg_caldav_url_is_root') && td_bkg_caldav_url_is_root($incoming['url']) && empty($incoming['calendar_path'])) {
        $missing[] = 'calendar_path';
    }
    if (!$merged || empty($merged['user'])) $missing[] = 'username';
    if (!$merged || empty($merged['pass'])) $missing[] = 'app_password';
    if (!empty($missing)) {
        return new WP_Error('invalid', sprintf(__('Missing CalDAV fields: %s', 'td-booking'), implode(', ', $missing)), ['status' => 400, 'missing' => $missing]);
    }

    // Ensure helpers are available
    if (!function_exists('td_bkg_caldav_propfind') || !function_exists('td_bkg_caldav_request')) {
        require_once dirname(__DIR__) . '/caldav/client.php';
    }
    if (!function_exists('td_bkg_caldav_ics')) {
        require_once dirname(__DIR__) . '/caldav/mapper.php';
    }

    $collection = rtrim($merged['url'], '/');
    $cleanup = !isset($request['cleanup']) || filter_var($request['cleanup'], FILTER_VALIDATE_BOOLEAN);

    // 1) PROPFIND Depth:0 to inspect supported components
    $prop = td_bkg_caldav_propfind($collection, $merged['user'], $merged['pass']);
    $supported = [];
    if (!empty($prop['body'])) {
        // Try to extract supported components like VEVENT, VTODO, VJOURNAL
        if (preg_match_all('/<[^:>]*comp[^>]*name="([A-Z]+)"/i', $prop['body'], $m)) {
            $supported = array_values(array_unique(array_map('strtoupper', $m[1])));
        }
    }

    // 2) Test PUT a minimal VEVENT at a temporary resource URL
    $uid = 'tdbkg-diagnostic-' . wp_generate_password(12, false, false);
    $resource_url = $collection . '/' . $uid . '.ics';
    $start_ts = time() + 3600; // 1 hour from now
    $end_ts = $start_ts + 3600; // 1-hour event
    $booking = [
        'id' => 0,
        'caldav_uid' => $uid,
        'start_utc' => gmdate('Y-m-d H:i:s', $start_ts),
        'end_utc' => gmdate('Y-m-d H:i:s', $end_ts),
        'customer_name' => 'CalDAV Diagnostic',
        'notes' => 'Temporary event to test PUT (safe to delete)',
    ];
    $service = [ 'name' => 'TD Booking Diagnostic', 'location_json' => '' ];
    $ics = td_bkg_caldav_ics($booking, $service, 'STORE');

    // Attempt 1: Content-Type with component=VEVENT
    $headers1 = [
        'Content-Type: text/calendar; charset=utf-8; component=VEVENT',
        'If-None-Match: *'
    ];
    $put1 = td_bkg_caldav_request($resource_url, 'PUT', $ics, $headers1, $merged['user'], $merged['pass']);
    $used_fallback = false;
    $put2 = null;
    if (intval($put1['status']) === 415) {
        // Attempt 2: Without component parameter
        $headers2 = [
            'Content-Type: text/calendar; charset=utf-8',
            'If-None-Match: *'
        ];
        $put2 = td_bkg_caldav_request($resource_url, 'PUT', $ics, $headers2, $merged['user'], $merged['pass']);
        $used_fallback = true;
    }

    // Cleanup: DELETE if resource created (201/200/204)
    $deleted = null;
    $created_ok = function($code) { $c = intval($code); return ($c >= 200 && $c < 300); };
    if ($cleanup && (($put2 && $created_ok($put2['status'])) || $created_ok($put1['status']))) {
        $deleted = td_bkg_caldav_delete($resource_url, $merged['user'], $merged['pass']);
    }

    // Build response with masked fields
    $resp = [
        'staff_id' => $staff_id,
        'collection_url' => $collection,
        'propfind' => [
            'status' => intval($prop['status']),
            'supported_components' => $supported,
            'redirect_url' => $prop['redirect_url'] ?? $collection,
        ],
        'put' => [
            'resource_url' => $resource_url,
            'attempt1' => [ 'status' => intval($put1['status']) ],
            'attempt2' => $put2 ? [ 'status' => intval($put2['status']) ] : null,
            'used_fallback' => $used_fallback,
        ],
        'cleanup' => $deleted ? [ 'status' => intval($deleted['status']) ] : null,
    ];

    return rest_ensure_response($resp);
}
