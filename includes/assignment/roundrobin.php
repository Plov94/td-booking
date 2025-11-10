<?php
defined('ABSPATH') || exit;

// Enhanced assignment: select best available staff for a service using TD Technicians data
function td_bkg_assignment_roundrobin($service_id, $candidates) {
    global $wpdb;
    
    if (empty($candidates)) {
        td_bkg_debug_log("No candidates available for assignment", ['service_id' => $service_id]);
        return null;
    }
    
    // Apply assignment candidates filter
    if (function_exists('td_bkg_filter_assignment_candidates')) {
        $service_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}td_service WHERE id = %d", 
            $service_id
        ), ARRAY_A);
        $candidates = td_bkg_filter_assignment_candidates($candidates, $service_id, $service_data);
        
        if (empty($candidates)) {
            td_bkg_debug_log("No candidates after filtering", ['service_id' => $service_id]);
            return null;
        }
    }
    
    // If only one candidate, return immediately
    if (count($candidates) === 1) {
        td_bkg_debug_log("Single candidate assignment", [
            'service_id' => $service_id,
            'staff_id' => $candidates[0]
        ]);
        return $candidates[0];
    }
    
    // Get service details for skills matching
    $service = $wpdb->get_row($wpdb->prepare(
        "SELECT skills_json FROM {$wpdb->prefix}td_service WHERE id = %d", 
        $service_id
    ), ARRAY_A);
    
    $required_skills = [];
    if ($service && !empty($service['skills_json'])) {
        $required_skills = json_decode($service['skills_json'], true) ?: [];
    }
    
    // Try to use TD Technicians for enhanced selection
    if (function_exists('td_tech') && method_exists(td_tech(), 'repo')) {
        try {
            $repo = td_tech()->repo();
            $scored_candidates = [];
            
            foreach ($candidates as $staff_id) {
                $score = td_bkg_calculate_staff_score($staff_id, $required_skills, $repo);
                if ($score > 0) {
                    $scored_candidates[] = [
                        'staff_id' => $staff_id,
                        'score' => $score
                    ];
                }
            }
            
            if (!empty($scored_candidates)) {
                // Sort by score (highest first), then by staff_id for consistency
                usort($scored_candidates, function($a, $b) {
                    if ($a['score'] === $b['score']) {
                        return $a['staff_id'] <=> $b['staff_id'];
                    }
                    return $b['score'] <=> $a['score'];
                });
                
                $selected_staff_id = $scored_candidates[0]['staff_id'];
                
                // Apply assigned staff filter
                if (function_exists('td_bkg_filter_assigned_staff')) {
                    $selected_staff_id = td_bkg_filter_assigned_staff($selected_staff_id, $candidates, $service_id);
                }
                
                td_bkg_log_assignment($service_id, $candidates, $selected_staff_id, $scored_candidates);
                
                return $selected_staff_id;
            }
        } catch (Exception $e) {
            td_bkg_debug_log("Assignment error, falling back to simple selection", [
                'service_id' => $service_id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    // Fallback to simple round-robin
    td_bkg_debug_log("Using fallback assignment", [
        'service_id' => $service_id,
        'selected_staff_id' => $candidates[0]
    ]);
    return $candidates[0];
}

// Calculate staff score based on skills, weight, and availability
function td_bkg_calculate_staff_score($staff_id, $required_skills, $repo) {
    try {
        // Use safer method to get staff data
        $staff = td_bkg_get_staff_safe($staff_id, $repo);
        if (!$staff || !$staff['active']) {
            return 0;
        }
        
        $score = 100; // Base score
        
        // Add weight bonus (higher weight = higher priority)
        $weight = intval($staff['weight'] ?? 1);
        $score += ($weight - 1) * 10;
        
        // Skills matching bonus (labels) + level weighting when detailed skills present
        if (!empty($required_skills)) {
            $staff_skills = is_array($staff['skills']) ? $staff['skills'] : [];
            $matching_skills = array_intersect($required_skills, $staff_skills);
            $score += count($matching_skills) * 20;
            // Detailed skills with levels
            if (!empty($staff['skills_detailed']) && is_array($staff['skills_detailed'])) {
                foreach ($staff['skills_detailed'] as $sk) {
                    $label = is_array($sk) ? ($sk['label'] ?? ($sk['slug'] ?? '')) : '';
                    if ($label && in_array($label, $required_skills)) {
                        $level = strtolower((string)($sk['level'] ?? ''));
                        // Simple mapping: beginner=+5, intermediate=+10, advanced=+15, expert=+20
                        $lvl_bonus = ['beginner'=>5,'intermediate'=>10,'advanced'=>15,'expert'=>20][$level] ?? 0;
                        $score += $lvl_bonus;
                    }
                }
            }
            // Perfect match bonus
            if (count($matching_skills) === count($required_skills)) {
                $score += 50;
            }
        }
        
        // Cooldown penalty (if they were recently assigned)
        $cooldown_sec = intval($staff['cooldown_sec'] ?? 0);
        if ($cooldown_sec > 0) {
            $last_assignment = td_bkg_get_last_assignment_time($staff_id);
            if ($last_assignment && (time() - $last_assignment) < $cooldown_sec) {
                $score -= 25; // Cooldown penalty
            }
        }
        
        return max(0, $score);
        
    } catch (Exception $e) {
        if (function_exists('td_bkg_debug_log')) {
            td_bkg_debug_log('Error calculating staff score', [ 'staff_id' => $staff_id, 'error' => $e->getMessage() ]);
        }
        return 0;
    }
}

// Get last assignment timestamp for cooldown calculation
function td_bkg_get_last_assignment_time($staff_id) {
    global $wpdb;
    
    $last_booking = $wpdb->get_var($wpdb->prepare(
        "SELECT UNIX_TIMESTAMP(created_at) FROM {$wpdb->prefix}td_booking 
         WHERE staff_id = %d AND status IN ('confirmed', 'pending') 
         ORDER BY created_at DESC LIMIT 1",
        $staff_id
    ));
    
    return $last_booking ? intval($last_booking) : null;
}

// Get qualified staff for a service based on skills
function td_bkg_get_qualified_staff($service_id) {
    global $wpdb;
    
    // Get service skills
    $service = $wpdb->get_row($wpdb->prepare(
        "SELECT skills_json FROM {$wpdb->prefix}td_service WHERE id = %d AND active = 1", 
        $service_id
    ), ARRAY_A);
    
    if (!$service) {
        return [];
    }
    
    $required_skills = [];
    if (!empty($service['skills_json'])) {
        $required_skills = json_decode($service['skills_json'], true) ?: [];
    }
    
    // First get mapped staff from service
    $mapped_staff = $wpdb->get_col($wpdb->prepare(
        "SELECT staff_id FROM {$wpdb->prefix}td_service_staff WHERE service_id = %d", 
        $service_id
    ));
    
    if (empty($mapped_staff)) {
        td_bkg_debug_log("No staff mapped to service", ['service_id' => $service_id]);
        return [];
    }
    
    // If no specific skills required, return all mapped staff
    if (empty($required_skills)) {
        td_bkg_debug_log("No skills required, returning all mapped staff", [
            'service_id' => $service_id,
            'mapped_staff_count' => count($mapped_staff)
        ]);
        return $mapped_staff;
    }
    
    // Filter by skills using TD Technicians
    if (function_exists('td_tech') && method_exists(td_tech(), 'repo')) {
        try {
            $repo = td_tech()->repo();
            $qualified_staff = [];
            
            // Try to use the new list_by_skills method if available (more efficient)
            if (method_exists($repo, 'list_by_skills')) {
                // Get all technicians with any of the required skills
                $skilled_technicians = $repo->list_by_skills($required_skills, ['active' => true]);
                
                // Filter to only those mapped to this service
                foreach ($skilled_technicians as $tech) {
                    $tech_id = is_array($tech) ? $tech['id'] : $tech->id;
                    if (in_array($tech_id, $mapped_staff)) {
                        $qualified_staff[] = $tech_id;
                    }
                }
                
                td_bkg_debug_log("Used list_by_skills method", [
                    'service_id' => $service_id,
                    'required_skills' => $required_skills,
                    'skilled_techs_found' => count($skilled_technicians),
                    'qualified_staff' => $qualified_staff
                ]);
            } else {
                // Fallback to individual staff loading
                foreach ($mapped_staff as $staff_id) {
                    $staff = td_bkg_get_staff_safe($staff_id, $repo);
                    if ($staff && $staff['active'] && !empty($staff['skills'])) {
                        $staff_skills = is_array($staff['skills']) ? $staff['skills'] : [];
                        $matching_skills = array_intersect($required_skills, $staff_skills);
                        
                        // Staff must have at least one required skill
                        if (!empty($matching_skills)) {
                            $qualified_staff[] = $staff_id;
                        }
                    }
                }
                
                td_bkg_debug_log("Used individual staff loading", [
                    'service_id' => $service_id,
                    'required_skills' => $required_skills
                ]);
            }
            
            td_bkg_log_skills_matching($service_id, $required_skills, $qualified_staff);
            return $qualified_staff;
            
        } catch (Exception $e) {
            td_bkg_debug_log("Error filtering staff by skills", [
                'error' => $e->getMessage(),
                'service_id' => $service_id
            ]);
        }
    }
    
    // Fallback to all mapped staff
    td_bkg_debug_log("Falling back to all mapped staff", [
        'service_id' => $service_id,
        'reason' => 'TD Technicians not available or error occurred'
    ]);
    return $mapped_staff;
}
