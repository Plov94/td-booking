<?php
defined('ABSPATH') || exit;

// TD Booking Loader
// Loads all core includes, registers REST, admin, widgets, etc.

// --- Smoke Test Checklist ---
// 1. Activate TD Technicians and add one staff with hours + valid NC creds and pass test connection.
// 2. Activate TD Booking; create one service (30m) and map the staff.
// 3. Call GET /td/v1/availability for tomorrow; confirm slots.
// 4. POST /td/v1/book with an available slot; confirm booking row, email with ICS, and CalDAV event created (UID/ETag stored).
// 5. Cancel booking via /td/v1/booking/{id}/cancel; confirm event deleted in Nextcloud and email sent.
// 6. Manually move event in Nextcloud; run reconcile; confirm WP booking updated/conflicted and cache invalidated.

// --- End Smoke Test Checklist ---

require_once TD_BKG_PATH . 'includes/helpers.php';
require_once TD_BKG_PATH . 'includes/crypto.php';
require_once TD_BKG_PATH . 'includes/debug.php';
require_once TD_BKG_PATH . 'includes/time.php';
require_once TD_BKG_PATH . 'includes/capabilities.php';
require_once TD_BKG_PATH . 'includes/service-container.php';
require_once TD_BKG_PATH . 'includes/schema.php';
require_once TD_BKG_PATH . 'includes/nonce.php';
require_once TD_BKG_PATH . 'includes/ratelimit.php';
require_once TD_BKG_PATH . 'includes/mailer.php';
require_once TD_BKG_PATH . 'includes/ics.php';
// Integration with TD Technicians (must be loaded before notices/compat checks)
require_once TD_BKG_PATH . 'includes/integration.php';

// CalDAV
require_once TD_BKG_PATH . 'includes/caldav/client.php';
require_once TD_BKG_PATH . 'includes/caldav/mapper.php';

// Availability
require_once TD_BKG_PATH . 'includes/availability/engine.php';
require_once TD_BKG_PATH . 'includes/availability/cache.php';

// Assignment
require_once TD_BKG_PATH . 'includes/assignment/roundrobin.php';

// Jobs
require_once TD_BKG_PATH . 'includes/jobs/scheduler.php';
require_once TD_BKG_PATH . 'includes/jobs/reconcile.php';
require_once TD_BKG_PATH . 'includes/jobs/retry.php';

// Admin
require_once TD_BKG_PATH . 'includes/admin/class-admin-menu.php';

// Widgets
require_once TD_BKG_PATH . 'includes/widgets/booking-form-shortcode.php';
require_once TD_BKG_PATH . 'includes/widgets/booking-form-widget.php';

// Demo helper for testing
require_once TD_BKG_PATH . 'td-booking-demo-helper.php';

// AJAX endpoint for getting nonce (for external API testing)
add_action('wp_ajax_nopriv_td_booking_get_nonce', 'td_bkg_ajax_get_nonce');
add_action('wp_ajax_td_booking_get_nonce', 'td_bkg_ajax_get_nonce');
function td_bkg_ajax_get_nonce() {
    wp_send_json(['nonce' => wp_create_nonce('wp_rest')]);
}

// REST
require_once TD_BKG_PATH . 'includes/rest/public-services.php';
require_once TD_BKG_PATH . 'includes/rest/public-staff.php';
require_once TD_BKG_PATH . 'includes/rest/public-availability.php';
require_once TD_BKG_PATH . 'includes/rest/public-book.php';
require_once TD_BKG_PATH . 'includes/rest/public-booking-actions.php';
require_once TD_BKG_PATH . 'includes/rest/public-terms.php';
require_once TD_BKG_PATH . 'includes/rest/admin-tools.php';

// --- TD Booking plugin loaded. All core features are implemented ---

// WooCommerce integration (feature-flagged)
if (get_option('td_bkg_wc_enabled') && class_exists('WooCommerce')) {
    add_action('woocommerce_payment_complete', function($order_id) {
        $order = wc_get_order($order_id);
        foreach ($order->get_items() as $item) {
            $booking_id = $item->get_meta('td_bkg_booking_id');
            if ($booking_id) {
                global $wpdb;
                $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_booking WHERE id=%d", $booking_id), ARRAY_A);
                if ($booking && $booking['status'] !== 'confirmed') {
                    $wpdb->update($wpdb->prefix . 'td_booking', ['status' => 'confirmed'], ['id' => $booking_id]);
                    td_bkg_log_audit('info', 'woocommerce', 'Booking confirmed via payment', '', $booking_id, $booking['staff_id']);
                    // CalDAV event creation if needed
                    // ...existing CalDAV sync logic...
                }
            }
        }
    });
    add_action('woocommerce_order_refunded', function($order_id) {
        $order = wc_get_order($order_id);
        foreach ($order->get_items() as $item) {
            $booking_id = $item->get_meta('td_bkg_booking_id');
            if ($booking_id && get_option('td_bkg_wc_cancel_on_refund')) {
                global $wpdb;
                $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_booking WHERE id=%d", $booking_id), ARRAY_A);
                if ($booking && $booking['status'] !== 'cancelled') {
                    $wpdb->update($wpdb->prefix . 'td_booking', ['status' => 'cancelled'], ['id' => $booking_id]);
                    td_bkg_log_audit('info', 'woocommerce', 'Booking cancelled via refund', '', $booking_id, $booking['staff_id']);
                    // CalDAV event deletion if needed
                    // ...existing CalDAV delete logic...
                }
            }
        }
    });
}

// Reports page (feature-flagged) - keep at end with other admin pages
if (get_option('td_bkg_reports_enabled')) {
    require_once TD_BKG_PATH . 'includes/admin/pages/reports.php';
}
