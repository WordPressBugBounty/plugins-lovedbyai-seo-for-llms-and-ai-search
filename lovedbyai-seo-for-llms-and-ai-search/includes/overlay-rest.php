<?php
/**
 * REST endpoints for the GeoGuru overlay payload (post meta), written by the optimizer service.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('GEOGURU_OVERLAY_POST_META_KEY')) {
    define('GEOGURU_OVERLAY_POST_META_KEY', '_geoguru_overlay_payload_v1');
}

// Server-to-server auth: Authorization Bearer must match geoguru_secret_token and
// body.site_id must match geoguru_site_id.
function geoguru_rest_overlay_service_permission_callback($request) {
    $logger = GeoGuru_Logger::get_instance();
    $stored_secret = get_option('geoguru_secret_token', '');
    $stored_site_id = get_option('geoguru_site_id', '');
    if ($stored_secret === '' || $stored_site_id === '') {
        $logger->warning('REST overlay-payload: missing site credentials');
        return false;
    }

    $params = $request->get_json_params();
    if (!is_array($params)) {
        return false;
    }
    $body_site = isset($params['site_id']) ? sanitize_text_field($params['site_id']) : '';
    if ($body_site === '' || !hash_equals($stored_site_id, $body_site)) {
        $logger->warning('REST overlay-payload: site_id mismatch');
        return false;
    }

    $auth = $request->get_header('authorization');
    if (empty($auth) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
    }
    if (empty($auth) || !preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        $logger->warning('REST overlay-payload: missing bearer token');
        return false;
    }
    $bearer = trim($m[1]);
    if (!hash_equals($stored_secret, $bearer)) {
        $logger->warning('REST overlay-payload: invalid bearer token');
        return false;
    }

    return true;
}

/**
 * Comparable form of a URL: its path, with everything that is a formatting detail removed.
 *
 * Scheme, host and query are dropped on purpose. The request has already authenticated against
 * this one site, and home_url() legitimately differs from the URL we were handed by http/https,
 * by www, or by a domain alias — none of which change which page is meant. The trailing slash
 * goes for the same reason: it is a permalink-structure detail, not an identity one (the same
 * site serves /industries/solar-web-design and /careers/ side by side). Percent-encoding is
 * decoded so a permalink's /caf%C3%A9/ matches a pushed /café/.
 *
 * The one case where the query is NOT a formatting detail is handled by the caller: see
 * geoguru_overlay_post_matches_url().
 */
function geoguru_overlay_url_path($url) {
    $path = wp_parse_url((string) $url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '/';
    }
    $path = rtrim(rawurldecode($path), '/');
    return $path === '' ? '/' : $path;
}

/** Raw query string of a URL, or '' when it has none. */
function geoguru_overlay_url_query($url) {
    $query = wp_parse_url((string) $url, PHP_URL_QUERY);
    return is_string($query) ? $query : '';
}

/** Does this post currently live at this URL? */
function geoguru_overlay_post_matches_url($post_id, $page_url) {
    $permalink = get_permalink($post_id);
    if (!is_string($permalink) || $permalink === '') {
        return false;
    }

    $path = geoguru_overlay_url_path($permalink);
    if ($path !== geoguru_overlay_url_path($page_url)) {
        return false;
    }

    // On "/" the path carries no identity of its own. A site left on Plain permalinks gives every
    // post the permalink /?p=N, so comparing paths alone would match ANY claimed id against ANY
    // pushed URL on that site — which is the "one page serves another page's optimized title"
    // failure this id is verified against in the first place. Compare the query there, and only
    // there: on a real path it is utm_* noise and stays ignored.
    //
    // Failing this check is safe: resolution falls through to url_to_postid(), which understands
    // ?p= and ?page_id= natively, so Plain-permalink sites resolve exactly as they did before.
    if ($path === '/') {
        return geoguru_overlay_url_query($permalink) === geoguru_overlay_url_query($page_url);
    }

    return true;
}

/**
 * Map a pushed page_url to the post whose meta should carry its overlay payload.
 *
 * Returns array($post_id, $resolved_by); $post_id is 0 when the URL is not a post.
 *
 * url_to_postid() alone is not enough, because it only understands core rewrite rules:
 *
 *  - Sites that mint their own permalinks route them with their own `request` handler, so core
 *    has no rule to match. rawcutcreative.com serves /waukegan/web-design-agency and
 *    /industries/solar-web-design this way — real `cities` posts whose get_permalink() is exactly
 *    the URL we push, yet url_to_postid() returns 0 for every one of them.
 *  - A page assigned as the posts page is reachable only at the blog archive URL, which core maps
 *    to the archive rather than to the page, so url_to_postid('/blog/') is 0 as well.
 *
 * Hence wp_post_id — which the optimizer carries from the WordPress REST API, where the post ID
 * and its canonical link are read together — is tried first, and the posts page last.
 */
function geoguru_overlay_resolve_post_id($page_url, $claimed_post_id) {
    // Verified against the post's current permalink rather than trusted: a post that has since
    // been re-slugged or deleted (with its ID reused) would otherwise overlay the wrong page,
    // and a discovered "page" that is really a taxonomy term carries a term ID, not a post ID.
    $claimed_post_id = (int) $claimed_post_id;
    if ($claimed_post_id > 0 && geoguru_overlay_post_matches_url($claimed_post_id, $page_url)) {
        return array($claimed_post_id, 'wp_post_id');
    }

    $post_id = (int) url_to_postid($page_url);
    if ($post_id > 0) {
        return array($post_id, 'url_to_postid');
    }

    // A slug that is not plain ASCII -- Hebrew, Arabic, Cyrillic, Greek, CJK -- travels
    // percent-encoded, and for a page with a parent core does not match that encoded path against
    // the stored slug: the same URL decoded resolves, the encoded one gives 0. Only reached when no
    // usable wp_post_id came with the push, which is the case this exists for.
    //
    // rawurldecode, not urldecode: the latter also turns '+' into a space, which is a legal
    // character in a slug and would corrupt the path.
    $decoded = rawurldecode($page_url);
    if ($decoded !== $page_url) {
        $post_id = (int) url_to_postid($decoded);
        if ($post_id > 0) {
            return array($post_id, 'url_to_postid_decoded');
        }
    }

    // The reader keys off get_queried_object_id(), which on the blog archive IS the posts page,
    // so the payload does apply once it is stored there.
    $page_for_posts = (int) get_option('page_for_posts');
    if ($page_for_posts > 0 && geoguru_overlay_post_matches_url($page_for_posts, $page_url)) {
        return array($page_for_posts, 'page_for_posts');
    }

    return array(0, 'unresolved');
}

/**
 * The entry ids an envelope carries: the head slots first, then the blocks in their own order.
 *
 * Reported back so the sender can record exactly which entries reached this site rather than
 * assuming the whole envelope landed. Ids are opaque strings here -- the plugin never parses or
 * validates their shape. A v1 envelope carries no ids and so reports none.
 *
 * @param mixed $envelope Decoded envelope.
 * @return string[] Ids, possibly empty.
 */
function geoguru_overlay_rest_entry_ids($envelope) {
    $ids = array();
    if (geoguru_overlay_envelope_version($envelope) !== 2) {
        return $ids;
    }

    $entries = array();
    if (isset($envelope['head']) && is_array($envelope['head'])) {
        if (isset($envelope['head']['metaTitle']) && is_array($envelope['head']['metaTitle'])) {
            $entries[] = $envelope['head']['metaTitle'];
        }
        if (isset($envelope['head']['jsonLd']) && is_array($envelope['head']['jsonLd'])) {
            foreach ($envelope['head']['jsonLd'] as $slot) {
                if (is_array($slot)) {
                    $entries[] = $slot;
                }
            }
        }
    }
    if (isset($envelope['blocks']) && is_array($envelope['blocks'])) {
        foreach ($envelope['blocks'] as $block) {
            if (is_array($block)) {
                $entries[] = $block;
            }
        }
    }

    foreach ($entries as $entry) {
        if (!isset($entry['changeId']) || !is_string($entry['changeId']) || $entry['changeId'] === '') {
            continue;
        }
        if (in_array($entry['changeId'], $ids, true)) {
            continue;
        }
        $ids[] = $entry['changeId'];
    }

    return $ids;
}

function geoguru_rest_receive_overlay_payload($request) {
    $logger = GeoGuru_Logger::get_instance();
    $params = $request->get_json_params();
    if (!is_array($params)) {
        return new WP_Error('geoguru_overlay_invalid_json', 'Invalid JSON body', array('status' => 400));
    }

    $page_url = isset($params['page_url']) ? esc_url_raw($params['page_url']) : '';
    $envelope = isset($params['envelope']) ? $params['envelope'] : null;
    if ($page_url === '' || !is_array($envelope)) {
        return new WP_Error('geoguru_overlay_bad_request', 'page_url and envelope are required', array('status' => 400));
    }

    // v1 and v2 are both accepted. Anything else keeps this exact error code and status: the
    // sender treats a geoguru_overlay_bad_schema rejection as "this site runs an older plugin"
    // and retries in the shape that plugin understands, so the code is part of the contract.
    $version = geoguru_overlay_envelope_version($envelope);
    if ($version !== 1 && $version !== 2) {
        return new WP_Error('geoguru_overlay_bad_schema', 'Unsupported envelope schemaVersion', array('status' => 400));
    }

    if ($version === 1) {
        if (!isset($envelope['payload']) || !is_array($envelope['payload'])) {
            return new WP_Error('geoguru_overlay_bad_payload', 'envelope.payload must be an object', array('status' => 400));
        }
    } else {
        // v2 has no `payload`; it carries `head` and `blocks`. Either may be omitted, but an
        // envelope with neither has nothing to store.
        $has_head = isset($envelope['head']);
        $has_blocks = isset($envelope['blocks']);
        if (!$has_head && !$has_blocks) {
            return new WP_Error('geoguru_overlay_bad_payload', 'envelope.head or envelope.blocks is required', array('status' => 400));
        }
        if (($has_head && !is_array($envelope['head'])) || ($has_blocks && !is_array($envelope['blocks']))) {
            return new WP_Error('geoguru_overlay_bad_payload', 'envelope.head must be an object and envelope.blocks an array', array('status' => 400));
        }
    }

    $claimed_post_id = isset($params['wp_post_id']) ? (int) $params['wp_post_id'] : 0;
    list($post_id, $resolved_by) = geoguru_overlay_resolve_post_id($page_url, $claimed_post_id);
    if ($claimed_post_id > 0 && $resolved_by !== 'wp_post_id') {
        // The optimizer's ID did not match this URL. Worth seeing: it means the page moved, was
        // deleted, or was never a post — not just that this one push needs another strategy.
        $logger->warning('REST overlay-payload: wp_post_id does not match page_url', array(
            'page_url' => $page_url,
            'wp_post_id' => $claimed_post_id,
            'resolved_by' => $resolved_by,
        ));
    }
    if ($post_id <= 0) {
        $logger->info('REST overlay-payload: could not resolve post id', array(
            'page_url' => $page_url,
            'had_wp_post_id' => $claimed_post_id > 0,
        ));
        return new WP_Error('geoguru_overlay_no_post', 'Could not resolve WordPress post for page_url', array('status' => 404));
    }

    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return new WP_Error('geoguru_overlay_invalid_post', 'Invalid post', array('status' => 404));
    }
    if ($post->post_status === 'trash') {
        return new WP_Error('geoguru_overlay_trashed_post', 'Post is in trash', array('status' => 400));
    }

    // Refuse an envelope older than the one this post already carries, rather than letting a slow
    // push put older content back on the page. Its own code, so the sender can tell "a newer one is
    // already here" from a push that failed.
    $previous_raw = get_post_meta($post_id, GEOGURU_OVERLAY_POST_META_KEY, true);
    if (geoguru_overlay_is_superseded($envelope, $previous_raw, $post_id)) {
        $logger->info('REST overlay-payload: refused an envelope older than the stored one', array(
            'post_id' => $post_id,
            'updated_at' => $envelope['updatedAt'],
        ));
        return new WP_Error('geoguru_overlay_superseded', 'A newer envelope is already stored for this post', array('status' => 409));
    }

    // Stamp the payload with the post it was resolved for. Post meta is copied wholesale by
    // WordPress duplication plugins, so without this a duplicated post inherits — and serves —
    // the source post's optimized title and JSON-LD. The reader refuses a mismatched stamp
    // (see geoguru_overlay_envelope_from_meta).
    $envelope['postId'] = $post_id;

    $encoded = wp_json_encode($envelope);
    if (!is_string($encoded)) {
        return new WP_Error('geoguru_overlay_encode_failed', 'Could not encode envelope', array('status' => 500));
    }
    if (strlen($encoded) > 500000) {
        return new WP_Error('geoguru_overlay_too_large', 'Envelope too large', array('status' => 400));
    }

    // wp_slash: update_post_meta strips one slash level, which would corrupt the JSON escapes
    // (\u, \/, \\n) and break the verification below.
    update_post_meta($post_id, GEOGURU_OVERLAY_POST_META_KEY, wp_slash($encoded));
    $stored = get_post_meta($post_id, GEOGURU_OVERLAY_POST_META_KEY, true);
    if (!is_string($stored) || $stored !== $encoded) {
        $logger->error('REST overlay-payload: post meta verification failed', array('post_id' => $post_id));
        return new WP_Error('geoguru_overlay_write_failed', 'Failed to persist overlay meta', array('status' => 500));
    }

    $logger->info('REST overlay-payload: stored', array('post_id' => $post_id, 'page_url' => $page_url));

    // Purge this page's cache so the reader re-merges the new payload on the next request -- unless
    // the content is what the post already carried, in which case nothing a visitor sees has changed.
    // The envelope is still written above, so its stamp moves on and a slower, older one is still
    // refused after it.
    $unchanged = geoguru_overlay_same_content($envelope, $previous_raw, $post_id);
    if (!$unchanged && function_exists('geoguru_overlay_purge_caches')) {
        geoguru_overlay_purge_caches($post_id);
    }

    return new WP_REST_Response(
        array(
            'ok' => true,
            'post_id' => $post_id,
            'stored' => geoguru_overlay_rest_entry_ids($envelope),
        ),
        200
    );
}

function geoguru_register_overlay_rest_routes() {
    $routes = array('/overlay-payload', '/overlay-payload/');
    foreach ($routes as $path) {
        register_rest_route(
            'geoguru/v1',
            $path,
            array(
                'methods' => 'POST',
                'callback' => 'geoguru_rest_receive_overlay_payload',
                'permission_callback' => 'geoguru_rest_overlay_service_permission_callback',
            )
        );
    }
}
