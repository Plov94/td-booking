<?php
echo '<h1>' . esc_html__('Services', 'td-booking') . '</h1>';
// Service list and add/edit UI below.
echo '<div class="wrap">';
echo '<h1>' . esc_html__('Services', 'td-booking') . ' <a href="' . admin_url('admin.php?page=td-booking&action=add') . '" class="page-title-action">' . esc_html__('Add New', 'td-booking') . '</a></h1>';

// Handle delete action
if (isset($_GET['delete']) && check_admin_referer('td_bkg_service_delete_' . intval($_GET['delete']))) {
    $service_id = intval($_GET['delete']);
    global $wpdb;
    
    // Delete associated bookings first
    $booking_table = $wpdb->prefix . 'td_booking';
    $booking_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$booking_table} WHERE service_id = %d", $service_id));
    if ($booking_count > 0) {
        $wpdb->delete($booking_table, ['service_id' => $service_id]);
    }
    
    // Delete associated staff mappings
    $staff_table = $wpdb->prefix . 'td_service_staff';
    $wpdb->delete($staff_table, ['service_id' => $service_id]);
    
    // Delete the service
    $service_table = $wpdb->prefix . 'td_service';
    $result = $wpdb->delete($service_table, ['id' => $service_id]);
    
	if ($result) {
		$message = __('Service deleted successfully.', 'td-booking');
		if ($booking_count > 0) {
			/* translators: %d is the number of associated bookings deleted */
			$message .= ' ' . sprintf(esc_html__('Also deleted %d associated booking(s).', 'td-booking'), intval($booking_count));
		}
		echo '<div class="updated"><p>' . esc_html($message) . '</p></div>';
	} else {
		echo '<div class="error"><p>' . esc_html__('Error deleting service.', 'td-booking') . '</p></div>';
	}
}

if (!class_exists('TD_BKG_Services_List_Table')) {
	if (!class_exists('WP_List_Table')) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}
	class TD_BKG_Services_List_Table extends WP_List_Table {
		function get_columns() {
			return [
				'name' => __('Name', 'td-booking'),
				'slug' => __('Slug', 'td-booking'),
				'duration_min' => __('Duration (min)', 'td-booking'),
				'active' => __('Active', 'td-booking'),
				'actions' => __('Actions', 'td-booking'),
			];
		}
		
		// Disable table footer
		function display_tablenav($which) {
			if ('top' === $which) {
				parent::display_tablenav($which);
			}
		}
		
		// Override display to remove footer
		function display() {
			$singular = $this->_args['singular'];

			$this->display_tablenav('top');

			$this->screen->render_screen_reader_content('heading_list');
			?>
			<table class="wp-list-table <?php echo implode(' ', $this->get_table_classes()); ?>">
				<thead>
					<tr>
						<?php $this->print_column_headers(); ?>
					</tr>
				</thead>

				<tbody id="the-list"<?php
					if ($singular) {
						echo " data-wp-lists='list:$singular'";
					} ?>>
					<?php $this->display_rows_or_placeholder(); ?>
				</tbody>
			</table>
			<?php
		}
		function prepare_items() {
			global $wpdb;
			$table = $wpdb->prefix . 'td_service';
			$items = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC", ARRAY_A);
			$this->items = $items;
			$this->_column_headers = [$this->get_columns(), [], []];
		}
		function column_default($item, $column_name) {
			switch ($column_name) {
				case 'actions':
					$edit_url = admin_url('admin.php?page=td-booking&action=edit&id=' . $item['id']);
					$delete_url = admin_url('admin.php?page=td-booking&delete=' . $item['id'] . '&_wpnonce=' . wp_create_nonce('td_bkg_service_delete_' . $item['id']));
					$actions = '<a href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'td-booking') . '</a>';
					$actions .= ' | <a href="' . esc_url($delete_url) . '" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this service? This will also delete all associated bookings.', 'td-booking')) . '\')" style="color: #d63638;">' . esc_html__('Delete', 'td-booking') . '</a>';
					return $actions;
				case 'active':
					return $item['active'] ? __('Yes', 'td-booking') : __('No', 'td-booking');
				default:
					return esc_html($item[$column_name]);
			}
		}
	}
}

$action = $_GET['action'] ?? '';
if ($action === 'add' || ($action === 'edit' && !empty($_GET['id']))) {
	require TD_BKG_PATH . 'includes/admin/pages/service-edit.php';
	return;
}

$table = new TD_BKG_Services_List_Table();
$table->prepare_items();
$table->display();
echo '</div>';
