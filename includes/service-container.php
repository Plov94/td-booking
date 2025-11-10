<?php
defined('ABSPATH') || exit;

/**
 * TD Booking Service Container
 * Provides centralized access to all plugin services
 */
function td_bkg() {
    static $container = null;
    if ($container) return $container;
    
    $container = new TD_BKG_Container();
    return $container;
}

/**
 * Main service container class
 */
class TD_BKG_Container {
    private $services = [];
    
    public function __construct() {
        $this->register_services();
    }
    
    private function register_services() {
        // Availability services
        $this->services['availability'] = function() {
            return new TD_BKG_Availability_Service();
        };
        
        // Assignment services
        $this->services['assignment'] = function() {
            return new TD_BKG_Assignment_Service();
        };
        
        // CalDAV services
        $this->services['caldav'] = function() {
            return new TD_BKG_CalDAV_Service();
        };
        
        // Communication services
        $this->services['mailer'] = function() {
            return new TD_BKG_Mailer_Service();
        };
        
        // ICS services
        $this->services['ics'] = function() {
            return new TD_BKG_ICS_Service();
        };
        
        // Background job services
        $this->services['jobs'] = function() {
            return new TD_BKG_Jobs_Service();
        };
        
        // Repository services
        $this->services['repo'] = function() {
            return new TD_BKG_Repository_Service();
        };
    }
    
    public function __get($name) {
        if (isset($this->services[$name])) {
            if (is_callable($this->services[$name])) {
                $this->services[$name] = call_user_func($this->services[$name]);
            }
            return $this->services[$name];
        }
        
        throw new Exception("Service '$name' not found in TD Booking container");
    }
    
    public function availability() {
        return $this->availability;
    }
    
    public function assignment() {
        return $this->assignment;
    }
    
    public function caldav() {
        return $this->caldav;
    }
    
    public function mailer() {
        return $this->mailer;
    }
    
    public function ics() {
        return $this->ics;
    }
    
    public function jobs() {
        return $this->jobs;
    }
    
    public function repo() {
        return $this->repo;
    }
}

/**
 * Availability Service
 */
class TD_BKG_Availability_Service {
    public function engine($service_id, $from, $to, $duration = 0) {
        return td_bkg_availability_engine($service_id, $from, $to, $duration);
    }
    
    public function cache_get($staff_id, $from, $to) {
        require_once TD_BKG_PATH . 'includes/availability/cache.php';
        return td_bkg_availability_cache($staff_id, $from, $to);
    }
    
    public function cache_invalidate($staff_id, $from, $to) {
        require_once TD_BKG_PATH . 'includes/availability/cache.php';
        return td_bkg_availability_cache_invalidate($staff_id, $from, $to);
    }
    
    public function cache_clear_all() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_td_bkg_avail_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_td_bkg_avail_%'");
        return true;
    }
}

/**
 * Assignment Service
 */
class TD_BKG_Assignment_Service {
    public function roundrobin($service_id, $candidates) {
        require_once TD_BKG_PATH . 'includes/assignment/roundrobin.php';
        return td_bkg_assignment_roundrobin($service_id, $candidates);
    }
    
    public function get_qualified_staff($service_id) {
        require_once TD_BKG_PATH . 'includes/assignment/roundrobin.php';
        return td_bkg_get_qualified_staff($service_id);
    }
    
    public function calculate_staff_score($staff_id, $required_skills, $repo = null) {
        require_once TD_BKG_PATH . 'includes/assignment/roundrobin.php';
        return td_bkg_calculate_staff_score($staff_id, $required_skills, $repo);
    }
}

/**
 * CalDAV Service
 */
class TD_BKG_CalDAV_Service {
    public function put($url, $ics, $user, $pass) {
        require_once TD_BKG_PATH . 'includes/caldav/client.php';
        return td_bkg_caldav_put($url, $ics, $user, $pass);
    }
    
    public function delete($url, $user, $pass) {
        require_once TD_BKG_PATH . 'includes/caldav/client.php';
        return td_bkg_caldav_delete($url, $user, $pass);
    }
    
    public function report($url, $xml, $user, $pass) {
        require_once TD_BKG_PATH . 'includes/caldav/client.php';
        return td_bkg_caldav_report($url, $xml, $user, $pass);
    }
    
    public function uid($booking_id) {
        require_once TD_BKG_PATH . 'includes/caldav/mapper.php';
        return td_bkg_caldav_uid($booking_id);
    }
    
    public function ics($booking, $service, $method = 'REQUEST') {
        require_once TD_BKG_PATH . 'includes/caldav/mapper.php';
        return td_bkg_caldav_ics($booking, $service, $method);
    }
}

/**
 * Mailer Service
 */
class TD_BKG_Mailer_Service {
    public function send($to, $subject, $message, $ics = null) {
        require_once TD_BKG_PATH . 'includes/mailer.php';
        return td_bkg_mailer($to, $subject, $message, $ics);
    }
    
    public function send_booking_confirmation($booking, $service) {
        $subject = sprintf(__('Booking Confirmation - %s', 'td-booking'), $service['name']);
        $message = $this->format_confirmation_message($booking, $service);
        
        $ics = td_bkg()->ics()->generate([
            'uid' => $booking['caldav_uid'] ?? td_bkg()->caldav()->uid($booking['id']),
            'dtstart' => gmdate('Ymd\THis\Z', strtotime($booking['start_utc'])),
            'dtend' => gmdate('Ymd\THis\Z', strtotime($booking['end_utc'])),
            'summary' => $service['name'] . ' – ' . $booking['customer_name'],
            'description' => $booking['notes'] ?? '',
        ]);
        
        return $this->send($booking['customer_email'], $subject, $message, $ics);
    }
    
    public function send_booking_cancellation($booking, $service) {
        $subject = sprintf(__('Booking Cancelled - %s', 'td-booking'), $service['name']);
        $message = $this->format_cancellation_message($booking, $service);
        
        $ics = td_bkg()->ics()->generate([
            'uid' => $booking['caldav_uid'] ?? td_bkg()->caldav()->uid($booking['id']),
            'dtstart' => gmdate('Ymd\THis\Z', strtotime($booking['start_utc'])),
            'dtend' => gmdate('Ymd\THis\Z', strtotime($booking['end_utc'])),
            'summary' => $service['name'] . ' – ' . $booking['customer_name'],
            'description' => $booking['notes'] ?? '',
            'method' => 'CANCEL'
        ]);
        
        return $this->send($booking['customer_email'], $subject, $message, $ics);
    }
    
    private function format_confirmation_message($booking, $service) {
        $local_start = get_date_from_gmt($booking['start_utc'], 'F j, Y \a\t g:i A');
        $local_end = get_date_from_gmt($booking['end_utc'], 'g:i A');
        
        $message = '<h2>' . __('Booking Confirmed', 'td-booking') . '</h2>';
        $message .= '<p>' . __('Your booking has been confirmed with the following details:', 'td-booking') . '</p>';
        $message .= '<ul>';
        $message .= '<li><strong>' . __('Service:', 'td-booking') . '</strong> ' . esc_html($service['name']) . '</li>';
        $message .= '<li><strong>' . __('Date & Time:', 'td-booking') . '</strong> ' . $local_start . ' - ' . $local_end . '</li>';
        $message .= '<li><strong>' . __('Duration:', 'td-booking') . '</strong> ' . $service['duration_min'] . ' ' . __('minutes', 'td-booking') . '</li>';
        if (!empty($booking['notes'])) {
            $message .= '<li><strong>' . __('Notes:', 'td-booking') . '</strong> ' . esc_html($booking['notes']) . '</li>';
        }
        $message .= '</ul>';
        
        // Add cancellation and reschedule links if tokens are enabled
        if (!empty($booking['ics_token'])) {
            $cancel_url = td_bkg_public_cancel_url($booking['id'], $booking['ics_token']);
            $reschedule_url = td_bkg_public_reschedule_url($booking['id'], $booking['ics_token']);
            $message .= '<p style="margin-top:16px">'
                . '<a href="' . esc_url($reschedule_url) . '" style="margin-right:12px">' . __('Reschedule this booking', 'td-booking') . '</a>'
                . '<a href="' . esc_url($cancel_url) . '">' . __('Cancel this booking', 'td-booking') . '</a>'
                . '</p>';
        }
        
        return $message;
    }
    
    private function format_cancellation_message($booking, $service) {
        $message = '<h2>' . __('Booking Cancelled', 'td-booking') . '</h2>';
        $message .= '<p>' . sprintf(__('Your booking for "%s" has been cancelled.', 'td-booking'), $service['name']) . '</p>';
        return $message;
    }
}

/**
 * ICS Service
 */
class TD_BKG_ICS_Service {
    public function generate($args) {
        require_once TD_BKG_PATH . 'includes/ics.php';
        return td_bkg_ics($args);
    }
}

/**
 * Jobs Service
 */
class TD_BKG_Jobs_Service {
    public function reconcile($from = null, $to = null) {
        require_once TD_BKG_PATH . 'includes/jobs/reconcile.php';
        return td_bkg_trigger_reconcile($from, $to);
    }
    
    public function retry_failed() {
        require_once TD_BKG_PATH . 'includes/jobs/retry.php';
        return td_bkg_retry_failed_bookings();
    }
    
    public function send_reminders() {
        require_once TD_BKG_PATH . 'includes/jobs/scheduler.php';
        return td_bkg_send_reminders();
    }
}

/**
 * Repository Service
 */
class TD_BKG_Repository_Service {
    public function get_service($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}td_service WHERE id = %d",
            $id
        ), ARRAY_A);
    }
    
    public function get_booking($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}td_booking WHERE id = %d",
            $id
        ), ARRAY_A);
    }
    
    public function get_active_services() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}td_service WHERE active = 1 ORDER BY name ASC",
            ARRAY_A
        );
    }
    
    public function get_staff_for_service($service_id) {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT staff_id FROM {$wpdb->prefix}td_service_staff WHERE service_id = %d",
            $service_id
        ));
    }
    
    public function create_booking($data) {
        global $wpdb;
        $data['created_at'] = current_time('mysql', 1);
        $data['updated_at'] = current_time('mysql', 1);
        
        $result = $wpdb->insert($wpdb->prefix . 'td_booking', $data);
        if ($result === false) {
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    public function update_booking($id, $data) {
        global $wpdb;
        $data['updated_at'] = current_time('mysql', 1);
        
        return $wpdb->update(
            $wpdb->prefix . 'td_booking',
            $data,
            ['id' => $id]
        );
    }
}

function td_bkg_require_technicians() {
    if (!function_exists('td_tech') || !defined('TD_TECH_API_VERSION') || version_compare(TD_TECH_API_VERSION, TD_BKG_MIN_TECH_API, '<')) {
        update_option('td_bkg_degraded', 1);
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>' . esc_html__('TD Booking requires the TD Technicians plugin (active, compatible version). Public endpoints are disabled.', 'td-booking') . '</p></div>';
        });
        return false;
    }
    delete_option('td_bkg_degraded');
    
    // Show enhanced integration notice
    if (td_bkg_check_technicians_compatibility()) {
        add_action('admin_notices', function() {
            if (get_transient('td_bkg_enhanced_integration_notice')) {
                return; // Don't show repeatedly
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 esc_html__('TD Booking: Enhanced integration with TD Technicians is active! Features include skills-based assignment, working hours integration, and smart availability caching.', 'td-booking') . 
                 '</p></div>';
            set_transient('td_bkg_enhanced_integration_notice', 1, DAY_IN_SECONDS);
        });
    }
    
    return true;
}
add_action('plugins_loaded', 'td_bkg_require_technicians', 20);
