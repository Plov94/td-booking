<?php

global $wpdb;
$service_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$is_edit = $service_id > 0;
$table = $wpdb->prefix . 'td_service';
$staff_table = $wpdb->prefix . 'td_service_staff';

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('td_bkg_service_save')) {
    // Invalidate availability cache for all mapped staff for today (conservative)
    require_once dirname(dirname(__DIR__)) . '/availability/cache.php';
    $today = date('Y-m-d');
    $staff_ids_to_invalidate = array();
    if ($is_edit) {
        // Invalidate for previous mapping
        $old_staff = $wpdb->get_col($wpdb->prepare("SELECT staff_id FROM $staff_table WHERE service_id=%d", $service_id));
        $staff_ids_to_invalidate = array_merge($staff_ids_to_invalidate, $old_staff);
    }
    if (!empty($_POST['staff_ids']) && is_array($_POST['staff_ids'])) {
        $staff_ids_to_invalidate = array_merge($staff_ids_to_invalidate, array_map('intval', $_POST['staff_ids']));
    }
    $staff_ids_to_invalidate = array_unique($staff_ids_to_invalidate);
    foreach ($staff_ids_to_invalidate as $sid) {
        td_bkg_availability_cache_invalidate($sid, $today, $today);
    }
    // Process required skills
    $required_skills = [];
    if (!empty($_POST['required_skills']) && is_array($_POST['required_skills'])) {
        $required_skills = array_map('sanitize_text_field', $_POST['required_skills']);
    }
    
    $data = [
        'name' => sanitize_text_field($_POST['name']),
        'slug' => sanitize_title($_POST['slug']),
        'description' => sanitize_textarea_field($_POST['description']),
        'duration_min' => intval($_POST['duration_min']),
        'buffer_min' => isset($_POST['buffer_min']) ? intval($_POST['buffer_min']) : 0,
        'skills_json' => !empty($required_skills) ? json_encode($required_skills) : null,
        'active' => isset($_POST['active']) ? 1 : 0,
        'updated_at' => current_time('mysql', 1),
    ];
    if ($is_edit) {
        $wpdb->update($table, $data, ['id' => $service_id]);
    } else {
        $data['created_at'] = current_time('mysql', 1);
        $wpdb->insert($table, $data);
        $service_id = $wpdb->insert_id;
    }
    // Save staff mapping
    $wpdb->delete($staff_table, ['service_id' => $service_id]);
    if (!empty($_POST['staff_ids']) && is_array($_POST['staff_ids'])) {
        foreach ($_POST['staff_ids'] as $staff_id) {
            $wpdb->insert($staff_table, [
                'service_id' => $service_id,
                'staff_id' => intval($staff_id),
            ]);
        }
    }
    echo '<div class="updated"><p>' . esc_html__('Saved!', 'td-booking') . '</p></div>';
    // Redirect to list
    echo '<script>window.location = "' . admin_url('admin.php?page=td-booking') . '";</script>';
    exit;
}

// Load service row if editing
$row = [
    'name' => '', 'slug' => '', 'description' => '', 'duration_min' => 30, 'active' => 1
];
if ($is_edit) {
    $row_db = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $service_id), ARRAY_A);
    if ($row_db) $row = $row_db;
}
// Load mapped staff
$mapped_staff = $is_edit ? $wpdb->get_col($wpdb->prepare("SELECT staff_id FROM $staff_table WHERE service_id=%d", $service_id)) : array();

// Fetch active staff from TD Technicians using safe integration
$staff = array();
if (function_exists('td_bkg_get_active_technicians')) {
    try {
        $staff = td_bkg_get_active_technicians();
    } catch (Exception $e) {
        $staff = [];
    }
}

// Render form
echo '<div class="wrap">';
echo '<h1>' . ($is_edit ? esc_html__('Edit Service', 'td-booking') : esc_html__('Add Service', 'td-booking')) . '</h1>';
echo '<form method="post">';
wp_nonce_field('td_bkg_service_save');
echo '<table class="form-table">';
echo '<tr><th>' . esc_html__('Name', 'td-booking') . '</th><td><input name="name" value="' . esc_attr($row['name']) . '" required></td></tr>';
echo '<tr><th>' . esc_html__('Slug', 'td-booking') . '</th><td><input name="slug" value="' . esc_attr($row['slug']) . '" required><br><small>' . esc_html__('URL-friendly version of the name (e.g., "oil-change" for "Oil Change Service")', 'td-booking') . '</small></td></tr>';
echo '<tr><th>' . esc_html__('Description', 'td-booking') . '</th><td><textarea name="description">' . esc_textarea($row['description']) . '</textarea></td></tr>';
echo '<tr><th>' . esc_html__('Duration (min)', 'td-booking') . '</th><td><input type="number" name="duration_min" value="' . esc_attr($row['duration_min']) . '" min="1" required></td></tr>';
echo '<tr><th>' . esc_html__('Buffer (min)', 'td-booking') . '</th><td><input type="number" name="buffer_min" value="' . esc_attr(isset($row['buffer_min']) ? $row['buffer_min'] : 0) . '" min="0" step="1"></td></tr>';
echo '<tr><th>' . esc_html__('Active', 'td-booking') . '</th><td><input type="checkbox" name="active" value="1"' . ($row['active'] ? ' checked' : '') . '></td></tr>';
// Enhanced staff mapping with skills
if (!empty($staff)) {
    echo '<tr><th>' . esc_html__('Required Skills', 'td-booking') . '</th><td>';
    
    // Load existing skills from service
    $existing_skills = [];
    if ($is_edit && !empty($row['skills_json'])) {
        $existing_skills = json_decode($row['skills_json'], true) ?: [];
    }
    
    // Get all available skills from TD Technicians
    $all_skills = td_bkg_get_all_skills();
    
    if (!empty($all_skills)) {
        echo '<div id="skills-selection" style="margin-bottom: 15px;">';
        echo '<h4>' . esc_html__('Select required skills for this service:', 'td-booking') . '</h4>';
        foreach ($all_skills as $skill) {
            $checked = in_array($skill, $existing_skills) ? ' checked' : '';
            echo '<label style="display: inline-block; margin-right: 15px; margin-bottom: 5px;">';
            echo '<input type="checkbox" name="required_skills[]" value="' . esc_attr($skill) . '"' . $checked . '> ';
            echo esc_html($skill);
            echo '</label>';
        }
        echo '</div>';
        echo '<p><em>' . esc_html__('Only staff with at least one of these skills will be qualified for this service. Leave empty to allow all staff.', 'td-booking') . '</em></p>';
    }
    
    echo '</td></tr>';
    
    echo '<tr><th>' . esc_html__('Staff Assignment', 'td-booking') . '</th><td>';
    echo '<div id="staff-mapping-container">';
    
    // Enhanced staff table with skills display - smaller and more compact
    echo '<table class="widefat" style="max-width: 900px; table-layout: fixed; margin: 0;">';
    echo '<thead><tr>';
    echo '<th style="width: 70px; text-align: center; padding: 8px;">' . esc_html__('Assign', 'td-booking') . '</th>';
    echo '<th style="width: 180px; padding: 8px;">' . esc_html__('Staff', 'td-booking') . '</th>';
    echo '<th style="width: 300px; padding: 8px;">' . esc_html__('Skills', 'td-booking') . '</th>';
    echo '<th style="width: 80px; text-align: center; padding: 8px;">' . esc_html__('Weight', 'td-booking') . '</th>';
    echo '<th style="width: 100px; padding: 8px;">' . esc_html__('Status', 'td-booking') . '</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($staff as $s) {
        $checked = in_array($s['id'], $mapped_staff) ? ' checked' : '';
        
        // Extract skill labels and detailed skills (with levels if available)
        $staff_skills = td_bkg_extract_skill_labels($s['skills'] ?? []);
        $skills_display_items = [];
        if (!empty($s['skills_detailed']) && is_array($s['skills_detailed'])) {
            foreach ($s['skills_detailed'] as $sk) {
                $label = is_array($sk) ? ($sk['label'] ?? ($sk['slug'] ?? '')) : '';
                if (!$label) { continue; }
                $level = '';
                if (is_array($sk)) {
                    $rawLevel = $sk['level'] ?? ($sk['proficiency'] ?? ($sk['rating'] ?? ($sk['lvl'] ?? ($sk['experience_level'] ?? ''))));
                    if ($rawLevel !== '') {
                        // Normalize common variants
                        if (is_numeric($rawLevel)) {
                            // Map 1-5 to Beginner..Expert
                            $map = [1=>'Beginner',2=>'Beginner',3=>'Intermediate',4=>'Advanced',5=>'Expert'];
                            $rawLevel = $map[intval($rawLevel)] ?? $rawLevel;
                        }
                        // Normalize string synonyms
                        $rl = strtolower(trim((string)$rawLevel));
                        $syn = [
                            'novice' => 'Beginner',
                            'jr' => 'Beginner',
                            'junior' => 'Beginner',
                            'med' => 'Intermediate',
                            'mid' => 'Intermediate',
                            'intermediate' => 'Intermediate',
                            'sr' => 'Advanced',
                            'senior' => 'Advanced',
                            'adv' => 'Advanced',
                            'expert' => 'Expert',
                        ];
                        $pretty = $syn[$rl] ?? ucfirst($rl);
                        $level = ' (' . esc_html($pretty) . ')';
                    }
                }
                $skills_display_items[] = '<span class="td-skill" data-skill-label="' . esc_attr($label) . '">' . esc_html($label) . $level . '</span>';
                // Ensure label exists in labels array for qualification logic
                if (!in_array($label, $staff_skills, true)) {
                    $staff_skills[] = $label;
                }
            }
        } elseif (!empty($staff_skills)) {
            foreach ($staff_skills as $label) {
                $skills_display_items[] = '<span class="td-skill" data-skill-label="' . esc_attr($label) . '">' . esc_html($label) . '</span>';
            }
        }
        $skills_html = !empty($skills_display_items)
            ? implode(', ', $skills_display_items)
            : '<em>' . esc_html__('No skills defined', 'td-booking') . '</em>';
        $weight = isset($s['weight']) ? $s['weight'] : 1;
        $active = isset($s['active']) ? $s['active'] : true;
        
        $row_class = '';
        if (!empty($existing_skills)) {
            $matching_skills = array_intersect($existing_skills, $staff_skills);
            if (empty($matching_skills)) {
                $row_class = ' style="background-color: #fff2cd; opacity: 0.7;"'; // Highlight non-matching
            }
        }
        
        echo '<tr' . $row_class . '>';
        echo '<td style="text-align: center; padding: 8px;"><input type="checkbox" name="staff_ids[]" value="' . esc_attr($s['id']) . '"' . $checked . ' class="staff-checkbox" data-staff-id="' . esc_attr($s['id']) . '"></td>';
        $staff_display = isset($s['display_name']) && $s['display_name'] !== '' ? $s['display_name'] : (isset($s['name']) ? $s['name'] : ('#' . intval($s['id'])));
        $sid = intval($s['id']);
        echo '<td style="padding: 8px;">'
            . '<strong>' . esc_html($staff_display) . '</strong>'
            . '<br><small>' . esc_html__('ID', 'td-booking') . ': '
            . '<code>' . esc_html($sid) . '</code> '
            . '<button type="button" class="button-link td-copy-id" data-copy="' . esc_attr($sid) . '" aria-label="' . esc_attr__('Copy ID', 'td-booking') . '">' . esc_html__('Copy', 'td-booking') . '</button>'
            . '</small>'
            . '</td>';
        echo '<td style="word-wrap: break-word; overflow-wrap: break-word; padding: 8px;"><small class="td-skill-list">' . $skills_html . '</small></td>';
        echo '<td style="text-align: center; padding: 8px;">' . esc_html($weight) . '</td>';
        echo '<td style="padding: 8px;">' . ($active ? '<span style="color: green;">●</span> ' . esc_html__('Active', 'td-booking') : '<span style="color: red;">●</span> ' . esc_html__('Inactive', 'td-booking')) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    
    echo '<div style="margin-top: 15px;">';
    echo '<button type="button" id="select-all-staff" class="button">' . esc_html__('Select All', 'td-booking') . '</button> ';
    echo '<button type="button" id="select-none-staff" class="button">' . esc_html__('Select None', 'td-booking') . '</button> ';
    echo '<button type="button" id="select-qualified-staff" class="button button-secondary">' . esc_html__('Select Only Qualified', 'td-booking') . '</button>';
    echo '</div>';
    
    echo '<div id="selection-summary" data-warn-text="' . esc_attr__('selected staff do not have required skills', 'td-booking') . '" style="margin-top: 10px; padding: 10px; background: #f1f1f1; border-radius: 3px; display: none;">';
    echo '<strong>' . esc_html__('Selection Summary:', 'td-booking') . '</strong> ';
    echo '<span id="selected-count">0</span> ' . esc_html__('staff selected', 'td-booking');
    echo '</div>';
    
    echo '</div>'; // staff-mapping-container
    echo '</td></tr>';
    
    // Add JavaScript for interactivity
    echo '<script>
    jQuery(document).ready(function($) {
        var requiredSkills = [];
        var tdWarnText = $(\'#selection-summary\').data(\'warn-text\') || "selected staff do not have required skills";
        
        function updateRequiredSkills() {
            requiredSkills = [];
            $(\'input[name="required_skills[]"]:checked\').each(function() {
                requiredSkills.push($(this).val());
            });
            updateStaffHighlighting();
            updateSelectionSummary();
        }
        
        function updateStaffHighlighting() {
            $(\'.staff-checkbox\').each(function() {
                var $row = $(this).closest(\'tr\');
                    var staffSkills = [];
                    $row.find(\'td:eq(2) .td-skill\').each(function() {
                        staffSkills.push($(this).data(\'skill-label\'));
                    });
                
                var isQualified = true;
                if (requiredSkills.length > 0) {
                    var matchingSkills = requiredSkills.filter(function(skill) {
                        return staffSkills.indexOf(skill) !== -1;
                    });
                    isQualified = matchingSkills.length > 0;
                }
                
                if (isQualified) {
                    $row.css({\'background-color\': \'\', \'opacity\': \'\'});
                } else {
                    $row.css({\'background-color\': \'#fff2cd\', \'opacity\': \'0.7\'});
                }
            });
        }
        
        function updateSelectionSummary() {
            var selectedCount = $(\'.staff-checkbox:checked\').length;
            var qualifiedCount = 0;
            
            $(\'.staff-checkbox:checked\').each(function() {
                var $row = $(this).closest(\'tr\');
                if ($row.css(\'opacity\') !== \'0.7\') {
                    qualifiedCount++;
                }
            });
            
            $(\'#selected-count\').text(selectedCount);
            
            if (selectedCount > 0) {
                $(\'#selection-summary\').show();
                // Remove any previous warning to avoid duplicates
                $(\'#selection-summary small\').remove();
                if (requiredSkills.length > 0 && qualifiedCount < selectedCount) {
                    $(\'#selection-summary\').append(\'<br><small style="color: #d63638;">\' + (selectedCount - qualifiedCount) + \' \'+ tdWarnText +\'</small>\');
                }
            } else {
                $(\'#selection-summary\').hide();
            }
        }
        
        // Event handlers
        $(\'input[name="required_skills[]"]\').change(updateRequiredSkills);
        $(\'.staff-checkbox\').change(updateSelectionSummary);
        
        $(\'#select-all-staff\').click(function() {
            $(\'.staff-checkbox\').prop(\'checked\', true);
            updateSelectionSummary();
        });
        
        $(\'#select-none-staff\').click(function() {
            $(\'.staff-checkbox\').prop(\'checked\', false);
            updateSelectionSummary();
        });
        
        $(\'#select-qualified-staff\').click(function() {
            $(\'.staff-checkbox\').each(function() {
                var $row = $(this).closest(\'tr\');
                var isQualified = $row.css(\'opacity\') !== \'0.7\';
                $(this).prop(\'checked\', isQualified);
            });
            updateSelectionSummary();
        });
        
        // Initialize
        updateRequiredSkills();

        // Copy staff ID helper
        function tdFlashCopied($btn) {
            var original = $btn.text();
            $btn.text(\'Copied\');
            setTimeout(function(){ $btn.text(original); }, 800);
        }
        function tdFallbackCopy(val, $btn) {
            var $tmp = $(\'<textarea readonly style="position:absolute;left:-9999px;"></textarea>\').val(val).appendTo(\'body\');
            $tmp[0].select();
            try { document.execCommand(\'copy\'); } catch(e) {}
            $tmp.remove();
            tdFlashCopied($btn);
        }
        $(document).on(\'click\', \'\.td-copy-id\', function(e){
            e.preventDefault();
            var val = String($(this).data(\'copy\') || \'\');
            var $btn = $(this);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(val).then(function(){
                    tdFlashCopied($btn);
                }).catch(function(){
                    tdFallbackCopy(val, $btn);
                });
            } else {
                tdFallbackCopy(val, $btn);
            }
        });
    });
    </script>';
} else {
    echo '<tr><th>' . esc_html__('Staff', 'td-booking') . '</th><td>' . esc_html__('TD Technicians plugin not connected or no active staff found.', 'td-booking') . '</td></tr>';
}
echo '</table>';
echo '<p><input type="submit" class="button-primary" value="' . esc_attr__('Save', 'td-booking') . '"></p>';
echo '</form>';
echo '</div>';
