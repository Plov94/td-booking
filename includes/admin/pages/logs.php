<?php
echo '<div class="wrap">';
echo '<h1>' . esc_html__('Logs', 'td-booking') . '</h1>';
global $wpdb;
$table = $wpdb->prefix . 'td_audit';

// Site timezone for local display
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

// Clear logs action (with nonce)
if (isset($_GET['td_bkg_clear_logs']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'td_bkg_clear_logs')) {
    if (current_user_can('manage_td_booking')) {
        $wpdb->query("DELETE FROM {$table}");
        echo '<div class="notice notice-success"><p>' . esc_html__('Logs cleared.', 'td-booking') . '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>' . esc_html__('You do not have permission to clear logs.', 'td-booking') . '</p></div>';
    }
}

// Collect filters
$level = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';
$source = isset($_GET['source']) ? sanitize_text_field($_GET['source']) : '';
$date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : '';
$from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
$to = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : '';
$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
$staff_id = isset($_GET['staff_id']) ? intval($_GET['staff_id']) : 0;
$q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
$per_page = isset($_GET['per_page']) ? max(10, min(200, intval($_GET['per_page']))) : 30;
$page = max(1, intval($_GET['paged'] ?? 1));

$where = '1=1';
if ($level) $where .= $wpdb->prepare(' AND level=%s', $level);
if ($source) $where .= $wpdb->prepare(' AND source=%s', $source);
if ($booking_id) $where .= $wpdb->prepare(' AND booking_id=%d', $booking_id);
if ($staff_id) $where .= $wpdb->prepare(' AND staff_id=%d', $staff_id);
// Backward-compat single date
if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $where .= $wpdb->prepare(' AND DATE(ts)=%s', $date);
}
// Date range: from/to
if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where .= $wpdb->prepare(' AND ts >= %s', $from . ' 00:00:00');
}
if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where .= $wpdb->prepare(' AND ts <= %s', $to . ' 23:59:59');
}
if ($q) {
    $like = '%' . $wpdb->esc_like($q) . '%';
    $where .= $wpdb->prepare(' AND (message LIKE %s OR context LIKE %s)', $like, $like);
}

// Export CSV for current filter
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!current_user_can('manage_td_booking')) {
        wp_die(__('You do not have permission to export logs.', 'td-booking'));
    }
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=td-booking-logs.csv');
    $out = fopen('php://output', 'w');
    // Header row
    fputcsv($out, [
        /* translators: CSV header label for local time column */
        __('Time (Local)', 'td-booking'),
        __('Level', 'td-booking'),
        __('Source', 'td-booking'),
        __('Booking ID', 'td-booking'),
        __('Staff ID', 'td-booking'),
        __('Staff Name', 'td-booking'),
        __('Message', 'td-booking'),
        __('Context', 'td-booking')
    ]);
    $rows_all = $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY ts DESC", ARRAY_A);
    foreach ($rows_all as $r) {
        $ts_local = '';
        try {
            $dt = new DateTimeImmutable($r['ts'], $utc_tz);
            $ts_local = $dt->setTimezone($site_tz)->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $ts_local = $r['ts'];
        }
        $staff_name = '';
        if (function_exists('td_bkg_get_staff_safe') && !empty($r['staff_id'])) {
            $staff = td_bkg_get_staff_safe(intval($r['staff_id']));
            if ($staff && !empty($staff['display_name'])) $staff_name = $staff['display_name'];
        }
        fputcsv($out, [
            $ts_local,
            $r['level'],
            $r['source'],
            $r['booking_id'],
            $r['staff_id'],
            $staff_name,
            $r['message'],
            $r['context'],
        ]);
    }
    fclose($out);
    exit;
}

// Query paged results
$offset = ($page-1)*$per_page;
$total = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY ts DESC LIMIT %d OFFSET %d", $per_page, $offset), ARRAY_A);

// Filters
$levels = $wpdb->get_col("SELECT DISTINCT level FROM {$table}");
$sources = $wpdb->get_col("SELECT DISTINCT source FROM {$table}");

echo '<form method="get" style="margin:10px 0 12px 0">';
echo '<input type="hidden" name="page" value="td-booking-logs">';
echo '<select name="level"><option value="">' . esc_html__('All Levels', 'td-booking') . '</option>';
foreach ($levels as $l) echo '<option' . selected($level, $l, false) . '>' . esc_html($l) . '</option>';
echo '</select> ';
echo '<select name="source"><option value="">' . esc_html__('All Sources', 'td-booking') . '</option>';
foreach ($sources as $s) echo '<option' . selected($source, $s, false) . '>' . esc_html($s) . '</option>';
echo '</select> ';
echo '<label>' . esc_html__('From', 'td-booking') . ' <input type="date" name="from" value="' . esc_attr($from) . '"></label> ';
echo '<label>' . esc_html__('To', 'td-booking') . ' <input type="date" name="to" value="' . esc_attr($to) . '"></label> ';
echo '<input type="number" name="booking_id" placeholder="' . esc_attr__('Booking ID', 'td-booking') . '" value="' . ($booking_id ? intval($booking_id) : '') . '" style="width:120px"> ';
echo '<input type="number" name="staff_id" placeholder="' . esc_attr__('Staff ID', 'td-booking') . '" value="' . ($staff_id ? intval($staff_id) : '') . '" style="width:120px"> ';
echo '<input type="search" name="q" placeholder="' . esc_attr__('Search message/context', 'td-booking') . '" value="' . esc_attr($q) . '" style="min-width:220px"> ';
echo '<select name="per_page">';
foreach ([20,30,50,100,200] as $pp) {
    echo '<option value="' . $pp . '"' . selected($per_page, $pp, false) . '>' . sprintf(esc_html__('%d per page', 'td-booking'), $pp) . '</option>';
}
echo '</select> ';
echo '<button class="button button-primary">' . esc_html__('Filter', 'td-booking') . '</button> ';
// Export CSV link preserves current filters
$export_url = add_query_arg(array_merge($_GET, ['export' => 'csv']));
echo '<a href="' . esc_url($export_url) . '" class="button">' . esc_html__('Export CSV', 'td-booking') . '</a> ';
// Clear logs link (nonce)
$clear_url = wp_nonce_url(add_query_arg('td_bkg_clear_logs', 1), 'td_bkg_clear_logs');
echo '<a href="' . esc_url($clear_url) . '" class="button button-secondary" onclick="return confirm(\'' . esc_js(__('Are you sure you want to clear all logs?', 'td-booking')) . '\')">' . esc_html__('Clear Logs', 'td-booking') . '</a>';
echo '</form>';

// Table
echo '<table class="widefat fixed striped"><thead><tr>';
echo '<th>' . esc_html__('Time (Local)', 'td-booking') . '</th>';
echo '<th>' . esc_html__('Level', 'td-booking') . '</th>';
echo '<th>' . esc_html__('Source', 'td-booking') . '</th>';
echo '<th>' . esc_html__('Booking', 'td-booking') . '</th>';
echo '<th>' . esc_html__('Staff', 'td-booking') . '</th>';
echo '<th>' . esc_html__('Message', 'td-booking') . '</th>';
echo '<th>' . esc_html__('Context', 'td-booking') . '</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    // Local time display
    $ts_local = '';
    try {
        $dt = new DateTimeImmutable($r['ts'], $utc_tz);
        $ts_local = $dt->setTimezone($site_tz)->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $ts_local = $r['ts'];
    }
    echo '<td>' . esc_html($ts_local) . '</td>';
    echo '<td>' . esc_html($r['level']) . '</td>';
    echo '<td>' . esc_html($r['source']) . '</td>';
    // Booking link
    $booking_cell = '';
    if (!empty($r['booking_id'])) {
        $booking_cell = '<a href="' . esc_url(admin_url('admin.php?page=td-booking-bookings&id=' . intval($r['booking_id']))) . '">' . intval($r['booking_id']) . '</a>';
    }
    echo '<td>' . $booking_cell . '</td>';
    // Staff name + id
    $staff_text = '';
    if (!empty($r['staff_id'])) {
        $staff_text = '#' . intval($r['staff_id']);
        if (function_exists('td_bkg_get_staff_safe')) {
            $staff = td_bkg_get_staff_safe(intval($r['staff_id']));
            if ($staff && !empty($staff['display_name'])) {
                $staff_text = esc_html($staff['display_name']) . ' (' . $staff_text . ')';
            }
        }
    }
    echo '<td>' . $staff_text . '</td>';
    echo '<td>' . esc_html($r['message']) . '</td>';
    $ctx = $r['context'];
    // Pretty print JSON context if applicable
    $ctx_pp = $ctx;
    $decoded = json_decode($ctx, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $ctx_pp = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    echo '<td><pre style="white-space:pre-wrap;max-width:520px;overflow:auto;">' . esc_html($ctx_pp) . '</pre></td>';
    echo '</tr>';
}
echo '</tbody></table>';

// Pagination
$total_pages = ceil($total/$per_page);
if ($total_pages > 1) {
    echo '<div class="tablenav"><div class="tablenav-pages">';
    for ($i=1; $i<=$total_pages; $i++) {
        if ($i == $page) echo '<span class="current">' . $i . '</span> ';
        else echo '<a class="page-numbers" href="' . esc_url(add_query_arg('paged', $i)) . '">' . $i . '</a> ';
    }
    echo '</div></div>';
}
echo '</div>';
