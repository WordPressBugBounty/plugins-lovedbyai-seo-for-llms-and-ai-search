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

    if (!isset($envelope['schemaVersion']) || (int) $envelope['schemaVersion'] !== 1) {
        return new WP_Error('geoguru_overlay_bad_schema', 'Unsupported envelope schemaVersion', array('status' => 400));
    }
    if (!isset($envelope['payload']) || !is_array($envelope['payload'])) {
        return new WP_Error('geoguru_overlay_bad_payload', 'envelope.payload must be an object', array('status' => 400));
    }

    $post_id = 0;
    if (isset($params['wp_post_id'])) {
        $post_id = (int) $params['wp_post_id'];
    }
    if ($post_id <= 0) {
        $post_id = (int) url_to_postid($page_url);
    }
    if ($post_id <= 0) {
        $logger->info('REST overlay-payload: could not resolve post id', array('page_url' => $page_url));
        return new WP_Error('geoguru_overlay_no_post', 'Could not resolve WordPress post for page_url', array('status' => 404));
    }

    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return new WP_Error('geoguru_overlay_invalid_post', 'Invalid post', array('status' => 404));
    }
    if ($post->post_status === 'trash') {
        return new WP_Error('geoguru_overlay_trashed_post', 'Post is in trash', array('status' => 400));
    }

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

    // Purge this page's cache so the reader re-merges the new payload on the next request.
    if (function_exists('geoguru_overlay_purge_caches')) {
        geoguru_overlay_purge_caches($post_id);
    }

    return new WP_REST_Response(
        array(
            'ok' => true,
            'post_id' => $post_id,
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
