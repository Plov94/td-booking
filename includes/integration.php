<?php
defined('ABSPATH') || exit;

/**
 * TD Technicians Plugin Integration Helpers
 */

// Hook into TD Technicians events if available
add_action('plugins_loaded', 'td_bkg_setup_technicians_integration', 25);

function td_bkg_setup_technicians_integration() {
    if (!function_exists('td_tech')) {
        return;
    }
    
    // Hook into technicians events to invalidate availability cache
    add_action('td_tech_schedule_updated', 'td_bkg_invalidate_staff_availability_cache', 10, 1);
    add_action('td_tech_staff_updated', 'td_bkg_invalidate_staff_availability_cache', 10, 1);
    add_action('td_tech_exception_added', 'td_bkg_invalidate_staff_availability_cache', 10, 1);
}

/**
 * Try multiple TD Technicians schedule API call patterns to fetch work windows.
 * Returns raw windows (array) in whatever shape the API provides. Engine will parse.
 * Logs each attempt for diagnostics.
 */
function td_bkg_fetch_schedule_windows($staff_id, \DateTimeInterface $from_dt, \DateTimeInterface $to_dt, \DateTimeZone $tz_for_logs = null) {
    if (!function_exists('td_tech') || !method_exists(td_tech(), 'schedule')) {
        return [];
    }
    try {
        $schedule = td_tech()->schedule();
        $attempts = [];
        $windows = [];
        $tzlabel = $tz_for_logs ? $tz_for_logs->getName() : 'unknown';
        // Prepare variants
        $from_date_only = $from_dt->format('Y-m-d');
        $to_date_only = $to_dt->format('Y-m-d');
        $utc = new \DateTimeZone('UTC');
        $from_utc = (new \DateTimeImmutable('@' . $from_dt->getTimestamp()))->setTimezone($utc);
        $to_utc = (new \DateTimeImmutable('@' . $to_dt->getTimestamp()))->setTimezone($utc);

        // Helper to run and log
        $run = function($label, $callable) use (&$windows, &$attempts) {
            try {
                $res = $callable();
                $cnt = (is_array($res) ? count($res) : 0);
                $attempts[] = [$label, $cnt];
                if (!empty($res)) {
                    $windows = $res;
                    return true;
                }
            } catch (\Throwable $e) {
                $attempts[] = [$label . ' (error: ' . $e->getMessage() . ')', 0];
            }
            return false;
        };

        // Try daily windows with DateTime in staff tz
        if (method_exists($schedule, 'get_daily_work_windows')) {
            if ($run("daily(staff-tz DateTime) {$from_dt->format('Y-m-d H:i:s')}..{$to_dt->format('Y-m-d H:i:s')} tz={$tzlabel}", function() use ($schedule, $staff_id, $from_dt, $to_dt) {
                return $schedule->get_daily_work_windows($staff_id, $from_dt, $to_dt);
            })) goto done;

            // Date strings variant disabled to avoid setTimezone() on string errors in some implementations

            // UTC DateTime
            if ($run("daily(UTC DateTime) {$from_utc->format('Y-m-d H:i:s')}..{$to_utc->format('Y-m-d H:i:s')} tz=UTC", function() use ($schedule, $staff_id, $from_utc, $to_utc) {
                return $schedule->get_daily_work_windows($staff_id, $from_utc, $to_utc);
            })) goto done;
        }

        // Try generic work windows
        if (method_exists($schedule, 'get_work_windows')) {
            if ($run("work(staff-tz DateTime) {$from_dt->format('Y-m-d H:i:s')}..{$to_dt->format('Y-m-d H:i:s')} tz={$tzlabel}", function() use ($schedule, $staff_id, $from_dt, $to_dt) {
                return $schedule->get_work_windows($staff_id, $from_dt, $to_dt);
            })) goto done;

            // Date strings variant disabled to avoid setTimezone() on string errors

            if ($run("work(UTC DateTime) {$from_utc->format('Y-m-d H:i:s')}..{$to_utc->format('Y-m-d H:i:s')} tz=UTC", function() use ($schedule, $staff_id, $from_utc, $to_utc) {
                return $schedule->get_work_windows($staff_id, $from_utc, $to_utc);
            })) goto done;
        }

        done:
        // If result seems too sparse for a long range, try per-day accumulation
        $accumulated = [];
        $range_days = max(1, (int)floor(($to_dt->getTimestamp() - $from_dt->getTimestamp()) / DAY_IN_SECONDS) + 1);
        $win_cnt = is_array($windows) ? count($windows) : 0;
        if ($range_days > 1 && ($win_cnt === 0 || $win_cnt < $range_days)) {
            try {
                $cur = (new \DateTimeImmutable('@' . $from_dt->getTimestamp()))->setTimezone($from_dt->getTimezone());
                $end = (new \DateTimeImmutable('@' . $to_dt->getTimestamp()))->setTimezone($to_dt->getTimezone());
                while ($cur <= $end) {
                    $day_start = $cur->setTime(0,0,0);
                    $day_end = $cur->setTime(23,59,59);
                    // day attempt
                    $day_res = [];
                    if (method_exists($schedule, 'get_daily_work_windows')) {
                        try { $day_res = $schedule->get_daily_work_windows($staff_id, $day_start, $day_end); } catch (\Throwable $e) {}
                    }
                    if (empty($day_res) && method_exists($schedule, 'get_work_windows')) {
                        try { $day_res = $schedule->get_work_windows($staff_id, $day_start, $day_end); } catch (\Throwable $e) {}
                    }
                    if (!empty($day_res)) {
                        $accumulated = array_merge($accumulated, is_array($day_res) ? $day_res : []);
                    }
                    $cur = $cur->modify('+1 day');
                }
            } catch (\Throwable $e) {}
        }
        // Log attempts summary
        $lines = array_map(function($t){ return $t[0] . ' => ' . $t[1]; }, $attempts);
        $acc_cnt = is_array($accumulated) ? count($accumulated) : 0;
        if ($acc_cnt > 0 && $acc_cnt > $win_cnt) {
            if (function_exists('td_bkg_debug_log')) {
                td_bkg_debug_log('Schedule fetch attempts', [
                    'staff_id' => $staff_id,
                    'attempts' => $lines,
                    'accumulated' => $acc_cnt,
                    'supersedes' => $win_cnt,
                ]);
            }
            return $accumulated;
        }
        if (function_exists('td_bkg_debug_log')) {
            td_bkg_debug_log('Schedule fetch attempts', [ 'staff_id' => $staff_id, 'attempts' => $lines ]);
        }
        return is_array($windows) ? $windows : [];
    } catch (\Throwable $e) {
        if (function_exists('td_bkg_debug_log')) {
            td_bkg_debug_log('Schedule fetch error', [ 'staff_id' => $staff_id, 'error' => $e->getMessage() ]);
        }
        return [];
    }
}

/**
 * Invalidate availability cache for a specific staff member
 */
function td_bkg_invalidate_staff_availability_cache($staff_id) {
    if (!$staff_id) {
        return;
    }
    
    // Clear all cached availability for this staff member
    global $wpdb;
    
    // Delete transients for this staff member
    $transient_keys = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} 
         WHERE option_name LIKE %s",
        '_transient_td_bkg_avail_' . $staff_id . '_%'
    ));
    
    foreach ($transient_keys as $key) {
        $transient_name = str_replace('_transient_', '', $key);
        delete_transient($transient_name);
    }
}

/**
 * Get technician timezone for proper date handling
 */
function td_bkg_get_staff_timezone($staff_id) {
    if (!function_exists('td_tech')) {
        return new DateTimeZone('UTC');
    }
    
    try {
        $repo = td_tech()->repo();
        $staff = $repo->get($staff_id);
        if ($staff) {
            // Object: prefer method, then property
            if (is_object($staff)) {
                // Common method names
                if (method_exists($staff, 'get_timezone')) {
                    $tz = $staff->get_timezone();
                    if (!empty($tz)) return new DateTimeZone($tz);
                }
                if (method_exists($staff, 'getTimezone')) {
                    $tz = $staff->getTimezone();
                    if (!empty($tz)) return new DateTimeZone($tz);
                }
                // Property access
                if (isset($staff->timezone) && !empty($staff->timezone)) {
                    return new DateTimeZone($staff->timezone);
                }
                // Convert to array via known methods
                if (method_exists($staff, 'to_safe_array')) {
                    try {
                        $arr = $staff->to_safe_array();
                        if (!empty($arr['timezone'])) return new DateTimeZone($arr['timezone']);
                    } catch (Exception $e) {}
                }
                if (method_exists($staff, 'to_array')) {
                    try {
                        $arr = $staff->to_array();
                        if (!empty($arr['timezone'])) return new DateTimeZone($arr['timezone']);
                    } catch (Exception $e) {}
                }
                // Normalize via helper
                if (function_exists('td_bkg_normalize_technician_data')) {
                    $data = td_bkg_normalize_technician_data($staff);
                    if ($data && !empty($data['timezone'])) return new DateTimeZone($data['timezone']);
                }
            }
            // Array
            if (is_array($staff) && !empty($staff['timezone'])) {
                return new DateTimeZone($staff['timezone']);
            }
        }
    } catch (Exception $e) {
        // Silently fall through to default
    }
    
    return new DateTimeZone('UTC');
}

/**
 * Get weekly hours for a staff member from TD Technicians.
 * Returns array keyed by weekday 0..6, each value an array of shifts {start_time, end_time}
 */
function td_bkg_get_staff_hours($staff_id, $full_week = true) {
    $hours = [];
    if (!function_exists('td_tech')) {
        return $hours;
    }
    try {
        // Try schedule service method if available
        $schedule = method_exists(td_tech(), 'schedule') ? td_tech()->schedule() : null;
        if ($schedule && method_exists($schedule, 'get_hours')) {
            $res = $schedule->get_hours($staff_id);
            if (is_array($res)) return td_bkg_normalize_staff_hours($res);
        }
    } catch (Exception $e) {
        // continue to REST fallback
    }
    // REST fallback: GET /td-tech/v1/staff/{id}/hours?full_week=true|false
    if (function_exists('rest_url') && function_exists('wp_remote_get')) {
        $url = rest_url('td-tech/v1/staff/' . intval($staff_id) . '/hours?full_week=' . ($full_week ? 'true' : 'false'));
        $response = wp_remote_get($url, ['timeout' => 5]);
        if (!is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                if (is_array($data)) {
                    $hours = td_bkg_normalize_staff_hours($data);
                }
            }
        }
    }
    return $hours;
}

/**
 * Normalize weekly hours to keys 0..6 (0=Sun..6=Sat) and shifts [{start_time, end_time}].
 */
function td_bkg_normalize_staff_hours($raw) {
    // Prepare container
    $normalized = [0=>[],1=>[],2=>[],3=>[],4=>[],5=>[],6=>[]];
    if (empty($raw) || !is_array($raw)) return $normalized;

    // Helper to coerce time to HH:MM
    $coerce_time = function($t) {
        if (!is_string($t) || $t === '') return null;
        $t = trim($t);
        // If format has seconds, drop seconds
        if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $t)) {
            $t = substr($t, 0, 5);
        }
        // If single-digit hour, pad
        if (preg_match('/^\d{1}:\d{2}$/', $t)) {
            $t = '0' . $t;
        }
        // Final check
        if (preg_match('/^\d{2}:\d{2}$/', $t)) return $t;
        return null;
    };

    // Map known string keys
    $string_key_map = [
        'sun' => 0, 'sunday' => 0,
        'mon' => 1, 'monday' => 1,
        'tue' => 2, 'tues' => 2, 'tuesday' => 2,
        'wed' => 3, 'wednesday' => 3,
        'thu' => 4, 'thur' => 4, 'thurs' => 4, 'thursday' => 4,
        'fri' => 5, 'friday' => 5,
        'sat' => 6, 'saturday' => 6,
    ];

    foreach ($raw as $k => $v) {
        $day = null;
        if (is_int($k)) {
            // Support 0..6 or 1..7 (Mon=1..Sun=7)
            if ($k >= 0 && $k <= 6) {
                $day = $k;
            } elseif ($k >= 1 && $k <= 7) {
                // Assume Mon=1..Sun=7 mapping
                $day = ($k == 7) ? 0 : $k; // Mon=1->1, ... Sat=6->6, Sun=7->0
            }
        } elseif (is_string($k)) {
            $lk = strtolower(trim($k));
            if (isset($string_key_map[$lk])) {
                $day = $string_key_map[$lk];
            }
        }
        if ($day === null || $day < 0 || $day > 6) {
            continue;
        }
        // Parse shifts array
        if (is_array($v)) {
            // Could be list of shifts or single shift
            $shifts = array_values($v);
            // If associative single shift, wrap
            if (!isset($shifts[0]) || !is_array($shifts[0])) {
                $shifts = [$v];
            }
            foreach ($shifts as $s) {
                if (!is_array($s)) continue;
                $st = $s['start_time'] ?? ($s['start'] ?? ($s['from'] ?? null));
                $et = $s['end_time'] ?? ($s['end'] ?? ($s['to'] ?? null));
                $st = $coerce_time($st);
                $et = $coerce_time($et);
                if ($st && $et) {
                    $normalized[$day][] = ['start_time' => $st, 'end_time' => $et];
                }
            }
        }
    }
    return $normalized;
}

/**
 * Check if staff member has required skills for a service
 */
function td_bkg_staff_has_skills($staff_id, $required_skills) {
    if (empty($required_skills) || !function_exists('td_tech')) {
        return true; // No skills required or TD Technicians not available
    }
    
    try {
        $repo = td_tech()->repo();
        $staff = td_bkg_get_staff_safe($staff_id, $repo);
        
        if (!$staff || !$staff['active']) {
            return false;
        }
        // Match using both labels and normalized slugs
        $staff_labels = is_array($staff['skills']) ? $staff['skills'] : [];
        $staff_slugs = array_map('td_bkg_skill_slug', $staff_labels);
        $req_labels = is_array($required_skills) ? $required_skills : [$required_skills];
        $req_slugs = array_map('td_bkg_skill_slug', $req_labels);
        $label_hit = count(array_intersect($req_labels, $staff_labels)) > 0;
        $slug_hit = count(array_intersect($req_slugs, $staff_slugs)) > 0;
        return ($label_hit || $slug_hit);
        
    } catch (Exception $e) {
        return false; // Default to not having skills on error
    }
}

/**
 * Get all active technicians from TD Technicians plugin
 */
function td_bkg_get_active_technicians($skills = []) {
    if (!function_exists('td_tech')) {
        return [];
    }

    // Prefer repository (API) to preserve rich skill data (levels), fallback to DB if needed
    try {
        $repo = td_tech()->repo();

        // If repo exposes list_by_skills, try that path for efficiency
        if (!empty($skills) && method_exists($repo, 'list_by_skills')) {
            try {
                $technicians = $repo->list_by_skills($skills, ['active' => true]);
                if (is_array($technicians) && !empty($technicians)) {
                    $validated = [];
                    foreach ($technicians as $tech) {
                        $norm = td_bkg_normalize_technician_data($tech);
                        if ($norm) $validated[] = $norm;
                    }
                    if (!empty($validated)) return $validated;
                }
            } catch (Exception $e) {
                // fall through to generic list
            }
        }

        // Generic list with active filter
        $filters = ['active' => true];
        $technicians = $repo->list($filters);
        if (is_array($technicians) && !empty($technicians)) {
            $validated_technicians = [];
            // Precompute requested skill slugs
            $req_labels = is_array($skills) ? $skills : [$skills];
            $req_slugs = array_map('td_bkg_skill_slug', $req_labels);
            foreach ($technicians as $tech) {
                $tech_data = td_bkg_normalize_technician_data($tech);
                if ($tech_data) {
                    // If specific skills requested, filter using both labels and slugs
                    if (!empty($req_labels)) {
                        $labels = is_array($tech_data['skills']) ? $tech_data['skills'] : [];
                        $slugs = array_map('td_bkg_skill_slug', $labels);
                        $label_hit = count(array_intersect($req_labels, $labels)) > 0;
                        $slug_hit = count(array_intersect($req_slugs, $slugs)) > 0;
                        if (!($label_hit || $slug_hit)) {
                            continue;
                        }
                    }
                    $validated_technicians[] = $tech_data;
                }
            }
            if (!empty($validated_technicians)) return $validated_technicians;
        }
    } catch (Exception $e) {
        // ignore, will fallback
    }

    // Fallback to direct DB if repo fails or returns empty
    $fallback_technicians = td_bkg_get_technicians_fallback();
    if (!empty($fallback_technicians) && !empty($skills)) {
        $filtered = [];
        $req_labels = is_array($skills) ? $skills : [$skills];
        $req_slugs = array_map('td_bkg_skill_slug', $req_labels);
        foreach ($fallback_technicians as $tech) {
            $tech_labels = is_array($tech['skills']) ? $tech['skills'] : [];
            $tech_slugs = array_map('td_bkg_skill_slug', $tech_labels);
            $label_hit = count(array_intersect($req_labels, $tech_labels)) > 0;
            $slug_hit = count(array_intersect($req_slugs, $tech_slugs)) > 0;
            if ($label_hit || $slug_hit) {
                $filtered[] = $tech;
            }
        }
        return $filtered;
    }
    return $fallback_technicians;
}

/**
 * Normalize a skill label into a slug-like token for matching
 */
function td_bkg_skill_slug($s) {
    $s = strtolower(trim((string)$s));
    // Replace non-alphanumeric with hyphens
    $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
    // Collapse multiple hyphens
    $s = preg_replace('/-+/', '-', $s);
    // Trim hyphens
    $s = trim($s, '-');
    return $s;
}

/**
 * Normalize technician data from various formats (object, array, TD_Staff instance)
 */
function td_bkg_normalize_technician_data($tech) {
    if (empty($tech)) {
        return null;
    }
    
    // Handle TD_Staff object (safe array) but normalize further to preserve detailed skills
    if (is_object($tech) && method_exists($tech, 'to_safe_array')) {
        try {
            $data = $tech->to_safe_array();
            // Re-run normalization on array data to extract detailed skills and ensure consistent keys
            if (is_array($data)) {
                return td_bkg_normalize_technician_data($data);
            }
        } catch (Exception $e) {
            // Silently continue to fallback method
        }
    }
    
    // Fallback to to_array method
    if (is_object($tech) && method_exists($tech, 'to_array')) {
        try {
            $data = $tech->to_array();
            
            // Handle enhanced skills format from TD Staff plugin
            $skills = [];
            if (isset($data['skills'])) {
                if (is_array($data['skills'])) {
                    // Check if this is the new rich skills format with objects
                    if (!empty($data['skills']) && is_array($data['skills'][0]) && isset($data['skills'][0]['label'])) {
                        // New format: array of skill objects with label, slug, level
                        foreach ($data['skills'] as $skill) {
                            $skills[] = $skill['label'] ?? $skill['slug'] ?? '';
                        }
                    } else {
                        // Legacy format: array of strings
                        $skills = $data['skills'];
                    }
                } elseif (is_string($data['skills']) && !empty($data['skills'])) {
                    // Try to decode if it's JSON, otherwise split by comma
                    $decoded = json_decode($data['skills'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        // Check if decoded JSON has rich skill objects
                        if (!empty($decoded) && is_array($decoded[0]) && isset($decoded[0]['label'])) {
                            foreach ($decoded as $skill) {
                                $skills[] = $skill['label'] ?? $skill['slug'] ?? '';
                            }
                        } else {
                            $skills = $decoded;
                        }
                    } else {
                        $skills = array_map('trim', explode(',', $data['skills']));
                    }
                }
            }
            
            // Capture detailed skills if provided
            $skills_detailed = [];
            if (isset($data['skills']) && is_array($data['skills']) && !empty($data['skills']) && is_array($data['skills'][0]) && isset($data['skills'][0]['label'])) {
                $skills_detailed = $data['skills'];
            }

            // Ensure all string fields are properly handled
            return [
                'id' => $data['id'] ?? 0,
                'wp_user_id' => $data['wp_user_id'] ?? 0,
                'display_name' => !empty($data['display_name']) ? $data['display_name'] : 'Unknown',
                'email' => !empty($data['email']) ? $data['email'] : '',
                'phone' => !empty($data['phone']) ? $data['phone'] : '',
                'timezone' => !empty($data['timezone']) ? $data['timezone'] : 'UTC',
                'skills' => $skills,
                'skills_detailed' => $skills_detailed,
                'weight' => $data['weight'] ?? 1,
                'cooldown_sec' => $data['cooldown_sec'] ?? 0,
                'active' => $data['active'] ?? true,
            ];
        } catch (Exception $e) {
            // Silently continue to fallback method
        }
    }
    
    // Handle stdClass or other objects
    if (is_object($tech)) {
        // Handle enhanced skills format from TD Staff plugin
        $skills = [];
        $skills_detailed = [];
        if (isset($tech->skills)) {
            if (is_array($tech->skills)) {
                // Check if this is the new rich skills format with objects
                if (!empty($tech->skills) && is_array($tech->skills[0]) && isset($tech->skills[0]['label'])) {
                    // New format: array of skill objects with label, slug, level
                    foreach ($tech->skills as $skill) {
                        $skills[] = $skill['label'] ?? $skill['slug'] ?? '';
                    }
                    $skills_detailed = $tech->skills;
                } else {
                    // Legacy format: array of strings
                    $skills = $tech->skills;
                }
            } elseif (is_string($tech->skills) && !empty($tech->skills)) {
                // Try to decode if it's JSON, otherwise split by comma
                $decoded = json_decode($tech->skills, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    // Check if decoded JSON has rich skill objects
                    if (!empty($decoded) && is_array($decoded[0]) && isset($decoded[0]['label'])) {
                        foreach ($decoded as $skill) {
                            $skills[] = $skill['label'] ?? $skill['slug'] ?? '';
                        }
                    } else {
                        $skills = $decoded;
                    }
                } else {
                    $skills = array_map('trim', explode(',', $tech->skills));
                }
            }
        }
        
        return [
            'id' => $tech->id ?? $tech->ID ?? 0,
            'wp_user_id' => $tech->wp_user_id ?? $tech->user_id ?? 0,
            'display_name' => !empty($tech->display_name) ? $tech->display_name : (!empty($tech->name) ? $tech->name : 'Unknown'),
            'email' => !empty($tech->email) ? $tech->email : '',
            'phone' => !empty($tech->phone) ? $tech->phone : '',
            'timezone' => !empty($tech->timezone) ? $tech->timezone : 'UTC',
            'skills' => $skills,
            'skills_detailed' => $skills_detailed,
            'weight' => $tech->weight ?? 1,
            'cooldown_sec' => $tech->cooldown_sec ?? 0,
            'active' => $tech->active ?? true,
        ];
    }
    
    // Handle arrays
    if (is_array($tech)) {
        // Handle enhanced skills format from TD Staff plugin
        $skills = [];
        $skills_detailed = [];
        if (isset($tech['skills'])) {
            if (is_array($tech['skills'])) {
                // Check if this is the new rich skills format with objects
                if (!empty($tech['skills']) && is_array($tech['skills'][0]) && isset($tech['skills'][0]['label'])) {
                    // New format: array of skill objects with label, slug, level
                    foreach ($tech['skills'] as $skill) {
                        $skills[] = $skill['label'] ?? $skill['slug'] ?? '';
                    }
                    $skills_detailed = $tech['skills'];
                } else {
                    // Legacy format: array of strings
                    $skills = $tech['skills'];
                }
            } elseif (is_string($tech['skills']) && !empty($tech['skills'])) {
                // Try to decode if it's JSON, otherwise split by comma
                $decoded = json_decode($tech['skills'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    // Check if decoded JSON has rich skill objects
                    if (!empty($decoded) && is_array($decoded[0]) && isset($decoded[0]['label'])) {
                        foreach ($decoded as $skill) {
                            $skills[] = $skill['label'] ?? $skill['slug'] ?? '';
                        }
                    } else {
                        $skills = $decoded;
                    }
                } else {
                    $skills = array_map('trim', explode(',', $tech['skills']));
                }
            }
        }
        
        return [
            'id' => $tech['id'] ?? $tech['ID'] ?? 0,
            'wp_user_id' => $tech['wp_user_id'] ?? $tech['user_id'] ?? 0,
            'display_name' => !empty($tech['display_name']) ? $tech['display_name'] : (!empty($tech['name']) ? $tech['name'] : 'Unknown'),
            'email' => !empty($tech['email']) ? $tech['email'] : '',
            'phone' => !empty($tech['phone']) ? $tech['phone'] : '',
            'timezone' => !empty($tech['timezone']) ? $tech['timezone'] : 'UTC',
            'skills' => $skills,
            'skills_detailed' => $skills_detailed,
            'weight' => $tech['weight'] ?? 1,
            'cooldown_sec' => $tech['cooldown_sec'] ?? 0,
            'active' => $tech['active'] ?? true,
        ];
    }
    
    return null;
}

/**
 * Fallback method to get technicians directly from database
 */
function td_bkg_get_technicians_fallback() {
    global $wpdb;
    
    try {
        // Try to get technicians directly from the database
        $table_name = $wpdb->prefix . 'td_staff';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        if (!$table_exists) {
            return [];
        }
        
        $results = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE active = 1 ORDER BY display_name",
            ARRAY_A
        );
        
        if ($wpdb->last_error) {
            return [];
        }
        
        $technicians = [];
        foreach ($results as $row) {
            // Decode JSON fields
            $skills = [];
            if (!empty($row['skills_json'])) {
                $skills = json_decode($row['skills_json'], true) ?: [];
            }
            
            $technicians[] = [
                'id' => intval($row['id']),
                'wp_user_id' => intval($row['wp_user_id'] ?? 0),
                'display_name' => $row['display_name'] ?? 'Unknown',
                'email' => $row['email'] ?? '',
                'phone' => $row['phone'] ?? '',
                'timezone' => $row['timezone'] ?? 'UTC',
                'skills' => $skills,
                'weight' => intval($row['weight'] ?? 1),
                'cooldown_sec' => intval($row['cooldown_sec'] ?? 0),
                'active' => !empty($row['active']),
            ];
        }
        
        return $technicians;
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Bulk invalidate availability cache for multiple staff members
 */
function td_bkg_invalidate_multiple_staff_cache($staff_ids) {
    if (empty($staff_ids) || !is_array($staff_ids)) {
        return;
    }
    
    foreach ($staff_ids as $staff_id) {
        td_bkg_invalidate_staff_availability_cache($staff_id);
    }
}

/**
 * Check TD Technicians API compatibility
 */
function td_bkg_check_technicians_compatibility() {
    if (!function_exists('td_tech')) {
        return false;
    }
    
    if (!defined('TD_TECH_API_VERSION')) {
        return false;
    }
    
    return version_compare(TD_TECH_API_VERSION, TD_BKG_MIN_TECH_API, '>=');
}

/**
 * Safely get staff data with error handling
 */
function td_bkg_get_staff_safe($staff_id, $repo = null) {
    // Try fallback method first to avoid TD_Staff constructor issues
    $fallback_staff = td_bkg_get_staff_fallback($staff_id);
    if ($fallback_staff) {
        return $fallback_staff;
    }
    
    // Only try repository if fallback failed and repo is available
    if ($repo) {
        try {
            $staff = $repo->get($staff_id);
            if ($staff) {
                return td_bkg_normalize_technician_data($staff);
            }
        } catch (Exception $e) {
            // Silently continue to next method
        }
    } else if (function_exists('td_tech')) {
        try {
            $repo = td_tech()->repo();
            $staff = $repo->get($staff_id);
            if ($staff) {
                return td_bkg_normalize_technician_data($staff);
            }
        } catch (Exception $e) {
            // Silently continue
        }
    }
    
    return null;
}

/**
 * Extract skill labels from various skill data formats
 * Handles both legacy string arrays and new rich skill objects
 */
function td_bkg_extract_skill_labels($skills_data) {
    if (empty($skills_data)) {
        return [];
    }
    
    $labels = [];
    
    if (is_array($skills_data)) {
        foreach ($skills_data as $skill) {
            if (is_array($skill) && isset($skill['label'])) {
                // New rich format: {label: "WordPress Development", slug: "wordpress-development", level: "expert"}
                $labels[] = $skill['label'];
            } elseif (is_string($skill)) {
                // Legacy format: ["WordPress Development", "PHP"]
                $labels[] = $skill;
            }
        }
    } elseif (is_string($skills_data)) {
        // Handle JSON string or comma-separated
        $decoded = json_decode($skills_data, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return td_bkg_extract_skill_labels($decoded); // Recursive call
        } else {
            $labels = array_map('trim', explode(',', $skills_data));
        }
    }
    
    return array_filter($labels, function($label) {
        return !empty(trim($label));
    });
}

/**
 * Get all available skills from technicians
 */
function td_bkg_get_all_skills() {
    if (!function_exists('td_tech')) {
        return [];
    }
    
    $all_skills = [];
    
    try {
        $technicians = td_bkg_get_active_technicians();
        
        foreach ($technicians as $tech) {
            $skill_labels = td_bkg_extract_skill_labels($tech['skills'] ?? []);
            $all_skills = array_merge($all_skills, $skill_labels);
        }
        
        // Remove duplicates and sort
        $all_skills = array_unique($all_skills);
        sort($all_skills);
        
        return array_filter($all_skills, function($skill) {
            return !empty(trim($skill));
        });
        
    } catch (Exception $e) {
        if (function_exists('td_bkg_debug_log')) {
            td_bkg_debug_log('Error getting all skills', [ 'error' => $e->getMessage() ]);
        }
        return [];
    }
}

/**
 * Get single staff member using direct database fallback
 */
function td_bkg_get_staff_fallback($staff_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'td_staff';
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    if (!$table_exists) {
        return null;
    }
    
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $staff_id
    ), ARRAY_A);
    
    if ($row) {
        // Decode JSON fields
        $skills = [];
        if (!empty($row['skills_json'])) {
            $skills = json_decode($row['skills_json'], true) ?: [];
        }
        
        return [
            'id' => intval($row['id']),
            'wp_user_id' => intval($row['wp_user_id'] ?? 0),
            'display_name' => !empty($row['display_name']) ? $row['display_name'] : 'Unknown',
            'email' => !empty($row['email']) ? $row['email'] : '',
            'phone' => !empty($row['phone']) ? $row['phone'] : '',
            'timezone' => !empty($row['timezone']) ? $row['timezone'] : 'UTC',
            'skills' => $skills,
            'weight' => intval($row['weight'] ?? 1),
            'cooldown_sec' => intval($row['cooldown_sec'] ?? 0),
            'active' => !empty($row['active']),
        ];
    }
    
    return null;
}

/**
 * Resolve a staff ID by display name (case-insensitive).
 * Falls back to direct DB query against td_staff when repo API is unavailable.
 * Returns integer staff ID or 0 when not found or ambiguous.
 */
function td_bkg_find_staff_id_by_name($name) {
    $name = trim((string)$name);
    if ($name === '') return 0;

    // Try TD Technicians repository if available
    if (function_exists('td_tech')) {
        try {
            $repo = td_tech()->repo();
            // Many repos support a generic list() with filters; try a permissive approach
            if (method_exists($repo, 'list')) {
                // Fetch active technicians and filter locally by name (case-insensitive)
                $techs = $repo->list(['active' => true]);
                if (is_array($techs)) {
                    $matches = [];
                    foreach ($techs as $t) {
                        $data = td_bkg_normalize_technician_data($t);
                        if (!$data) continue;
                        $dn = strtolower(trim($data['display_name'] ?? ''));
                        $target = strtolower($name);
                        if ($dn === $target) {
                            // Exact case-insensitive match: return immediately
                            return intval($data['id'] ?? 0);
                        }
                        if ($dn !== '' && strpos($dn, $target) !== false) {
                            $matches[] = intval($data['id'] ?? 0);
                        }
                    }
                    $matches = array_values(array_filter(array_unique($matches)));
                    if (count($matches) === 1) {
                        return $matches[0];
                    }
                    // Ambiguous or none -> fall through to DB
                }
            }
        } catch (Exception $e) {
            // ignore and fall back
        }
    }

    // Fallback: direct DB search by display_name
    global $wpdb;
    $table = $wpdb->prefix . 'td_staff';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (!$table_exists) return 0;

    // First, try exact (case-insensitive) match
    $exact = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE LOWER(display_name) = LOWER(%s) AND active = 1 LIMIT 1", $name));
    if ($exact) return intval($exact);

    // Then, try partial contains (case-insensitive); if unique, return it
    $like = '%' . $wpdb->esc_like($name) . '%';
    $rows = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE LOWER(display_name) LIKE LOWER(%s) AND active = 1 LIMIT 2", $like));
    if (is_array($rows) && count($rows) === 1) return intval($rows[0]);

    return 0; // Not found or ambiguous
}

// Get staff exceptions (holidays, breaks, etc.) from TD Technicians
function td_bkg_get_staff_exceptions($staff_id, $from_date, $to_date) {
    if (!function_exists('td_tech') || !method_exists(td_tech(), 'schedule')) {
        return [];
    }
    
    try {
        $schedule_service = td_tech()->schedule();
        
        // Check if the new get_exceptions method is available
        if (method_exists($schedule_service, 'get_exceptions')) {
            $exceptions = $schedule_service->get_exceptions($staff_id, $from_date, $to_date);
            
            if (is_array($exceptions)) {
                return $exceptions;
            }
        }
    } catch (Exception $e) {
        // Silently continue
    }
    
    return [];
}
