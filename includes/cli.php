<?php
/**
 * WP-CLI Commands for TD Booking Plugin
 * 
 * Provides command line tools for:
 * - Seeding demo data
 * - Cache management  
 * - Background job operations
 * - Audit trail management
 */

if (!defined('ABSPATH')) {
    exit;
}

// Only load if WP-CLI is available
if (!class_exists('WP_CLI')) {
    return;
}

/**
 * TD Booking WP-CLI Commands
 */
class TD_Booking_CLI_Command {

    /**
     * Seed demo booking data for testing
     * 
     * ## OPTIONS
     * 
     * [--count=<count>]
     * : Number of demo bookings to create (default: 50)
     * 
     * [--days=<days>]
     * : Number of days in the past/future to spread bookings (default: 30)
     * 
     * [--services=<services>]
     * : Comma-separated service IDs to use (optional)
     * 
     * [--staff=<staff>]
     * : Comma-separated staff IDs to use (optional)
     * 
     * ## EXAMPLES
     * 
     *     wp td-booking seed --count=100 --days=60
     *     wp td-booking seed --services=1,2,3 --staff=5,6
     * 
     * @param array $args
     * @param array $assoc_args
     */
    public function seed($args, $assoc_args) {
        global $wpdb;
        
        $count = intval($assoc_args['count'] ?? 50);
        $days = intval($assoc_args['days'] ?? 30);
        $specific_services = !empty($assoc_args['services']) ? explode(',', $assoc_args['services']) : null;
        $specific_staff = !empty($assoc_args['staff']) ? explode(',', $assoc_args['staff']) : null;
        
        WP_CLI::line("Seeding $count demo bookings across $days days...");
        
        // Get available services
        $service_table = $wpdb->prefix . 'td_service';
        if ($specific_services) {
            $placeholders = implode(',', array_fill(0, count($specific_services), '%d'));
            $services = $wpdb->get_results($wpdb->prepare("SELECT * FROM $service_table WHERE id IN ($placeholders)", $specific_services));
        } else {
            $services = $wpdb->get_results("SELECT * FROM $service_table WHERE active = 1");
        }
        
        if (empty($services)) {
            WP_CLI::error('No services found. Please create services first.');
        }
        
        // Get available staff from TD Technicians
        if (function_exists('td_tech_get_all_technicians')) {
            $all_staff = td_tech_get_all_technicians();
            if ($specific_staff) {
                $all_staff = array_filter($all_staff, function($staff) use ($specific_staff) {
                    return in_array($staff['id'], $specific_staff);
                });
            }
        } else {
            // Fallback: use service-staff mappings
            $mapping_table = $wpdb->prefix . 'td_service_staff';
            $staff_ids = $wpdb->get_col("SELECT DISTINCT staff_id FROM $mapping_table");
            $all_staff = array_map(function($id) { return ['id' => $id, 'name' => "Staff $id"]; }, $staff_ids);
        }
        
        if (empty($all_staff)) {
            WP_CLI::error('No staff found. Please set up staff assignments first.');
        }
        
        $booking_table = $wpdb->prefix . 'td_booking';
        $statuses = ['confirmed', 'pending', 'cancelled'];
        $demo_names = ['John Doe', 'Jane Smith', 'Bob Johnson', 'Alice Brown', 'Charlie Wilson', 'Diana Davis', 'Frank Miller', 'Grace Lee'];
        $demo_emails = ['john@example.com', 'jane@example.com', 'bob@example.com', 'alice@example.com', 'charlie@example.com', 'diana@example.com', 'frank@example.com', 'grace@example.com'];
        
        $created = 0;
        $progress = WP_CLI\Utils\make_progress_bar('Creating bookings', $count);
        
        for ($i = 0; $i < $count; $i++) {
            $service = $services[array_rand($services)];
            $staff = $all_staff[array_rand($all_staff)];
            
            // Random date within range
            $random_days = rand(-$days, $days);
            $booking_date = date('Y-m-d', strtotime("$random_days days"));
            
            // Random time during business hours
            $hour = rand(9, 17);
            $minute = rand(0, 3) * 15; // 15-minute slots
            $booking_time = sprintf('%02d:%02d:00', $hour, $minute);
            
            // Random customer
            $customer_name = $demo_names[array_rand($demo_names)];
            $customer_email = $demo_emails[array_rand($demo_emails)];
            
            // Random status (mostly confirmed)
            $status_weights = ['confirmed' => 70, 'pending' => 20, 'cancelled' => 10];
            $rand = rand(1, 100);
            if ($rand <= 70) {
                $status = 'confirmed';
            } elseif ($rand <= 90) {
                $status = 'pending'; 
            } else {
                $status = 'cancelled';
            }
            
            $notes = rand(1, 3) == 1 ? 'Demo booking - ' . $service->name : '';
            
            $result = $wpdb->insert($booking_table, [
                'service_id' => $service->id,
                'staff_id' => $staff['id'],
                'booking_date' => $booking_date,
                'booking_time' => $booking_time,
                'customer_name' => $customer_name,
                'customer_email' => $customer_email,
                'status' => $status,
                'notes' => $notes,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);
            
            if ($result) {
                $created++;
            }
            
            $progress->tick();
        }
        
        $progress->finish();
        WP_CLI::success("Created $created demo bookings successfully!");
        
        // Clear cache after seeding
        WP_CLI::line('Clearing availability cache...');
        $this->clear_cache([], []);
    }

    /**
     * Clear all booking-related caches
     * 
     * ## EXAMPLES
     * 
     *     wp td-booking clear-cache
     * 
     * @param array $args
     * @param array $assoc_args
     */
    public function clear_cache($args, $assoc_args) {
        global $wpdb;
        
        WP_CLI::line('Clearing TD Booking caches...');
        
        // Clear availability cache
        $cache_table = $wpdb->prefix . 'td_calendar_cache';
        $deleted = $wpdb->query("DELETE FROM $cache_table");
        WP_CLI::line("- Cleared $deleted availability cache entries");
        
        // Clear WordPress transients
        $transients_deleted = 0;
        $transient_keys = [
            'td_bkg_services_',
            'td_bkg_staff_',
            'td_bkg_availability_',
            'td_bkg_exceptions_'
        ];
        
        foreach ($transient_keys as $key) {
            $results = $wpdb->get_results($wpdb->prepare("
                SELECT option_name FROM {$wpdb->options} 
                WHERE option_name LIKE %s OR option_name LIKE %s
            ", "_transient_$key%", "_transient_timeout_$key%"));
            
            foreach ($results as $result) {
                delete_option($result->option_name);
                $transients_deleted++;
            }
        }
        
        WP_CLI::line("- Cleared $transients_deleted WordPress transients");
        
        // Clear object cache if available
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            WP_CLI::line('- Flushed object cache');
        }
        
        WP_CLI::success('All caches cleared successfully!');
    }

    /**
     * Retry failed background jobs
     * 
     * ## OPTIONS
     * 
     * [--type=<type>]
     * : Job type to retry (caldav_sync, send_email, etc.)
     * 
     * [--limit=<limit>]
     * : Maximum number of jobs to retry (default: 50)
     * 
     * ## EXAMPLES
     * 
     *     wp td-booking retry-failed
     *     wp td-booking retry-failed --type=caldav_sync --limit=10
     * 
     * @param array $args
     * @param array $assoc_args
     */
    public function retry_failed($args, $assoc_args) {
        $job_type = $assoc_args['type'] ?? null;
        $limit = intval($assoc_args['limit'] ?? 50);
        
        WP_CLI::line('Retrying failed background jobs...');
        
        // Get failed scheduled actions (Action Scheduler)
        if (class_exists('ActionScheduler_Store')) {
            
            $store = ActionScheduler_Store::instance();
            
            $search_args = [
                'status' => 'failed',
                'per_page' => $limit,
                'orderby' => 'date',
                'order' => 'ASC'
            ];
            
            if ($job_type) {
                $search_args['hook'] = "td_bkg_job_$job_type";
            } else {
                $search_args['search'] = 'td_bkg_job_';
            }
            
            $failed_actions = $store->query_actions($search_args);
            
            if (empty($failed_actions)) {
                WP_CLI::line('No failed jobs found to retry.');
                return;
            }
            
            $retried = 0;
            $progress = WP_CLI\Utils\make_progress_bar('Retrying jobs', count($failed_actions));
            
            foreach ($failed_actions as $action_id) {
                $action = $store->fetch_action($action_id);
                
                if ($action) {
                    // Schedule a new action with the same parameters
                    $new_action_id = as_schedule_single_action(
                        time(),
                        $action->get_hook(),
                        $action->get_args(),
                        $action->get_group()
                    );
                    
                    if ($new_action_id) {
                        $retried++;
                    }
                }
                
                $progress->tick();
            }
            
            $progress->finish();
            WP_CLI::success("Retried $retried failed jobs successfully!");
            
        } else {
            WP_CLI::error('Action Scheduler not available. Cannot retry failed jobs.');
        }
    }

    /**
     * Run CalDAV reconciliation manually
     * 
     * ## OPTIONS
     * 
     * [--staff=<staff>]
     * : Comma-separated staff IDs to reconcile (default: all)
     * 
     * [--days=<days>]
     * : Number of days to reconcile (default: 30)
     * 
     * [--dry-run]
     * : Show what would be reconciled without making changes
     * 
     * ## EXAMPLES
     * 
     *     wp td-booking reconcile
     *     wp td-booking reconcile --staff=1,2,3 --days=7
     *     wp td-booking reconcile --dry-run
     * 
     * @param array $args
     * @param array $assoc_args
     */
    public function reconcile($args, $assoc_args) {
        $specific_staff = !empty($assoc_args['staff']) ? explode(',', $assoc_args['staff']) : null;
        $days = intval($assoc_args['days'] ?? 30);
        $dry_run = isset($assoc_args['dry-run']);
        
        if ($dry_run) {
            WP_CLI::line('DRY RUN: Showing what would be reconciled...');
        }
        
        WP_CLI::line("Running CalDAV reconciliation for $days days...");
        
        // Load reconcile functions
        require_once plugin_dir_path(__FILE__) . 'jobs/reconcile.php';
        
        if (!function_exists('td_bkg_reconcile_bookings')) {
            WP_CLI::error('Reconcile functions not available.');
        }
        
        // Get staff to reconcile
        if (function_exists('td_tech_get_all_technicians')) {
            $all_staff = td_tech_get_all_technicians();
            if ($specific_staff) {
                $all_staff = array_filter($all_staff, function($staff) use ($specific_staff) {
                    return in_array($staff['id'], $specific_staff);
                });
            }
        } else {
            WP_CLI::error('TD Technicians plugin not available for staff data.');
        }
        
        if (empty($all_staff)) {
            WP_CLI::error('No staff found to reconcile.');
        }
        
        $total_reconciled = 0;
        $progress = WP_CLI\Utils\make_progress_bar('Reconciling staff calendars', count($all_staff));
        
        foreach ($all_staff as $staff) {
            if ($dry_run) {
                WP_CLI::line("Would reconcile calendar for: {$staff['name']} (ID: {$staff['id']})");
            } else {
                try {
                    $result = td_bkg_reconcile_bookings($staff['id'], $days);
                    if ($result) {
                        $total_reconciled++;
                    }
                } catch (Exception $e) {
                    WP_CLI::warning("Failed to reconcile {$staff['name']}: " . $e->getMessage());
                }
            }
            
            $progress->tick();
        }
        
        $progress->finish();
        
        if ($dry_run) {
            WP_CLI::line('DRY RUN completed. Use without --dry-run to perform actual reconciliation.');
        } else {
            WP_CLI::success("Reconciled $total_reconciled staff calendars successfully!");
            
            // Clear cache after reconciliation
            WP_CLI::line('Clearing availability cache...');
            $this->clear_cache([], []);
        }
    }

    /**
     * Dump audit trail for analysis
     * 
     * ## OPTIONS
     * 
     * [--days=<days>]
     * : Number of days to include (default: 30)
     * 
     * [--format=<format>]
     * : Output format (table, json, csv) (default: table)
     * 
     * [--booking=<booking_id>]
     * : Show audit trail for specific booking
     * 
     * ## EXAMPLES
     * 
     *     wp td-booking dump-audit
     *     wp td-booking dump-audit --days=7 --format=json
     *     wp td-booking dump-audit --booking=123
     * 
     * @param array $args
     * @param array $assoc_args
     */
    public function dump_audit($args, $assoc_args) {
        global $wpdb;
        
        $days = intval($assoc_args['days'] ?? 30);
        $format = $assoc_args['format'] ?? 'table';
        $booking_id = $assoc_args['booking'] ?? null;
        
        $audit_table = $wpdb->prefix . 'td_audit';
        
        if ($booking_id) {
            WP_CLI::line("Audit trail for booking #$booking_id:");
            $audits = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM $audit_table 
                WHERE booking_id = %d 
                ORDER BY created_at DESC
            ", $booking_id));
        } else {
            WP_CLI::line("Audit trail for last $days days:");
            $audits = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM $audit_table 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                ORDER BY created_at DESC
                LIMIT 1000
            ", $days));
        }
        
        if (empty($audits)) {
            WP_CLI::line('No audit entries found.');
            return;
        }
        
        // Format output
        switch ($format) {
            case 'json':
                WP_CLI::line(json_encode($audits, JSON_PRETTY_PRINT));
                break;
                
            case 'csv':
                $headers = ['ID', 'Booking ID', 'Action', 'User ID', 'Meta Data', 'Created At'];
                WP_CLI::line(implode(',', $headers));
                
                foreach ($audits as $audit) {
                    $row = [
                        $audit->id,
                        $audit->booking_id,
                        $audit->action,
                        $audit->user_id,
                        '"' . str_replace('"', '""', $audit->meta_data) . '"',
                        $audit->created_at
                    ];
                    WP_CLI::line(implode(',', $row));
                }
                break;
                
            case 'table':
            default:
                $table_data = [];
                foreach ($audits as $audit) {
                    $meta = json_decode($audit->meta_data, true);
                    $meta_summary = is_array($meta) ? implode(', ', array_keys($meta)) : $audit->meta_data;
                    
                    $table_data[] = [
                        'ID' => $audit->id,
                        'Booking' => $audit->booking_id,
                        'Action' => $audit->action,
                        'User' => $audit->user_id,
                        'Meta' => substr($meta_summary, 0, 50) . (strlen($meta_summary) > 50 ? '...' : ''),
                        'Date' => $audit->created_at
                    ];
                }
                
                WP_CLI\Utils\format_items('table', $table_data, ['ID', 'Booking', 'Action', 'User', 'Meta', 'Date']);
                break;
        }
        
        WP_CLI::success('Audit dump completed!');
    }

    /**
     * Show booking statistics
     * 
     * ## OPTIONS
     * 
     * [--days=<days>]
     * : Number of days to analyze (default: 30)
     * 
     * ## EXAMPLES
     * 
     *     wp td-booking stats
     *     wp td-booking stats --days=7
     * 
     * @param array $args
     * @param array $assoc_args
     */
    public function stats($args, $assoc_args) {
        global $wpdb;
        
        $days = intval($assoc_args['days'] ?? 30);
        $booking_table = $wpdb->prefix . 'td_booking';
        
        WP_CLI::line("TD Booking Statistics (Last $days days)");
        WP_CLI::line(str_repeat('=', 50));
        
        // Total bookings by status
        $status_stats = $wpdb->get_results($wpdb->prepare("
            SELECT status, COUNT(*) as count 
            FROM $booking_table 
            WHERE booking_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
            GROUP BY status
        ", $days));
        
        WP_CLI::line('Bookings by Status:');
        foreach ($status_stats as $stat) {
            WP_CLI::line("  {$stat->status}: {$stat->count}");
        }
        
        // Daily average
        $total_bookings = array_sum(array_column($status_stats, 'count'));
        $daily_average = round($total_bookings / $days, 2);
        WP_CLI::line("Daily Average: $daily_average bookings");
        
        WP_CLI::line('');
        
        // Busiest days
        $daily_stats = $wpdb->get_results($wpdb->prepare("
            SELECT booking_date, COUNT(*) as count 
            FROM $booking_table 
            WHERE booking_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
            GROUP BY booking_date 
            ORDER BY count DESC 
            LIMIT 5
        ", $days));
        
        WP_CLI::line('Busiest Days:');
        foreach ($daily_stats as $day) {
            WP_CLI::line("  {$day->booking_date}: {$day->count} bookings");
        }
        
        WP_CLI::line('');
        
        // Popular services
        $service_stats = $wpdb->get_results($wpdb->prepare("
            SELECT b.service_id, s.name, COUNT(*) as count 
            FROM $booking_table b
            LEFT JOIN {$wpdb->prefix}td_service s ON b.service_id = s.id
            WHERE b.booking_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
            GROUP BY b.service_id, s.name 
            ORDER BY count DESC 
            LIMIT 5
        ", $days));
        
        WP_CLI::line('Popular Services:');
        foreach ($service_stats as $service) {
            $name = $service->name ?: "Service #{$service->service_id}";
            WP_CLI::line("  $name: {$service->count} bookings");
        }
        
        WP_CLI::success('Statistics generated successfully!');
    }

    /**
     * Send a test email using the plugin mailer
     *
     * ## OPTIONS
     *
     * [--to=<email>]
     * : Recipient email (default: admin_email)
     *
     * [--ics]
     * : Include a tiny ICS attachment to validate attachments
     *
     * ## EXAMPLES
     *
     *     wp td-booking mail-test --to=user@example.com
     *     wp td-booking mail-test --ics
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function mail_test($args, $assoc_args) {
        $to = $assoc_args['to'] ?? get_option('admin_email');
        if (!is_email($to)) {
            WP_CLI::error('Invalid recipient email.');
        }
        if (!function_exists('td_bkg_mailer')) {
            require_once plugin_dir_path(__FILE__) . 'mailer.php';
        }
        $include_ics = isset($assoc_args['ics']);
        $subject = '[TD Booking] Mail test';
        $body = 'This is a TD Booking test email sent at ' . gmdate('c') . ' UTC from ' . home_url('/');
        $ics = null;
        if ($include_ics && function_exists('td_bkg_ics')) {
            $start = gmdate('Ymd\\THis\\Z', time() + 600);
            $end = gmdate('Ymd\\THis\\Z', time() + 1800);
            $ics = td_bkg_ics([
                'uid' => 'tdbkg-mailtest-cli-' . wp_generate_password(8, false, false),
                'dtstart' => $start,
                'dtend' => $end,
                'summary' => 'TD Booking Mail Test',
                'description' => 'Test event to verify ICS attachments.'
            ]);
        }
        WP_CLI::line('Sending test email to ' . $to . ($include_ics ? ' (with ICS)' : ''));
        $ok = td_bkg_mailer($to, $subject, $body, $ics, 'cli-test', []);
        if ($ok) {
            WP_CLI::success('Email sent (wp_mail returned true).');
        } else {
            WP_CLI::error('Email failed (see audit log for wp_mail_failed details).');
        }
    }

    /**
     * Test CalDAV connectivity for a staff member
     *
     * ## OPTIONS
     *
     * --staff=<id>
     * : Staff ID to use for CalDAV test-connection
     *
     * ## EXAMPLES
     *
     *     wp td-booking caldav-test --staff=12
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function caldav_test($args, $assoc_args) {
        $staff_id = intval($assoc_args['staff'] ?? 0);
        if ($staff_id <= 0) {
            WP_CLI::error('Missing required --staff=<id>');
        }
        if (!function_exists('td_tech') || !method_exists(td_tech(), 'caldav')) {
            WP_CLI::error('TD Technicians CalDAV API not available.');
        }
        // Fetch credentials and normalize
        try {
            $creds = td_tech()->caldav()->get_credentials($staff_id);
        } catch (Exception $e) {
            WP_CLI::error('Failed to load credentials: ' . $e->getMessage());
            return;
        }
        if (function_exists('td_bkg_normalize_caldav_creds')) {
            $creds = td_bkg_normalize_caldav_creds($creds);
        }
        $url = $creds['url'] ?? '';
        $user = $creds['user'] ?? '';
        $pass = $creds['pass'] ?? '';
        if (empty($url) || empty($user) || empty($pass)) {
            WP_CLI::error('Missing CalDAV credentials (url/user/pass).');
        }
        if (!function_exists('td_bkg_caldav_request')) {
            require_once plugin_dir_path(__FILE__) . 'caldav/client.php';
        }
        WP_CLI::line("Testing CalDAV OPTIONS for staff #$staff_id...");
        $res = td_bkg_caldav_request($url, 'OPTIONS', '', [], $user, $pass);
        $status = intval($res['status'] ?? 0);
        // Mask URL output
        $safe_url = $url;
        $parts = parse_url($url);
        if ($parts) {
            $safe_url = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'host') . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
        }
        if ($status >= 200 && $status < 300) {
            WP_CLI::success("Connection OK (HTTP $status) @ $safe_url");
        } else {
            $err = $res['error'] ?: ('HTTP ' . $status);
            WP_CLI::error("CalDAV connection failed: $err @ $safe_url");
        }
    }

    /**
     * Perform a booking smoke test end-to-end (creates a booking and attempts CalDAV sync)
     *
     * ## OPTIONS
     *
     * --service=<id>
     * : Service ID to book
     *
     * [--start=<"YYYY-mm-dd HH:MM:SS">]
     * : Start time in UTC. If not provided, will search next available slot within 7 days.
     *
     * [--name=<name>]
     * : Customer name (default: CLI Test)
     *
     * [--email=<email>]
     * : Customer email (default: cli-test@example.com)
     *
     * ## EXAMPLES
     *
     *     wp td-booking book-smoke --service=1
     *     wp td-booking book-smoke --service=2 --start="2025-09-30 14:00:00" --name="Alice" --email="alice@example.com"
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function book_smoke($args, $assoc_args) {
        global $wpdb;
        $service_id = intval($assoc_args['service'] ?? 0);
        if ($service_id <= 0) {
            WP_CLI::error('Missing required --service=<id>');
        }
        $start_utc = $assoc_args['start'] ?? '';
        $name = $assoc_args['name'] ?? 'CLI Test';
        $email = $assoc_args['email'] ?? 'cli-test@example.com';

        // Load availability engine if needed
        if (!function_exists('td_bkg_availability_engine')) {
            require_once plugin_dir_path(__FILE__) . 'availability/engine.php';
        }
        // Find service and duration
        $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_service WHERE id=%d AND active=1", $service_id), ARRAY_A);
        if (!$service) {
            WP_CLI::error('Service not found or inactive.');
        }
        $duration = intval($service['duration_min'] ?? 60);

        if ($start_utc === '') {
            // Look for next available slot within 7 days
            $from = gmdate('Y-m-d 00:00:00');
            $to = gmdate('Y-m-d 23:59:59', time() + 7 * DAY_IN_SECONDS);
            $slots = td_bkg_availability_engine($service_id, $from, $to, $duration, ['return_staff' => false]);
            if (empty($slots)) {
                WP_CLI::error('No available slots found in the next 7 days for this service.');
            }
            $start_iso = $slots[0]['start_utc']; // e.g. 2025-09-25T14:00:00Z
            $start_utc = str_replace('T', ' ', substr($start_iso, 0, 19));
        }

        // Prepare request and call REST handler directly
        if (!function_exists('td_bkg_rest_book')) {
            require_once plugin_dir_path(__FILE__) . 'rest/public-book.php';
        }
        $req = [
            'service_id' => $service_id,
            'start_utc' => $start_utc,
            'customer' => [ 'name' => $name, 'email' => $email ],
        ];
        WP_CLI::line("Attempting booking: service=$service_id start_utc='$start_utc' name='$name' email='$email'");
        $res = td_bkg_rest_book($req);

        if (is_wp_error($res)) {
            $data = $res->get_error_data();
            $code = $data['status'] ?? 400;
            WP_CLI::error('Booking failed: ' . $res->get_error_message() . ' (HTTP ' . $code . ')');
        }
        $data = $res->get_data ? $res->get_data() : $res; // WP_REST_Response or array
        $booking_id = intval($data['booking_id'] ?? 0);
        $status = $data['status'] ?? 'unknown';

        // Fetch row to show CalDAV fields
        $row = $booking_id ? $wpdb->get_row($wpdb->prepare("SELECT status, caldav_etag, caldav_uid FROM {$wpdb->prefix}td_booking WHERE id=%d", $booking_id), ARRAY_A) : null;
        $etag = $row['caldav_etag'] ?? '';
        $uid = $row['caldav_uid'] ?? '';

        if ($booking_id) {
            WP_CLI::success("Booked #$booking_id status=$status caldav_etag=" . ($etag !== '' ? $etag : '(none)') . ' uid=' . ($uid !== '' ? $uid : '(none)'));
        } else {
            WP_CLI::warning('Booking created but ID not returned.');
        }
    }
}

// Register the command
WP_CLI::add_command('td-booking', 'TD_Booking_CLI_Command');
