<?php
defined('ABSPATH') || exit;

function td_bkg_add_caps() {
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap('manage_td_booking');
    }
}
add_action('init', 'td_bkg_add_caps');

function td_bkg_can_manage() {
    return current_user_can('manage_td_booking');
}
