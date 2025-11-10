<?php
defined('ABSPATH') || exit;

// Simple rate limit: IP+route, RPM
function td_bkg_rate_limit($route, $rpm = 30) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $key = 'td_bkg_rl_' . md5($ip . $route);
    $count = (int) get_transient($key);
    if ($count >= $rpm) {
        return false;
    }
    set_transient($key, $count + 1, 60);
    return true;
}
