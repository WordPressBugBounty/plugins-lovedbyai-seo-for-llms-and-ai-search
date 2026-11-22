<?php
// Site cleanup REST routes: find the posts behind a list of paths, and move them to the trash.

defined('ABSPATH') || exit;

define('GEOGURU_SITE_CLEANUP_MAX_PATHS', 200);
define('GEOGURU_SITE_CLEANUP_MAX_PATH_LENGTH', 2048);

function geoguru_register_site_cleanup_routes() {
    $routes = array(
        '/cleanup/resolve' => 'geoguru_rest_site_cleanup_resolve',
        '/cleanup/trash'   => 'geoguru_rest_site_cleanup_trash',
    );
    foreach ($routes as $path => $callback) {
        register_rest_route('geoguru/v1', $path, array(
            'methods' => 'POST',
            'callback' => $callback,
            'permission_callback' => 'geoguru_rest_permission_callback',
        ));
    }
}

function geoguru_site_cleanup_paths($request) {
    $body = method_exists($request, 'get_json_params') ? $request->get_json_params() : null;
    $paths = is_array($body) && array_key_exists('paths', $body) ? $body['paths'] : $request->get_param('paths');

    if (!is_array($paths) || count($paths) === 0) {
        return new WP_Error('invalid_paths', 'paths must be a non-empty list', array('status' => 400));
    }
    if (count($paths) > GEOGURU_SITE_CLEANUP_MAX_PATHS) {
        return new WP_Error('too_many_paths', 'at most ' . GEOGURU_SITE_CLEANUP_MAX_PATHS . ' paths', array('status' => 400));
    }

    $unique = array();
    $seen = array();
    foreach ($paths as $path) {
        if (!is_string($path)) {
            return new WP_Error('invalid_paths', 'each path must be a string', array('status' => 400));
        }
        if (!isset($seen[$path])) {
            $seen[$path] = true;
            $unique[] = $path;
        }
    }
    return $unique;
}

// An invalid path is reported on its own, so it never stops the rest of the list.
function geoguru_site_cleanup_is_valid_path($path) {
    return $path !== ''
        && $path[0] === '/'
        && strlen($path) <= GEOGURU_SITE_CLEANUP_MAX_PATH_LENGTH
        // `//host/x` or an embedded URL could point the lookup at another host.
        && strpos($path, '//') !== 0
        && strpos($path, '://') === false
        && !preg_match('/[\x00-\x1f\x7f]/', $path);
}

// With EMPTY_TRASH_DAYS at 0, wp_trash_post() deletes for good.
function geoguru_site_cleanup_trash_days() {
    return defined('EMPTY_TRASH_DAYS') ? (int) EMPTY_TRASH_DAYS : 30;
}

// Paths arrive decoded; WordPress stores non-Latin slugs percent-encoded.
function geoguru_site_cleanup_encode_path($path) {
    $segments = explode('/', $path);
    foreach ($segments as $i => $segment) {
        $segments[$i] = rawurlencode(rawurldecode($segment));
    }
    return implode('/', $segments);
}

function geoguru_site_cleanup_find_post($path) {
    $encoded = geoguru_site_cleanup_encode_path($path);

    $id = (int) url_to_postid(home_url($encoded));
    if ($id > 0) {
        $post = get_post($id);
        if ($post) {
            return $post;
        }
    }

    $slug_path = trim($encoded, '/');
    if ($slug_path === '') {
        return null;
    }
    $types = get_post_types(array('public' => true, 'hierarchical' => true), 'names');
    $post = get_page_by_path($slug_path, OBJECT, array_values($types));
    if ($post) {
        return $post;
    }

    // Trashing renames the slug to `<slug>__trashed`; only an unambiguous match counts.
    $slug = substr(strrchr('/' . $slug_path, '/'), 1);
    if (function_exists('_truncate_post_slug')) {
        $slug = _truncate_post_slug($slug, 191);
    }
    $trashed = get_posts(array(
        'name' => $slug . '__trashed',
        'post_type' => 'any',
        'post_status' => 'trash',
        'posts_per_page' => 2,
        'no_found_rows' => true,
    ));
    return count($trashed) === 1 ? $trashed[0] : null;
}

function geoguru_site_cleanup_blocked_reason($post) {
    $id = (int) $post->ID;

    if ($post->post_type === 'attachment') {
        return 'attachment';
    }
    if ($post->post_type === 'nav_menu_item') {
        return 'menu_item';
    }
    $type = get_post_type_object($post->post_type);
    if (!$type || empty($type->public)) {
        return 'not_public';
    }

    $special = array(
        'front_page'   => (int) get_option('page_on_front'),
        'posts_page'   => (int) get_option('page_for_posts'),
        'privacy_page' => (int) get_option('wp_page_for_privacy_policy'),
    );
    foreach ($special as $reason => $special_id) {
        if ($special_id > 0 && $special_id === $id) {
            return $reason;
        }
    }

    if (function_exists('wc_get_page_id')) {
        foreach (array('shop', 'cart', 'checkout', 'myaccount') as $woo_page) {
            $woo_id = (int) wc_get_page_id($woo_page);
            if ($woo_id > 0 && $woo_id === $id) {
                return 'store_page';
            }
        }
    }

    if (!current_user_can('delete_post', $id)) {
        return 'not_allowed';
    }
    if (geoguru_site_cleanup_trash_days() === 0) {
        return 'trash_disabled';
    }
    return null;
}

function geoguru_rest_site_cleanup_resolve($request) {
    $paths = geoguru_site_cleanup_paths($request);
    if (is_wp_error($paths)) {
        return $paths;
    }

    $results = array();
    foreach ($paths as $path) {
        $valid = geoguru_site_cleanup_is_valid_path($path);
        $post = $valid ? geoguru_site_cleanup_find_post($path) : null;
        if (!$post) {
            $results[] = array(
                'path' => $path,
                'post_id' => null,
                'post_type' => null,
                'title' => null,
                'status' => null,
                'edit_url' => null,
                'blocked' => $valid ? null : 'invalid_path',
            );
            continue;
        }
        $edit_url = get_edit_post_link($post->ID, 'raw');
        $results[] = array(
            'path' => $path,
            'post_id' => (int) $post->ID,
            'post_type' => $post->post_type,
            'title' => get_the_title($post),
            'status' => $post->post_status,
            'edit_url' => $edit_url ? $edit_url : null,
            'blocked' => geoguru_site_cleanup_blocked_reason($post),
        );
    }

    GeoGuru_Logger::get_instance()->info('Site cleanup: resolved paths', array(
        'paths' => count($paths),
        'found' => count(array_filter($results, function ($r) { return $r['post_id'] !== null; })),
    ));
    return array('results' => $results, 'trash_days' => geoguru_site_cleanup_trash_days());
}

function geoguru_site_cleanup_trash_one($path) {
    if (!geoguru_site_cleanup_is_valid_path($path)) {
        return array('path' => $path, 'result' => 'blocked', 'post_id' => null, 'reason' => 'invalid_path');
    }
    $post = geoguru_site_cleanup_find_post($path);
    if (!$post) {
        return array('path' => $path, 'result' => 'not_found', 'post_id' => null);
    }
    if ($post->post_status === 'trash') {
        return array('path' => $path, 'result' => 'already_trashed', 'post_id' => (int) $post->ID);
    }
    $reason = geoguru_site_cleanup_blocked_reason($post);
    if ($reason !== null) {
        return array('path' => $path, 'result' => 'blocked', 'post_id' => (int) $post->ID, 'reason' => $reason);
    }
    $result = wp_trash_post($post->ID) ? 'trashed' : 'failed';
    return array('path' => $path, 'result' => $result, 'post_id' => (int) $post->ID);
}

function geoguru_rest_site_cleanup_trash($request) {
    $paths = geoguru_site_cleanup_paths($request);
    if (is_wp_error($paths)) {
        return $paths;
    }

    $results = array();
    $counts = array('trashed' => 0, 'already_trashed' => 0, 'not_found' => 0, 'blocked' => 0, 'failed' => 0);
    $trashed_ids = array();
    foreach ($paths as $path) {
        $entry = geoguru_site_cleanup_trash_one($path);
        $counts[$entry['result']]++;
        if ($entry['result'] === 'trashed') {
            $trashed_ids[] = $entry['post_id'];
        }
        $results[] = $entry;
    }

    GeoGuru_Logger::get_instance()->info('Site cleanup: moved pages to the trash', array_merge($counts, array(
        'user_id' => get_current_user_id(),
        'post_ids' => $trashed_ids,
    )));
    return array('results' => $results);
}
