<?php
// Handle reset of persisted filters early to allow redirect
if (isset($_GET['td_bkg_reset_filters']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'td_bkg_reset_filters')) {
	$uid = get_current_user_id();
	if ($uid) {
		delete_user_meta($uid, 'td_bkg_bookings_business_only');
		delete_user_meta($uid, 'td_bkg_bookings_tz_mode');
	}
	// Use client-side redirect to avoid header issues after output has begun
	$redir = admin_url('admin.php?page=td-booking-bookings');
	echo '<div class="wrap"><h1>' . esc_html__('Redirecting…', 'td-booking') . '</h1><script>location.replace(' . json_encode($redir) . ');</script></div>';
	exit;
}

// Handle admin delete booking action before rendering or delegating to view page
if (isset($_GET['delete']) && isset($_GET['_wpnonce'])) {
	$delete_id = intval($_GET['delete']);
	if ($delete_id > 0 && function_exists('td_bkg_can_manage') && td_bkg_can_manage() && wp_verify_nonce($_GET['_wpnonce'], 'td_bkg_booking_delete_' . $delete_id)) {
		global $wpdb;
		$booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_booking WHERE id=%d", $delete_id), ARRAY_A);
		if ($booking) {
			// Attempt CalDAV deletion if possible
			if (!empty($booking['caldav_uid']) && function_exists('td_tech') && method_exists(td_tech(), 'caldav')) {
				$creds = td_tech()->caldav()->get_credentials(intval($booking['staff_id']));
				if (function_exists('td_bkg_normalize_caldav_creds')) { $creds = td_bkg_normalize_caldav_creds($creds); }
				if ($creds && !empty($creds['url']) && !empty($creds['user']) && !empty($creds['pass'])) {
					$event_url = rtrim($creds['url'], '/') . '/' . $booking['caldav_uid'] . '.ics';
					if (function_exists('td_bkg_caldav_delete')) {
						td_bkg_caldav_delete($event_url, $creds['user'], $creds['pass']);
					}
				}
			}
			// Invalidate availability cache for the staff/day
			if (function_exists('td_bkg_availability_cache_invalidate') && !empty($booking['staff_id']) && !empty($booking['start_utc'])) {
				$day = substr($booking['start_utc'], 0, 10);
				td_bkg_availability_cache_invalidate(intval($booking['staff_id']), $day, $day);
			}
			// Delete the row
			$wpdb->delete($wpdb->prefix . 'td_booking', ['id' => $delete_id]);
			// Audit log
			if (function_exists('td_bkg_log_audit')) {
				td_bkg_log_audit('info', 'admin', 'Booking deleted (admin)', json_encode([]), $delete_id, intval($booking['staff_id']));
			}
			// Redirect with success flag
			$redir = add_query_arg(['page' => 'td-booking-bookings', 'deleted' => 1], admin_url('admin.php'));
			echo '<div class="wrap"><h1>' . esc_html__('Redirecting…', 'td-booking') . '</h1><script>location.replace(' . json_encode($redir) . ');</script></div>';
			exit;
		} else {
			$redir = add_query_arg(['page' => 'td-booking-bookings', 'delete_error' => 1], admin_url('admin.php'));
			echo '<div class="wrap"><h1>' . esc_html__('Redirecting…', 'td-booking') . '</h1><script>location.replace(' . json_encode($redir) . ');</script></div>';
			exit;
		}
	}
}

// Check if viewing individual booking
if (isset($_GET['id']) && $_GET['id']) {
	require_once TD_BKG_PATH . 'includes/admin/pages/booking-view.php';
	return;
}

echo '<h1>' . esc_html__('Bookings', 'td-booking') . '</h1>';

// Show deletion notices
if (!empty($_GET['deleted'])) {
	echo '<div class="updated notice"><p>' . esc_html__('Booking deleted.', 'td-booking') . '</p></div>';
}
if (!empty($_GET['delete_error'])) {
	echo '<div class="notice notice-error"><p>' . esc_html__('Unable to delete booking.', 'td-booking') . '</p></div>';
}

$group_enabled = get_option('td_bkg_group_enabled');
global $wpdb;

// Site timezone helper
if (function_exists('wp_timezone')) {
	$site_tz = wp_timezone();
} else {
	$tz_string = get_option('timezone_string');
	if (!$tz_string || $tz_string === '') {
		$offset = get_option('gmt_offset');
		$tz_string = $offset ? sprintf('%+03d:00', (int)$offset) : 'UTC';
	}
	$site_tz = new DateTimeZone($tz_string ?: 'UTC');
}
$utc_tz = new DateTimeZone('UTC');

// Date range filters (local/site timezone days)
$today_local_ts = function_exists('current_time') ? current_time('timestamp') : time();
$default_from = (new DateTimeImmutable('@' . $today_local_ts))->setTimezone($site_tz)->format('Y-m-d');
$default_to = (new DateTimeImmutable('@' . $today_local_ts))->setTimezone($site_tz)->modify('+21 days')->format('Y-m-d');

$from_in = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : $default_from;
$to_in = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : $default_to;

// Normalize/validate dates (Y-m-d)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_in)) { $from_in = $default_from; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_in)) { $to_in = $default_to; }

$from_local = new DateTimeImmutable($from_in . ' 00:00:00', $site_tz);
$to_local = new DateTimeImmutable($to_in . ' 23:59:59', $site_tz);

// Convert to UTC for querying
$from_utc = $from_local->setTimezone($utc_tz)->format('Y-m-d H:i:s');
$to_utc = $to_local->setTimezone($utc_tz)->format('Y-m-d H:i:s');

// Filter form
echo '<form method="get" style="margin: 10px 0 20px;">';
echo '<input type="hidden" name="page" value="td-booking-bookings">';
echo '<label>' . esc_html__('From', 'td-booking') . ': <input type="date" name="from" value="' . esc_attr($from_in) . '"></label> ';
echo '<label>' . esc_html__('To', 'td-booking') . ': <input type="date" name="to" value="' . esc_attr($to_in) . '"></label> ';
// Business days only toggle (persist per-user)
$current_user_id = get_current_user_id();
$stored_biz_only = $current_user_id ? (int) get_user_meta($current_user_id, 'td_bkg_bookings_business_only', true) : 0;
if (isset($_GET['biz'])) {
	$business_only = (int) $_GET['biz'];
	if ($current_user_id) {
		update_user_meta($current_user_id, 'td_bkg_bookings_business_only', $business_only);
	}
} else {
	$business_only = $stored_biz_only ? 1 : 0;
}
echo '<label><input type="checkbox" name="biz" value="1" ' . checked(1, $business_only, false) . '> ' . esc_html__('Business days only', 'td-booking') . '</label> ';
// Timezone display selector (persist per-user)
$user_id = get_current_user_id();
$stored_tz_mode = $user_id ? get_user_meta($user_id, 'td_bkg_bookings_tz_mode', true) : '';
$query_tz_mode = isset($_GET['tz']) ? sanitize_text_field($_GET['tz']) : '';
$tz_mode = in_array($query_tz_mode, ['site','utc','staff'], true) ? $query_tz_mode : (in_array($stored_tz_mode, ['site','utc','staff'], true) ? $stored_tz_mode : 'site');
if ($user_id && $query_tz_mode) update_user_meta($user_id, 'td_bkg_bookings_tz_mode', $tz_mode);
// Tooltip explaining Staff TZ behavior and fallback
$tz_help = esc_attr__(
	'Site TZ: Uses the site timezone. UTC: Shows times in UTC. Staff TZ: Uses the staff member\'s timezone when available; falls back to Site TZ otherwise.',
	'td-booking'
);
echo '<label>' . esc_html__('Timezone', 'td-booking') . ' ' . '<span class="dashicons dashicons-editor-help" title="' . $tz_help . '"></span>: ';
echo '<select name="tz">';
echo '<option value="site"' . selected($tz_mode, 'site', false) . '>' . esc_html__('Site TZ', 'td-booking') . '</option>';
echo '<option value="utc"' . selected($tz_mode, 'utc', false) . '>' . esc_html__('UTC', 'td-booking') . '</option>';
echo '<option value="staff"' . selected($tz_mode, 'staff', false) . '>' . esc_html__('Staff TZ', 'td-booking') . '</option>';
echo '</select></label> ';
echo '<button class="button">' . esc_html__('Filter', 'td-booking') . '</button> ';
// Reset link also clears persisted preference
$reset_url = wp_nonce_url(admin_url('admin.php?page=td-booking-bookings&td_bkg_reset_filters=1'), 'td_bkg_reset_filters');
echo '<a class="button button-secondary" href="' . esc_url($reset_url) . '">' . esc_html__('Reset', 'td-booking') . '</a>';
echo '</form>';

// Load bookings within range (UTC) and group by local date
$table_name = $wpdb->prefix . 'td_booking';
$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT * FROM {$table_name} WHERE start_utc BETWEEN %s AND %s ORDER BY start_utc ASC",
		$from_utc,
		$to_utc
	),
	ARRAY_A
);

// Decrypt PII for display when encrypted
if (function_exists('td_bkg_booking_decrypt_row')) {
    foreach ($rows as &$r) { $r = td_bkg_booking_decrypt_row($r); }
    unset($r);
}

// Show count and any errors
echo '<p><strong>' . esc_html__('Total bookings in range:', 'td-booking') . '</strong> ' . intval(count($rows)) . '</p>';
if ($wpdb->last_error) {
	echo '<div class="notice notice-error"><p><strong>' . esc_html__('Database Error:', 'td-booking') . '</strong> ' . esc_html($wpdb->last_error) . '</p></div>';
}

// Build map by local day (Y-m-d)
$by_day = [];
foreach ($rows as $row) {
	$start_dt_local = (new DateTimeImmutable($row['start_utc'], $utc_tz))->setTimezone($site_tz);
	$day_key = $start_dt_local->format('Y-m-d');
	if (!isset($by_day[$day_key])) { $by_day[$day_key] = []; }
	$by_day[$day_key][] = $row;
}

// Iterate each day in the range and render a header and table (or empty message)
$cur = new DateTimeImmutable($from_in . ' 00:00:00', $site_tz);
while ($cur <= $to_local) {
	$day_key = $cur->format('Y-m-d');
	// Rows for this day (if any) prepared earlier
	$day_rows = $by_day[$day_key] ?? [];
	// Optionally skip non-business days, but only when there are no bookings for that day
	if ($business_only) {
		$dow = (int) $cur->format('w');
		$ranges = td_bkg_get_global_hours_for_day($dow);
		// Skip the day entirely when it's closed (no ranges) AND there are no bookings for that day
		if ((!
			is_array($ranges) || count(array_filter((array)$ranges)) === 0
		) && empty($day_rows)) {
			$cur = $cur->modify('+1 day');
			continue;
		}
	}
	// Day heading localized, like: Friday, September 19, 2025
	// Use wp_date for localization if available
	if (function_exists('wp_date')) {
		$heading = wp_date('l, F j, Y', $cur->getTimestamp(), $site_tz);
	} else {
		// Fallback to PHP format (English)
		$heading = $cur->format('l, F j, Y');
	}
	echo '<h2 style="margin-top:22px;">' . esc_html($heading) . '</h2>';

	if (empty($day_rows)) {
		echo '<p style="color:#666;">' . esc_html__('No bookings for this day.', 'td-booking') . '</p>';
		$cur = $cur->modify('+1 day');
		continue;
	}

	// Determine Start column label based on selected display timezone
	$start_col_label = __('Start', 'td-booking');
	$tz_label_for_header = 'site';
	if (isset($tz_mode)) {
		$tz_label_for_header = $tz_mode;
	} else {
		// Fallback to stored selection if not available in this scope
		$uid_for_hdr = get_current_user_id();
		$stored_mode_hdr = $uid_for_hdr ? get_user_meta($uid_for_hdr, 'td_bkg_bookings_tz_mode', true) : '';
		$tz_label_for_header = in_array($stored_mode_hdr, ['site','utc','staff'], true) ? $stored_mode_hdr : 'site';
	}
	if ($tz_label_for_header === 'utc') {
		$start_col_label = __('Start (UTC)', 'td-booking');
	} elseif ($tz_label_for_header === 'staff') {
		$start_col_label = __('Start (Staff TZ)', 'td-booking');
	} else {
		$start_col_label = __('Start (Site TZ)', 'td-booking');
	}

	echo '<table class="widefat fixed striped"><thead><tr>';
	echo '<th>' . esc_html__('ID', 'td-booking') . '</th>';
	echo '<th>' . esc_html__('Service', 'td-booking') . '</th>';
	echo '<th>' . esc_html__('Customer', 'td-booking') . '</th>';
	echo '<th>' . esc_html__('Staff', 'td-booking') . '</th>';
	echo '<th>' . esc_html($start_col_label) . '</th>';
	echo '<th>' . esc_html__('Status', 'td-booking') . '</th>';
	if ($group_enabled) echo '<th>' . esc_html__('Group Size', 'td-booking') . '</th>';
	echo '<th>' . esc_html__('Actions', 'td-booking') . '</th>';
	echo '</tr></thead><tbody>';
	foreach ($day_rows as $row) {
		$service = '';
		if (!empty($row['service_id'])) {
			$service = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}td_service WHERE id=%d", $row['service_id']));
		}
		if (!$service) { $service = __('Custom / Specifically requested', 'td-booking'); }
		$staff_name = '';
		if (function_exists('td_bkg_get_staff_safe') && !empty($row['staff_id'])) {
			$staff = td_bkg_get_staff_safe(intval($row['staff_id']));
			if ($staff && !empty($staff['display_name'])) {
				$staff_name = $staff['display_name'];
			}
		}
		// Display timezone selector (persist per-user)
		// Read selection from query or user meta once per render
		static $display_tz_mode = null;
		static $display_tz_label = null;
		if ($display_tz_mode === null) {
			$user_id = get_current_user_id();
			$stored_mode = $user_id ? get_user_meta($user_id, 'td_bkg_bookings_tz_mode', true) : '';
			$query_mode = isset($_GET['tz']) ? sanitize_text_field($_GET['tz']) : '';
			$mode = in_array($query_mode, ['site','utc','staff'], true) ? $query_mode : (in_array($stored_mode, ['site','utc','staff'], true) ? $stored_mode : 'site');
			if ($user_id && $query_mode) update_user_meta($user_id, 'td_bkg_bookings_tz_mode', $mode);
			$display_tz_mode = $mode;
			$display_tz_label = ($mode === 'utc') ? __('UTC', 'td-booking') : (($mode === 'staff') ? __('Staff TZ', 'td-booking') : __('Site TZ', 'td-booking'));
		}

		// Determine timezone per row
		$row_tz = $site_tz;
		if ($display_tz_mode === 'utc') {
			$row_tz = $utc_tz;
		} elseif ($display_tz_mode === 'staff' && !empty($row['staff_id']) && function_exists('td_bkg_get_staff_timezone')) {
			$stz = td_bkg_get_staff_timezone(intval($row['staff_id']));
			if ($stz instanceof DateTimeZone) { $row_tz = $stz; }
		}
		$start_str = (new DateTimeImmutable($row['start_utc'], $utc_tz))->setTimezone($row_tz)->format('Y-m-d H:i');
		echo '<tr>';
		echo '<td>' . intval($row['id']) . '</td>';
		echo '<td>' . esc_html($service) . '</td>';
		echo '<td>' . esc_html($row['customer_name']) . '</td>';
		$staff_cell = '';
		if ($staff_name) {
			$staff_cell = esc_html($staff_name);
		}
		if (!empty($row['staff_id'])) {
			$staff_cell .= ($staff_cell ? ' ' : '') . '<span class="description">(' . sprintf(esc_html__('ID: %d', 'td-booking'), intval($row['staff_id'])) . ')</span>';
		}
		echo '<td>' . $staff_cell . '</td>';
	echo '<td>' . esc_html($start_str) . '</td>';
		echo '<td>' . esc_html($row['status']) . '</td>';
		if ($group_enabled) echo '<td>' . intval($row['group_size']) . '</td>';
		$actions_html = '<a href="' . admin_url('admin.php?page=td-booking-bookings&id=' . intval($row['id'])) . '">' . esc_html__('View', 'td-booking') . '</a>';
		if (function_exists('td_bkg_can_manage') && td_bkg_can_manage()) {
			$del_url = wp_nonce_url(admin_url('admin.php?page=td-booking-bookings&delete=' . intval($row['id'])), 'td_bkg_booking_delete_' . intval($row['id']));
			$actions_html .= ' | <a href="' . esc_url($del_url) . '" style="color:#d63638;" onclick="return confirm(\'' . esc_js(__('Delete this booking? This will remove it permanently.', 'td-booking')) . '\')">' . esc_html__('Delete', 'td-booking') . '</a>';
		}
		echo '<td>' . $actions_html . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';

	$cur = $cur->modify('+1 day');
}
