<?php
/*
Plugin Name: TD Booking Demo Page
Description: Creates a demo page for testing customer bookings
*/

// Add admin menu item to create the demo page
add_action('admin_menu', function() {
    add_submenu_page(
        'td-booking',
        'Customer Demo',
        'Customer Demo',
        'manage_options',
        'td-booking-demo',
        'td_booking_demo_page'
    );
});

function td_booking_demo_page() {
    ?>
    <div class="wrap">
        <h1>Customer Booking Demo</h1>
        <p>This creates a WordPress page where customers can test the booking system.</p>
        <?php
        // Preload services and staff for selectors
        global $wpdb;
        $services = $wpdb->get_results("SELECT id, name, slug FROM {$wpdb->prefix}td_service WHERE active=1 ORDER BY name ASC", ARRAY_A);
        $staff_list = function_exists('td_bkg_get_active_technicians') ? td_bkg_get_active_technicians() : [];
        if (empty($staff_list)) {
            // Fallback to direct DB if integration not available
            $table = $wpdb->prefix . 'td_staff';
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if ($exists) {
                $rows = $wpdb->get_results("SELECT id, display_name AS name FROM {$table} WHERE active=1 ORDER BY display_name ASC", ARRAY_A);
                foreach ($rows as $r) { $staff_list[] = [ 'id' => intval($r['id']), 'display_name' => $r['name'] ]; }
            }
        }
        ?>
        
        <?php if (isset($_POST['create_demo_page'])): ?>
            <?php
            // Create the demo page
            $page_content = '[td_booking_form]

<style>
.td-booking-form {
    max-width: 500px;
    margin: 0 auto;
    padding: 30px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
}

.td-booking-form select,
.td-booking-form input,
.td-booking-form textarea {
    width: 100%;
    padding: 12px;
    border: 2px solid #e1e5e9;
    border-radius: 8px;
    font-size: 16px;
    margin-bottom: 15px;
    box-sizing: border-box;
}

.td-booking-form button {
    width: 100%;
    padding: 15px;
    background: linear-gradient(45deg, #4CAF50, #45a049);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 18px;
    font-weight: 600;
    cursor: pointer;
}

.td-booking-slots {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 10px;
    margin: 15px 0;
}

.td-booking-slots button {
    padding: 12px;
    border: 2px solid #e1e5e9;
    border-radius: 8px;
    background: white;
    cursor: pointer;
    transition: all 0.3s;
}

.td-booking-slots button:hover {
    border-color: #2196F3;
    background: #f0f8ff;
}

.td-booking-message {
    margin-top: 15px;
    padding: 12px;
    border-radius: 8px;
    text-align: center;
}
</style>';

            $page_id = wp_insert_post([
                'post_title' => 'Book a Technician - Demo',
                'post_content' => $page_content,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => get_current_user_id(),
            ]);
            
            if ($page_id) {
                $page_url = get_permalink($page_id);
                echo '<div class="notice notice-success"><p>Demo page created! <a href="' . esc_url($page_url) . '" target="_blank">Visit Demo Page</a></p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to create demo page.</p></div>';
            }
            ?>
        <?php endif; ?>
        
        <form method="post">
            <p>
                <input type="submit" name="create_demo_page" class="button-primary" value="Create Customer Demo Page">
            </p>
        </form>
        
        <h2>Or use the shortcode directly:</h2>
        <p>Add <code>[td_booking_form]</code> to any page or post to display the booking form.</p>
    <p>Or preselect a service: <code>[td_booking_service service="service-slug-or-id"]</code></p>
    <p>Restrict to a staff member: <code>[td_booking_staff staff="123"]</code></p>
    <p>Both: <code>[td_booking_service_staff service="service-slug-or-id" staff="123"]</code></p>
        
        <h2>API Testing URLs:</h2>
        <ul>
            <li><a href="<?php echo site_url('?rest_route=/td/v1/services'); ?>" target="_blank">Services API</a></li>
            <li><a href="<?php echo site_url('?rest_route=/td/v1/availability&service_id=1&from=2025-09-09%2009:00:00&to=2025-09-09%2017:00:00&duration=30'); ?>" target="_blank">Availability API</a></li>
        </ul>

        <hr>
        <h2>Create quick test pages</h2>
        <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <div>
                <label for="svc_select">Service</label>
                <select id="svc_select" name="svc_select">
                    <option value="">— Any service —</option>
                    <?php foreach (($services ?: []) as $svc): ?>
                        <option value="<?php echo esc_attr($svc['id']); ?>"><?php echo esc_html($svc['name']); ?><?php echo $svc['slug'] ? ' (' . esc_html($svc['slug']) . ')' : ''; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="staff_select">Staff</label>
                <select id="staff_select" name="staff_select">
                    <option value="">— Any technician —</option>
                    <?php foreach (($staff_list ?: []) as $st): ?>
                        <option value="<?php echo esc_attr($st['id']); ?>"><?php echo esc_html($st['display_name'] ?? $st['name'] ?? ('#' . $st['id'])); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="page_title">Page title</label>
                <input type="text" id="page_title" name="page_title" placeholder="TD Booking Shortcode Test">
            </div>
            <div>
                <label for="agnostic_flag">Staff-first (no service required)</label><br/>
                <label style="display:inline-flex;align-items:center;gap:6px;">
                    <input type="checkbox" id="agnostic_flag" name="agnostic_flag" value="1" />
                    <span>Customer books the person directly; service is set to “Custom / Specifically requested”.</span>
                </label>
            </div>
            <div>
                <input type="submit" name="create_shortcode_page" class="button" value="Create shortcode test page">
            </div>
        </form>
        <?php if (isset($_POST['create_shortcode_page'])): ?>
            <?php
            $svc_sel = sanitize_text_field($_POST['svc_select'] ?? '');
            $staff_sel = intval($_POST['staff_select'] ?? 0);
            $title = sanitize_text_field($_POST['page_title'] ?? 'TD Booking Shortcode Test');
            $agnostic = !empty($_POST['agnostic_flag']);
            $shortcode = '';
            if ($svc_sel && $staff_sel) {
                $shortcode = '[td_booking_service_staff service="' . esc_attr($svc_sel) . '" staff="' . esc_attr($staff_sel) . '"]';
            } elseif ($svc_sel) {
                $shortcode = '[td_booking_service service="' . esc_attr($svc_sel) . '"]';
            } elseif ($staff_sel) {
                $shortcode = '[td_booking_staff staff="' . esc_attr($staff_sel) . '"]';
            } else {
                $shortcode = '[td_booking_form]';
            }
            $page_id = wp_insert_post([
                'post_title' => $title ?: 'TD Booking Shortcode Test',
                'post_content' => $shortcode,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => get_current_user_id(),
            ]);
            if ($page_id && $agnostic) {
                update_post_meta($page_id, 'td_bkg_staff_agnostic', 1);
            }
            if ($page_id) {
                echo '<div class="notice notice-success"><p>Page created! <a href="' . esc_url(get_permalink($page_id)) . '" target="_blank">Open test page</a></p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to create test page.</p></div>';
            }
            ?>
        <?php endif; ?>
    </div>
    <?php
}
?>
