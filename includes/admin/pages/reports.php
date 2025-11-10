<?php
defined('ABSPATH') || exit;

// TD Booking Reports Page (feature-flagged)

if (!function_exists('td_bkg_reports_page_cb')) {
function td_bkg_reports_page_cb() {
    global $wpdb;
    echo '<div class="wrap"><h1>' . esc_html__('Booking Reports', 'td-booking') . '</h1>';

    // Timezones
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

    // Build filters
    $today_local_ts = function_exists('current_time') ? current_time('timestamp') : time();
    $default_from = (new DateTimeImmutable('@' . $today_local_ts))->setTimezone($site_tz)->modify('-30 days')->format('Y-m-d');
    $default_to = (new DateTimeImmutable('@' . $today_local_ts))->setTimezone($site_tz)->format('Y-m-d');

    $from_in = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : $default_from;
    $to_in = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : $default_to;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_in)) { $from_in = $default_from; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_in)) { $to_in = $default_to; }
    $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $service_id = isset($_GET['service_id']) ? intval($_GET['service_id']) : 0;
    $staff_id = isset($_GET['staff_id']) ? intval($_GET['staff_id']) : 0;
    $source = isset($_GET['source']) ? sanitize_text_field($_GET['source']) : '';

    // Convert date range (local days) to UTC for querying
    $from_local = new DateTimeImmutable($from_in . ' 00:00:00', $site_tz);
    $to_local = new DateTimeImmutable($to_in . ' 23:59:59', $site_tz);
    $from_utc = $from_local->setTimezone($utc_tz)->format('Y-m-d H:i:s');
    $to_utc = $to_local->setTimezone($utc_tz)->format('Y-m-d H:i:s');

    // Where clause
    $where = '1=1';
    $where .= $wpdb->prepare(' AND start_utc BETWEEN %s AND %s', $from_utc, $to_utc);
    if ($status && in_array($status, ['pending','confirmed','cancelled','conflicted','failed_sync'], true)) {
        $where .= $wpdb->prepare(' AND status=%s', $status);
    }
    if ($service_id > 0) {
        $where .= $wpdb->prepare(' AND service_id=%d', $service_id);
    }
    if ($staff_id > 0) {
        $where .= $wpdb->prepare(' AND staff_id=%d', $staff_id);
    }
    if ($source && in_array($source, ['public','admin'], true)) {
        $where .= $wpdb->prepare(' AND source=%s', $source);
    }

    // Services for filter dropdown
    $services = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}td_service ORDER BY name ASC", ARRAY_A);

    // Filters UI
    echo '<form method="get" style="margin: 10px 0 16px;">';
    echo '<input type="hidden" name="page" value="td-bkg-reports">';
    echo '<label>' . esc_html__('From', 'td-booking') . ': <input type="date" name="from" value="' . esc_attr($from_in) . '"></label> ';
    echo '<label>' . esc_html__('To', 'td-booking') . ': <input type="date" name="to" value="' . esc_attr($to_in) . '"></label> ';
    echo '<label>' . esc_html__('Status', 'td-booking') . ': <select name="status">';
    $statuses = ['' => __('All', 'td-booking'), 'pending'=>__('Pending', 'td-booking'), 'confirmed'=>__('Confirmed', 'td-booking'), 'cancelled'=>__('Cancelled', 'td-booking'), 'conflicted'=>__('Conflicted', 'td-booking'), 'failed_sync'=>__('Failed Sync', 'td-booking')];
    foreach ($statuses as $val => $label) {
        echo '<option value="' . esc_attr($val) . '"' . selected($status, $val, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label> ';
    echo '<label>' . esc_html__('Service', 'td-booking') . ': <select name="service_id"><option value="0">' . esc_html__('All Services', 'td-booking') . '</option>';
    foreach ($services as $svc) {
        echo '<option value="' . intval($svc['id']) . '"' . selected($service_id, intval($svc['id']), false) . '>' . esc_html($svc['name']) . '</option>';
    }
    echo '</select></label> ';
    echo '<label>' . esc_html__('Staff ID', 'td-booking') . ': <input type="number" name="staff_id" value="' . ($staff_id ? intval($staff_id) : '') . '" style="width:120px"></label> ';
    echo '<label>' . esc_html__('Source', 'td-booking') . ': <select name="source">';
    $sources = ['' => __('All', 'td-booking'), 'public'=>__('Public', 'td-booking'), 'admin'=>__('Admin', 'td-booking')];
    foreach ($sources as $val => $label) {
        echo '<option value="' . esc_attr($val) . '"' . selected($source, $val, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label> ';
    echo '<button class="button button-primary">' . esc_html__('Apply Filters', 'td-booking') . '</button> ';
    // Export link preserves filters
    $export_url = add_query_arg(array_merge($_GET, ['export' => 'csv']));
    echo '<a href="' . esc_url($export_url) . '" class="button">' . esc_html__('Export CSV (filtered)', 'td-booking') . '</a>';
    echo '</form>';

    // Handle CSV export via GET similar to Logs page
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        if (!current_user_can('manage_td_booking')) {
            wp_die(__('You do not have permission to export bookings.', 'td-booking'));
        }
        $group_enabled = get_option('td_bkg_group_enabled');
        td_bkg_export_bookings_csv_filtered($where, $group_enabled);
        // td_bkg_export_bookings_csv_filtered will exit
    }

    // Totals scoped to filters
    $total = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}td_booking WHERE {$where}"));
    if ($total === 0) {
        echo '<p style="font-size:1.2em; color:#666; margin-top:2em;">' . esc_html__('No booking data matches the current filters.', 'td-booking') . '</p>';
        echo '</div>';
        return;
    }

    $confirmed = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}td_booking WHERE {$where} AND status='confirmed'"));
    $cancelled = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}td_booking WHERE {$where} AND status='cancelled'"));
    $pending = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}td_booking WHERE {$where} AND status='pending'"));

    // Revenue: only if an 'amount' column exists on td_booking
    $revenue = null;
    $amount_column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}td_booking LIKE 'amount'");
    if ($amount_column_exists) {
        $rev_val = $wpdb->get_var("SELECT SUM(amount) FROM {$wpdb->prefix}td_booking WHERE {$where} AND status='confirmed'");
        $revenue = $rev_val ? floatval($rev_val) : 0.0;
    }

    echo '<div class="notice inline" style="padding:10px 12px;">';
    echo '<strong>' . esc_html__('In range', 'td-booking') . ':</strong> ' . esc_html($from_in) . ' – ' . esc_html($to_in) . ' &nbsp; ';
    echo '<strong>' . esc_html__('Total', 'td-booking') . ':</strong> ' . intval($total) . ' &nbsp; ';
    echo '<strong>' . esc_html__('Confirmed', 'td-booking') . ':</strong> ' . intval($confirmed) . ' &nbsp; ';
    echo '<strong>' . esc_html__('Pending', 'td-booking') . ':</strong> ' . intval($pending) . ' &nbsp; ';
    echo '<strong>' . esc_html__('Cancelled', 'td-booking') . ':</strong> ' . intval($cancelled);
    if ($revenue !== null) {
        echo ' &nbsp; <strong>' . esc_html__('Revenue', 'td-booking') . ':</strong> ' . esc_html(number_format_i18n($revenue, 2));
    } else {
        echo ' &nbsp; <em style="color:#666;">' . esc_html__('Revenue unavailable (no amount column).', 'td-booking') . '</em>';
    }
    echo '</div>';

    // Bookings by Service (name + count)
    echo '<h2 style="margin-top:18px;">' . esc_html__('Bookings by Service', 'td-booking') . '</h2>';
    $by_service = $wpdb->get_results(
        "SELECT b.service_id, COALESCE(s.name, CONCAT('Service #', b.service_id)) as service_name, COUNT(*) as cnt
         FROM {$wpdb->prefix}td_booking b
         LEFT JOIN {$wpdb->prefix}td_service s ON s.id = b.service_id
         WHERE {$where}
         GROUP BY b.service_id, service_name
         ORDER BY cnt DESC",
        ARRAY_A
    );
    if (!empty($by_service)) {
        echo '<table class="widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('Service', 'td-booking') . '</th><th>' . esc_html__('Count', 'td-booking') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($by_service as $row) {
            echo '<tr><td>' . esc_html($row['service_name']) . '</td><td>' . intval($row['cnt']) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p style="color:#666;">' . esc_html__('No data.', 'td-booking') . '</p>';
    }

    // Bookings by Staff (name + count)
    echo '<h2 style="margin-top:18px;">' . esc_html__('Bookings by Staff', 'td-booking') . '</h2>';
    $by_staff = $wpdb->get_results(
        "SELECT staff_id, COUNT(*) as cnt
         FROM {$wpdb->prefix}td_booking
         WHERE {$where}
         GROUP BY staff_id
         ORDER BY cnt DESC",
        ARRAY_A
    );
    if (!empty($by_staff)) {
        echo '<table class="widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('Staff', 'td-booking') . '</th><th>' . esc_html__('Count', 'td-booking') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($by_staff as $row) {
            $staff_label = '#' . intval($row['staff_id']);
            if (!empty($row['staff_id']) && function_exists('td_bkg_get_staff_safe')) {
                $staff = td_bkg_get_staff_safe(intval($row['staff_id']));
                if ($staff && !empty($staff['display_name'])) {
                    $staff_label = esc_html($staff['display_name']) . ' (#' . intval($row['staff_id']) . ')';
                }
            }
            echo '<tr><td>' . $staff_label . '</td><td>' . intval($row['cnt']) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p style="color:#666;">' . esc_html__('No data.', 'td-booking') . '</p>';
    }

    // Bookings by Day (Site TZ)
    echo '<h2 style="margin-top:18px;">' . esc_html__('Bookings by Day (Site TZ)', 'td-booking') . '</h2>';
    $rows = $wpdb->get_results("SELECT id, start_utc FROM {$wpdb->prefix}td_booking WHERE {$where}", ARRAY_A);
    $by_day = [];
    foreach ($rows as $r) {
        try {
            $dt_local = (new DateTimeImmutable($r['start_utc'], $utc_tz))->setTimezone($site_tz);
            $key = $dt_local->format('Y-m-d');
            $by_day[$key] = isset($by_day[$key]) ? ($by_day[$key] + 1) : 1;
        } catch (Exception $e) {}
    }
    ksort($by_day);
    if (!empty($by_day)) {
        echo '<table class="widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('Day', 'td-booking') . '</th><th>' . esc_html__('Count', 'td-booking') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($by_day as $day => $cnt) {
            echo '<tr><td>' . esc_html($day) . '</td><td>' . intval($cnt) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p style="color:#666;">' . esc_html__('No data.', 'td-booking') . '</p>';
    }

    echo '</div>';
}
}

// CSV export (filtered)
if (!function_exists('td_bkg_export_bookings_csv_filtered')) {
function td_bkg_export_bookings_csv_filtered($where_sql, $group_enabled = false) {
    global $wpdb;
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=td-bookings-' . date('Ymd-Hi') . '.csv');
    $out = fopen('php://output', 'w');
    // Query rows for export matching current filters
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}td_booking WHERE {$where_sql} ORDER BY start_utc ASC", ARRAY_A);
    if (!$rows) {
        // Write header only if no rows
    fputcsv($out, ['id','service_id','staff_id','status','start_utc','end_utc','customer_name','customer_email','customer_phone','customer_address_json','group_size','sms_reminder_sent','notes','caldav_uid','caldav_etag','source','idempotency_key','ics_token','created_at','updated_at']);
        fclose($out);
        exit;
    }
    $header = array_keys($rows[0]);
    if ($group_enabled && !in_array('group_size', $header)) $header[] = 'group_size';
    fputcsv($out, $header);
    foreach ($rows as $row) {
        if ($group_enabled && !isset($row['group_size'])) $row['group_size'] = 1;
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
}

// Backward compatibility: old POST export calls now route to the filtered export
if (!function_exists('td_bkg_export_csv')) {
function td_bkg_export_csv($group_enabled = false) {
    global $wpdb;
    // Rebuild a simple where from current GET (if any), else export all
    $where = '1=1';
    // Optional simple filters
    $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $service_id = isset($_GET['service_id']) ? intval($_GET['service_id']) : 0;
    $staff_id = isset($_GET['staff_id']) ? intval($_GET['staff_id']) : 0;
    if ($status && in_array($status, ['pending','confirmed','cancelled','conflicted','failed_sync'], true)) {
        $where .= $wpdb->prepare(' AND status=%s', $status);
    }
    if ($service_id > 0) { $where .= $wpdb->prepare(' AND service_id=%d', $service_id); }
    if ($staff_id > 0) { $where .= $wpdb->prepare(' AND staff_id=%d', $staff_id); }
    // Date range if present
    if (!empty($_GET['from']) && !empty($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])) {
        // Use site tz to convert to UTC bounds
        if (function_exists('wp_timezone')) { $site_tz = wp_timezone(); } else { $site_tz = new DateTimeZone(get_option('timezone_string') ?: 'UTC'); }
        $utc_tz = new DateTimeZone('UTC');
        $from_local = new DateTimeImmutable(sanitize_text_field($_GET['from']) . ' 00:00:00', $site_tz);
        $to_local = new DateTimeImmutable(sanitize_text_field($_GET['to']) . ' 23:59:59', $site_tz);
        $from_utc = $from_local->setTimezone($utc_tz)->format('Y-m-d H:i:s');
        $to_utc = $to_local->setTimezone($utc_tz)->format('Y-m-d H:i:s');
        $where .= $wpdb->prepare(' AND start_utc BETWEEN %s AND %s', $from_utc, $to_utc);
    }
    td_bkg_export_bookings_csv_filtered($where, $group_enabled);
}
}
