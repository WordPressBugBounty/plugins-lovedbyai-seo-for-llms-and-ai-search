<?php

if (!defined('ABSPATH')) {
    exit;
}

function geoguru_page_delivery_from_envelope($envelope) {
    if (geoguru_overlay_envelope_version($envelope) !== 2 || !isset($envelope['delivery'])) {
        return null;
    }
    $pair = geoguru_delivery_validate($envelope['delivery']);
    // Serving a pre-built page on the canonical URL stays a site-wide mode.
    if ($pair === null || $pair['original'] === 'cdn_fetch') {
        return null;
    }
    return $pair;
}

function geoguru_request_delivery($reset = false) {
    static $resolved = null;
    if ($reset) {
        $resolved = null;
    }
    if ($resolved !== null) {
        return $resolved;
    }

    $site = geoguru_get_delivery_settings();
    if ($site['original'] === 'cdn_fetch') {
        return $site;
    }
    // Not kept: before the main query the post is unknown, and later callers need the real answer.
    if (did_action('wp') === 0) {
        return $site;
    }

    $resolved = $site;
    $post = get_queried_object();
    if ($post instanceof WP_Post) {
        $envelope = geoguru_overlay_envelope_from_meta(
            get_post_meta($post->ID, GEOGURU_OVERLAY_POST_META_KEY, true),
            $post->ID
        );
        $pair = geoguru_page_delivery_from_envelope($envelope);
        if ($pair !== null) {
            $resolved = $pair;
        }
    }
    return $resolved;
}

function geoguru_is_mirror_request() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only surface check, no nonce needed
    return isset($_GET[GEOGURU_LLM_VIEW_QUERY_ARG]) && $_GET[GEOGURU_LLM_VIEW_QUERY_ARG] == '1';
}
