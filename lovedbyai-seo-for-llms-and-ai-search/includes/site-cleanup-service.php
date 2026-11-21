<?php
/**
 * Site cleanup: find and trash the pages the dashboard flagged as not belonging to the site.
 *
 *   POST geoguru/v1/cleanup/resolve  { "paths": ["/a-page", ...] }  what each path is in WordPress
 *   POST geoguru/v1/cleanup/trash    { "paths": ["/a-page", ...] }  move each one to the trash
 *
 * Who may call it: only the logged-in administrator viewing the dashboard in wp-admin. The permission
 * callback is the dashboard's own (geoguru_rest_permission_callback): the 4-hour token WordPress issued to
 * that user, then `manage_options`. The site's secret token, which our servers use for other routes, is
 * never accepted here, so nothing outside the admin's browser can remove content. The dashboard only
 * reaches these routes through the wp-admin page's bridge (portal-bridge.php), never our proxy.
 *
 * Why the paths are not checked against our list of findings: the request and any list would both come
 * from the admin's own browser, and an administrator can trash any post in WordPress anyway. The guard
 * rails below protect against our mistakes instead: the front page, the posts page, the privacy page,
 * WooCommerce's pages, attachments and private post types are never trashed. And it is always the trash,
 * never a permanent delete, so a wrong pick can be restored for 30 days.
 */

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

/**
 * The request's paths: 1 to 200 strings, each starting with `/`, no scheme or host, duplicates dropped.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error The paths, or a 400.
 */
function geoguru_site_cleanup_paths($request) {
    $body = method_exists($request, 'get_json_params') ? $request->get_json_params() : null;
    $paths = is_array($body) && array_key_exists('paths', $body) ? $body['paths'] : $request->get_param('paths');

    if (!is_array($paths) || count($paths) === 0) {
        return new WP_Error('invalid_paths', 'paths must be a non-empty list', array('status' => 400));
    }
    if (count($paths) > GEOGURU_SITE_CLEANUP_MAX_PATHS) {
        return new WP_Error('too_many_paths', 'at most ' . GEOGURU_SITE_CLEANUP_MAX_PATHS . ' paths', array('status' => 400));
    }

    $clean = array();
    foreach ($paths as $path) {
        if (!is_string($path) || $path === '' || $path[0] !== '/' || strlen($path) > GEOGURU_SITE_CLEANUP_MAX_PATH_LENGTH) {
            return new WP_Error('invalid_paths', 'each path must start with /', array('status' => 400));
        }
        // `//host/x` would make home_url() point at another host.
        if (strpos($path, '//') === 0 || strpos($path, '://') !== false || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return new WP_Error('invalid_paths', 'each path must be a path on this site', array('status' => 400));
        }
        $clean[$path] = true;
    }
    return array_keys($clean);
}

/**
 * The path as it would appear in a link: each segment percent-encoded once. The dashboard stores paths
 * decoded (Hebrew and Russian spam slugs read as text), while WordPress stores such slugs encoded.
 *
 * @param string $path Path starting with `/`.
 * @return string Encoded path.
 */
function geoguru_site_cleanup_encode_path($path) {
    $segments = explode('/', $path);
    foreach ($segments as $i => $segment) {
        $segments[$i] = rawurlencode(rawurldecode($segment));
    }
    return implode('/', $segments);
}

/**
 * The post a path on this site shows, or null. WordPress renames a trashed post's slug (`__trashed`), so a
 * page already in the trash usually reads as not found; one that still resolves reads as already trashed.
 *
 * @param string $path Path starting with `/`.
 * @return WP_Post|null
 */
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
    return $post ? $post : null;
}

/**
 * Why a post must not be trashed from the dashboard, or null when it may be.
 *
 * @param WP_Post $post Post.
 * @return string|null Reason code.
 */
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
    return null;
}

/**
 * POST /cleanup/resolve: what each path is, so the dashboard can show what it can trash and what the
 * owner must remove by hand.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error `{ results: [...] }`, one entry per path, in request order.
 */
function geoguru_rest_site_cleanup_resolve($request) {
    $paths = geoguru_site_cleanup_paths($request);
    if (is_wp_error($paths)) {
        return $paths;
    }

    $results = array();
    foreach ($paths as $path) {
        $post = geoguru_site_cleanup_find_post($path);
        if (!$post) {
            $results[] = array(
                'path' => $path,
                'post_id' => null,
                'post_type' => null,
                'title' => null,
                'status' => null,
                'edit_url' => null,
                'blocked' => null,
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
    return array('results' => $results);
}

/**
 * POST /cleanup/trash: move each path's post to the trash. Finds every post itself; never takes post ids
 * from the request.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error `{ results: [...] }`, one entry per path, in request order.
 */
function geoguru_rest_site_cleanup_trash($request) {
    $paths = geoguru_site_cleanup_paths($request);
    if (is_wp_error($paths)) {
        return $paths;
    }

    $results = array();
    $counts = array('trashed' => 0, 'already_trashed' => 0, 'not_found' => 0, 'blocked' => 0, 'failed' => 0);
    $trashed_ids = array();

    foreach ($paths as $path) {
        $post = geoguru_site_cleanup_find_post($path);
        if (!$post) {
            $entry = array('path' => $path, 'result' => 'not_found', 'post_id' => null);
        } elseif ($post->post_status === 'trash') {
            $entry = array('path' => $path, 'result' => 'already_trashed', 'post_id' => (int) $post->ID);
        } else {
            $reason = geoguru_site_cleanup_blocked_reason($post);
            if ($reason !== null) {
                $entry = array('path' => $path, 'result' => 'blocked', 'post_id' => (int) $post->ID, 'reason' => $reason);
            } elseif (wp_trash_post($post->ID)) {
                $entry = array('path' => $path, 'result' => 'trashed', 'post_id' => (int) $post->ID);
                $trashed_ids[] = (int) $post->ID;
            } else {
                $entry = array('path' => $path, 'result' => 'failed', 'post_id' => (int) $post->ID);
            }
        }
        $counts[$entry['result']]++;
        $results[] = $entry;
    }

    GeoGuru_Logger::get_instance()->info('Site cleanup: moved pages to the trash', array_merge($counts, array(
        'user_id' => get_current_user_id(),
        'post_ids' => $trashed_ids,
    )));
    return array('results' => $results);
}
