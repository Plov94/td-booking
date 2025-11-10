<?php
defined('ABSPATH') || exit;

// Compute available booking slots per staff, subtracting local and CalDAV busy events.
function td_bkg_availability_engine($service_id, $from, $to, $duration, $options = []) {
    $return_staff = is_array($options) && !empty($options['return_staff']);
    $override_staff_ids = [];
    $ignore_mapping = false;
    if (is_array($options)) {
        if (!empty($options['override_staff_ids']) && is_array($options['override_staff_ids'])) {
            $override_staff_ids = array_values(array_filter(array_map('intval', $options['override_staff_ids'])));
        }
        if (!empty($options['ignore_mapping'])) { $ignore_mapping = true; }
    }
    global $wpdb;
    
    $staff_breaks_enabled = get_option('td_bkg_staff_breaks_enabled');
    $break_ranges = [];
    if ($staff_breaks_enabled) {
        // Fetch staff-wide breaks/holidays (staff_id = 0) that overlap the requested range
        $breaks = $wpdb->get_results($wpdb->prepare(
            "SELECT start_utc, end_utc FROM {$wpdb->prefix}td_staff_breaks WHERE staff_id = 0 AND ((start_utc BETWEEN %s AND %s) OR (end_utc BETWEEN %s AND %s) OR (start_utc <= %s AND end_utc >= %s))",
            $from, $to, $from, $to, $from, $to
        ), ARRAY_A);
        $break_ranges = array_map(function($b) {
            return [strtotime($b['start_utc'] . ' UTC'), strtotime($b['end_utc'] . ' UTC')];
        }, $breaks);
    }
    $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}td_service WHERE id=%d AND active=1", $service_id), ARRAY_A);
    if (!$service) {
        // Allow synthetic service in agnostic staff mode
        if (!empty($override_staff_ids) || $ignore_mapping) {
            $default_dur = intval(get_option('td_bkg_default_duration_minutes', 30));
            if ($default_dur <= 0) { $default_dur = 30; }
            $service = [
                'id' => 0,
                'name' => __('Custom / Specifically requested', 'td-booking'),
                'duration_min' => $default_dur,
                'buffer_min' => 0,
                'skills_json' => '[]',
                'active' => 1,
            ];
        } else {
            return [];
        }
    }
    
    // Use requested duration or fall back to service duration
    $duration = $duration > 0 ? $duration : intval($service['duration_min']);
    $buffer = isset($service['buffer_min']) ? intval($service['buffer_min']) : 0;
    // Booking rules
    $lead_time_min = intval(get_option('td_bkg_lead_time_minutes', 60));
    $horizon_days = intval(get_option('td_bkg_booking_horizon_days', 30));
    $slot_grid_min = intval(get_option('td_bkg_slot_grid_minutes', 15));
    
    // Debug: log duration and service info
    // Debug log removed
    // Determine staff list
    if (!empty($override_staff_ids)) {
        $staff_ids = $override_staff_ids; // explicit override
    } else {
        // Get mapped staff for this service
        $staff_ids = $wpdb->get_col($wpdb->prepare("SELECT staff_id FROM {$wpdb->prefix}td_service_staff WHERE service_id=%d", $service_id));
    }
    // If no mapped staff, use all active technicians (filtered by required skills)
    if (empty($staff_ids) && !$ignore_mapping) {
        $required_skills = [];
        if (!empty($service['skills_json'])) {
            $required_skills = json_decode($service['skills_json'], true) ?: [];
        }
        if (function_exists('td_bkg_get_active_technicians')) {
            $techs = td_bkg_get_active_technicians($required_skills);
            $staff_ids = array_map(function($t){ return is_array($t) ? intval($t['id'] ?? 0) : (is_object($t) ? intval($t->id ?? 0) : 0); }, $techs);
            $staff_ids = array_values(array_filter(array_unique($staff_ids)));
            // Debug log removed
        }
    }
    
    // Debug: log staff mapping (final)
    // Debug log removed
    
    if (empty($staff_ids)) {
    // Debug log removed
        return [];
    }
    $slots = [];
    
    // Handle both date and datetime formats properly (treat as UTC bounds)
    if (strlen($from) > 10) {
        // Full datetime format: "2025-09-09 09:00:00" (treat as UTC if not timezone-qualified)
        $from_ts = strtotime($from . ' UTC');
        $to_ts = strtotime($to . ' UTC');
    } else {
        // Date only format: "2025-09-09" (treat as whole-day UTC bounds)
        $from_ts = strtotime($from . ' 00:00:00 UTC');
        $to_ts = strtotime($to . ' 23:59:59 UTC');
    }
    
    // Debug: log date range
    // Debug log removed
    
    // Site timezone for interpreting business hours (local times)
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
    
    // Log site timezone for diagnostics
    if ($site_tz instanceof DateTimeZone) {
    // Debug log removed
    }

    // Precompute global business-hour windows across the requested range (in UTC timestamps)
    $global_hours = get_option('td_bkg_global_hours', []);
    $global_windows_ts = [];
    try {
        $from_local = new DateTimeImmutable((strlen($from) > 10 ? $from : ($from . ' 00:00:00')), $site_tz);
        $to_local = new DateTimeImmutable((strlen($to) > 10 ? $to : ($to . ' 23:59:59')), $site_tz);
        $cur_local = new DateTimeImmutable($from_local->format('Y-m-d 00:00:00'), $site_tz);
        while ($cur_local <= $to_local) {
            $day_num = intval($cur_local->format('w')); // 0=Sun..
            $ranges = isset($global_hours[$day_num]) ? $global_hours[$day_num] : [];
            if (!empty($ranges)) {
                foreach ($ranges as $r) {
                    $start_str = isset($r['start']) ? $r['start'] : null;
                    $end_str = isset($r['end']) ? $r['end'] : null;
                    if (!$start_str || !$end_str) continue;
                    // Ensure HH:MM
                    if (!preg_match('/^\d{2}:\d{2}$/', $start_str) || !preg_match('/^\d{2}:\d{2}$/', $end_str)) continue;
                    $start_local = new DateTimeImmutable($cur_local->format('Y-m-d') . ' ' . $start_str . ':00', $site_tz);
                    $end_local = new DateTimeImmutable($cur_local->format('Y-m-d') . ' ' . $end_str . ':00', $site_tz);
                    // Skip invalid
                    if ($end_local <= $start_local) continue;
                    // Convert to UTC timestamps
                    $gs = $start_local->setTimezone($utc_tz)->getTimestamp();
                    $ge = $end_local->setTimezone($utc_tz)->getTimestamp();
                    // Keep within requested bounds
                    if ($ge < $from_ts || $gs > $to_ts) {
                        // outside
                    } else {
                        $global_windows_ts[] = [
                            'start_ts' => max($gs, $from_ts),
                            'end_ts' => min($ge, $to_ts),
                        ];
                    }
                }
            }
            $cur_local = $cur_local->modify('+1 day');
        }
    // Debug log removed
    } catch (Exception $e) {
    // Debug log removed
    }
    
    $enforcement_mode = get_option('td_bkg_global_hours_enforcement', 'restrict');
    // Debug log removed
    $group_enabled = get_option('td_bkg_group_enabled');
    $max_group = $group_enabled ? max(1, intval($service['max_group_size'] ?? 1)) : 1;
    require_once __DIR__ . '/cache.php';
    $technicians_available = function_exists('td_tech') && method_exists(td_tech(), 'schedule');
    // Compute absolute earliest/latest bounds considering lead time and horizon (UTC)
    $now_utc = time();
    $earliest_ts = $now_utc + max(0, $lead_time_min) * 60;
    $latest_ts = $now_utc + max(1, $horizon_days) * DAY_IN_SECONDS;

    foreach ($staff_ids as $staff_id) {
        // Temporarily disable cache to debug
        $cached = false; // td_bkg_availability_cache($staff_id, $from, $to);
        
        if ($cached !== false && is_array($cached)) {
            $slots = array_merge($slots, $cached);
            continue;
        }
    // Compute slots if not cached
        $windows = [];
    $pre_restrict_windows_count = 0;
    $restricted_applied = false;
        
    if ($technicians_available) {
            try {
                // Use STAFF timezone when querying schedule; default to site timezone
                $from_day = (strlen($from) > 10) ? substr($from, 0, 10) : $from;
                $to_day = (strlen($to) > 10) ? substr($to, 0, 10) : $to;
                $staff_tz = function_exists('td_bkg_get_staff_timezone') ? td_bkg_get_staff_timezone($staff_id) : null;
                $query_tz = ($staff_tz instanceof DateTimeZone) ? $staff_tz : $site_tz;
                // Debug log removed
                $from_date = new DateTime($from_day . ' 00:00:00', $query_tz);
                $to_date = new DateTime($to_day . ' 23:59:59', $query_tz);

                // Fetch windows via robust helper that tries multiple API shapes
                if (function_exists('td_bkg_fetch_schedule_windows')) {
                    $windows_raw = td_bkg_fetch_schedule_windows($staff_id, $from_date, $to_date, $query_tz);
                } else {
                    $windows_raw = [];
                }
                // Debug log removed
                
                if (!empty($windows_raw)) {
                    // Convert objects to arrays if needed
                    foreach ($windows_raw as $w) {
                        $start_ts = null; $end_ts = null;
                        if (is_array($w) && count($w) == 2) {
                            $start_dt = $w[0];
                            $end_dt = $w[1];
                            if ($start_dt instanceof DateTimeInterface && $end_dt instanceof DateTimeInterface) {
                                $start_ts = $start_dt->getTimestamp();
                                $end_ts = $end_dt->getTimestamp();
                            }
                        } elseif (is_object($w)) {
                            $sv = $w->start_utc ?? ($w->start ?? ($w->from_utc ?? ($w->from ?? null)));
                            $ev = $w->end_utc ?? ($w->end ?? ($w->to_utc ?? ($w->to ?? null)));
                            if ($sv instanceof DateTimeInterface) {
                                $start_ts = $sv->getTimestamp();
                            } elseif (!empty($sv)) {
                                try {
                                    $sv_dt = new DateTimeImmutable((string)$sv, $query_tz);
                                    $start_ts = $sv_dt->setTimezone($utc_tz)->getTimestamp();
                                } catch (Exception $ie) {
                                    $start_ts = strtotime((string)$sv);
                                }
                            }
                            if ($ev instanceof DateTimeInterface) {
                                $end_ts = $ev->getTimestamp();
                            } elseif (!empty($ev)) {
                                try {
                                    $ev_dt = new DateTimeImmutable((string)$ev, $query_tz);
                                    $end_ts = $ev_dt->setTimezone($utc_tz)->getTimestamp();
                                } catch (Exception $ie) {
                                    $end_ts = strtotime((string)$ev);
                                }
                            }
                        } elseif (is_array($w)) {
                            $sv = $w['start_utc'] ?? ($w['start'] ?? ($w['from_utc'] ?? ($w['from'] ?? null)));
                            $ev = $w['end_utc'] ?? ($w['end'] ?? ($w['to_utc'] ?? ($w['to'] ?? null)));
                            if (!empty($sv)) {
                                try {
                                    $sv_dt = new DateTimeImmutable((string)$sv, $query_tz);
                                    $start_ts = $sv_dt->setTimezone($utc_tz)->getTimestamp();
                                } catch (Exception $ie) {
                                    $start_ts = strtotime((string)$sv);
                                }
                            }
                            if (!empty($ev)) {
                                try {
                                    $ev_dt = new DateTimeImmutable((string)$ev, $query_tz);
                                    $end_ts = $ev_dt->setTimezone($utc_tz)->getTimestamp();
                                } catch (Exception $ie) {
                                    $end_ts = strtotime((string)$ev);
                                }
                            }
                        }
                        if ($start_ts && $end_ts && $end_ts > $start_ts) {
                            $windows[] = [ 'start_ts' => $start_ts, 'end_ts' => $end_ts ];
                            // Debug log removed
                        }
                    }
                } else {
                    // Build windows from weekly hours as provided by Technicians (fallback within technicians domain)
                    if (function_exists('td_bkg_get_staff_hours')) {
                        $hours = td_bkg_get_staff_hours($staff_id);
                        if (is_array($hours) && !empty($hours)) {
                            // Log summary of hours
                            $hour_counts = [];
                            for ($i=0;$i<7;$i++){ $hour_counts[$i] = isset($hours[$i]) && is_array($hours[$i]) ? count($hours[$i]) : 0; }
                            // Debug log removed
                            $tz_build = (isset($query_tz) && $query_tz instanceof DateTimeZone) ? $query_tz : $site_tz;
                            $from_day = (strlen($from) > 10) ? substr($from, 0, 10) : $from;
                            $to_day = (strlen($to) > 10) ? substr($to, 0, 10) : $to;
                            $cur = new DateTime($from_day . ' 00:00:00', $tz_build);
                            $end = new DateTime($to_day . ' 23:59:59', $tz_build);
                            while ($cur <= $end) {
                                $wday = intval($cur->format('w')); // 0..6
                                $day_shifts = $hours[$wday] ?? [];
                                if (is_array($day_shifts) && !empty($day_shifts)) {
                                    foreach ($day_shifts as $shift) {
                                        $st = $shift['start_time'] ?? ($shift['start'] ?? null);
                                        $et = $shift['end_time'] ?? ($shift['end'] ?? null);
                                        if (!$st || !$et) continue;
                                        if (!preg_match('/^\d{2}:\d{2}$/', $st) || !preg_match('/^\d{2}:\d{2}$/', $et)) continue;
                                        try {
                                            $start_local = new DateTimeImmutable($cur->format('Y-m-d') . ' ' . $st . ':00', $tz_build);
                                            $end_local = new DateTimeImmutable($cur->format('Y-m-d') . ' ' . $et . ':00', $tz_build);
                                            if ($end_local <= $start_local) continue;
                                            $gs = $start_local->setTimezone($utc_tz)->getTimestamp();
                                            $ge = $end_local->setTimezone($utc_tz)->getTimestamp();
                                            if ($ge < $from_ts || $gs > $to_ts) {
                                                // outside
                                            } else {
                                                $windows[] = [
                                                    'start_ts' => max($gs, $from_ts),
                                                    'end_ts' => min($ge, $to_ts)
                                                ];
                                            }
                                        } catch (Exception $ie) {
                                            // ignore this shift
                                        }
                                    }
                                }
                                $cur = $cur->modify('+1 day');
                            }
                            // Debug log removed
                        } else {
                            // Debug log removed
                        }
                    }
                }
            } catch (Exception $e) {
                // Technicians plugin error - continue with fallback
                // Debug log removed
            }
        } else {
            // Debug log removed
        }
        // Record count before global restriction
        $pre_restrict_windows_count = is_array($windows) ? count($windows) : 0;
        
        // Apply global hours enforcement
        if ($enforcement_mode === 'override') {
            // Use global hours only, but if none configured, keep technician windows to avoid zeroing out availability
            if (empty($global_windows_ts)) {
                // Debug log removed
            } else {
                $windows = $global_windows_ts;
                // Debug log removed
            }
        } elseif ($enforcement_mode === 'restrict') {
            // Intersect technicians windows with global hours when business hours are configured.
            // If no global hours defined, leave technicians windows as-is.
            if (empty($global_windows_ts)) {
                // No business hours configured -> do not restrict
                // Debug log removed
            } elseif (!empty($windows)) {
                $restricted = [];
                foreach ($windows as $tw) {
                    foreach ($global_windows_ts as $gw) {
                        $s = max($tw['start_ts'], $gw['start_ts']);
                        $e = min($tw['end_ts'], $gw['end_ts']);
                        if ($e > $s) {
                            $restricted[] = ['start_ts' => $s, 'end_ts' => $e];
                        }
                    }
                }
                $windows = $restricted;
                $restricted_applied = true;
                // Debug log removed
            } else {
                // Technicians windows empty, try optional fallback to business hours
                $restrict_fallback = (int) get_option('td_bkg_restrict_fallback_enabled', 1);
                if ($restrict_fallback) {
                    $windows = $global_windows_ts;
                    // Debug log removed
                } else {
                    $windows = [];
                    // Debug log removed
                }
            }
        }

        // FALLBACK: Only when technicians are NOT available at all and enforcement mode is off
        if (!$technicians_available && $enforcement_mode === 'off' && (empty($windows))) {
            // Reset windows if we got unusable data
            $windows = [];
            // Create basic work hours: Monday-Friday 9:00-17:00
            $current_date = new DateTime($from);
            $end_date = new DateTime($to);
            
            while ($current_date <= $end_date) {
                $weekday = $current_date->format('w'); // 0=Sunday, 1=Monday, etc.
                
                // Only weekdays (Monday=1 to Friday=5)
                if ($weekday >= 1 && $weekday <= 5) {
                    $day_start = clone $current_date;
                    $day_start->setTime(9, 0, 0); // 9:00 AM
                    
                    $day_end = clone $current_date;
                    $day_end->setTime(17, 0, 0); // 5:00 PM
                    
                    $windows[] = [
                        'start_ts' => $day_start->getTimestamp(),
                        'end_ts' => $day_end->getTimestamp()
                    ];
                }
                
                $current_date->modify('+1 day');
            }
        }
        
    // Debug: Check what windows we generated
    // Debug log removed
        
    // Pull staff-level exceptions (holidays/sick/custom) from TD Technicians and add to exclusion ranges
        $staff_exception_ranges = [];
        if (function_exists('td_bkg_get_staff_exceptions')) {
            try {
                $from_dt = (strlen($from) > 10) ? new DateTime($from) : new DateTime($from . ' 00:00:00');
                $to_dt = (strlen($to) > 10) ? new DateTime($to) : new DateTime($to . ' 23:59:59');
                $exceptions = td_bkg_get_staff_exceptions($staff_id, $from_dt, $to_dt);
                if (is_array($exceptions)) {
                    foreach ($exceptions as $ex) {
                        $start_ts = null;
                        $end_ts = null;
                        // Object: try common properties/methods
                        if (is_object($ex)) {
                            // Properties first
                            if (isset($ex->start) || property_exists($ex, 'start')) {
                                $v = $ex->start;
                                $start_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            } elseif (isset($ex->start_utc) || property_exists($ex, 'start_utc')) {
                                $v = $ex->start_utc;
                                $start_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            } elseif (isset($ex->from) || property_exists($ex, 'from')) {
                                $v = $ex->from;
                                $start_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            } elseif (isset($ex->from_utc) || property_exists($ex, 'from_utc')) {
                                $v = $ex->from_utc;
                                $start_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            } elseif (method_exists($ex, 'get_start')) {
                                $v = $ex->get_start();
                                $start_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            } elseif (method_exists($ex, 'getStart')) {
                                $v = $ex->getStart();
                                $start_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            } elseif (method_exists($ex, 'getStartDateTime')) {
                                $v = $ex->getStartDateTime();
                                $start_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            } elseif (method_exists($ex, 'getFrom') ) {
                                $v = $ex->getFrom();
                                $start_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            }
                            if (isset($ex->end) || property_exists($ex, 'end')) {
                                $v = $ex->end;
                                $end_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            } elseif (isset($ex->end_utc) || property_exists($ex, 'end_utc')) {
                                $v = $ex->end_utc;
                                $end_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            } elseif (isset($ex->to) || property_exists($ex, 'to')) {
                                $v = $ex->to;
                                $end_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            } elseif (isset($ex->to_utc) || property_exists($ex, 'to_utc')) {
                                $v = $ex->to_utc;
                                $end_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            } elseif (method_exists($ex, 'get_end')) {
                                $v = $ex->get_end();
                                $end_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            } elseif (method_exists($ex, 'getEnd')) {
                                $v = $ex->getEnd();
                                $end_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            } elseif (method_exists($ex, 'getEndDateTime')) {
                                $v = $ex->getEndDateTime();
                                $end_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            } elseif (method_exists($ex, 'getTo')) {
                                $v = $ex->getTo();
                                $end_ts = $v instanceof DateTimeInterface ? $v->getTimestamp() : strtotime((string)$v);
                            }
                        }
                        // Array fallback
                        if (is_array($ex)) {
                            $ex_start = $ex['start'] ?? ($ex['start_utc'] ?? ($ex['from'] ?? ($ex['from_utc'] ?? null)));
                            $ex_end = $ex['end'] ?? ($ex['end_utc'] ?? ($ex['to'] ?? ($ex['to_utc'] ?? null)));
                            if ($ex_start) $start_ts = strtotime((string)$ex_start);
                            if ($ex_end) $end_ts = strtotime((string)$ex_end);
                        }
                        if ($start_ts && $end_ts) {
                            $staff_exception_ranges[] = [$start_ts, $end_ts];
                        }
                    }
                    if (!empty($staff_exception_ranges)) {
                        // Debug log removed
                    } else {
                        // Debug log removed
                    }
                }
            } catch (Exception $e) {
                // ignore
            }
        }

    // Fetch busy bookings that overlap the requested window (proper overlap logic)
        $busy = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT start_utc, end_utc, group_size
                 FROM {$wpdb->prefix}td_booking
                 WHERE staff_id=%d
                   AND status IN ('pending','confirmed','failed_sync','conflicted')
                   AND (start_utc < %s AND end_utc > %s)",
                $staff_id,
                $to,
                $from
            ),
            ARRAY_A
        );
        $busy_count = is_array($busy) ? count($busy) : 0;
        $staff_slots = [];
        
        // Debug: log key variables
    // Debug log removed
        
        foreach ($windows as $win) {
            $start = isset($win['start_ts']) ? intval($win['start_ts']) : strtotime($win['start']);
            $end = isset($win['end_ts']) ? intval($win['end_ts']) : strtotime($win['end']);
            $grid_step = max(1, $slot_grid_min) * 60;
            $duration_with_buffer = ($duration + $buffer) * 60;
            for ($slot = $start; $slot + $duration * 60 <= $end; $slot += $grid_step) {
                // Apply bounds: from/to params, lead time, and horizon
                if ($slot < $from_ts || ($slot + $duration * 60) > $to_ts) continue;
                if ($slot < $earliest_ts) continue;
                if ($slot + $duration * 60 > $latest_ts) continue;
                // Exclude if slot overlaps any staff-wide break/holiday
                $overlap = false;
                foreach ($break_ranges as $br) {
                    if ($slot < $br[1] && ($slot + $duration * 60) > $br[0]) {
                        $overlap = true;
                        break;
                    }
                }
                // Exclude if slot overlaps any staff-level exception
                if (!$overlap && !empty($staff_exception_ranges)) {
                    foreach ($staff_exception_ranges as $er) {
                        if ($slot < $er[1] && ($slot + $duration * 60) > $er[0]) {
                            $overlap = true;
                            break;
                        }
                    }
                }
                if ($overlap) continue;
                $current_group = 0;
                foreach ($busy as $b) {
                    $b_start = strtotime($b['start_utc'] . ' UTC');
                    $b_end = strtotime($b['end_utc'] . ' UTC');
                    if ($slot < $b_end && ($slot + $duration * 60) > $b_start) {
                        $current_group += isset($b['group_size']) ? intval($b['group_size']) : 1;
                    }
                }
                if ($group_enabled && $current_group >= $max_group) continue;
                if (!$group_enabled && $current_group > 0) continue;
                $staff_slots[] = [
                    'start_utc' => gmdate('Y-m-d\TH:i:s\Z', $slot),
                    'end_utc' => gmdate('Y-m-d\TH:i:s\Z', $slot + $duration * 60),
                    'available_group' => $group_enabled ? max(0, $max_group - $current_group) : 0,
                    'staff_id' => intval($staff_id),
                ];
            }
        }
        // Summary line for this staff
        if (function_exists('td_bkg_debug_log')) {
            td_bkg_debug_log('Availability staff summary', [
                'staff_id' => $staff_id,
                'tech_windows' => $pre_restrict_windows_count,
                'global_mode' => $enforcement_mode,
                'global_windows' => is_array($global_windows_ts) ? count($global_windows_ts) : 0,
                'restricted_applied' => $restricted_applied,
                'windows_after' => is_array($windows) ? count($windows) : 0,
                'exceptions' => is_array($staff_exception_ranges) ? count($staff_exception_ranges) : 0,
                'busy' => $busy_count,
                'duration' => $duration,
                'grid_min' => $slot_grid_min,
                'lead_min' => $lead_time_min,
                'horizon_days' => $horizon_days,
                'final_slots' => is_array($staff_slots) ? count($staff_slots) : 0,
            ]);
        }

        // Cache for 5 minutes
        set_transient('td_bkg_avail_' . $staff_id . '_' . md5($from . $to), $staff_slots, 5 * MINUTE_IN_SECONDS);
        $slots = array_merge($slots, $staff_slots);
    }
    // Aggregate duplicate times across staff into unique slots unless per-staff requested
    if (!$return_staff) {
        if (!empty($slots)) {
            $aggregated = [];
            foreach ($slots as $s) {
                $key = $s['start_utc'] . '|' . $s['end_utc'];
                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'start_utc' => $s['start_utc'],
                        'end_utc' => $s['end_utc'],
                        'available_group' => isset($s['available_group']) ? intval($s['available_group']) : 1,
                    ];
                } else {
                    $aggregated[$key]['available_group'] += isset($s['available_group']) ? intval($s['available_group']) : 1;
                }
            }
            // Sort by start time
            usort($aggregated, function($a, $b) {
                return strcmp($a['start_utc'], $b['start_utc']);
            });
            $slots = array_values($aggregated);
        }
    } else {
        // Sort per-staff slots by start time
        usort($slots, function($a, $b) {
            return strcmp($a['start_utc'], $b['start_utc']);
        });
    }
    // Debug: log final result
    // Debug log removed
    return $slots;
}
// For future: implement robust DST edge handling tests.
