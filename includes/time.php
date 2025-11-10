<?php
defined('ABSPATH') || exit;

// Convert UTC datetime string to local (Europe/Oslo by default)
function td_bkg_utc_to_local($utc, $tz = 'Europe/Oslo') {
    $dt = new DateTime($utc, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone($tz));
    return $dt->format('Y-m-d H:i:s');
}

// Convert local datetime string to UTC
function td_bkg_local_to_utc($local, $tz = 'Europe/Oslo') {
    $dt = new DateTime($local, new DateTimeZone($tz));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}
