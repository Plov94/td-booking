<?php
defined('ABSPATH') || exit;

// Invalidate staff availability cache for a given staff and date range
function td_bkg_availability_cache_invalidate($staff_id, $from, $to) {
    $key = 'td_bkg_avail_' . $staff_id . '_' . md5($from . $to);
    delete_transient($key);
}

// Cache for staff availability. Used to store and retrieve computed availability windows for performance.
function td_bkg_availability_cache($staff_id, $from, $to) {
    // Example: get/set transient for cache
    $key = 'td_bkg_avail_' . $staff_id . '_' . md5($from . $to);
    $data = get_transient($key);
    return $data;
}
