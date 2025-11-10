<?php
// Admin page for managing staff-wide breaks and holidays
// Use the plugin's capability consistently
if (!current_user_can('manage_td_booking')) return;
global $wpdb;
$table = $wpdb->prefix . 'td_staff_breaks';

// Handle add/edit/delete actions
if (isset($_POST['td_bkg_break_save']) && check_admin_referer('td_bkg_break_save')) {
    $start_utc = sanitize_text_field($_POST['start_utc']);
    $end_utc = sanitize_text_field($_POST['end_utc']);
    $type = sanitize_text_field($_POST['type']);
    $notes = sanitize_textarea_field($_POST['notes']);
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id > 0) {
        $wpdb->update($table, [
            'start_utc' => $start_utc,
            'end_utc' => $end_utc,
            'type' => $type,
            'notes' => $notes,
            'updated_at' => current_time('mysql', 1),
        ], ['id' => $id]);
    } else {
        $wpdb->insert($table, [
            'staff_id' => 0, // 0 = all staff
            'start_utc' => $start_utc,
            'end_utc' => $end_utc,
            'type' => $type,
            'notes' => $notes,
            'created_at' => current_time('mysql', 1),
            'updated_at' => current_time('mysql', 1),
        ]);
    }
    echo '<div class="updated"><p>' . esc_html__('Break/holiday saved.', 'td-booking') . '</p></div>';
}
if (isset($_GET['delete']) && check_admin_referer('td_bkg_break_delete_' . intval($_GET['delete']))) {
    $wpdb->delete($table, ['id' => intval($_GET['delete'])]);
    echo '<div class="updated"><p>' . esc_html__('Break/holiday deleted.', 'td-booking') . '</p></div>';
}

// List all staff-wide breaks/holidays
$breaks = $wpdb->get_results("SELECT * FROM $table WHERE staff_id = 0 ORDER BY start_utc DESC", ARRAY_A);
echo '<div class="wrap">';
echo '<h1>' . esc_html__('Staff-wide Breaks & Holidays', 'td-booking') . '</h1>';
// Add/edit form
$id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$row = ['start_utc' => '', 'end_utc' => '', 'type' => 'break', 'notes' => ''];
if ($id > 0) {
    $row_db = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
    if ($row_db) $row = $row_db;
}
echo '<h2>' . ($id ? esc_html__('Edit', 'td-booking') : esc_html__('Add', 'td-booking')) . ' ' . esc_html__('Break/Holiday', 'td-booking') . '</h2>';
echo '<form method="post">';
wp_nonce_field('td_bkg_break_save');
echo '<input type="hidden" name="id" value="' . esc_attr($id) . '">';
echo '<table class="form-table">';
echo '<tr><th>' . esc_html__('Start (UTC)', 'td-booking') . '</th><td><input type="datetime-local" name="start_utc" value="' . esc_attr($row['start_utc']) . '" required></td></tr>';
echo '<tr><th>' . esc_html__('End (UTC)', 'td-booking') . '</th><td><input type="datetime-local" name="end_utc" value="' . esc_attr($row['end_utc']) . '" required></td></tr>';
echo '<tr><th>' . esc_html__('Type', 'td-booking') . '</th><td><select name="type"><option value="break"' . ($row['type']=='break'?' selected':'') . '>' . esc_html__('Break', 'td-booking') . '</option><option value="holiday"' . ($row['type']=='holiday'?' selected':'') . '>' . esc_html__('Holiday', 'td-booking') . '</option></select></td></tr>';
echo '<tr><th>' . esc_html__('Notes', 'td-booking') . '</th><td><textarea name="notes">' . esc_textarea($row['notes']) . '</textarea></td></tr>';
echo '</table>';
echo '<p><input type="submit" name="td_bkg_break_save" class="button-primary" value="' . esc_attr__('Save', 'td-booking') . '"></p>';
echo '</form>';
// List table
if ($breaks) {
    echo '<h2>' . esc_html__('Existing Staff-wide Breaks & Holidays', 'td-booking') . '</h2>';
    echo '<table class="widefat"><thead><tr><th>' . esc_html__('Start (UTC)', 'td-booking') . '</th><th>' . esc_html__('End (UTC)', 'td-booking') . '</th><th>' . esc_html__('Type', 'td-booking') . '</th><th>' . esc_html__('Notes', 'td-booking') . '</th><th>' . esc_html__('Actions', 'td-booking') . '</th></tr></thead><tbody>';
    foreach ($breaks as $b) {
        echo '<tr>';
        echo '<td>' . esc_html($b['start_utc']) . '</td>';
        echo '<td>' . esc_html($b['end_utc']) . '</td>';
    // Localize type label instead of using ucfirst
    $type_label = ($b['type'] === 'holiday') ? __('Holiday', 'td-booking') : __('Break', 'td-booking');
    echo '<td>' . esc_html($type_label) . '</td>';
        echo '<td>' . esc_html($b['notes']) . '</td>';
        echo '<td><a href="?page=td-bkg-staff-breaks&edit=' . intval($b['id']) . '" class="button">' . esc_html__('Edit', 'td-booking') . '</a> ';
        echo '<a href="?page=td-bkg-staff-breaks&delete=' . intval($b['id']) . '&_wpnonce=' . wp_create_nonce('td_bkg_break_delete_' . intval($b['id'])) . '" class="button" onclick="return confirm(\'' . esc_js(__('Delete this break/holiday?', 'td-booking')) . '\')">' . esc_html__('Delete', 'td-booking') . '</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';
