<?php

// Best-effort purges across common WordPress page-cache plugins; each call is a no-op when its plugin is inactive.

if (!defined('ABSPATH')) {
    exit;
}

// $post_id scopes the purge to one post; 0 purges the whole site.
function geoguru_purge_page_caches($post_id = 0) {
    $post_id = (int) $post_id;

    if ($post_id > 0) {
        clean_post_cache($post_id);
    }

    if ($post_id > 0) {
        do_action('litespeed_purge_post', $post_id);
    } else {
        do_action('litespeed_purge_all');
    }

    if ($post_id > 0 && function_exists('rocket_clean_post')) {
        rocket_clean_post($post_id);
    } elseif (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
    }

    if ($post_id > 0 && function_exists('w3tc_flush_post')) {
        w3tc_flush_post($post_id);
    } elseif (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
    }

    if ($post_id > 0 && function_exists('wpsc_delete_post_cache')) {
        wpsc_delete_post_cache($post_id);
    } elseif (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }
}

// One address that is not a post, from the same caches.
function geoguru_purge_page_cache_url($url) {
    do_action('litespeed_purge_url', $url);
    if (function_exists('rocket_clean_files')) {
        rocket_clean_files([$url]);
    }
    if (function_exists('w3tc_flush_url')) {
        w3tc_flush_url($url);
    }
    if (function_exists('wpsc_delete_url_cache')) {
        wpsc_delete_url_cache($url);
    }
}
