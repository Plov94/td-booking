<?php
defined('ABSPATH') || exit;

function td_bkg_create_nonce() {
    return wp_create_nonce('td_bkg_public');
}

function td_bkg_verify_nonce($nonce) {
    return wp_verify_nonce($nonce, 'td_bkg_public');
}
