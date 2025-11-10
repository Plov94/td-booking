<?php
defined('ABSPATH') || exit;

add_action('rest_api_init', function() {
    register_rest_route('td/v1', '/terms', [
        'methods' => 'GET',
        'callback' => 'td_bkg_rest_terms',
        'permission_callback' => '__return_true',
    ]);
});

function td_bkg_rest_terms($request) {
    $terms_page_id = intval(get_option('td_bkg_terms_page_id', 0));
    $terms_url = trim((string) get_option('td_bkg_terms_url', ''));
    $content_html = '';
    if ($terms_page_id) {
        $post = get_post($terms_page_id);
        if ($post && $post->post_status === 'publish') {
            $content_html = apply_filters('the_content', $post->post_content);
        }
    } else if (!empty($terms_url)) {
        // Attempt to fetch external content (subject to WP HTTP API and allow_url_fopen policies)
        $resp = wp_remote_get($terms_url, [
            'timeout' => 5,
            'redirection' => 3,
            'headers' => [ 'User-Agent' => 'TD-Booking/1.0' ],
        ]);
        if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
            $body = wp_remote_retrieve_body($resp);
            // Very light sanitization: allow common content tags
            $allowed = [
                'a' => ['href' => [], 'title' => [], 'target' => [], 'rel' => []],
                'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'ul' => [], 'ol' => [], 'li' => [],
                'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [], 'blockquote' => [], 'code' => [], 'pre' => [], 'span' => ['class' => []], 'div' => ['class' => []],
            ];
            // Try to extract a main content container if present
            $content_html = $body;
            if (preg_match('/<main[\s\S]*?>([\s\S]*?)<\/main>/i', $body, $m)) {
                $content_html = $m[1];
            } elseif (preg_match('/<article[\s\S]*?>([\s\S]*?)<\/article>/i', $body, $m)) {
                $content_html = $m[1];
            } elseif (preg_match('/<(div|section)[^>]+class=["\'](?:entry-content|post-content|page-content|site-content)["\'][\s\S]*?>([\s\S]*?)<\/\1>/i', $body, $m)) {
                $content_html = isset($m[2]) ? $m[2] : $body;
            }
            $content_html = wp_kses($content_html, $allowed);
            return rest_ensure_response(['html' => $content_html, 'href' => esc_url_raw($terms_url)]);
        }
        // Fallback: link only
        return rest_ensure_response(['html' => '', 'href' => esc_url_raw($terms_url)]);
    }
    return rest_ensure_response(['html' => $content_html]);
}
