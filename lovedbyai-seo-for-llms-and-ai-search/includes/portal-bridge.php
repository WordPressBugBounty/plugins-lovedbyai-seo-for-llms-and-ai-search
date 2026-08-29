<?php
/**
 * Relays REST calls from the embedded dashboard iframe through the admin page,
 * which is same-origin with the REST API. The iframe supplies its own session
 * token with every call, so the relay grants it nothing it could not already do.
 */

defined('ABSPATH') || exit;

// Element id given to the dashboard iframe; used to verify message senders.
define('GEOGURU_PORTAL_IFRAME_ID', 'geoguru-portal-iframe');

/**
 * Routes the iframe may ask this page to call. Without this list the relay
 * could be asked to call any REST route, including core ones.
 *
 * @return array Map of route suffix to allowed HTTP methods.
 */
function geoguru_portal_bridge_allowed_routes() {
    return array(
        '/settings'       => array('GET', 'POST'),
        '/settings/'      => array('GET', 'POST'),
        '/settings/reset' => array('POST'),
        '/token/refresh'  => array('POST'),
    );
}

/**
 * Scheme, host and (when non-default) port of a URL.
 *
 * @param string $url URL to reduce to an origin.
 * @return string Origin, or empty string when the URL cannot be parsed.
 */
function geoguru_portal_bridge_origin($url) {
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $origin = $parts['scheme'] . '://' . $parts['host'];
    // Browsers normalise away the scheme's default port, so keeping an explicit
    // :443 or :80 would produce an origin no window can ever match.
    $defaults = array('https' => 443, 'http' => 80);
    $scheme = strtolower($parts['scheme']);
    $is_default = isset($defaults[$scheme], $parts['port']) && (int) $parts['port'] === $defaults[$scheme];
    if (!empty($parts['port']) && !$is_default) {
        $origin .= ':' . $parts['port'];
    }
    return $origin;
}

/**
 * Whether the given admin page embeds the dashboard iframe.
 *
 * Mirrors the page matching used for the admin styles: exact screen id first,
 * with a hook substring fallback. The log viewer is excluded explicitly because
 * its hook contains the dashboard page slug.
 *
 * @param string $hook Admin page hook suffix.
 * @return bool
 */
function geoguru_is_portal_screen($hook) {
    global $lovedbyai_portal_slug, $lovedbyai_settings_slug, $lovedbyai_log_viewer_slug;

    if (strpos($hook, $lovedbyai_log_viewer_slug) !== false) {
        return false;
    }

    $screen = get_current_screen();
    $current_page = $screen ? $screen->id : '';

    if ($current_page === $lovedbyai_portal_slug . '_page_' . $lovedbyai_settings_slug
        || strpos($hook, $lovedbyai_settings_slug) !== false) {
        return true;
    }

    return $current_page === 'toplevel_page_' . $lovedbyai_portal_slug
        || strpos($hook, $lovedbyai_portal_slug) !== false;
}

add_action('admin_enqueue_scripts', 'geoguru_enqueue_portal_bridge');
/**
 * Load the relay on the admin pages that embed the dashboard.
 *
 * @param string $hook Admin page hook suffix.
 * @return void
 */
function geoguru_enqueue_portal_bridge($hook) {
    if (!geoguru_is_portal_screen($hook) || !current_user_can('manage_options')) {
        return;
    }

    $portal_url = get_option('geoguru_portal_url');
    if (empty($portal_url) || !filter_var($portal_url, FILTER_VALIDATE_URL)) {
        return;
    }

    $portal_origin = geoguru_portal_bridge_origin($portal_url);
    if ($portal_origin === '') {
        return;
    }

    $rest_base = rest_url('geoguru/v1/');
    $config = array(
        'portalOrigin'  => $portal_origin,
        'restBase'      => esc_url_raw($rest_base),
        'restOrigin'    => geoguru_portal_bridge_origin($rest_base),
        'allowed'       => geoguru_portal_bridge_allowed_routes(),
        'pluginVersion' => GEOGURU_PLUGIN_VERSION,
        'iframeId'      => GEOGURU_PORTAL_IFRAME_ID,
    );

    wp_register_script('geoguru-portal-bridge', false, array(), GEOGURU_PLUGIN_VERSION, true);
    wp_enqueue_script('geoguru-portal-bridge');
    wp_add_inline_script(
        'geoguru-portal-bridge',
        'window.geoguruPortalBridge = ' . wp_json_encode($config) . ';',
        'before'
    );
    $script = GeoGuru_Utils::inline_js('portal-bridge');
    if ($script !== '') {
        wp_add_inline_script('geoguru-portal-bridge', $script);
    }
}
