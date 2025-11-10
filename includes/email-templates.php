<?php
/**
 * Enhanced Email Templates for TD Booking
 * 
 * Provides customizable email templates with:
 * - Professional HTML formatting
 * - Local time zone display
 * - Configurable sender identity
 * - Template inheritance system
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email Template Manager
 */
class TD_Booking_Email_Templates {
    
    private $default_templates;
    private $site_timezone;
    
    public function __construct() {
        $this->site_timezone = wp_timezone();
        $this->init_default_templates();
    }
    
    /**
     * Initialize default email templates
     */
    private function init_default_templates() {
        $this->default_templates = [
            'booking_confirmation' => [
                'subject' => __('[{{site_name}}] Booking Confirmation - {{service_name}}', 'td-booking'),
                'heading' => __('Booking Confirmed', 'td-booking'),
                'template' => $this->get_confirmation_template()
            ],
            'booking_reminder' => [
                'subject' => __('[{{site_name}}] Reminder: {{service_name}} tomorrow', 'td-booking'),
                'heading' => __('Appointment Reminder', 'td-booking'),
                'template' => $this->get_reminder_template()
            ],
            'booking_cancelled' => [
                'subject' => __('[{{site_name}}] Booking Cancelled - {{service_name}}', 'td-booking'),
                'heading' => __('Booking Cancelled', 'td-booking'),
                'template' => $this->get_cancellation_template()
            ],
            'booking_rescheduled' => [
                'subject' => __('[{{site_name}}] Booking Rescheduled - {{service_name}}', 'td-booking'),
                'heading' => __('Booking Rescheduled', 'td-booking'),
                'template' => $this->get_reschedule_template()
            ],
            'staff_assignment' => [
                'subject' => __('[{{site_name}}] New Assignment: {{service_name}}', 'td-booking'),
                'heading' => __('New Booking Assignment', 'td-booking'),
                'template' => $this->get_staff_template()
            ]
        ];
    }
    
    /**
     * Send booking confirmation email
     */
    public function send_confirmation($booking_id) {
        $booking = $this->get_booking_data($booking_id);
        if (!$booking) return false;
        
        $template_data = $this->prepare_template_data($booking);
        
        return $this->send_email(
            $booking['customer_email'],
            'booking_confirmation',
            $template_data
        );
    }
    
    /**
     * Send booking reminder email
     */
    public function send_reminder($booking_id) {
        $booking = $this->get_booking_data($booking_id);
        if (!$booking) return false;
        
        $template_data = $this->prepare_template_data($booking);
        
        return $this->send_email(
            $booking['customer_email'],
            'booking_reminder',
            $template_data
        );
    }
    
    /**
     * Send cancellation email
     */
    public function send_cancellation($booking_id, $reason = '') {
        $booking = $this->get_booking_data($booking_id);
        if (!$booking) return false;
        
        $template_data = $this->prepare_template_data($booking);
        $template_data['cancellation_reason'] = $reason;
        
        return $this->send_email(
            $booking['customer_email'],
            'booking_cancelled',
            $template_data
        );
    }
    
    /**
     * Send reschedule notification
     */
    public function send_reschedule($booking_id, $old_date, $old_time) {
        $booking = $this->get_booking_data($booking_id);
        if (!$booking) return false;
        
        $template_data = $this->prepare_template_data($booking);
        $template_data['old_date'] = $this->format_date($old_date);
        $template_data['old_time'] = $this->format_time($old_time);
        
        return $this->send_email(
            $booking['customer_email'],
            'booking_rescheduled',
            $template_data
        );
    }
    
    /**
     * Send staff assignment notification
     */
    public function send_staff_assignment($booking_id) {
        $booking = $this->get_booking_data($booking_id);
        if (!$booking || !$booking['staff_email']) return false;
        
        $template_data = $this->prepare_template_data($booking);
        
        return $this->send_email(
            $booking['staff_email'],
            'staff_assignment',
            $template_data
        );
    }
    
    /**
     * Send email using template
     */
    private function send_email($to, $template_type, $data) {
        $template = $this->get_template($template_type);
        if (!$template) return false;
        
        // Replace placeholders in subject and content
        $subject = $this->replace_placeholders($template['subject'], $data);
        $content = $this->replace_placeholders($template['template'], $data);
        
        // Wrap in HTML layout
        $html_content = $this->wrap_in_layout($content, $data);
        
        // Email headers
        $headers = $this->get_email_headers();
        
        // Send email
        return wp_mail($to, $subject, $html_content, $headers);
    }
    
    /**
     * Get template (custom or default)
     */
    private function get_template($template_type) {
        // Check for custom template
        $custom_templates = get_option('td_bkg_email_templates', []);
        
        if (isset($custom_templates[$template_type])) {
            return $custom_templates[$template_type];
        }
        
        // Fall back to default
        return $this->default_templates[$template_type] ?? null;
    }
    
    /**
     * Get booking data with related information
     */
    private function get_booking_data($booking_id) {
        global $wpdb;
        
        $booking_table = $wpdb->prefix . 'td_booking';
        $service_table = $wpdb->prefix . 'td_service';
        
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, s.name as service_name, s.duration as service_duration
            FROM $booking_table b
            LEFT JOIN $service_table s ON b.service_id = s.id
            WHERE b.id = %d
        ", $booking_id), ARRAY_A);
        
        if (!$booking) return null;
        
        // Get staff information
        if (function_exists('td_tech_get_technician') && $booking['staff_id']) {
            $staff = td_tech_get_technician($booking['staff_id']);
            $booking['staff_name'] = $staff['name'] ?? "Staff #{$booking['staff_id']}";
            $booking['staff_email'] = $staff['email'] ?? '';
        } else {
            $booking['staff_name'] = "Staff #{$booking['staff_id']}";
            $booking['staff_email'] = '';
        }
        
        return $booking;
    }
    
    /**
     * Prepare template data with all necessary placeholders
     */
    private function prepare_template_data($booking) {
        return [
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url('/'),
            'booking_id' => $booking['id'],
            'customer_name' => $booking['customer_name'],
            'customer_email' => $booking['customer_email'],
            'service_name' => $booking['service_name'],
            'service_duration' => $booking['service_duration'] ?? 60,
            'staff_name' => $booking['staff_name'],
            'booking_date' => $this->format_date($booking['booking_date']),
            'booking_time' => $this->format_time($booking['booking_time']),
            'booking_datetime_local' => $this->format_datetime_local($booking['booking_date'], $booking['booking_time']),
            'booking_status' => ucfirst($booking['status']),
            'notes' => $booking['notes'] ?? '',
            'year' => date('Y'),
            'timezone_name' => $this->site_timezone->getName(),
            'manage_url' => $this->get_manage_booking_url($booking['id']),
            'cancel_url' => $this->get_cancel_booking_url($booking['id']),
        ];
    }
    
    /**
     * Format date for display
     */
    private function format_date($date) {
        $dt = new DateTime($date, $this->site_timezone);
        return $dt->format(get_option('date_format', 'F j, Y'));
    }
    
    /**
     * Format time for display
     */
    private function format_time($time) {
        $dt = new DateTime($time, $this->site_timezone);
        return $dt->format(get_option('time_format', 'g:i A'));
    }
    
    /**
     * Format combined datetime with timezone
     */
    private function format_datetime_local($date, $time) {
        $dt = new DateTime("$date $time", $this->site_timezone);
        $date_format = get_option('date_format', 'F j, Y');
        $time_format = get_option('time_format', 'g:i A');
        
        return $dt->format("$date_format \\a\\t $time_format T");
    }
    
    /**
     * Get booking management URL
     */
    private function get_manage_booking_url($booking_id) {
        return home_url("/booking/manage/$booking_id");
    }
    
    /**
     * Get booking cancellation URL
     */
    private function get_cancel_booking_url($booking_id) {
        return home_url("/booking/cancel/$booking_id");
    }
    
    /**
     * Replace placeholders in template content
     */
    private function replace_placeholders($content, $data) {
        foreach ($data as $key => $value) {
            $content = str_replace("{{" . $key . "}}", $value, $content);
        }
        return $content;
    }
    
    /**
     * Wrap content in HTML email layout
     */
    private function wrap_in_layout($content, $data) {
        $header_color = get_option('td_bkg_email_header_color', '#0073aa');
        $logo_url = get_option('td_bkg_email_logo_url', '');
        
        $layout = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{site_name}}</title>
    <style>
        body { margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .email-header { background-color: ' . $header_color . '; color: #ffffff; padding: 20px; text-align: center; }
        .email-header h1 { margin: 0; font-size: 24px; }
        .email-logo { max-height: 60px; margin-bottom: 10px; }
        .email-body { padding: 30px; line-height: 1.6; }
        .booking-details { background-color: #f9f9f9; border: 1px solid #ddd; border-radius: 5px; padding: 20px; margin: 20px 0; }
        .booking-details h3 { margin-top: 0; color: ' . $header_color . '; }
        .detail-row { margin: 10px 0; }
        .detail-label { font-weight: bold; display: inline-block; width: 120px; }
        .cta-button { display: inline-block; background-color: ' . $header_color . '; color: #ffffff !important; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
        .email-footer { background-color: #f4f4f4; padding: 20px; text-align: center; font-size: 12px; color: #666; }
        .email-footer a { color: #666; }
        @media only screen and (max-width: 600px) {
            .email-container { width: 100% !important; }
            .email-body { padding: 20px !important; }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">';
        
        if ($logo_url) {
            $layout .= '<img src="' . esc_url($logo_url) . '" alt="{{site_name}}" class="email-logo">';
        }
        
        $layout .= '<h1>{{site_name}}</h1>
        </div>
        <div class="email-body">
            ' . $content . '
        </div>
        <div class="email-footer">
            <p>&copy; {{year}} {{site_name}}. All rights reserved.</p>
            <p><a href="{{site_url}}">Visit our website</a></p>
        </div>
    </div>
</body>
</html>';

        return $this->replace_placeholders($layout, $data);
    }
    
    /**
     * Get email headers
     */
    private function get_email_headers() {
        $from_name = get_option('td_bkg_email_from_name', get_bloginfo('name'));
        $from_email = get_option('td_bkg_email_from_email', get_option('admin_email'));
        
        return [
            'Content-Type: text/html; charset=UTF-8',
            "From: $from_name <$from_email>",
            "Reply-To: $from_email"
        ];
    }
    
    /**
     * Booking confirmation template
     */
    private function get_confirmation_template() {
        return '<h2 style="color: #0073aa;">Thank you for your booking!</h2>
        
        <p>Dear {{customer_name}},</p>
        
        <p>Your booking has been confirmed. Here are the details:</p>
        
        <div class="booking-details">
            <h3>Booking Details</h3>
            <div class="detail-row">
                <span class="detail-label">Service:</span> {{service_name}}
            </div>
            <div class="detail-row">
                <span class="detail-label">Date & Time:</span> {{booking_datetime_local}}
            </div>
            <div class="detail-row">
                <span class="detail-label">Duration:</span> {{service_duration}} minutes
            </div>
            <div class="detail-row">
                <span class="detail-label">Technician:</span> {{staff_name}}
            </div>
            <div class="detail-row">
                <span class="detail-label">Booking ID:</span> #{{booking_id}}
            </div>
        </div>
        
        <p>If you need to make changes to your booking, please use the links below:</p>
        
        <p style="text-align: center;">
            <a href="{{manage_url}}" class="cta-button">Manage Booking</a>
            <a href="{{cancel_url}}" class="cta-button" style="background-color: #dc3232;">Cancel Booking</a>
        </p>
        
        <p>We look forward to seeing you!</p>
        
        <p>Best regards,<br>
        The {{site_name}} Team</p>';
    }
    
    /**
     * Booking reminder template
     */
    private function get_reminder_template() {
        return '<h2 style="color: #0073aa;">Reminder: Your appointment is tomorrow</h2>
        
        <p>Dear {{customer_name}},</p>
        
        <p>This is a friendly reminder about your upcoming appointment:</p>
        
        <div class="booking-details">
            <h3>Appointment Details</h3>
            <div class="detail-row">
                <span class="detail-label">Service:</span> {{service_name}}
            </div>
            <div class="detail-row">
                <span class="detail-label">Date & Time:</span> {{booking_datetime_local}}
            </div>
            <div class="detail-row">
                <span class="detail-label">Technician:</span> {{staff_name}}
            </div>
            <div class="detail-row">
                <span class="detail-label">Booking ID:</span> #{{booking_id}}
            </div>
        </div>
        
        <p>Please arrive on time and bring any necessary documentation.</p>
        
        <p style="text-align: center;">
            <a href="{{manage_url}}" class="cta-button">View Details</a>
        </p>
        
        <p>If you need to reschedule or have any questions, please contact us as soon as possible.</p>
        
        <p>Thank you,<br>
        The {{site_name}} Team</p>';
    }
    
    /**
     * Booking cancellation template
     */
    private function get_cancellation_template() {
        return '<h2 style="color: #dc3232;">Booking Cancelled</h2>
        
        <p>Dear {{customer_name}},</p>
        
        <p>Your booking has been cancelled.</p>
        
        <div class="booking-details">
            <h3>Cancelled Booking Details</h3>
            <div class="detail-row">
                <span class="detail-label">Service:</span> {{service_name}}
            </div>
            <div class="detail-row">
                <span class="detail-label">Date & Time:</span> {{booking_datetime_local}}
            </div>
            <div class="detail-row">
                <span class="detail-label">Booking ID:</span> #{{booking_id}}
            </div>
        </div>
        
        <p>If you would like to reschedule, please visit our website to book a new appointment.</p>
        
        <p style="text-align: center;">
            <a href="{{site_url}}" class="cta-button">Book New Appointment</a>
        </p>
        
        <p>We apologize for any inconvenience and hope to see you again soon.</p>
        
        <p>Best regards,<br>
        The {{site_name}} Team</p>';
    }
    
    /**
     * Booking reschedule template
     */
    private function get_reschedule_template() {
        return '<h2 style="color: #0073aa;">Booking Rescheduled</h2>
        
        <p>Dear {{customer_name}},</p>
        
        <p>Your booking has been rescheduled. Here are the updated details:</p>
        
        <div class="booking-details">
            <h3>New Booking Details</h3>
            <div class="detail-row">
                <span class="detail-label">Service:</span> {{service_name}}
            </div>
            <div class="detail-row">
                <span class="detail-label">New Date & Time:</span> {{booking_datetime_local}}
            </div>
            <div class="detail-row">
                <span class="detail-label">Previous Date & Time:</span> {{old_date}} at {{old_time}}
            </div>
            <div class="detail-row">
                <span class="detail-label">Technician:</span> {{staff_name}}
            </div>
            <div class="detail-row">
                <span class="detail-label">Booking ID:</span> #{{booking_id}}
            </div>
        </div>
        
        <p style="text-align: center;">
            <a href="{{manage_url}}" class="cta-button">View Updated Booking</a>
        </p>
        
        <p>We look forward to seeing you at the new time!</p>
        
        <p>Best regards,<br>
        The {{site_name}} Team</p>';
    }
    
    /**
     * Staff assignment template
     */
    private function get_staff_template() {
        return '<h2 style="color: #0073aa;">New Booking Assignment</h2>
        
        <p>Hello {{staff_name}},</p>
        
        <p>You have been assigned to a new booking:</p>
        
        <div class="booking-details">
            <h3>Assignment Details</h3>
            <div class="detail-row">
                <span class="detail-label">Service:</span> {{service_name}}
            </div>
            <div class="detail-row">
                <span class="detail-label">Date & Time:</span> {{booking_datetime_local}}
            </div>
            <div class="detail-row">
                <span class="detail-label">Customer:</span> {{customer_name}}
            </div>
            <div class="detail-row">
                <span class="detail-label">Duration:</span> {{service_duration}} minutes
            </div>
            <div class="detail-row">
                <span class="detail-label">Booking ID:</span> #{{booking_id}}
            </div>
        </div>
        
        <p>Please make sure to check your calendar and prepare for this appointment.</p>
        
        <p>If you have any questions or conflicts, please contact the administrator immediately.</p>
        
        <p>Thank you,<br>
        The {{site_name}} Team</p>';
    }
}

// Initialize email templates
function td_bkg_email_templates() {
    static $instance = null;
    if ($instance === null) {
        $instance = new TD_Booking_Email_Templates();
    }
    return $instance;
}

// Helper functions for easy access
function td_bkg_send_confirmation_email($booking_id) {
    return td_bkg_email_templates()->send_confirmation($booking_id);
}

function td_bkg_send_reminder_email($booking_id) {
    return td_bkg_email_templates()->send_reminder($booking_id);
}

function td_bkg_send_cancellation_email($booking_id, $reason = '') {
    return td_bkg_email_templates()->send_cancellation($booking_id, $reason);
}

function td_bkg_send_reschedule_email($booking_id, $old_date, $old_time) {
    return td_bkg_email_templates()->send_reschedule($booking_id, $old_date, $old_time);
}

function td_bkg_send_staff_assignment_email($booking_id) {
    return td_bkg_email_templates()->send_staff_assignment($booking_id);
}
