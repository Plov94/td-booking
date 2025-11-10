<?php
defined('ABSPATH') || exit;

function td_bkg_mailer($to, $subject, $message, $ics = null, $email_type = 'general', $booking_data = []) {
    // Apply email subject filter
    if (function_exists('td_bkg_filter_email_subject')) {
        $subject = td_bkg_filter_email_subject($subject, $email_type, $booking_data);
    }
    
    // Apply email content filter
    if (function_exists('td_bkg_filter_email_content')) {
        $message = td_bkg_filter_email_content($message, $email_type, $booking_data);
    }
    
    // Get enhanced email settings
    $from_name = get_option('td_bkg_email_from_name', get_bloginfo('name'));
    $from_email = get_option('td_bkg_email_from_email', get_option('admin_email'));
    
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        "From: $from_name <$from_email>",
        "Reply-To: $from_email"
    ];
    
    $attachments = [];
    if ($ics) {
        $tmp = tempnam(sys_get_temp_dir(), 'booking_');
        file_put_contents($tmp, $ics);
        $attachments[] = $tmp;
    }
    
    // Wrap plain text in basic HTML if not already HTML
    if (strpos($message, '<html>') === false && strpos($message, '<body>') === false) {
        $message = td_bkg_wrap_email_content($message);
    }
    
    // Capture PHPMailer failures for this send
    $captured_error = '';
    $cb = function($wp_error) use (&$captured_error, $to, $subject, $email_type, $booking_data) {
        if (is_wp_error($wp_error)) {
            $captured_error = $wp_error->get_error_message();
        }
    };
    add_action('wp_mail_failed', $cb, 10, 1);

    $sent = wp_mail($to, $subject, $message, $headers, $attachments);

    // Remove temporary failure hook
    remove_action('wp_mail_failed', $cb, 10);
    
    if ($ics && isset($tmp) && file_exists($tmp)) {
        unlink($tmp);
    }
    
    // Audit log for success/failure
    if (function_exists('td_bkg_log_audit')) {
        if ($sent) {
            td_bkg_log_audit('info', 'mailer', 'mail_sent', wp_json_encode([
                'to' => $to,
                'subject' => $subject,
                'type' => $email_type,
                'has_ics' => (bool)$ics,
            ]));
        } else {
            td_bkg_log_audit('error', 'mailer', 'mail_failed', wp_json_encode([
                'to' => $to,
                'subject' => $subject,
                'type' => $email_type,
                'has_ics' => (bool)$ics,
                'error' => $captured_error,
            ]));
        }
    }

    return $sent;
}

/**
 * Wrap plain content in basic HTML email layout
 */
function td_bkg_wrap_email_content($content) {
    $header_color = get_option('td_bkg_email_header_color', '#0073aa');
    $logo_url = get_option('td_bkg_email_logo_url', '');
    $site_name = get_bloginfo('name');
    $site_url = home_url('/');
    
    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html($site_name) . '</title>
    <style>
        body { margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .email-header { background-color: ' . $header_color . '; color: #ffffff; padding: 20px; text-align: center; }
        .email-header h1 { margin: 0; font-size: 24px; }
        .email-logo { max-height: 60px; margin-bottom: 10px; }
        .email-body { padding: 30px; line-height: 1.6; }
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
        $html .= '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($site_name) . '" class="email-logo">';
    }
    
    $html .= '<h1>' . esc_html($site_name) . '</h1>
        </div>
        <div class="email-body">
            ' . wpautop($content) . '
        </div>
        <div class="email-footer">
            <p>&copy; ' . date('Y') . ' ' . esc_html($site_name) . '. All rights reserved.</p>
            <p><a href="' . esc_url($site_url) . '">Visit our website</a></p>
        </div>
    </div>
</body>
</html>';

    return $html;
}

/**
 * Enhanced booking confirmation email
 */
function td_bkg_send_booking_confirmation($booking_id) {
    global $wpdb;
    $booking_table = $wpdb->prefix . 'td_booking';
    $service_table = $wpdb->prefix . 'td_service';
    
    $booking = $wpdb->get_row($wpdb->prepare("
        SELECT b.*, s.name as service_name, s.duration_min 
        FROM $booking_table b 
        LEFT JOIN $service_table s ON b.service_id = s.id 
        WHERE b.id = %d
    ", $booking_id), ARRAY_A);
    if ($booking && function_exists('td_bkg_booking_decrypt_row')) { $booking = td_bkg_booking_decrypt_row($booking); }
    
    if (!$booking) return false;
    
    // Get staff information
    $staff_name = 'Staff';
    if (function_exists('td_bkg_get_staff_safe') && $booking['staff_id']) {
        $staff = td_bkg_get_staff_safe($booking['staff_id']);
        $staff_name = $staff['display_name'] ?? "Staff #{$booking['staff_id']}";
    }
    
    // Format datetime for display
    $local_timezone = wp_timezone();
    $start_dt = new DateTime($booking['start_utc'], new DateTimeZone('UTC'));
    $start_dt->setTimezone($local_timezone);
    $end_dt = new DateTime($booking['end_utc'], new DateTimeZone('UTC'));
    $end_dt->setTimezone($local_timezone);
    
    $date_format = get_option('date_format', 'F j, Y');
    $time_format = get_option('time_format', 'g:i A');
    
    $booking_date = $start_dt->format($date_format);
    $booking_time = $start_dt->format($time_format) . ' - ' . $end_dt->format($time_format);
    $timezone_name = $local_timezone->getName();
    
    // Create ICS attachment
    $ics_content = td_bkg_ics([
        'uid' => $booking['caldav_uid'] ?: 'tdbkg-' . $booking['id'],
        'dtstart' => $start_dt->format('Ymd\THis\Z'),
        'dtend' => $end_dt->format('Ymd\THis\Z'),
        'summary' => $booking['service_name'] . ' - ' . $booking['customer_name'],
        'description' => $booking['notes'] ?: 'Booking appointment',
        'method' => 'REQUEST'
    ]);
    
    $to = $booking['customer_email'];
    $subject = sprintf(__('[%s] Booking Confirmation - %s', 'td-booking'), get_bloginfo('name'), $booking['service_name']);
    
    $message = '<h2 style="color: #0073aa;">Thank you for your booking!</h2>
        
        <p>Dear ' . esc_html($booking['customer_name']) . ',</p>
        
        <p>Your booking has been confirmed. Here are the details:</p>
        
        <div style="background-color: #f9f9f9; border: 1px solid #ddd; border-radius: 5px; padding: 20px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #0073aa;">Booking Details</h3>
            <p><strong>Service:</strong> ' . esc_html($booking['service_name']) . '</p>
            <p><strong>Date:</strong> ' . esc_html($booking_date) . '</p>
            <p><strong>Time:</strong> ' . esc_html($booking_time) . ' (' . esc_html($timezone_name) . ')</p>
            <p><strong>Duration:</strong> ' . intval($booking['duration_min']) . ' minutes</p>
            <p><strong>Technician:</strong> ' . esc_html($staff_name) . '</p>
            <p><strong>Booking ID:</strong> #' . $booking['id'] . '</p>';
    
    if (!empty($booking['notes'])) {
        $message .= '<p><strong>Notes:</strong> ' . esc_html($booking['notes']) . '</p>';
    }
    
    $message .= '</div>';
    
    // Action links (reschedule/cancel)
    if (!empty($booking['ics_token'])) {
        $cancel_url = function_exists('td_bkg_public_cancel_url') ? td_bkg_public_cancel_url($booking['id'], $booking['ics_token']) : '';
        $reschedule_url = function_exists('td_bkg_public_reschedule_url') ? td_bkg_public_reschedule_url($booking['id'], $booking['ics_token']) : '';
        $message .= '<p><a href="' . esc_url($reschedule_url) . '" style="margin-right:12px">' . __('Reschedule', 'td-booking') . '</a>';
        $message .= '<a href="' . esc_url($cancel_url) . '">' . __('Cancel', 'td-booking') . '</a></p>';
    }

    $message .= '<p>We look forward to seeing you!</p>
        
        <p>If you need to make changes or have any questions, please contact us.</p>
        
        <p>Best regards,<br>
        The ' . esc_html(get_bloginfo('name')) . ' Team</p>';
    
    return td_bkg_mailer($to, $subject, $message, $ics_content, 'confirmation', $booking);
}

/**
 * Enhanced booking reminder email
 */
function td_bkg_send_booking_reminder($booking_id) {
    global $wpdb;
    $booking_table = $wpdb->prefix . 'td_booking';
    $service_table = $wpdb->prefix . 'td_service';
    
    $booking = $wpdb->get_row($wpdb->prepare("
        SELECT b.*, s.name as service_name 
        FROM $booking_table b 
        LEFT JOIN $service_table s ON b.service_id = s.id 
        WHERE b.id = %d
    ", $booking_id), ARRAY_A);
    if ($booking && function_exists('td_bkg_booking_decrypt_row')) { $booking = td_bkg_booking_decrypt_row($booking); }
    
    if (!$booking) return false;
    
    // Format datetime for display
    $local_timezone = wp_timezone();
    $start_dt = new DateTime($booking['start_utc'], new DateTimeZone('UTC'));
    $start_dt->setTimezone($local_timezone);
    $end_dt = new DateTime($booking['end_utc'], new DateTimeZone('UTC'));
    $end_dt->setTimezone($local_timezone);
    
    $date_format = get_option('date_format', 'F j, Y');
    $time_format = get_option('time_format', 'g:i A');
    
    $booking_date = $start_dt->format($date_format);
    $booking_time = $start_dt->format($time_format) . ' - ' . $end_dt->format($time_format);
    
    $to = $booking['customer_email'];
    $subject = sprintf(__('[%s] Reminder: %s tomorrow', 'td-booking'), get_bloginfo('name'), $booking['service_name']);
    
    $message = '<h2 style="color: #0073aa;">Reminder: Your appointment is soon</h2>
        
        <p>Dear ' . esc_html($booking['customer_name']) . ',</p>
        
        <p>This is a friendly reminder about your upcoming appointment:</p>
        
        <div style="background-color: #f9f9f9; border: 1px solid #ddd; border-radius: 5px; padding: 20px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #0073aa;">Appointment Details</h3>
            <p><strong>Service:</strong> ' . esc_html($booking['service_name']) . '</p>
            <p><strong>Date:</strong> ' . esc_html($booking_date) . '</p>
            <p><strong>Time:</strong> ' . esc_html($booking_time) . '</p>
            <p><strong>Booking ID:</strong> #' . $booking['id'] . '</p>
        </div>
        
        <p>Please arrive on time and bring any necessary documentation.</p>
        
        <p>If you need to reschedule or have any questions, please contact us as soon as possible.</p>
        
        <p>Thank you,<br>
        The ' . esc_html(get_bloginfo('name')) . ' Team</p>';
    
    return td_bkg_mailer($to, $subject, $message);
}

/**
 * Enhanced booking cancellation email
 */
function td_bkg_send_booking_cancellation($booking_id, $reason = '') {
    global $wpdb;
    $booking_table = $wpdb->prefix . 'td_booking';
    $service_table = $wpdb->prefix . 'td_service';
    
    $booking = $wpdb->get_row($wpdb->prepare("
        SELECT b.*, s.name as service_name 
        FROM $booking_table b 
        LEFT JOIN $service_table s ON b.service_id = s.id 
        WHERE b.id = %d
    ", $booking_id), ARRAY_A);
    if ($booking && function_exists('td_bkg_booking_decrypt_row')) { $booking = td_bkg_booking_decrypt_row($booking); }
    
    if (!$booking) return false;
    
    // Format datetime for display
    $local_timezone = wp_timezone();
    $start_dt = new DateTime($booking['start_utc'], new DateTimeZone('UTC'));
    $start_dt->setTimezone($local_timezone);
    
    $date_format = get_option('date_format', 'F j, Y');
    $time_format = get_option('time_format', 'g:i A');
    
    $booking_date = $start_dt->format($date_format);
    $booking_time = $start_dt->format($time_format);
    
    // Create cancellation ICS
    $ics_content = td_bkg_ics([
        'uid' => $booking['caldav_uid'] ?: 'tdbkg-' . $booking['id'],
        'dtstart' => $start_dt->format('Ymd\THis\Z'),
        'dtend' => (new DateTime($booking['end_utc'], new DateTimeZone('UTC')))->format('Ymd\THis\Z'),
        'summary' => $booking['service_name'] . ' - ' . $booking['customer_name'],
        'description' => 'Cancelled: ' . ($reason ?: 'No reason provided'),
        'method' => 'CANCEL'
    ]);
    
    $to = $booking['customer_email'];
    $subject = sprintf(__('[%s] Booking Cancelled - %s', 'td-booking'), get_bloginfo('name'), $booking['service_name']);
    
    $message = '<h2 style="color: #dc3232;">Booking Cancelled</h2>
        
        <p>Dear ' . esc_html($booking['customer_name']) . ',</p>
        
        <p>Your booking has been cancelled.</p>
        
        <div style="background-color: #f9f9f9; border: 1px solid #ddd; border-radius: 5px; padding: 20px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #dc3232;">Cancelled Booking Details</h3>
            <p><strong>Service:</strong> ' . esc_html($booking['service_name']) . '</p>
            <p><strong>Date:</strong> ' . esc_html($booking_date) . '</p>
            <p><strong>Time:</strong> ' . esc_html($booking_time) . '</p>
            <p><strong>Booking ID:</strong> #' . $booking['id'] . '</p>';
    
    if ($reason) {
        $message .= '<p><strong>Reason:</strong> ' . esc_html($reason) . '</p>';
    }
    
    $message .= '</div>
        
        <p>If you would like to reschedule, please visit our website to book a new appointment.</p>
        
        <p>We apologize for any inconvenience and hope to see you again soon.</p>
        
        <p>Best regards,<br>
        The ' . esc_html(get_bloginfo('name')) . ' Team</p>';
    
    return td_bkg_mailer($to, $subject, $message, $ics_content);
}
