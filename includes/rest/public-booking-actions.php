<?php
defined('ABSPATH') || exit;

add_action('rest_api_init', function() {
    // Support both POST (API) and GET (email link) for cancellation; token still required for public callers
    register_rest_route('td/v1', '/booking/(?P<id>\d+)/cancel', [
        'methods' => 'POST',
        'callback' => 'td_bkg_rest_booking_cancel',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('td/v1', '/booking/(?P<id>\d+)/cancel', [
        'methods' => 'GET',
        'callback' => 'td_bkg_rest_booking_cancel',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('td/v1', '/booking/(?P<id>\d+)/reschedule', [
        'methods' => 'POST',
        'callback' => 'td_bkg_rest_booking_reschedule',
        'permission_callback' => '__return_true',
    ]);
    // Public fetch for booking info to power the reschedule UI
    register_rest_route('td/v1', '/booking/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'td_bkg_rest_booking_get',
        'permission_callback' => '__return_true',
    ]);
});

function td_bkg_rest_booking_cancel($request) {
    $booking_id = intval($request['id']);
    $token = isset($request['token']) ? sanitize_text_field($request['token']) : '';
    $reason = isset($request['reason']) ? sanitize_text_field($request['reason']) : '';
    global $wpdb;
    $original_id = $booking_id;
    // Fetch by id first
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_booking WHERE id=%d", $booking_id), ARRAY_A);
    if ($booking && function_exists('td_bkg_booking_decrypt_row')) { $booking = td_bkg_booking_decrypt_row($booking); }
    if (!$booking) return new WP_Error('not_found', __('Booking not found', 'td-booking'), ['status' => 404]);
    // Security: public callers must provide a valid token; admins can bypass with capability
    if (!function_exists('td_bkg_can_manage') || !td_bkg_can_manage()) {
        if (!function_exists('td_bkg_validate_token') || !td_bkg_validate_token($booking_id, $token)) {
            // Try resolve via ics_token
            if (!empty($token)) {
                $by_token_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}td_booking WHERE ics_token=%s", $token));
                if ($by_token_id) {
                    $booking_id = intval($by_token_id);
                    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_booking WHERE id=%d", $booking_id), ARRAY_A);
                    if ($booking && function_exists('td_bkg_booking_decrypt_row')) { $booking = td_bkg_booking_decrypt_row($booking); }
                    // Log resolution if requested id differs from token-resolved id
                    if (function_exists('td_bkg_log_audit') && $booking_id !== $original_id) {
                        $token_mask = $token ? (substr($token, 0, 4) . '…' . substr($token, -4)) : '';
                        td_bkg_log_audit('warn', 'api', 'Cancel token resolved different booking id', json_encode([
                            'requested_id' => $original_id,
                            'resolved_id' => $booking_id,
                            'token_mask' => $token_mask,
                            'endpoint' => 'GET/POST /td/v1/booking/{id}/cancel'
                        ]), $booking_id, $booking['staff_id'] ?? null);
                    }
                } else {
                    return new WP_Error('invalid_token', __('Invalid token', 'td-booking'), ['status' => 403]);
                }
            } else {
                return new WP_Error('invalid_token', __('Invalid token', 'td-booking'), ['status' => 403]);
            }
        }
    }
    // Idempotent: already cancelled — log it so the audit shows activity
    if (isset($booking['status']) && $booking['status'] === 'cancelled') {
        if (function_exists('td_bkg_log_audit')) {
            $initiator = (function_exists('td_bkg_can_manage') && td_bkg_can_manage()) ? 'admin' : 'public';
            td_bkg_log_audit('info', 'api', 'Booking cancel requested (already cancelled)', json_encode([
                'initiator' => $initiator
            ]), $booking_id, $booking['staff_id'] ?? null);
        }
        return rest_ensure_response(['status' => 'cancelled', 'message' => __('Already cancelled', 'td-booking'), 'booking_id' => $booking_id]);
    }
    $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_service WHERE id=%d", $booking['service_id']), ARRAY_A);
    $status = 'cancelled';
    $fail_reason = '';
    // CalDAV delete (best-effort). Try stored UID; if missing, also try fallback derived UID
    if (function_exists('td_tech') && method_exists(td_tech(), 'caldav')) {
        $creds = td_tech()->caldav()->get_credentials($booking['staff_id']);
        if (function_exists('td_bkg_normalize_caldav_creds')) { $creds = td_bkg_normalize_caldav_creds($creds); }
        if ($creds && !empty($creds['url']) && !empty($creds['user']) && !empty($creds['pass'])) {
            $try_uids = [];
            if (!empty($booking['caldav_uid'])) { $try_uids[] = $booking['caldav_uid']; }
            $fallback_uid = function_exists('td_bkg_caldav_uid') ? td_bkg_caldav_uid($booking_id) : ('tdbkg-' . $booking_id);
            if (!in_array($fallback_uid, $try_uids, true)) { $try_uids[] = $fallback_uid; }
            foreach ($try_uids as $uid_try) {
                $resname = function_exists('td_bkg_caldav_resource_name') ? td_bkg_caldav_resource_name($uid_try) : $uid_try;
                $event_url = rtrim($creds['url'], '/') . '/' . $resname . '.ics';
                if (function_exists('td_bkg_caldav_url_is_root') && td_bkg_caldav_url_is_root($creds['url'])) {
                    if (function_exists('td_bkg_log_audit')) {
                        td_bkg_log_audit('warn', 'caldav', 'CalDAV base URL appears root-ish on cancel; expected calendar collection URL', json_encode([
                            'base_url' => $creds['url'],
                            'event_url' => $event_url,
                        ]), $booking_id, $booking['staff_id']);
                    }
                }
                $res = td_bkg_caldav_delete($event_url, $creds['user'], $creds['pass']);
                if ($res['status'] >= 200 && $res['status'] < 300) {
                    if (function_exists('td_bkg_log_audit')) {
                        td_bkg_log_audit('info', 'caldav', 'CalDAV event deleted (cancel)', json_encode(['url' => $event_url]), $booking_id, $booking['staff_id']);
                    }
                    break;
                }
                if (intval($res['status']) === 404) {
                    if (function_exists('td_bkg_log_audit')) {
                        td_bkg_log_audit('info', 'caldav', 'CalDAV event not found during cancel (404)', json_encode(['url' => $event_url]), $booking_id, $booking['staff_id']);
                    }
                    break;
                }
                $status = 'failed_sync';
                $url_parts = parse_url($event_url);
                $safe_host = $url_parts ? ($url_parts['scheme'] . '://' . ($url_parts['host'] ?? 'host') . (isset($url_parts['port']) ? ':' . $url_parts['port'] : '') . ($url_parts['path'] ?? '')) : '';
                $fail_reason = ($res['error'] ?: ('HTTP ' . $res['status'])) . ' @ ' . $safe_host;
                if (function_exists('td_bkg_log_audit')) {
                    td_bkg_log_audit('error', 'caldav', 'CalDAV cancel failed', json_encode([
                        'status' => $res['status'],
                        'error' => $res['error'],
                        'url' => $safe_host
                    ]), $booking_id, $booking['staff_id']);
                }
                // continue loop to try next UID if any
            }
        } else {
            $status = 'failed_sync';
            $fail_reason = 'Missing CalDAV credentials';
        }
    }
    // Persist cancelled status
    $wpdb->update($wpdb->prefix . 'td_booking', [ 'status' => 'cancelled', 'updated_at' => current_time('mysql', 1) ], ['id' => $booking_id]);
    // Structured audit
    if (function_exists('td_bkg_log_audit')) {
        $initiator = (function_exists('td_bkg_can_manage') && td_bkg_can_manage()) ? 'admin' : 'public';
        $ctx = [ 'initiator' => $initiator ];
        if ($reason) { $ctx['reason'] = $reason; }
        td_bkg_log_audit('info', 'api', 'Booking cancelled', json_encode($ctx), $booking_id, $booking['staff_id']);
        if ($status === 'failed_sync') {
            td_bkg_log_audit('error', 'caldav', 'CalDAV cancel failed', $fail_reason, $booking_id, $booking['staff_id']);
        }
    }
    // Email CANCEL ICS
    $ics = function_exists('td_bkg_caldav_ics') ? td_bkg_caldav_ics($booking, $service, 'CANCEL') : '';
    if (function_exists('td_bkg_mailer')) {
        td_bkg_mailer($booking['customer_email'], __('Booking Cancelled', 'td-booking'), __('Your booking is cancelled.', 'td-booking'), $ics);
    }
    return rest_ensure_response(['status' => 'cancelled', 'booking_id' => $booking_id]);
}

function td_bkg_rest_booking_reschedule($request) {
    $booking_id = intval($request['id']);
    $token = sanitize_text_field($request['token'] ?? '');
    $new_start_utc = sanitize_text_field($request['start_utc'] ?? '');
    if (!function_exists('td_bkg_validate_token') || !td_bkg_validate_token($booking_id, $token)) {
        return new WP_Error('invalid_token', __('Invalid token', 'td-booking'), ['status' => 403]);
    }
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_booking WHERE id=%d", $booking_id), ARRAY_A);
    if ($booking && function_exists('td_bkg_booking_decrypt_row')) { $booking = td_bkg_booking_decrypt_row($booking); }
    if (!$booking) return new WP_Error('not_found', __('Booking not found', 'td-booking'), ['status' => 404]);
    if ($booking['status'] === 'cancelled') {
        return new WP_Error('invalid_state', __('This booking is cancelled and cannot be rescheduled.', 'td-booking'), ['status' => 409]);
    }
    // Preserve old CalDAV and time info for deletion and logging
    $old_uid = $booking['caldav_uid'] ?? '';
    $old_etag = $booking['caldav_etag'] ?? '';
    $old_start_utc = $booking['start_utc'];
    $old_end_utc = $booking['end_utc'];
    $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_service WHERE id=%d", $booking['service_id']), ARRAY_A);
    $duration = $service['duration_min'];
    $new_end_utc = gmdate('Y-m-d H:i:s', strtotime($new_start_utc) + $duration * 60);
    
    // Verify new slot availability for the SAME staff to avoid cross-calendar moves
    if (!empty($new_start_utc)) {
        require_once dirname(__DIR__) . '/availability/engine.php';
        $verify_to = gmdate('Y-m-d H:i:s', strtotime($new_start_utc) + $duration * 60);
        $cand = td_bkg_availability_engine(intval($booking['service_id']), $new_start_utc, $verify_to, intval($duration), ['return_staff' => true]);
        $target_start_iso = gmdate('Y-m-d\TH:i:s\Z', strtotime($new_start_utc));
        $ok = false;
        foreach ($cand as $slot) {
            if (($slot['start_utc'] ?? '') === $target_start_iso && intval($slot['staff_id'] ?? 0) === intval($booking['staff_id'])) {
                $ok = true; break;
            }
        }
        if (!$ok) {
            return new WP_Error('not_available', __('Selected time is not available with your technician. Please choose another slot.', 'td-booking'), ['status' => 409]);
        }
    }
    // Update booking row
    $wpdb->update($wpdb->prefix . 'td_booking', [
        'start_utc' => $new_start_utc,
        'end_utc' => $new_end_utc,
        'updated_at' => current_time('mysql', 1),
    ], ['id' => $booking_id]);
    
    // Invalidate availability cache for the affected staff and dates (old and new day)
    if (function_exists('td_bkg_availability_cache_invalidate')) {
        $new_day = substr($new_start_utc, 0, 10);
        $old_day = substr($old_start_utc, 0, 10);
        td_bkg_availability_cache_invalidate(intval($booking['staff_id']), $new_day, $new_day);
        if ($old_day !== $new_day) {
            td_bkg_availability_cache_invalidate(intval($booking['staff_id']), $old_day, $old_day);
        }
    }
    // Update CalDAV by deleting old resource and creating a new one with a fresh UID
    $uid = $booking['caldav_uid'];
    if (function_exists('td_tech') && method_exists(td_tech(), 'caldav')) {
        $creds = td_tech()->caldav()->get_credentials($booking['staff_id']);
        if (function_exists('td_bkg_normalize_caldav_creds')) { $creds = td_bkg_normalize_caldav_creds($creds); }
        if ($creds && !empty($creds['url']) && !empty($creds['user']) && !empty($creds['pass'])) {
            // 1) Delete old event if we have an old UID
            if (!empty($old_uid)) {
                $old_res = function_exists('td_bkg_caldav_resource_name') ? td_bkg_caldav_resource_name($old_uid) : $old_uid;
                $old_url = rtrim($creds['url'], '/') . '/' . $old_res . '.ics';
                $del = td_bkg_caldav_delete($old_url, $creds['user'], $creds['pass']);
                if (!($del['status'] >= 200 && $del['status'] < 300) && intval($del['status']) !== 404) {
                    $url_parts = parse_url($old_url);
                    $safe = $url_parts ? ($url_parts['scheme'] . '://' . ($url_parts['host'] ?? 'host') . (isset($url_parts['port']) ? ':' . $url_parts['port'] : '') . ($url_parts['path'] ?? '')) : '';
                    if (function_exists('td_bkg_log_audit')) {
                        td_bkg_log_audit('warn', 'caldav', 'CalDAV delete (old event) failed during reschedule', json_encode([
                            'status' => $del['status'], 'error' => $del['error'], 'url' => $safe
                        ]), $booking_id, $booking['staff_id']);
                    }
                } else {
                    if (function_exists('td_bkg_log_audit')) {
                        td_bkg_log_audit('info', 'caldav', 'CalDAV old event deleted for reschedule', '', $booking_id, $booking['staff_id']);
                    }
                }
            }

            // 2) Create new event with fresh UID
            $uid = 'tdbkg-' . $booking_id . '-' . substr(wp_generate_password(8, false, false), 0, 8);
            // Persist the new UID immediately so retries target the new resource if PUT fails
            $wpdb->update($wpdb->prefix . 'td_booking', [ 'caldav_uid' => $uid, 'caldav_etag' => '' ], ['id' => $booking_id]);
            $ics_new = function_exists('td_bkg_caldav_ics') ? td_bkg_caldav_ics([
                'id' => $booking_id,
                'start_utc' => $new_start_utc,
                'end_utc' => $new_end_utc,
                'customer_name' => $booking['customer_name'],
                'notes' => $booking['notes'],
                'customer_address_json' => $booking['customer_address_json'],
                'caldav_uid' => $uid,
            ], $service, 'STORE') : '';
            $new_res = function_exists('td_bkg_caldav_resource_name') ? td_bkg_caldav_resource_name($uid) : $uid;
            $new_url = rtrim($creds['url'], '/') . '/' . $new_res . '.ics';
            if (function_exists('td_bkg_caldav_url_is_root') && td_bkg_caldav_url_is_root($creds['url'])) {
                if (function_exists('td_bkg_log_audit')) {
                    td_bkg_log_audit('warn', 'caldav', 'CalDAV base URL appears root-ish on reschedule; expected calendar collection URL', json_encode([
                        'base_url' => $creds['url'],
                        'event_url' => $new_url,
                    ]), $booking_id, $booking['staff_id']);
                }
            }
            $put = td_bkg_caldav_put($new_url, $ics_new, $creds['user'], $creds['pass']);
            if ($put['status'] >= 200 && $put['status'] < 300) {
                $wpdb->update($wpdb->prefix . 'td_booking', [ 'caldav_etag' => $put['etag'] ?? '' ], ['id' => $booking_id]);
                if (function_exists('td_bkg_log_audit')) {
                    td_bkg_log_audit('info', 'caldav', 'CalDAV new event created for reschedule', json_encode(['etag' => $put['etag'] ?? '']), $booking_id, $booking['staff_id']);
                }
            } else {
                $url_parts = parse_url($new_url);
                $safe = $url_parts ? ($url_parts['scheme'] . '://' . ($url_parts['host'] ?? 'host') . (isset($url_parts['port']) ? ':' . $url_parts['port'] : '') . ($url_parts['path'] ?? '')) : '';
                if (function_exists('td_bkg_log_audit')) {
                    td_bkg_log_audit('error', 'caldav', 'CalDAV create (new event) failed during reschedule', json_encode([
                        'status' => $put['status'], 'error' => $put['error'], 'url' => $safe
                    ]), $booking_id, $booking['staff_id']);
                }
                // Mark for retry
                $wpdb->update($wpdb->prefix . 'td_booking', [ 'status' => 'failed_sync' ], ['id' => $booking_id]);
            }
        }
    }
    // Send updated ICS (REQUEST). We could also send a CANCEL for the old UID if needed.
    $ics = function_exists('td_bkg_caldav_ics') ? td_bkg_caldav_ics([
        'id' => $booking_id,
        'start_utc' => $new_start_utc,
        'end_utc' => $new_end_utc,
        'customer_name' => $booking['customer_name'],
        'notes' => $booking['notes'],
        'customer_address_json' => $booking['customer_address_json'],
        'caldav_uid' => $uid,
    ], $service, 'REQUEST') : '';
    if (function_exists('td_bkg_mailer')) {
        td_bkg_mailer($booking['customer_email'], __('Booking Rescheduled', 'td-booking'), __('Your booking has been rescheduled.', 'td-booking'), $ics);
    }
    if (function_exists('td_bkg_log_audit')) {
        td_bkg_log_audit('info', 'api', 'Booking rescheduled', '', $booking_id, $booking['staff_id']);
    }
    return rest_ensure_response(['status' => 'rescheduled']);
}

// Public: fetch minimal booking details for UI, requires valid token
function td_bkg_rest_booking_get($request) {
    $booking_id = intval($request['id']);
    $token = sanitize_text_field($request['token'] ?? '');
    $token_mask = $token ? (substr($token, 0, 4) . '…' . substr($token, -4)) : '';
    if (!function_exists('td_bkg_validate_token') || !td_bkg_validate_token($booking_id, $token)) {
        // If token doesn't validate for provided id, try resolving booking by ics_token instead
        if (!empty($token)) {
            global $wpdb;
            $by_token_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}td_booking WHERE ics_token=%s", $token));
            if ($by_token_id) {
                if (function_exists('td_bkg_log_audit')) {
                    td_bkg_log_audit('warn', 'reschedule', 'Booking GET token resolved different id', json_encode([
                        'requested_id' => $booking_id,
                        'resolved_id' => intval($by_token_id),
                        'token_mask' => $token_mask,
                        'endpoint' => 'GET /td/v1/booking/{id}'
                    ]));
                }
                $booking_id = intval($by_token_id);
            } else {
                // Log invalid token attempt to PHP error log and audit table
                // Quiet: avoid noisy PHP error_log in public endpoints
                if (function_exists('td_bkg_log_audit')) {
                    td_bkg_log_audit('warn', 'reschedule', 'Booking GET invalid token', json_encode([
                        'booking_id' => $booking_id,
                        'token_mask' => $token_mask,
                        'endpoint' => 'GET /td/v1/booking/{id}'
                    ]));
                }
                return new WP_Error('invalid_token', __('Invalid token', 'td-booking'), ['status' => 403]);
            }
        } else {
            return new WP_Error('invalid_token', __('Invalid token', 'td-booking'), ['status' => 403]);
        }
    }
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_booking WHERE id=%d", $booking_id), ARRAY_A);
    if ($booking && function_exists('td_bkg_booking_decrypt_row')) { $booking = td_bkg_booking_decrypt_row($booking); }
    if (!$booking) {
        // Fallback: if ID not found but we have a token, try to locate booking by ics_token
        if (!empty($token)) {
            $by_token = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_booking WHERE ics_token=%s", $token), ARRAY_A);
            if ($by_token) {
                if (function_exists('td_bkg_log_audit')) {
                    td_bkg_log_audit('warn', 'reschedule', 'Booking GET id/token mismatch  resolved via token lookup', json_encode([
                        'requested_id' => $booking_id,
                        'resolved_id' => $by_token['id'],
                        'token_mask' => $token_mask
                    ]));
                }
                $booking = $by_token;
            }
        }
        if (!$booking) {
            // Quiet: avoid noisy PHP error_log in public endpoints
            if (function_exists('td_bkg_log_audit')) {
                td_bkg_log_audit('warn', 'reschedule', 'Booking GET not found', json_encode([
                    'booking_id' => $booking_id,
                    'token_mask' => $token_mask,
                    'endpoint' => 'GET /td/v1/booking/{id}'
                ]));
            }
            return new WP_Error('not_found', __('Booking not found', 'td-booking'), ['status' => 404]);
        }
    }
    $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_service WHERE id=%d", $booking['service_id']), ARRAY_A);
    $local_tz = wp_timezone();
    $start = new DateTime($booking['start_utc'], new DateTimeZone('UTC')); $start->setTimezone($local_tz);
    $end = new DateTime($booking['end_utc'], new DateTimeZone('UTC')); $end->setTimezone($local_tz);
    // Quiet: avoid PHP error_log noise on success
    return rest_ensure_response([
        'id' => $booking['id'],
        'service_id' => intval($booking['service_id']),
        'service' => $service ? $service['name'] : '',
        'status' => $booking['status'],
        'start_utc' => $booking['start_utc'],
        'end_utc' => $booking['end_utc'],
        'start_local' => $start->format('Y-m-d H:i:s'),
        'end_local' => $end->format('Y-m-d H:i:s'),
        'duration_min' => $service ? intval($service['duration_min']) : 0,
    ]);
}
