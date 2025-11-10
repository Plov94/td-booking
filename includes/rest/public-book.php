<?php
defined('ABSPATH') || exit;

add_action('rest_api_init', function() {
    register_rest_route('td/v1', '/book', [
        'methods' => 'POST',
        'callback' => 'td_bkg_rest_book',
        'permission_callback' => '__return_true',
    ]);
});

function td_bkg_rest_book($request) {
    $group_size = 1;
    if (get_option('td_bkg_group_enabled')) {
        $group_size = max(1, intval($request['group_size'] ?? 1));
    }
    if (get_option('td_bkg_degraded')) {
        return new WP_Error('technicians_missing', __('Technicians plugin missing', 'td-booking'), ['status' => 503]);
    }
    $service_id = intval($request['service_id'] ?? 0);
    $start_utc = sanitize_text_field($request['start_utc'] ?? '');
    $customer = $request['customer'] ?? [];
    $name = sanitize_text_field($customer['name'] ?? '');
    $email = sanitize_email($customer['email'] ?? '');
    $wc_enabled = get_option('td_bkg_wc_enabled');
    $is_wc = !empty($request['wc']) && $wc_enabled;
    $requested_staff_id = intval($request['staff_id'] ?? 0);
    $agnostic = !empty($request['agnostic']);
    if (((!$service_id && !($agnostic && $requested_staff_id > 0)) || !$start_utc || !$name || !$email)) {
        return new WP_Error('invalid_params', __('Missing required parameters', 'td-booking'), ['status' => 400]);
    }
    global $wpdb;
    $service = null;
    if ($service_id > 0) {
        $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_service WHERE id=%d AND active=1", $service_id), ARRAY_A);
        if (!$service) return new WP_Error('not_found', __('Service not found', 'td-booking'), ['status' => 404]);
    } else if ($agnostic && $requested_staff_id > 0) {
        // Synthetic service for staff-agnostic booking
        $default_dur = intval(get_option('td_bkg_default_duration_minutes', 30));
        if ($default_dur <= 0) { $default_dur = 30; }
        $service = [
            'id' => 0,
            'name' => __('Custom / Specifically requested', 'td-booking'),
            'duration_min' => $default_dur,
            'buffer_min' => 0,
            'price' => 0,
            'active' => 1,
        ];
        $service_id = 0; // persist as 0
    } else {
        return new WP_Error('invalid_params', __('Service required', 'td-booking'), ['status' => 400]);
    }
    // Fetch all mapped staff for this service
    $mapped_staff = ($service_id > 0) ? $wpdb->get_col($wpdb->prepare("SELECT staff_id FROM {$wpdb->prefix}td_service_staff WHERE service_id=%d", $service_id)) : [];
    if (empty($mapped_staff) && !$agnostic) return new WP_Error('no_tech', __('No technician mapped', 'td-booking'), ['status' => 400]);

    // Normalize start to standard format if ISO provided; expect UTC input
    $start_norm = str_replace('T', ' ', substr($start_utc, 0, 19));
    if (empty($start_norm) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start_norm)) {
        return new WP_Error('invalid_time', __('Invalid start time format', 'td-booking'), ['status' => 400]);
    }

    // Verify availability right now before booking (per-staff)
    require_once dirname(__DIR__) . '/availability/engine.php';
    $duration = intval($service['duration_min']);
    // Expand verification range to [start, start+duration] so engine can emit the exact slot
    $verify_to = gmdate('Y-m-d H:i:s', strtotime($start_norm) + $duration * 60);
    $engine_opts = ['return_staff' => true];
    if ($requested_staff_id > 0) {
        $engine_opts['override_staff_ids'] = [$requested_staff_id];
        if ($agnostic) { $engine_opts['ignore_mapping'] = true; }
    }
    $cand = td_bkg_availability_engine($service_id, $start_norm, $verify_to, $duration, $engine_opts);
    // Debug log removed
    // Choose an actually available staff from mapped list at this exact time
    $selected_staff_id = 0;
    $target_start_iso = gmdate('Y-m-d\TH:i:s\Z', strtotime($start_norm));
    foreach ($cand as $slot) {
        $sid = intval($slot['staff_id'] ?? 0);
        // If not agnostic, ensure staff is mapped to service
        $mapped_ok = $agnostic ? true : in_array($sid, array_map('intval', $mapped_staff), true);
        if ($sid && $mapped_ok && $slot['start_utc'] === $target_start_iso) {
            // If a specific staff was requested (from shortcode), enforce it
            if ($requested_staff_id > 0 && $sid !== $requested_staff_id) {
                continue;
            }
            // If group bookings enabled, ensure capacity remains
            if (get_option('td_bkg_group_enabled')) {
                $avail_group = intval($slot['available_group'] ?? 0);
                if ($avail_group <= 0 || $avail_group < $group_size) {
                    continue; // not enough capacity on this staff
                }
            }
            $selected_staff_id = $sid;
            break;
        }
    }
    if (!$selected_staff_id) {
        if (function_exists('td_bkg_log_audit')) {
            td_bkg_log_audit('warn', 'booking', 'Requested slot no longer available', json_encode([
                'service_id' => $service_id,
                'start_utc' => $start_norm,
                'duration' => $duration,
                'group_size' => $group_size
            ]));
        }
        return new WP_Error('not_available', __('Selected time is no longer available. Please choose another slot.', 'td-booking'), ['status' => 409]);
    }
    
    // Invalidate staff availability cache for this staff and date
    require_once dirname(__DIR__) . '/availability/cache.php';
    $from = substr($start_utc, 0, 10); // 'Y-m-d'
    $to = $from;
    td_bkg_availability_cache_invalidate($selected_staff_id, $from, $to);
    if (function_exists('td_bkg_log_audit')) {
        td_bkg_log_audit('info', 'cache', 'Availability cache invalidated', json_encode([
            'staff_id' => $selected_staff_id,
            'from' => $from,
            'to' => $to
        ]));
    }
    $end_utc = gmdate('Y-m-d H:i:s', strtotime($start_norm) + $duration * 60);
    $ics_token = wp_generate_password(32, false, false);
    $status = $is_wc ? 'pending' : 'confirmed';
    // Debug log removed
    // Prepare fields and apply PII encryption + hashes
    $fields = [
        'service_id' => $service_id,
        'staff_id' => $selected_staff_id,
        'status' => $status,
        'start_utc' => $start_norm,
        'end_utc' => $end_utc,
        'customer_name' => $name,
        'ics_token' => $ics_token,
        'group_size' => $group_size,
        'created_at' => current_time('mysql', 1),
        'updated_at' => current_time('mysql', 1),
    ];
    $fields = td_bkg_booking_apply_pii($fields, [
        'email' => $email,
        'phone' => sanitize_text_field($customer['phone'] ?? ''),
        'address' => isset($customer['address']) ? $customer['address'] : '',
        'notes' => sanitize_textarea_field($request['notes'] ?? ''),
    ]);
    $wpdb->insert($wpdb->prefix . 'td_booking', $fields);
    $booking_id = $wpdb->insert_id;
    if (function_exists('td_bkg_log_audit')) {
        td_bkg_log_audit('info', 'booking', 'Booking row inserted', json_encode([
            'service_id' => $service_id,
            'staff_id' => $selected_staff_id,
            'start_utc' => $start_norm,
            'end_utc' => $end_utc,
            'group_size' => $group_size,
            'status' => $status
        ]), $booking_id, $selected_staff_id);
    }
    if ($is_wc) {
        // WooCommerce flow: create/find product, add to cart, return checkout URL
        if (!class_exists('WC_Product')) {
            return new WP_Error('no_wc', __('WooCommerce not active', 'td-booking'), ['status' => 500]);
        }
        $product_id = get_option('td_bkg_wc_product_' . $service_id);
        if (!$product_id || !get_post($product_id)) {
            $product = new WC_Product_Simple();
            $product->set_name($service['name']);
            $product->set_price(floatval($service['price'] ?? 1));
            $product->set_catalog_visibility('hidden');
            $product->set_status('private');
            $product_id = $product->save();
            update_option('td_bkg_wc_product_' . $service_id, $product_id);
        }
        // Add to cart and get checkout URL
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart($product_id, 1, 0, [], [
            'td_bkg_booking_id' => $booking_id,
            'td_bkg_service_id' => $service_id,
            'td_bkg_start_utc' => $start_utc
        ]);
        $checkout_url = wc_get_checkout_url();
        if (function_exists('td_bkg_log_audit')) {
            td_bkg_log_audit('info', 'woocommerce', 'Booking pending payment (WC)', json_encode([
                'booking_id' => $booking_id,
                'product_id' => $product_id,
                'checkout_url' => $checkout_url
            ]), $booking_id, $selected_staff_id);
        }
        return rest_ensure_response(['checkout_url' => $checkout_url]);
    }
    $uid = function_exists('td_bkg_caldav_uid') ? td_bkg_caldav_uid($booking_id) : ('tdbkg-' . $booking_id);
    // CalDAV integration
    $caldav_status = 'confirmed';
    $caldav_uid = $uid;
    $caldav_etag = '';
    $fail_reason = '';
    if (function_exists('td_tech') && method_exists(td_tech(), 'caldav')) {
        $creds = td_tech()->caldav()->get_credentials($selected_staff_id);
        if (function_exists('td_bkg_normalize_caldav_creds')) { $creds = td_bkg_normalize_caldav_creds($creds); }
        if ($creds && !empty($creds['url']) && !empty($creds['user']) && !empty($creds['pass'])) {
            // For CalDAV storage, omit METHOD (use 'STORE' to suppress iTIP METHOD)
            $ics = function_exists('td_bkg_caldav_ics') ? td_bkg_caldav_ics([
                'id' => $booking_id,
                'start_utc' => $start_utc,
                'end_utc' => $end_utc,
                'customer_name' => $name,
                'notes' => $request['notes'] ?? '',
                'customer_address_json' => isset($customer['address']) ? td_bkg_json_encode($customer['address']) : '',
            ], $service, 'STORE') : '';
            $resname = function_exists('td_bkg_caldav_resource_name') ? td_bkg_caldav_resource_name($uid) : $uid;
            $event_url = rtrim($creds['url'], '/') . '/' . $resname . '.ics';
            if (function_exists('td_bkg_caldav_url_is_root') && td_bkg_caldav_url_is_root($creds['url'])) {
                if (function_exists('td_bkg_log_audit')) {
                    td_bkg_log_audit('warn', 'caldav', 'CalDAV base URL appears to be a root DAV endpoint; expected calendar collection URL', json_encode([
                        'base_url' => $creds['url'],
                        'event_url' => $event_url,
                    ]), $booking_id, $selected_staff_id);
                }
            }
            $res = td_bkg_caldav_put($event_url, $ics, $creds['user'], $creds['pass']);
            if ($res['status'] >= 200 && $res['status'] < 300) {
                $caldav_etag = $res['etag'] ?? '';
                $caldav_status = 'confirmed';
            } else {
                $caldav_status = 'failed_sync';
                // Mask credentials in URL; keep host/path for diagnostics
                $url_parts = parse_url($event_url);
                $safe_host = $url_parts ? ($url_parts['scheme'] . '://' . ($url_parts['host'] ?? 'host') . (isset($url_parts['port']) ? ':' . $url_parts['port'] : '') . ($url_parts['path'] ?? '')) : '';
                $fail_reason = ($res['error'] ?: ('HTTP ' . $res['status'])) . ' @ ' . $safe_host;
                if (function_exists('td_bkg_log_audit')) {
                    td_bkg_log_audit('error', 'caldav', 'CalDAV PUT failed', json_encode([
                        'status' => $res['status'],
                        'error' => $res['error'],
                        'url' => $safe_host
                    ]), $booking_id, $selected_staff_id);
                }
            }
        } else {
            $caldav_status = 'failed_sync';
            $fail_reason = 'Missing CalDAV credentials';
        }
    } else {
        $caldav_status = 'failed_sync';
        $fail_reason = 'TD Technicians CalDAV unavailable';
    }
    $wpdb->update($wpdb->prefix . 'td_booking', [
        'status' => $caldav_status,
        'caldav_uid' => $caldav_uid,
        'caldav_etag' => $caldav_etag,
    ], ['id' => $booking_id]);
    if (function_exists('td_bkg_log_audit')) {
        if ($caldav_status === 'failed_sync') {
            td_bkg_log_audit('error', 'caldav', 'CalDAV sync failed', $fail_reason, $booking_id, $selected_staff_id);
            if (function_exists('td_bkg_trigger_caldav_sync_failed')) {
                td_bkg_trigger_caldav_sync_failed($booking_id, $fail_reason, []);
            }
        } else {
            td_bkg_log_audit('info', 'caldav', 'CalDAV sync successful', json_encode(['etag' => $caldav_etag]), $booking_id, $selected_staff_id);
            if (function_exists('td_bkg_trigger_caldav_sync_success')) {
                td_bkg_trigger_caldav_sync_success($booking_id, ['etag' => $caldav_etag]);
            }
        }
    }
    // Send confirmation email with ICS
    $ics = function_exists('td_bkg_ics') ? td_bkg_ics([
        'uid' => $uid,
        'dtstart' => gmdate('Ymd\THis\Z', strtotime($start_utc)),
        'dtend' => gmdate('Ymd\THis\Z', strtotime($end_utc)),
        'summary' => $service['name'] . ' – ' . $name,
        'description' => $request['notes'] ?? '',
    ]) : '';
    if (function_exists('td_bkg_mailer')) {
        $msg = '<p>' . esc_html__('Your booking is confirmed.', 'td-booking') . '</p>';
        if (!empty($ics_token)) {
            $cancel_url = function_exists('td_bkg_public_cancel_url') ? td_bkg_public_cancel_url($booking_id, $ics_token) : '';
            $reschedule_url = function_exists('td_bkg_public_reschedule_url') ? td_bkg_public_reschedule_url($booking_id, $ics_token) : '';
            $msg .= '<p><a href="' . esc_url($reschedule_url) . '" style="margin-right:12px">' . __('Reschedule this booking', 'td-booking') . '</a>';
            $msg .= '<a href="' . esc_url($cancel_url) . '">' . __('Cancel this booking', 'td-booking') . '</a></p>';
        }
        td_bkg_mailer($email, __('Booking Confirmation', 'td-booking'), $msg, $ics, 'confirmation', [
            'id' => $booking_id,
            'ics_token' => $ics_token
        ]);
    }
    // Fire booking created/confirmed hooks for integrations
    if (function_exists('td_bkg_trigger_booking_created')) {
        td_bkg_trigger_booking_created($booking_id, [
            'service_id' => $service_id,
            'staff_id' => $selected_staff_id,
            'start_utc' => $start_norm,
            'end_utc' => $end_utc,
            'status' => $caldav_status,
        ]);
    }
    if ($caldav_status === 'confirmed' && function_exists('td_bkg_trigger_booking_confirmed')) {
        td_bkg_trigger_booking_confirmed($booking_id, [
            'service_id' => $service_id,
            'staff_id' => $selected_staff_id,
            'start_utc' => $start_norm,
            'end_utc' => $end_utc,
            'status' => $caldav_status,
        ]);
    }
    return rest_ensure_response([
        'booking_id' => $booking_id,
        'status' => $caldav_status,
        'start_utc' => $start_utc,
        'end_utc' => $end_utc,
    ]);
}
