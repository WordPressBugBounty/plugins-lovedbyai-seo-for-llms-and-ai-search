<?php 
/**
 * Plugin Name: LovedByAI - Generative Engine Optimization AI Search
 * Description: Automatically optimize your website to ensure it gets noticed by LLMs and AI search engines.
 * Plugin URI: https://lovedby.ai
 * Version: 1.7.28
 * Author: LovedByAI
 * Author URI: https://lovedby.ai
 * Requires PHP: 7.1
 * Requires at least: 5.8
 * Tested up to: 7.1
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * 
*/

defined('ABSPATH') || exit;

// Derive plugin version for analytics
if (!defined('GEOGURU_PLUGIN_VERSION')) {
    $geoguru_plugin_data = get_file_data(__FILE__, ['Version' => 'Version']);
    define('GEOGURU_PLUGIN_VERSION', isset($geoguru_plugin_data['Version']) ? $geoguru_plugin_data['Version'] : 'unknown');
}

// Secure file inclusion with existence checks
$geoguru_required_files = [
    'includes/utils.php',
    'includes/logger.php',
    'includes/requests-interceptor.php',
    'includes/config-service.php',
    'includes/supabase-service.php',
    'includes/mixpanel-service.php',
    'includes/llms-txt-service.php',
    'includes/discovery-trigger-service.php',
    'includes/indexnow-service.php',
    'includes/settings-sync-service.php',
    'includes/migration-manager.php',
    'includes/diagnostics-page.php',
    'includes/manual-credentials-page.php',
    'includes/overlay-rest.php',
    'includes/overlay-reader.php',
    'includes/portal-bridge.php'
];

foreach ($geoguru_required_files as $geoguru_file) {
    $geoguru_file_path = plugin_dir_path(__FILE__) . $geoguru_file;
    if (!file_exists($geoguru_file_path)) {
        wp_die(sprintf('GeoGuru Plugin Error: Required file %s not found.', esc_html($geoguru_file)));
    }
    require_once $geoguru_file_path;
}

// Menu/page slugs (static non-branded slugs so toggling white-label never changes URLs)
$lovedbyai_portal_slug = 'ai-optimization-portal';
$lovedbyai_settings_slug = 'ai-optimization-portal-settings';
$lovedbyai_log_viewer_slug = 'ai-optimization-log-viewer';
define('LOVEDBYAI_DEFAULT_WHITE_LABEL_DISPLAY_NAME', 'AI Optimizations');

register_activation_hook(__FILE__, 'geoguru_plugin_activated');
register_deactivation_hook(__FILE__, 'geoguru_plugin_deactivate');
register_uninstall_hook(__FILE__, 'geoguru_plugin_uninstall');

// Register REST API routes
add_action('rest_api_init', 'geoguru_register_rest_routes');

// Allow our REST namespace when another plugin restricts the REST API to logged-in
// users; our endpoints authenticate in their own permission_callback. Runs last so
// a later filter cannot re-add the error.
add_filter('rest_authentication_errors', 'geoguru_allow_rest_namespace_when_restricted', PHP_INT_MAX, 1);

// Resolve our session token to a user early so site-wide "REST requires login"
// rules pass. Priority 30: real login cookies (priority 20) win over the token.
add_filter('determine_current_user', 'geoguru_determine_current_user_from_token', 30);

// Add CORS headers for REST API requests
// Use late priority to ensure our headers take precedence over other plugins that set CORS headers.
add_filter('rest_pre_serve_request', 'geoguru_rest_cors_headers', PHP_INT_MAX, 4);
// Add early CORS handler for OPTIONS requests (before WordPress processes them)
// Use late priority to ensure our headers take precedence over other plugins that set CORS headers.
add_filter('rest_pre_dispatch', 'geoguru_handle_cors_preflight', PHP_INT_MAX, 3);

// In white-label mode, replace the plugin name and author in the Plugins list with the brand display name and author.
add_filter('all_plugins', 'geoguru_filter_all_plugins_mask_brand_name');

// PHP 7.1+ compatible class to replace enum (PHP 8.1+ feature)
if (!class_exists('GeoGuru_OptimizationMethod')) {
    class GeoGuru_OptimizationMethod {
        const LLM_LINK_GENERATOR = "llm_link_generator";
        const REQUESTS_INTERCEPTOR = "requests_interceptor";
    }
}

// Option name prefix for iframe tokens (one option per token; avoids race conditions)
// Uses options table instead of transients so tokens persist across HTTP requests
define('GEOGURU_IFRAME_TOKEN_OPTION_PREFIX', 'geoguru_iframe_token_');

/**
 * Prune expired iframe tokens. Called when admin accesses plugin pages.
 * Does not rely on cron (WP-Cron may be disabled on some hosts).
 */
function geoguru_prune_expired_iframe_tokens() {
    global $wpdb;

    // One-time cleanup of legacy shared option format (pre-1.5.3)
    delete_option('geoguru_iframe_tokens');

    $max_age = 4 * HOUR_IN_SECONDS;
    $cutoff = time() - $max_age;
    $prefix = GEOGURU_IFRAME_TOKEN_OPTION_PREFIX;
    $like = $wpdb->esc_like($prefix) . '%';

    $option_names = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like
    ));

    foreach ($option_names as $opt_name) {
        $data = get_option($opt_name, null);
        if (is_array($data) && isset($data['created']) && $data['created'] < $cutoff) {
            delete_option($opt_name);
        }
    }
}

// Store the WordPress nonce server-side with a unique token
// One option per token: no read-modify-write, so concurrent creates cannot race
function geoguru_create_secure_nonce($nonce_action) {
    $token = wp_generate_password(32, false);
    $user_id = get_current_user_id();
    $timestamp = time();

    // Create custom token using WordPress salt + user_id + timestamp
    $wp_salt = wp_salt('nonce'); // WordPress nonce salt
    $custom_nonce = hash('sha256', $wp_salt . $user_id . $timestamp);

    $option_name = GEOGURU_IFRAME_TOKEN_OPTION_PREFIX . $token;
    $data = array(
        'nonce' => $custom_nonce,
        'user_id' => $user_id,
        'created' => $timestamp,
    );

    $result = update_option($option_name, $data);
    return $result ? $token : false;
}

// Verify using the token
function geoguru_verify_secure_token($token, $nonce_action) {
    $logger = GeoGuru_Logger::get_instance();
    $option_name = GEOGURU_IFRAME_TOKEN_OPTION_PREFIX . $token;
    $data = get_option($option_name, null);

    if (!is_array($data) || !isset($data['nonce'], $data['user_id'], $data['created'])) {
        $logger->error('secure token not found', array('token' => $token));
        return false;
    }

    $time_since_creation = time() - $data['created'];
    if ($time_since_creation > (4 * HOUR_IN_SECONDS)) {
        delete_option($option_name); // Lazy delete expired token
        $logger->error('Token expired', array('time_since_creation' => $time_since_creation));
        return false;
    }

    $wp_salt = wp_salt('nonce');
    $expected_nonce = hash('sha256', $wp_salt . $data['user_id'] . $data['created']);
    $valid = ($data['nonce'] === $expected_nonce);

    $logger->info('secure token verified', array(
        'valid' => $valid,
        'user_id' => $data['user_id'],
        'time_since_creation' => $time_since_creation,
    ));

    return $valid ? (int) $data['user_id'] : false;
}

function geoguru_insert_plugin_default_config() {
    $config = GeoGuru_ConfigService::get_instance();
    
    if (!get_option('geoguru_portal_url')) {
        $portal_url = $config->get('PORTAL_URL', 'https://app.lovedby.ai');
        if (filter_var($portal_url, FILTER_VALIDATE_URL)) {
            update_option('geoguru_portal_url', esc_url_raw($portal_url));
        }
    }
    if (!get_option('geoguru_optimization_method')) {
        update_option('geoguru_optimization_method', GeoGuru_OptimizationMethod::LLM_LINK_GENERATOR);
    }
    if (!get_option('geoguru_supabase_url')) {
        $supabase_url = $config->get('SUPABASE_URL', 'https://sup.lovedby.ai');
        if (filter_var($supabase_url, FILTER_VALIDATE_URL)) {
            update_option('geoguru_supabase_url', esc_url_raw($supabase_url));
        }
    }
    if (!get_option('geoguru_supabase_anon_key')) {
        $supabase_anon_key = $config->get('SUPABASE_ANON_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InlmdHplb3BtZGx5bWFyZWRxb3NiIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NDQ3OTIxNjEsImV4cCI6MjA2MDM2ODE2MX0.cBsNTgKhVwW5yPPmNXTX6s0EELkpfYOxPUdeSUSQcnc');
        update_option('geoguru_supabase_anon_key', $supabase_anon_key);
    }
    if (!get_option('geoguru_fire_and_forget_worker_url')) {
        $fire_and_forget_worker_url = $config->get('FIRE_AND_FORGET_WORKER_URL', 'https://api.lovedby.ai/fire-and-forget');
        update_option('geoguru_fire_and_forget_worker_url', $fire_and_forget_worker_url);
    }
    if (!get_option('geoguru_optimized_content_cdn_url')) {
        $optimized_content_cdn_url = $config->get('OPTIMIZED_CONTENT_CDN_URL', 'https://cdn.lovedby.ai');
        update_option('geoguru_optimized_content_cdn_url', $optimized_content_cdn_url);
    }
    if (!get_option('geoguru_llm_tracking_enabled')) {
        update_option('geoguru_llm_tracking_enabled', 1);
    }
    if (!get_option('geoguru_log_level')) {
        update_option('geoguru_log_level', $config->get('LOG_LEVEL', GeoGuru_Logger::ERROR));
    }
    if (!get_option('geoguru_optimizer_service_url')) {
        $optimizer_service_url = $config->get('OPTIMIZER_URL', 'https://api.lovedby.ai');
        update_option('geoguru_optimizer_service_url', $optimizer_service_url);
    }
    if (!get_option('geoguru_mixpanel_token')) {
        $mixpanel_token = $config->get('MIXPANEL_TOKEN', '7a03831bc016b6a12830f3b94f8ff6dc');
        update_option('geoguru_mixpanel_token', $mixpanel_token);
    }
    if (!get_option('geoguru_automatic_optimization_enabled')) {
        update_option('geoguru_automatic_optimization_enabled', 1);
    }
    if (!get_option('geoguru_llms_txt_enabled')) {
        update_option('geoguru_llms_txt_enabled', 1);
    }
    if (!get_option('geoguru_llms_txt_config')) {
        update_option('geoguru_llms_txt_config', array());
    }
    if (!get_option('geoguru_discovery_service_url')) {
        $discovery_service_url = $config->get('DISCOVERY_SERVICE_URL', 'https://api.lovedby.ai');
        if (filter_var($discovery_service_url, FILTER_VALIDATE_URL)) {
            update_option('geoguru_discovery_service_url', esc_url_raw($discovery_service_url));
        }
    }
    if (get_option('geoguru_indexnow_enabled', null) === null) {
        add_option('geoguru_indexnow_enabled', 1);
    }
    if (get_option('geoguru_apply_non_visible_overlay_on_original_page', null) === null) {
        add_option('geoguru_apply_non_visible_overlay_on_original_page', 1);
    }
    if (!get_option('geoguru_indexnow_service_url')) {
        $indexnow_service_url = $config->get('INDEXNOW_SERVICE_URL', 'https://api.lovedby.ai');
        if (filter_var($indexnow_service_url, FILTER_VALIDATE_URL)) {
            update_option('geoguru_indexnow_service_url', esc_url_raw($indexnow_service_url));
        }
    }
}

/**
 * Pull this site's settings from the backend and apply them, so local options converge to the
 * source of truth. No-op before the site is registered or if the backend can't be reached.
 */
function geoguru_reconcile_synced_settings() {
    $site_id = get_option('geoguru_site_id', '');
    $secret_token = get_option('geoguru_secret_token', '');
    if (empty($site_id) || empty($secret_token) || !class_exists('GeoGuru_SettingsSyncService')) {
        return;
    }
    $synced_settings = GeoGuru_SupabaseService::get_instance()->get_synced_settings($site_id, $secret_token);
    if (is_array($synced_settings)) {
        GeoGuru_SettingsSyncService::get_instance()->apply_synced_settings($synced_settings);
    }
}

/**
 * The REST route this request is for, or empty string when it is not a REST
 * request. Handles the /wp-json/ path form and the ?rest_route= query form.
 *
 * Derived from the path and the parsed rest_route parameter only, never from a
 * substring of the raw URI: a decoy value elsewhere in the query string must
 * not be able to pass this off as one of our routes.
 *
 * @return string Route with a leading slash, e.g. '/geoguru/v1/settings'.
 */
function geoguru_current_rest_route() {
    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    if ($uri === '') {
        return '';
    }

    $path = wp_parse_url($uri, PHP_URL_PATH);
    if (is_string($path) && $path !== '') {
        $path = rawurldecode($path);
        $marker = strpos($path, '/wp-json/');
        if ($marker !== false) {
            return '/' . ltrim(substr($path, $marker + strlen('/wp-json/')), '/');
        }
    }

    $query = wp_parse_url($uri, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        $params = array();
        parse_str($query, $params);
        if (isset($params['rest_route']) && is_string($params['rest_route']) && $params['rest_route'] !== '') {
            return '/' . ltrim($params['rest_route'], '/');
        }
    }

    return '';
}

/**
 * Whether the current request targets one of the given REST route namespaces.
 *
 * @param array $namespaces REST namespaces, e.g. array('geoguru/v1').
 * @return bool
 */
function geoguru_rest_request_targets($namespaces) {
    $route = geoguru_current_rest_route();
    if ($route === '') {
        return false;
    }
    foreach ($namespaces as $namespace) {
        if (strpos($route, '/' . trim($namespace, '/') . '/') === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Whether a route in our namespace is one of the routes registered without any
 * permission check.
 *
 * @param string $route Route with a leading slash.
 * @return bool
 */
function geoguru_is_public_rest_route($route) {
    $route = rtrim($route, '/');
    $public = array(
        '/geoguru/v1/reachability',
        '/geoguru/v1/llm-source-event',
    );
    return in_array($route, $public, true);
}

/**
 * Read the session token from the raw request (runs before WP_REST_Request exists).
 *
 * @return string Token, or empty string when absent.
 */
function geoguru_read_request_token() {
    if (isset($_SERVER['HTTP_X_GEOGURU_TOKEN']) && is_string($_SERVER['HTTP_X_GEOGURU_TOKEN'])) {
        return sanitize_text_field(wp_unslash($_SERVER['HTTP_X_GEOGURU_TOKEN']));
    }
    if (isset($_GET['token']) && is_string($_GET['token'])) {
        return sanitize_text_field(wp_unslash($_GET['token']));
    }
    if (isset($_POST['token']) && is_string($_POST['token'])) {
        return sanitize_text_field(wp_unslash($_POST['token']));
    }
    return '';
}

/**
 * Resolve the session token to a WordPress user early, so filters on
 * rest_authentication_errors that require a logged-in user pass. Scoped to our
 * namespace: the token must never authenticate a WordPress core route. The
 * route's own permission_callback still verifies the token before it runs.
 *
 * @param int|false $user_id User ID resolved by earlier filters, if any.
 * @return int|false
 */
function geoguru_determine_current_user_from_token($user_id) {
    if (!empty($user_id)) {
        return $user_id;
    }
    if (!geoguru_rest_request_targets(array('geoguru/v1'))) {
        return $user_id;
    }
    // Our public routes never need a user, and looking a token up for them would
    // let anonymous callers drive an option query per made-up token.
    if (geoguru_is_public_rest_route(geoguru_current_rest_route())) {
        return $user_id;
    }

    // Token verification logs, and logging can re-enter this filter.
    static $resolving = false;
    if ($resolving) {
        return $user_id;
    }

    $token = geoguru_read_request_token();
    if ($token === '') {
        return $user_id;
    }

    $resolving = true;
    $resolved = geoguru_verify_secure_token($token, 'geoguru_rest_nonce');
    $resolving = false;

    return $resolved ? (int) $resolved : $user_id;
}

/**
 * Whether a REST authentication result is a login-required rejection. Matched by
 * HTTP status (401/403) because each site invents its own error code; known core
 * codes cover errors that carry no status.
 *
 * @param WP_Error|null|true $result Result from previous filters.
 * @return bool
 */
function geoguru_is_rest_login_required_error($result) {
    if (!is_wp_error($result)) {
        return false;
    }
    // Never clear WordPress's own nonce rejection. On an invalid nonce core keeps
    // the cookie-authenticated user, so discarding the error would let a
    // cross-site request act as that user.
    if ($result->get_error_code() === 'rest_cookie_invalid_nonce') {
        return false;
    }
    $data = $result->get_error_data();
    if (is_array($data) && isset($data['status'])) {
        $status = (int) $data['status'];
        return $status === 401 || $status === 403;
    }
    $codes = array(
        'rest_api_authentication_required',
        'rest_not_logged_in',
    );
    return in_array($result->get_error_code(), $codes, true);
}

/**
 * Allow requests to our REST namespaces through when the site requires a login
 * for all REST traffic. Our endpoints authenticate in their own
 * permission_callback. Also lets the CORS preflight through, which carries no
 * credentials and would otherwise always be rejected.
 *
 * @param WP_Error|null|true $result Result from previous filters.
 * @return WP_Error|null|true Pass through, or null to allow our namespaces.
 */
function geoguru_allow_rest_namespace_when_restricted($result) {
    if (!geoguru_is_rest_login_required_error($result)) {
        return $result;
    }
    if (!geoguru_rest_request_targets(array('geoguru/v1', 'geoguru-api'))) {
        return $result;
    }
    return null;
}

/**
 * Permission callback for REST API endpoints
 * Verifies the custom token and checks user permissions
 * 
 * @param WP_REST_Request $request The REST API request object
 * @return bool|WP_Error True if user is authenticated and has permissions, false or WP_Error otherwise
 */
function geoguru_rest_permission_callback($request) {
    $logger = GeoGuru_Logger::get_instance();
    
    // Extract token from header or query param
    $token = $request->get_header('X-GeoGuru-Token');
    if (empty($token)) {
        $token = $request->get_param('token');
    }
    
    if (empty($token)) {
        $logger->error('REST API: Token missing', ['endpoint' => $request->get_route()]);
        return false;
    }
    
    // Verify token
    $user_id = geoguru_verify_secure_token(sanitize_text_field($token), 'geoguru_rest_nonce');
    
    if (!$user_id) {
        $logger->error('REST API: Token verification failed', ['endpoint' => $request->get_route()]);
        return false;
    }
    
    // Set user context
    wp_set_current_user($user_id);
    
    // Check user permissions
    if (!current_user_can('manage_options')) {
        $logger->error('REST API: Insufficient permissions', ['user_id' => $user_id, 'endpoint' => $request->get_route()]);
        return false;
    }
    
    return true;
}

/**
 * Add CORS headers for REST API requests
 * 
 * @param bool $served Whether the request has already been served
 * @param WP_REST_Response $result Result to send to the client
 * @param WP_REST_Request $request Request used to generate the response
 * @param WP_REST_Server $server Server instance
 * @return bool Whether the request was served
 */
function geoguru_rest_cors_headers($served, $result, $request, $server) {
    $config = GeoGuru_ConfigService::get_instance();
    $logger = GeoGuru_Logger::get_instance();
    $logger->info('REST API: CORS headers request');

    // Only apply to our namespace
    $route = $request->get_route();
    if (strpos($route, '/geoguru/v1/') !== 0) {
        $logger->debug('REST API: Not in our namespace', ['route' => $route]);
        return $served;
    }
    
    // Define allowed origins (production only - no development URLs in production)
    $allowed_origins = [
        'https://app.geoguru.ai',
        'https://portal.geoguru.ai',
        'https://lovedby.ai',
        'https://app.lovedby.ai'
    ];
    
    // Add localhost only in development/staging environments
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $allowed_origins_config = $config->get('ALLOWED_ORIGINS', 'http://localhost:8081,https://app.xxx.com');
        if (!empty($allowed_origins_config)) {
            // Parse comma-separated origins
            $origins = array_map('trim', explode(',', $allowed_origins_config));
            foreach ($origins as $origin) {
                if (!empty($origin)) {
                    $allowed_origins[] = $origin;
                }
            }
        }

    }

    // Get the actual request origin from the Origin header
    $request_origin = $request->get_header('Origin');
    if (empty($request_origin)) {
        // Fallback to HTTP_ORIGIN server variable (some browsers use this)
        $request_origin = isset($_SERVER['HTTP_ORIGIN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
    }
    
    // If no origin header, check if this is a same-origin request (no CORS needed)
    if (empty($request_origin)) {
        $logger->debug('REST API: No Origin header found, likely same-origin request');
        return $served;
    }
    
    // Validate and sanitize the origin
    $request_origin = esc_url_raw($request_origin);
    if (!filter_var($request_origin, FILTER_VALIDATE_URL)) {
        $logger->warning('REST API: Invalid origin format', ['origin' => $request_origin]);
        return $served;
    }
    
    // Check if the request origin is in the allowed list
    if (!in_array($request_origin, $allowed_origins, true)) {
        $logger->warning('REST API: Origin not allowed', ['origin' => $request_origin, 'allowed' => $allowed_origins]);
        return $served;
    }
    
    // Set CORS headers with the actual request origin
    $logger->info('REST API: Adding CORS headers', ['route' => $route, 'origin' => $request_origin]);
    header('Access-Control-Allow-Origin: ' . $request_origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin'); // Important for caching
    
    // Handle preflight OPTIONS requests
    // According to CORS spec, Access-Control-Allow-Methods and Access-Control-Allow-Headers
    // should ONLY be set in preflight responses, not in actual request responses
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        $logger->info('REST API: OPTIONS preflight request', ['origin' => $request_origin]);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        // Handle requested headers from preflight request
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
            $requested_headers = sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']));
            header('Access-Control-Allow-Headers: ' . $requested_headers);
        } else {
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-GeoGuru-Token, X-Requested-With');
        }
        // Send 200 OK and exit for preflight
        status_header(200);
        exit(0);
    }
    
    return $served;
}

/**
 * Handle CORS preflight requests early (before route matching)
 * This ensures OPTIONS requests always get CORS headers
 * 
 * @param mixed $result Response to replace the requested route with
 * @param WP_REST_Server $server REST server instance
 * @param WP_REST_Request $request Request used to generate the response
 * @return mixed Response or null to continue processing
 */
function geoguru_handle_cors_preflight($result, $server, $request) {
    $logger = GeoGuru_Logger::get_instance();
    $logger->info('REST API: Early OPTIONS preflight handler');
    
    // Only handle OPTIONS requests for our namespace
    if ($request->get_method() !== 'OPTIONS') {
        $logger->debug('REST API: Not an OPTIONS request', ['request' => $request->get_method()]);
        return $result;
    }
    
    $route = $request->get_route();
    if (strpos($route, '/geoguru/v1/') !== 0) {
        $logger->debug('REST API: Not in our namespace', ['route' => $route]);
        return $result;
    }
    
    $logger->info('REST API: Early OPTIONS preflight handler', ['route' => $route]);
    
    // Use shared function to set CORS headers
    $cors_set = geoguru_set_cors_headers($request);
    
    if ($cors_set) {
        status_header(200);
        exit(0);
    }
    
    return $result;
}

/**
 * Shared function to set CORS headers
 * Returns true if headers were set, false otherwise
 * 
 * @param WP_REST_Request $request Request object
 * @return bool True if headers were set
 */
function geoguru_set_cors_headers($request) {
    $config = GeoGuru_ConfigService::get_instance();
    $logger = GeoGuru_Logger::get_instance();
    $logger->info('REST API: Setting CORS headers');
    
    // Define allowed origins
    $allowed_origins = [
        'https://app.geoguru.ai',
        'https://portal.geoguru.ai',
        'https://lovedby.ai',
        'https://app.lovedby.ai'
    ];
    
    // Add localhost only in development/staging environments
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $allowed_origins_config = $config->get('ALLOWED_ORIGINS', 'http://localhost:8081,https://app.xxx.com');
        if (!empty($allowed_origins_config)) {
            // Parse comma-separated origins
            $origins = array_map('trim', explode(',', $allowed_origins_config));
            foreach ($origins as $origin) {
                if (!empty($origin)) {
                    $allowed_origins[] = $origin;
                }
            }
        }
    }

    // Get the actual request origin from the Origin header
    $request_origin = $request->get_header('Origin');
    if (empty($request_origin)) {
        // Fallback to HTTP_ORIGIN server variable
        $request_origin = isset($_SERVER['HTTP_ORIGIN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
    }
    
    // If no origin header, this might be a same-origin request
    if (empty($request_origin)) {
        return false;
    }
    
    // Validate and sanitize the origin
    $request_origin = esc_url_raw($request_origin);
    if (!filter_var($request_origin, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    // Check if the request origin is in the allowed list
    if (!in_array($request_origin, $allowed_origins, true)) {
        $logger->warning('REST API: Origin not allowed', ['origin' => $request_origin]);
        return false;
    }
    
    // Set CORS headers with the actual request origin
    header('Access-Control-Allow-Origin: ' . $request_origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin'); // Important for caching
    
    // For preflight OPTIONS requests, set additional headers
    // According to CORS spec, Access-Control-Allow-Methods and Access-Control-Allow-Headers
    // should ONLY be set in preflight responses, not in actual request responses
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        // Handle requested headers from preflight request
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
            $requested_headers = sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']));
            header('Access-Control-Allow-Headers: ' . $requested_headers);
        } else {
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-GeoGuru-Token, X-Requested-With');
        }
    }
    
    return true;
}

/**
 * Register REST API routes
 */
function geoguru_register_rest_routes() {
    $logger = GeoGuru_Logger::get_instance();
    $logger->info('Registering REST API routes');

    // Register with and without trailing slash so either URL is a first-class REST match (no 301 hop).
    $settings_routes = array('/settings', '/settings/');
    foreach ($settings_routes as $settings_path) {
        register_rest_route('geoguru/v1', $settings_path, array(
            'methods' => 'GET',
            'callback' => 'geoguru_rest_get_settings',
            'permission_callback' => 'geoguru_rest_permission_callback',
        ));
        register_rest_route('geoguru/v1', $settings_path, array(
            'methods' => 'POST',
            'callback' => 'geoguru_rest_update_settings',
            'permission_callback' => 'geoguru_rest_permission_callback',
        ));
    }
    
    // POST /wp-json/geoguru/v1/settings/reset
    register_rest_route('geoguru/v1', '/settings/reset', array(
        'methods' => 'POST',
        'callback' => 'geoguru_rest_reset_settings',
        'permission_callback' => 'geoguru_rest_permission_callback',
    ));
    
    // POST /wp-json/geoguru/v1/token/refresh
    register_rest_route('geoguru/v1', '/token/refresh', array(
        'methods' => 'POST',
        'callback' => 'geoguru_rest_refresh_token',
        'permission_callback' => 'geoguru_rest_permission_callback',
    ));

    // POST /wp-json/geoguru/v1/llm-source-event (proxy for client-side LLM tracking; no auth required)
    register_rest_route('geoguru/v1', '/llm-source-event', array(
        'methods' => 'POST',
        'callback' => 'geoguru_rest_llm_source_event',
        'permission_callback' => '__return_true',
    ));

    // GET /wp-json/geoguru/v1/reachability — public probe for backend services (WAF / connectivity checks)
    $reachability_routes = array('/reachability', '/reachability/');
    foreach ($reachability_routes as $reachability_path) {
        register_rest_route('geoguru/v1', $reachability_path, array(
            'methods' => 'GET',
            'callback' => 'geoguru_rest_reachability',
            'permission_callback' => '__return_true',
        ));
    }

    if (function_exists('geoguru_register_overlay_rest_routes')) {
        geoguru_register_overlay_rest_routes();
    }
}

/**
 * Sanitize LLM link appearance settings
 * 
 * Merges new settings with existing settings, preserving existing fields
 * and sanitizing/validating new appearance fields.
 * 
 * @param array $settings Settings array to sanitize
 * @return array Sanitized settings array
 */
function geoguru_sanitize_llm_link_settings($settings) {
    $sanitized = array();
    
    // Preserve existing fields
    if (isset($settings['show_on_posts'])) {
        $sanitized['show_on_posts'] = (int)$settings['show_on_posts'];
    }
    if (isset($settings['show_on_pages'])) {
        $sanitized['show_on_pages'] = (int)$settings['show_on_pages'];
    }
    if (isset($settings['link_text'])) {
        $sanitized['link_text'] = sanitize_text_field($settings['link_text']);
    }
    if (isset($settings['link_position'])) {
        $valid_positions = array('left', 'right', 'center', 'css_selector');
        $position = sanitize_text_field($settings['link_position']);
        $sanitized['link_position'] = in_array($position, $valid_positions) ? $position : 'center';
    }
    
    // New appearance fields
    if (isset($settings['font_background_color'])) {
        $color = sanitize_text_field($settings['font_background_color']);
        $sanitized['font_background_color'] = GeoGuru_Utils::validate_hex_color($color) ? $color : '';
    }
    if (isset($settings['font_color'])) {
        $color = sanitize_text_field($settings['font_color']);
        $sanitized['font_color'] = GeoGuru_Utils::validate_hex_color($color) ? $color : '';
    }
    if (isset($settings['font_size'])) {
        $valid_sizes = array('small', 'medium', 'large');
        $size = sanitize_text_field($settings['font_size']);
        $sanitized['font_size'] = in_array($size, $valid_sizes) ? $size : 'medium';
    }
    if (isset($settings['css_selector'])) {
        $sanitized['css_selector'] = sanitize_text_field($settings['css_selector']);
    }
    if (isset($settings['offset_x'])) {
        $sanitized['offset_x'] = (int)$settings['offset_x'];
    }
    if (isset($settings['offset_y'])) {
        $sanitized['offset_y'] = (int)$settings['offset_y'];
    }
    
    return $sanitized;
}

/**
 * REST API callback: Get settings
 * 
 * @param WP_REST_Request $request The REST API request object
 * @return WP_REST_Response|WP_Error
 */
function geoguru_rest_get_settings($request) {
    $logger = GeoGuru_Logger::get_instance();
    $logger->info('REST API: get settings request');

    if (class_exists('GeoGuru_IndexNowService')) {
        $ensured = GeoGuru_IndexNowService::get_instance()->ensure_key_if_enabled();
        if (is_wp_error($ensured)) {
            $logger->warning(
                'REST API: IndexNow key could not be ensured while loading settings',
                array('code' => $ensured->get_error_code())
            );
        }
    }

    // Permission callback already verified token and set user context
    $settings = array(
        'site_id' => sanitize_text_field(get_option('geoguru_site_id', '')),
        'secret_token' => sanitize_text_field(get_option('geoguru_secret_token', '')),
        'plugin_active' => (bool)get_option('geoguru_plugin_active', 1),
        'optimization_method' => sanitize_text_field(get_option('geoguru_optimization_method', GeoGuru_OptimizationMethod::LLM_LINK_GENERATOR)),
        'llm_tracking_enabled' => (bool)get_option('geoguru_llm_tracking_enabled', 1),
        'portal_url' => esc_url_raw(get_option('geoguru_portal_url')),
        'show_logs_menu' => (bool)get_option('geoguru_show_logs_menu', 0),
        'log_level' => sanitize_text_field(get_option('geoguru_log_level', GeoGuru_Logger::ERROR)),
        'llms_txt_enabled' => (bool)get_option('geoguru_llms_txt_enabled', 1),
        'indexnow_enabled' => (bool)get_option('geoguru_indexnow_enabled', 1),
        'indexnow_key' => sanitize_text_field(get_option('geoguru_indexnow_key', '')),
        'automatic_optimization_enabled' => (bool)get_option('geoguru_automatic_optimization_enabled', 1),
        'llm_version_settings' => get_option('geoguru_llm_version_settings', array()),
        'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
        'site_url' => esc_url_raw(get_site_url())
    );
    
    return new WP_REST_Response($settings, 200);
}

/**
 * REST API callback: Update settings
 * 
 * @param WP_REST_Request $request The REST API request object
 * @return WP_REST_Response|WP_Error
 */
function geoguru_rest_update_settings($request) {
    $logger = GeoGuru_Logger::get_instance();
    $logger->info('REST API: update settings request');
    
    // Permission callback already verified token and set user context
    $original_user_id = get_current_user_id();
    
    // Get JSON body parameters
    $json_params = $request->get_json_params();
    
    $site_id = isset($json_params['site_id']) ? sanitize_text_field($json_params['site_id']) : '';
    $secret_token = isset($json_params['secret_token']) ? sanitize_text_field($json_params['secret_token']) : '';
    $optimization_method = isset($json_params['optimization_method']) ? sanitize_text_field($json_params['optimization_method']) : '';
    // Properly convert string boolean values: "true"/"1" -> 1, "false"/"0"/empty -> 0
    // Store as integers (0/1) instead of booleans because WordPress deletes options when value is false
    $llm_tracking_enabled = isset($json_params['llm_tracking_enabled']) ? (filter_var($json_params['llm_tracking_enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : null;
    $show_logs_menu = isset($json_params['show_logs_menu']) ? (filter_var($json_params['show_logs_menu'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : null;
    $log_level = isset($json_params['log_level']) ? sanitize_text_field($json_params['log_level']) : '';
    $automatic_optimization_enabled = isset($json_params['automatic_optimization_enabled']) ? (filter_var($json_params['automatic_optimization_enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : null;
    $llms_txt_enabled = isset($json_params['llms_txt_enabled']) ? filter_var($json_params['llms_txt_enabled'], FILTER_VALIDATE_BOOLEAN) : null;
    $indexnow_enabled = isset($json_params['indexnow_enabled']) ? (filter_var($json_params['indexnow_enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : null;
    $indexnow_regenerate = ! empty($json_params['indexnow_regenerate']) && filter_var($json_params['indexnow_regenerate'], FILTER_VALIDATE_BOOLEAN);
    $llm_version_settings = isset($json_params['llm_version_settings']) ? $json_params['llm_version_settings'] : null;
    // White-label / brand (plugin branding from DB)
    $white_label_enabled = isset($json_params['white_label_enabled']) ? (filter_var($json_params['white_label_enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : null;
    $brand_display_name = isset($json_params['brand_display_name']) ? sanitize_text_field($json_params['brand_display_name']) : null;
    $brand_plugin_name = isset($json_params['brand_plugin_name']) ? sanitize_text_field($json_params['brand_plugin_name']) : null;
    $brand_plugin_author = isset($json_params['brand_plugin_author']) ? sanitize_text_field($json_params['brand_plugin_author']) : null;
    $brand_logo = isset($json_params['brand_logo']) ? esc_url_raw($json_params['brand_logo']) : null;
    $brand_details_url = isset($json_params['brand_details_url']) ? esc_url_raw($json_params['brand_details_url']) : null;

    $has_brand = $white_label_enabled !== null || $brand_display_name !== null || $brand_plugin_name !== null || $brand_plugin_author !== null || $brand_logo !== null || $brand_details_url !== null;
    $has_other = $site_id !== '' || $secret_token !== '' || $optimization_method !== '' || $llm_tracking_enabled !== null || $show_logs_menu !== null || $log_level !== '' || $automatic_optimization_enabled !== null || $llms_txt_enabled !== null || $indexnow_enabled !== null || $indexnow_regenerate || $llm_version_settings !== null;
    if (!$has_brand && !$has_other) {
        return new WP_Error('no_settings', 'At least one setting must be provided', array('status' => 400));
    }
    if (!empty($optimization_method)) {
        if (!in_array($optimization_method, array(GeoGuru_OptimizationMethod::LLM_LINK_GENERATOR, GeoGuru_OptimizationMethod::REQUESTS_INTERCEPTOR))) {
            return new WP_Error('invalid_optimization_method', 'Invalid optimization method', array('status' => 400));
        }
    }
    
    // Validate log level if provided
    if (!empty($log_level)) {
        $valid_log_levels = array(
            GeoGuru_Logger::EMERGENCY,
            GeoGuru_Logger::ALERT,
            GeoGuru_Logger::CRITICAL,
            GeoGuru_Logger::ERROR,
            GeoGuru_Logger::WARNING,
            GeoGuru_Logger::NOTICE,
            GeoGuru_Logger::INFO,
            GeoGuru_Logger::DEBUG
        );
        if (!in_array($log_level, $valid_log_levels)) {
            return new WP_Error('invalid_log_level', 'Invalid log level', array('status' => 400));
        }
    }

    if (!empty($site_id)) {
        update_option('geoguru_site_id', $site_id);
    }
    if (!empty($secret_token)) {
        update_option('geoguru_secret_token', $secret_token);
    }
    if (!empty($optimization_method)) {
        update_option('geoguru_optimization_method', $optimization_method);
    }
    if ($llm_tracking_enabled !== null) {
        update_option('geoguru_llm_tracking_enabled', (int)$llm_tracking_enabled);
    }
    if ($llms_txt_enabled !== null) {
        $logger->info('Updating llms.txt enabled setting to: ' . (int)$llms_txt_enabled);
        update_option('geoguru_llms_txt_enabled', (int)$llms_txt_enabled);
        
        // Clear llms.txt cache when feature is toggled
        if (class_exists('GeoGuru_LlmsTxtService')) {
            $llms_txt_service = GeoGuru_LlmsTxtService::get_instance();
            if ($llms_txt_enabled === true) {
                // Register rewrite rules and flush them
                $llms_txt_service->register_llms_txt_endpoint();
                flush_rewrite_rules();
                $logger->debug('llms.txt service initialized and rewrite rules flushed');
            }
            else {
                $llms_txt_service->clear_cache();
                flush_rewrite_rules();
                $logger->debug('llms.txt cache cleared and rewrite rules flushed');
            }
        }
    }
    if ($indexnow_regenerate && class_exists('GeoGuru_IndexNowService')) {
        $indexnow_svc = GeoGuru_IndexNowService::get_instance();
        $old_key = $indexnow_svc->get_stored_key();
        if ($old_key !== '') {
            $indexnow_svc->delete_physical_key_file($old_key);
        }
        delete_option(GeoGuru_IndexNowService::OPTION_KEY);
        $gen = $indexnow_svc->generate_and_store_key();
        if (is_wp_error($gen)) {
            wp_set_current_user($original_user_id);
            return $gen;
        }
        update_option(GeoGuru_IndexNowService::OPTION_ENABLED, 1);
        $logger->info('IndexNow key regenerated via REST');
    } elseif ($indexnow_enabled !== null) {
        update_option('geoguru_indexnow_enabled', (int) $indexnow_enabled);
        if ((int) $indexnow_enabled === 1 && class_exists('GeoGuru_IndexNowService')) {
            $indexnow_svc = GeoGuru_IndexNowService::get_instance();
            if ($indexnow_svc->get_stored_key() === '') {
                $gen = $indexnow_svc->generate_and_store_key();
                if (is_wp_error($gen)) {
                    wp_set_current_user($original_user_id);
                    return $gen;
                }
            } else {
                $indexnow_svc->try_write_physical_key_file();
                $indexnow_svc->register_key_endpoint();
                flush_rewrite_rules(false);
            }
        }
        $logger->info('IndexNow enabled option updated via REST');
    }
    if ($show_logs_menu !== null) {
        update_option('geoguru_show_logs_menu', $show_logs_menu);
    }
    if (!empty($log_level)) {
        update_option('geoguru_log_level', $log_level);
    }
    if ($automatic_optimization_enabled !== null) {
        update_option('geoguru_automatic_optimization_enabled', $automatic_optimization_enabled);
    }

    // Handle LLM version settings
    if ($llm_version_settings !== null) {
        // Parse JSON if sent as string
        if (is_string($llm_version_settings)) {
            $llm_settings = json_decode(stripslashes($llm_version_settings), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $logger->error('Failed to parse llm_version_settings JSON', array('error' => json_last_error_msg()));
                return new WP_Error('invalid_json', 'Invalid JSON format for llm_version_settings', array('status' => 400));
            }
        } else {
            $llm_settings = $llm_version_settings;
        }
        
        // Get existing settings
        $existing_settings = get_option('geoguru_llm_version_settings', array());
        
        // Merge with new settings (preserve existing fields)
        $updated_settings = array_merge($existing_settings, $llm_settings);
        
        // Validate and sanitize
        $updated_settings = geoguru_sanitize_llm_link_settings($updated_settings);
        
        // Update option
        update_option('geoguru_llm_version_settings', $updated_settings);
        $logger->info('Updated llm_version_settings', array('settings' => $updated_settings));
    }

    // White-label / brand options (plugin branding from DB)
    if ($white_label_enabled !== null) {
        update_option('geoguru_white_label_enabled', (int) $white_label_enabled);
    }
    if ($brand_display_name !== null) {
        update_option('geoguru_brand_display_name', $brand_display_name);
    }
    if ($brand_plugin_name !== null) {
        update_option('geoguru_brand_plugin_name', $brand_plugin_name);
    }
    if ($brand_plugin_author !== null) {
        update_option('geoguru_brand_plugin_author', $brand_plugin_author);
    }
    if ($brand_logo !== null) {
        update_option('geoguru_brand_logo', $brand_logo);
    }
    if ($brand_details_url !== null) {
        update_option('geoguru_brand_details_url', $brand_details_url);
    }

    // Restore original user
    wp_set_current_user($original_user_id);
    
    return new WP_REST_Response(array('message' => 'Settings updated successfully'), 200);
}

/**
 * REST API callback: Reset settings
 * 
 * @param WP_REST_Request $request The REST API request object
 * @return WP_REST_Response|WP_Error
 */
function geoguru_rest_reset_settings($request) {
    $logger = GeoGuru_Logger::get_instance();
    $logger->info('REST API: reset settings request');
    
    // Permission callback already verified token and set user context
    $original_user_id = get_current_user_id();

    delete_option('geoguru_site_id');
    delete_option('geoguru_secret_token');
    delete_option('geoguru_llm_tracking_enabled');
    delete_option('geoguru_llms_txt_enabled');
    delete_option('geoguru_llms_txt_config');
    delete_option('geoguru_llm_version_settings');
    delete_option('geoguru_apply_non_visible_overlay_on_original_page');

    // Restore original user
    wp_set_current_user($original_user_id);
    
    return new WP_REST_Response(array('message' => 'Settings reset successfully'), 200);
}

/**
 * REST API callback: Refresh token
 * 
 * @param WP_REST_Request $request The REST API request object
 * @return WP_REST_Response|WP_Error
 */
function geoguru_rest_refresh_token($request) {
    $logger = GeoGuru_Logger::get_instance();
    $logger->info('REST API: refresh token request');
    
    // Permission callback already verified token and set user context
    // Get current user ID from context
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('no_user', 'User not found', array('status' => 401));
    }
    
    // Create new token for the same user
    $new_token = geoguru_create_secure_nonce('geoguru_rest_nonce');
    
    if (!$new_token) {
        $logger->error('Failed to create new token');
        return new WP_Error('token_creation_failed', 'Failed to create new token', array('status' => 500));
    }
    
    return new WP_REST_Response(array('token' => $new_token), 200);
}

/**
 * REST API callback: Proxy for client-side LLM source event tracking.
 * Accepts payload from browser and calls Supabase edge function with server-held credentials.
 *
 * @param WP_REST_Request $request The REST API request object
 * @return WP_REST_Response|WP_Error
 */
function geoguru_rest_llm_source_event($request) {
    $logger = GeoGuru_Logger::get_instance();
    $json_params = $request->get_json_params();
    // sendBeacon() cannot set Content-Type; get_json_params() only parses when that header is set.
    if (!is_array($json_params)) {
        $raw = $request->get_body();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $json_params = $decoded;
            }
        }
    }
    if (!is_array($json_params)) {
        return new WP_Error('invalid_body', 'Invalid JSON body', array('status' => 400));
    }

    $url = isset($json_params['url']) ? esc_url_raw($json_params['url']) : '';
    $referer = isset($json_params['referer']) ? esc_url_raw($json_params['referer']) : '';
    $utm_source = isset($json_params['utm_source']) ? sanitize_text_field($json_params['utm_source']) : '';
    $user_agent = isset($json_params['user_agent']) ? sanitize_text_field($json_params['user_agent']) : '';

    $site_id = get_option('geoguru_site_id', '');
    $secret_token = get_option('geoguru_secret_token', '');
    if (empty($site_id) || empty($secret_token)) {
        $logger->warning('LLM source event proxy: site credentials not configured');
        return new WP_Error('not_configured', 'Tracking not configured', array('status' => 503));
    }

    // Validate url is present and same-origin to reduce abuse (do not skip when url is missing or malformed)
    if (empty($url)) {
        return new WP_Error('invalid_body', 'URL is required', array('status' => 400));
    }
    $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
    $url_host = wp_parse_url($url, PHP_URL_HOST);
    if (empty($home_host)) {
        // Cannot validate origin; allow (unusual server config)
    } elseif (empty($url_host) || !is_string($url_host)) {
        $logger->debug('LLM source event proxy: url could not be parsed', array('url' => $url));
        return new WP_Error('invalid_origin', 'Invalid URL', array('status' => 400));
    } elseif (strtolower($url_host) !== strtolower($home_host)) {
        $logger->debug('LLM source event proxy: url host mismatch', array('url_host' => $url_host, 'home_host' => $home_host));
        return new WP_Error('invalid_origin', 'URL must be from this site', array('status' => 400));
    }

    $supabase_service = GeoGuru_SupabaseService::get_instance();
    $result = $supabase_service->create_llm_source_event_via_edge($site_id, $secret_token, $url, $user_agent, $referer, $utm_source);

    if ($result === false) {
        return new WP_Error('tracking_failed', 'Failed to record event', array('status' => 502));
    }

    return new WP_REST_Response(array('success' => true), 201);
}

/**
 * REST API callback: Public reachability probe for LovedByAI backend services.
 * No auth — used to verify outbound traffic from our services reaches this WordPress site.
 *
 * @param WP_REST_Request $request The REST API request object
 * @return WP_REST_Response
 */
function geoguru_rest_reachability($_request) {
    $plugin_version = defined('GEOGURU_PLUGIN_VERSION') ? GEOGURU_PLUGIN_VERSION : 'unknown';
    $body = array(
        'ok' => true,
        'plugin' => 'geoguru',
        'plugin_version' => $plugin_version,
        'server_time' => gmdate('c'),
        'site_url' => home_url(),
    );
    $response = new WP_REST_Response($body, 200);
    $response->set_headers(array(
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
    ));
    return $response;
}

function geoguru_plugin_activated() {
    // Track install (first activation) and activation
    $mixpanel = GeoGuru_MixpanelService::get_instance();
    $logger = GeoGuru_Logger::get_instance();
    $logger->debug('Plugin activation started');

    // Mark plugin as active
    update_option('geoguru_plugin_active', true);

    geoguru_insert_plugin_default_config();

    // Ensure log directory security files are created/updated
    $logger = GeoGuru_Logger::get_instance();
    $logger->ensure_security_files();

    $secret_token = get_option('geoguru_secret_token', '');
    $site_id = get_option('geoguru_site_id', '');
    
    // Get Supabase service instance
    $supabase_service = GeoGuru_SupabaseService::get_instance();

    $logger->debug('Checking if credentials exist');
    if (!empty($secret_token) && !empty($site_id)) {
        // Credentials exist locally, verify they still exist in Supabase
        $logger->info('Found existing credentials, verifying with Supabase', [
            'site_id' => $site_id
        ]);
        
        if ($supabase_service->verify_website_credentials($site_id, $secret_token)) {
            $logger->info('Existing credentials verified successfully, no registration needed');

            // Reconcile local options to the backend's source of truth, so a value set
            // server-side applies to this site even if an earlier sync never reached it.
            geoguru_reconcile_synced_settings();

            $backend_status_set_active_ok = $supabase_service->set_website_status_via_edge($site_id, $secret_token, 'active');
            if (!$backend_status_set_active_ok) {
                $logger->error(
                    'Failed to set website status to active in Supabase; local plugin is active but backend status may be stale',
                    array('site_id_prefix' => substr($site_id, 0, 8) . '...')
                );
            }
            $mixpanel->track('geoguru_plugin_activated', [
                'is_first_activation' => false,
                'did_create_website_id' => false,
                'backend_status_set_active_ok' => $backend_status_set_active_ok,
            ], [
                'timeout' => 10,
            ]);
            return;
        } else {
            $logger->warning('Existing credentials not found in Supabase, will create new registration');
            // Clear old credentials and proceed with new registration
            delete_option('geoguru_secret_token');
            delete_option('geoguru_site_id');
        }
    }

    // Deferred registration: website is created from client-side (browser) when admin first loads
    // GeoGuru page, so the affiliation cookie can be sent. Do not create website or start
    // interceptor here; plugins_loaded will start the interceptor once credentials exist.
    $mixpanel->track('geoguru_plugin_activated', [
        'optimization_method' => get_option('geoguru_optimization_method', GeoGuru_OptimizationMethod::LLM_LINK_GENERATOR),
        'is_first_activation' => true,
        'did_create_website_id' => false,
        'registration_deferred' => true,
    ], [
        'timeout' => 10
    ]);

    // Register llms.txt rewrite rules and flush them
    if (class_exists('GeoGuru_LlmsTxtService')) {
        $llms_txt_service = GeoGuru_LlmsTxtService::get_instance();
        // Register rewrite rules (hooks are registered on every request via init hook)
        $llms_txt_service->register_llms_txt_endpoint();
        flush_rewrite_rules();
        $logger->debug('llms.txt rewrite rules registered and flushed', ['wp_hook' => current_action()]);
    }
    $logger->debug('Plugin activation completed');
}

// Deactivation cleanup
function geoguru_plugin_deactivate() {
    $logger = GeoGuru_Logger::get_instance();
    $logger->info('Plugin deactivation started');

    $deactivate_site_id = get_option('geoguru_site_id', '');
    $deactivate_secret = get_option('geoguru_secret_token', '');
    $deactivated_track_props = array();
    if ($deactivate_site_id !== '' && $deactivate_secret !== '' && class_exists('GeoGuru_SupabaseService')) {
        $backend_status_set_inactive_ok = GeoGuru_SupabaseService::get_instance()->set_website_status_via_edge(
            $deactivate_site_id,
            $deactivate_secret,
            'inactive'
        );
        if (!$backend_status_set_inactive_ok) {
            $logger->error(
                'Failed to set website status to inactive in Supabase; plugin is deactivated locally but backend may still show active',
                array('site_id_prefix' => substr($deactivate_site_id, 0, 8) . '...')
            );
        }
        $deactivated_track_props['backend_status_set_inactive_ok'] = $backend_status_set_inactive_ok;
    }

    // Track deactivation
    if (class_exists('GeoGuru_MixpanelService')) {
        GeoGuru_MixpanelService::get_instance()->track('geoguru_plugin_deactivated', $deactivated_track_props);
    }
    
    // Set plugin as deactivated
    update_option('geoguru_plugin_active', false);
    
    // Stop the requests interceptor
    if (class_exists('GeoGuru_RequestsInterceptor')) {
        $interceptor = GeoGuru_RequestsInterceptor::get_instance();
        $interceptor->stop_intercepting();
        $logger->info('Requests interceptor stopped');
    }
    
    // Clear llms.txt cache and flush rewrite rules
    if (class_exists('GeoGuru_LlmsTxtService')) {
        $llms_txt_service = GeoGuru_LlmsTxtService::get_instance();
        $llms_txt_service->clear_cache();
        flush_rewrite_rules();
        $logger->info('llms.txt cache cleared and rewrite rules flushed');
    }
    
    $logger->info('Plugin deactivated successfully');
}

function geoguru_plugin_uninstall() {
    if (class_exists('GeoGuru_MixpanelService')) {
        GeoGuru_MixpanelService::get_instance()->track('geoguru_plugin_uninstalled');
    }
    
    // Clean up all plugin options
    delete_option('geoguru_plugin_active');
    delete_option('geoguru_plugin_version');
    delete_option('geoguru_optimization_method');
    delete_option('geoguru_llm_tracking_enabled');
    delete_option('geoguru_llms_txt_enabled');
    delete_option('geoguru_llms_txt_config');
    delete_option('geoguru_llm_version_settings');
    delete_option('geoguru_portal_url');
    delete_option('geoguru_show_logs_menu');
    delete_option('geoguru_log_level');
    delete_option('geoguru_automatic_optimization_enabled');
    delete_option('geoguru_apply_non_visible_overlay_on_original_page');
    delete_option('geoguru_supabase_url');
    delete_option('geoguru_supabase_anon_key');
    delete_option('geoguru_fire_and_forget_worker_url');
    delete_option('geoguru_optimized_content_cdn_url');
    delete_option('geoguru_optimizer_service_url');
    delete_option('geoguru_discovery_service_url');
    delete_option('geoguru_indexnow_service_url');
    delete_option('geoguru_mixpanel_token');
    delete_option('geoguru_white_label_enabled');
    delete_option('geoguru_brand_display_name');
    delete_option('geoguru_brand_plugin_name');
    delete_option('geoguru_brand_plugin_author');
    delete_option('geoguru_brand_logo');
    delete_option('geoguru_brand_details_url');
    delete_option('geoguru_indexnow_enabled');
    delete_option('geoguru_indexnow_key');
    delete_option('geoguru_settings_last_sync');

    // Delete all iframe token options (one per token)
    global $wpdb;
    $prefix = 'geoguru_iframe_token_';
    $like = $wpdb->esc_like($prefix) . '%';
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like
    ));

    // Delete overlay payload post meta so nothing is orphaned.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
        '_geoguru_overlay_payload_v1'
    ));
}

// Add settings link to plugin actions
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) use ($lovedbyai_portal_slug) {
    $portal_link = '<a href="' . admin_url('admin.php?page=' . $lovedbyai_portal_slug) . '">Enable Optimizations</a>';
    array_unshift($links, $portal_link);
    return $links;
});

// Enqueue admin styles properly using WordPress enqueue functions
add_action('admin_enqueue_scripts', 'geoguru_enqueue_admin_styles');
function geoguru_enqueue_admin_styles($hook) {
    global $lovedbyai_portal_slug, $lovedbyai_settings_slug, $lovedbyai_log_viewer_slug;
    // Check if we're on one of our plugin's admin pages
    // Use get_current_screen() instead of $_GET to avoid nonce verification requirement
    $screen = get_current_screen();
    $current_page = $screen ? $screen->id : '';
    
    // Check more specific pages first to avoid conflicts
    // Portal settings page styles (hides WordPress admin notices)
    if ($current_page === $lovedbyai_portal_slug . '_page_' . $lovedbyai_settings_slug || strpos($hook, $lovedbyai_settings_slug) !== false) {
        // Register and enqueue a style handle
        wp_register_style('geoguru-portal-settings-styles', false, array(), GEOGURU_PLUGIN_VERSION);
        wp_enqueue_style('geoguru-portal-settings-styles');
        
        // Add inline CSS to hide WordPress admin notices
        $portal_settings_css = '
            /* Hide WordPress admin notices/messages for this page */
            .notice, .update-nag, .updated, .error, .is-dismissible { display: none !important; }
        ';
        wp_add_inline_style('geoguru-portal-settings-styles', wp_strip_all_tags($portal_settings_css));
        return; // Early return to avoid matching portal page
    }
    
    // Log viewer page styles
    if ($current_page === $lovedbyai_portal_slug . '_page_' . $lovedbyai_log_viewer_slug || strpos($hook, $lovedbyai_log_viewer_slug) !== false) {
        // Register and enqueue a style handle (using false for inline-only styles)
        wp_register_style('geoguru-log-viewer-styles', false, array(), GEOGURU_PLUGIN_VERSION);
        wp_enqueue_style('geoguru-log-viewer-styles');
        
        // Add inline CSS for log viewer
        $log_viewer_css = '
            /* Force left-to-right direction for log viewer regardless of theme */
            .geoguru-log-viewer-wrap {
                direction: ltr !important;
                text-align: left !important;
            }
            .geoguru-log-viewer-wrap * {
                direction: ltr !important;
                text-align: left !important;
            }
            .geoguru-log-viewer-wrap h1,
            .geoguru-log-viewer-wrap h2,
            .geoguru-log-viewer-wrap h3 {
                text-align: left !important;
            }
            .geoguru-log-viewer-wrap form,
            .geoguru-log-viewer-wrap button {
                direction: ltr !important;
            }
        ';
        wp_add_inline_style('geoguru-log-viewer-styles', wp_strip_all_tags($log_viewer_css));
        return; // Early return to avoid matching portal page
    }
    
    // Portal page styles (hides WordPress admin notices)
    // Check this last since portal slug can be substring of settings slug
    if ($current_page === 'toplevel_page_' . $lovedbyai_portal_slug || strpos($hook, $lovedbyai_portal_slug) !== false) {
        // Register and enqueue a style handle
        wp_register_style('geoguru-portal-styles', false, array(), GEOGURU_PLUGIN_VERSION);
        wp_enqueue_style('geoguru-portal-styles');

        // Add inline CSS to hide WordPress admin notices
        $portal_css = '
            /* Hide WordPress admin notices/messages for this page */
            .notice, .update-nag, .updated, .error, .is-dismissible { display: none !important; }
        ';
        wp_add_inline_style('geoguru-portal-styles', wp_strip_all_tags($portal_css));
    }
}

// Helper: whether plugin is in white-label mode (from DB-synced option)
function geoguru_is_white_label() {
    return (int) get_option('geoguru_white_label_enabled', 0) === 1;
}

// Helper: display name for plugin UI (menu, llms.txt, etc.). From options when white-label, else LovedByAI.
function geoguru_get_display_name() {
    if (geoguru_is_white_label()) {
        $display_name = get_option('geoguru_brand_display_name', '');
        if ($display_name !== '') {
            return $display_name;
        }
        return LOVEDBYAI_DEFAULT_WHITE_LABEL_DISPLAY_NAME;
    }
    return 'LovedByAI';
}

// Helper: plugin author for Plugins list "By Author" (white-label only)
function geoguru_get_plugin_author() {
    return get_option('geoguru_brand_plugin_author', '');
}

function geoguru_get_plugin_name() {
    if (geoguru_is_white_label()) {
        $plugin_name = get_option('geoguru_brand_plugin_name', '');
        if ($plugin_name !== '') {
            return $plugin_name;
        }
        $plugin_name = get_option('geoguru_brand_display_name', '');
        if ($plugin_name !== '') {
            return $plugin_name;
        }
        return LOVEDBYAI_DEFAULT_WHITE_LABEL_DISPLAY_NAME; // Default white-label plugin name
    }
    return '';
}

/**
 * In white-label mode, replace the plugin name and author in the Plugins list with the brand display name and author.
 * Uses the all_plugins filter; branding from options (synced from DB by portal).
 * The plugin name is completely replaced (not just substring replacement).
 */
function geoguru_filter_all_plugins_mask_brand_name($all_plugins) {
    if (!geoguru_is_white_label()) {
        return $all_plugins;
    }
    $plugin_display_name = geoguru_get_plugin_name();
    $author = geoguru_get_plugin_author();
    foreach ($all_plugins as $plugin_file => $plugin_data) {
        if (!isset($plugin_data['Name']) || !is_string($plugin_data['Name'])) {
            continue;
        }
        // Only mask plugins that contain LovedByAI
        if (stripos($plugin_data['Name'], 'LovedByAI') !== false) {
            $plugin_data['Name'] = $plugin_display_name;
            $plugin_data['Author'] = $author;
            $all_plugins[$plugin_file] = $plugin_data;
        }
    }
    return $all_plugins;
}

/**
 * In white-label mode, remove or replace the "View details" link in the Plugins list for this plugin.
 * URL from option geoguru_brand_details_url when set; otherwise the link is removed.
 */
function geoguru_filter_plugin_row_meta($plugin_meta, $plugin_file, $plugin_data, $status) {
    if ($plugin_file !== plugin_basename(__FILE__)) {
        return $plugin_meta;
    }
    if (!geoguru_is_white_label()) {
        return $plugin_meta;
    }
    $replacement_url = get_option('geoguru_brand_details_url', '');
    $out = array();
    foreach ($plugin_meta as $meta) {
        if (strpos($meta, 'open-plugin-details-modal') !== false) {
            if ($replacement_url !== '') {
                $out[] = '<a href="' . esc_url($replacement_url) . '" class="open-plugin-details-modal" target="_blank" rel="noopener noreferrer">' . esc_html__('View details', 'lovedbyai-seo-for-llms-and-ai-search') . '</a>';
            }
            continue;
        }
        $out[] = $meta;
    }
    return $out;
}
add_filter('plugin_row_meta', 'geoguru_filter_plugin_row_meta', 10, 4);

// Helper function to get the menu icon (LovedByAI logo or generic white-label icon)
function geoguru_get_menu_icon() {
    // White-label: neutral gear icon (#a7aaad matches WordPress admin menu)
    if (geoguru_is_white_label()) {
        $svg = 'PHN2ZyBmaWxsPSJ3aGl0ZSIgd2lkdGg9IjEyMDBwdCIgaGVpZ2h0PSIxMjAwcHQiIHZlcnNpb249IjEuMSIgdmlld0JveD0iMCAwIDEyMDAgMTIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KIDxwYXRoIGQ9Im02OTUuMTYgNTM3LjYxYy00OC40NjkgMjIuNDUzLTkzLjQ2OSA1NC0xMzMuOTIgOTMuOTM4LTM5LjkzOCAzOS45MzgtNzAuOTIyIDg1LjQ1My05My40NjkgMTMzLjkyLTIzLjA2Mi00OC40NjktNTQtOTMuOTM4LTkzLjkzOC0xMzMuOTItNDAuNDUzLTM5LjkzOC04NS45MjItNzEuMzkxLTEzMy45Mi05My45MzggNDguNDY5LTIyLjQ1MyA5My45MzgtNTMuNTMxIDEzMy45Mi05My45MzggMzkuOTM4LTM5LjkzOCA3MS4zOTEtODUuNDUzIDkzLjkzOC0xMzMuOTIgMjIuNDUzIDQ4LjQ2OSA1My41MzEgOTMuOTM4IDkzLjQ2OSAxMzMuOTIgNDAuNDUzIDM5LjkzOCA4NS40NTMgNzAuOTIyIDEzMy45MiA5My45Mzh6Ii8+CiA8cGF0aCBkPSJtOTQ4LjQ3IDc2NS4zN2MtMzEuOTIyIDE1LTYxLjkyMiAzNS41MzEtODguNDUzIDYxLjkyMnMtNDYuOTIyIDU3LTYxLjkyMiA4OC45MjJjLTE1LTMxLjkyMi0zNi02Mi4zOTEtNjIuMzkxLTg4LjkyMnMtNTYuNTMxLTQ2LjkyMi04OC40NTMtNjEuOTIyYzMxLjkyMi0xNSA2Mi4zOTEtMzUuNTMxIDg4LjkyMi02MS45MjJzNDYuOTIyLTU3IDYxLjkyMi04OC45MjJjMTUgMzEuOTIyIDM1LjUzMSA2MS45MjIgNjEuOTIyIDg4LjQ1M3M1Ni41MzEgNDcuNTMxIDg4LjQ1MyA2Mi4zOTF6Ii8+CiA8cGF0aCBkPSJtOTYwIDM3Mi42MWMtMTguNDY5IDktMzYgMjEtNTEuOTM4IDM2LjQ2OS0xNS40NjkgMTUuNDY5LTI3LjQ2OSAzMy40NjktMzYuNDY5IDUyLjQ1My05LTE4LjkzOC0yMS0zNi45MzgtMzYuNDY5LTUyLjQ1My0xNS40NjktMTUuNDY5LTMzLjQ2OS0yNy40NjktNTIuNDUzLTM2LjQ2OSAxOC45MzgtOSAzNi45MzgtMjEgNTIuNDUzLTM2LjQ2OSAxNS40NjktMTUuNDY5IDI3LjkzOC0zMy40NjkgMzYuNDY5LTUyLjQ1MyA5IDE4LjkzOCAyMSAzNi40NjkgMzYuNDY5IDUyLjQ1MyAxNS45MzggMTUuNDY5IDMzLjQ2OSAyNy40NjkgNTEuOTM4IDM2LjQ2OXoiLz4KPC9zdmc+';
        return 'data:image/svg+xml;base64,' . $svg;
    }
    // LovedByAI: branded SVG logo (20x20px display, #a7aaad)
    $svg = 'PHN2ZyB3aWR0aD0iODAyIiBoZWlnaHQ9IjgwMiIgdmlld0JveD0iMCAwIDgwMiA4MDIiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxwYXRoIGQ9Ik00MDcuNjQ1IDY4MS44MzlMMzcyLjE1NCA2NTIuMDQzQzM1NC43MzUgNjM3LjM0NSAzMzYuNTAxIDYyMi45NjkgMzE3LjI5MSA2MDcuNzA5QzI5MS43MzEgNTg3LjQ3IDI2NS4zNTcgNTY2LjU4OSAyNDAuODU2IDU0NC45ODRMMjM0Ljc1MSA1MzkuNjAzQzE2Ny41MTUgNDgwLjQxMSA3NS4zNjk4IDM5OS40NTUgODQuNjQ5NCAyODUuODlDOTEuMjQyOCAyMDUuMjU1IDE2OS4zMDUgMTI4LjIzNCAyNTEuNzY0IDEyMS4wMDVDMzEwLjYxNiAxMTUuODY1IDM2MS44OTggMTMwLjQwMiA0MDcuNDAxIDE2NS4zMzlMNDA5LjY4IDE2My40OTJDNDcwLjgxMSAxMTYuNjY4IDU0Ni42NzYgMTA3LjAzMSA2MTIuNTI5IDEzNy43OTFDNjc2LjI2NSAxNjcuNTA3IDcxNy4wNDYgMjI5LjI2OSA3MTkgMjk5LjA2Mkw2MTMuMzQzIDMwMS45NTNDNjEyLjUyOSAyNzEuNjc1IDU5NC44NjUgMjQ0LjkzIDU2Ny4zNTIgMjMyLjE2QzUzOC4yMSAyMTguNTg3IDUwMi40NzYgMjI3LjkwNCA0NzMuMzM1IDI0OS42NjlMNDAyLjE5MSAzMDkuODI0TDM2Ny4wMjYgMjcwLjc5MUMzMzQuODczIDIzNS4wNTIgMzAzLjEyNyAyMjEuMzk4IDI2MS4wNDMgMjI1LjA5M0MyMjcuMDk5IDIyOC4wNjQgMTkyLjI2IDI2Ni42OTUgMTg5Ljk4MSAyOTQuNDA0QzE4NC45MzQgMzU2LjMyNiAyNDguNzUyIDQxMi4zODUgMzA0Ljk5OSA0NjEuODU5TDMxMS4xODYgNDY3LjMyQzMzMy42NTIgNDg3LjA3OCAzNTguOTY4IDUwNy4xNTYgMzgzLjM4OCA1MjYuNTEyQzM5MC4zODggNTMyLjA1MyAzOTcuNTUxIDUzNy43NTYgNDA0LjYzMyA1NDMuMzc4QzQyMS40ODMgNTI4LjE5OCA0NDAuMDQyIDUxMS43MzQgNDYwLjU1NSA0OTMuOTg1TDUzMy4xNjQgNTcwLjUyNEM1MDQuODM2IDU5Ni44NjcgNDA3LjQ4MiA2ODIgNDA3LjQ4MiA2ODJMNDA3LjY0NSA2ODEuODM5Wk01MDMuMzcxIDM1NS40NDNDNTQ0LjMxNSA0MTYuODAzIDU0Ny4yNDYgNDQyLjU4MyA1MjAuODcyIDUxMS4yNTJDNTgzLjA2MiA0NzAuODU0IDYwOS4xOTEgNDY3Ljk2MyA2NzguNzg4IDQ5My45ODVDNjM3Ljg0NCA0MzIuNjI0IDYzNC45MTQgNDA2Ljg0NCA2NjEuMjg3IDMzOC4xNzVDNTk5LjA5OCAzNzguNTczIDU3Mi45NjggMzgxLjQ2NCA1MDMuMzcxIDM1NS40NDNaIiBmaWxsPSIjM0I4MkY2Ii8+Cjwvc3ZnPgo=';
    return 'data:image/svg+xml;base64,' . $svg;
}

// Add settings page to the WordPress admin menu
add_action('admin_menu', function() use ($lovedbyai_portal_slug, $lovedbyai_settings_slug, $lovedbyai_log_viewer_slug) {
    $display_name = geoguru_get_display_name();
    // Top-level menu (loads portal app in iframe)
    add_menu_page(
        $display_name, // Page title
        $display_name, // Menu title
        'manage_options', // Capability
        $lovedbyai_portal_slug, // Menu slug
        '', // No callback for parent, handled by first submenu
        geoguru_get_menu_icon(), // Custom SVG icon
        2 // Position (after Dashboard)
    );

    // First submenu: "Overview" (loads portal app in iframe)
    add_submenu_page(
        $lovedbyai_portal_slug, // Parent slug
        $display_name . ' Overview', // Page title
        'Overview', // Menu title
        'manage_options', // Capability
        $lovedbyai_portal_slug, // Menu slug (same as parent to make this default)
        'geoguru_render_portal_page' // Callback function
    );

    // Add Settings submenu (loads WordpressSettings page in iframe)
    add_submenu_page(
        $lovedbyai_portal_slug, // Parent slug
        $display_name . ' Settings', // Page title
        'Settings', // Menu title
        'manage_options', // Capability
        $lovedbyai_settings_slug, // Menu slug
        'geoguru_render_portal_settings_page' // Callback function
    );

    add_submenu_page(
        null,
        'Environment Diagnosis',
        __('Environment Diagnosis', 'lovedbyai-seo-for-llms-and-ai-search'),
        'manage_options',
        GEOGURU_DIAGNOSTIC_PAGE_SLUG,
        'geoguru_render_environment_diagnosis_page'
    );

    add_submenu_page(
        null,
        __('Manual website credentials', 'lovedbyai-seo-for-llms-and-ai-search'),
        null,
        'manage_options',
        GEOGURU_MANUAL_CREDENTIALS_PAGE_SLUG,
        'geoguru_render_manual_credentials_page'
    );

    $plugin_show_logs = get_option('geoguru_show_logs_menu', 0);

    if ($plugin_show_logs) {
        add_submenu_page($lovedbyai_portal_slug, $display_name . ' Log Viewer', 'Log Viewer', 'manage_options', $lovedbyai_log_viewer_slug, 'geoguru_render_log_viewer_page');
    }
});

// Render the GeoGuru log viewer page
function geoguru_render_log_viewer_page() {
    geoguru_prune_expired_iframe_tokens();

    // Validate user has proper permissions
    if (!current_user_can('manage_options')) {
        wp_die(esc_html(__('You do not have sufficient permissions to access this page.', 'lovedbyai-seo-for-llms-and-ai-search')));
    }
    
    // Handle clear log action
    if (isset($_POST['geoguru_clear_logs']) && isset($_POST['geoguru_log_nonce'])) {
        if (!wp_verify_nonce(sanitize_text_field( wp_unslash ($_POST['geoguru_log_nonce'])), 'geoguru_clear_logs_action')) {
            wp_die(esc_html(__('Security check failed.', 'lovedbyai-seo-for-llms-and-ai-search')));
        }
        
        $logger = GeoGuru_Logger::get_instance();
        $logger->clear_logs();
        
        // Redirect to avoid resubmission
        global $lovedbyai_log_viewer_slug;
        wp_safe_redirect(admin_url('admin.php?page=' . $lovedbyai_log_viewer_slug . '&cleared=1'));
        exit;
    }
    
    // Show success message if log was cleared
    if (isset($_GET['cleared']) && $_GET['cleared'] == '1') {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Log file cleared successfully.', 'lovedbyai-seo-for-llms-and-ai-search') . '</p></div>';
    }
    
    $logger = GeoGuru_Logger::get_instance();
    $file = $logger->get_log_file_path();
    $file_exists = file_exists($file);
    ?>
    <div class="wrap geoguru-log-viewer-wrap" style="direction: ltr !important; text-align: left !important;">
        <h1 style="direction: ltr !important; text-align: left !important;"><?php echo esc_html(geoguru_get_display_name() . ' Log Viewer'); ?></h1>
        
        <div style="margin-bottom: 20px; direction: ltr !important; text-align: left !important;">
            <form method="post" action="" style="display: inline-block; margin-right: 10px; direction: ltr !important;">
                <?php wp_nonce_field('geoguru_clear_logs_action', 'geoguru_log_nonce'); ?>
                <input type="hidden" name="geoguru_clear_logs" value="1">
                <button type="submit" class="button button-secondary" 
                        onclick="return confirm('<?php echo esc_js(__('Are you sure you want to clear all log files? This action cannot be undone.', 'lovedbyai-seo-for-llms-and-ai-search')); ?>');"
                        style="direction: ltr !important;">
                    <?php echo esc_html__('Clear Log File', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
                </button>
            </form>
            
            <button type="button" class="button button-primary" onclick="location.reload();" style="direction: ltr !important;">
                <?php echo esc_html__('Reload Log File', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
            </button>
        </div>
        
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 4px; direction: ltr !important; text-align: left !important;">
            <?php if ($file_exists): ?>
                <?php
                $content = file_get_contents($file);
                $lines = explode("\n", $content);
                $reversed_lines = array_reverse($lines);
                $reversed_content = implode("\n", $reversed_lines);
                ?>
                <pre style="direction: ltr !important; text-align: left !important; unicode-bidi: embed !important; white-space: pre-wrap; word-wrap: break-word; font-family: 'Courier New', monospace; font-size: 12px; line-height: 1.5; max-height: 70vh; overflow-y: auto; padding: 15px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;"><?php echo esc_html($reversed_content); ?></pre>
            <?php else: ?>
                <p style="color: #666; font-style: italic; direction: ltr !important; text-align: left !important;"><?php echo esc_html__('Log file not found.', 'lovedbyai-seo-for-llms-and-ai-search'); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// Render the GeoGuru portal app in an iframe (for the top-level menu)
function geoguru_render_portal_page() {
    geoguru_prune_expired_iframe_tokens();

    // Validate user has proper permissions
    if (!current_user_can('manage_options')) {
        wp_die(esc_html(__('You do not have sufficient permissions to access this page.', 'lovedbyai-seo-for-llms-and-ai-search')));
    }
    
    $portal_url = get_option('geoguru_portal_url');
    
    // Validate portal URL
    if (empty($portal_url) || !filter_var($portal_url, FILTER_VALIDATE_URL)) {
        echo '<div class="notice notice-error"><p>Invalid or missing portal URL configuration.</p></div>';
        return;
    }

    $token = geoguru_create_secure_nonce('geoguru_rest_nonce');
    if (!$token) {
        echo '<div class="notice notice-error"><p>Failed to create secure nonce.</p></div>';
        return;
    }
    
    $rest_url = rest_url('geoguru/v1/');
    
    // Validate and sanitize all URL parameters
    $safe_rest_url = esc_url_raw($rest_url);
    $safe_portal_url = esc_url_raw($portal_url);
    $safe_nonce = sanitize_text_field($token);
    
    // Build query parameters safely
    $params = array(
        'wp_rest_url' => $safe_rest_url,
        'wp_nonce' => $safe_nonce,
    );
    if (geoguru_is_white_label()) {
        $params['wp_white_label'] = 'true';
    }
    $query_params = http_build_query($params);

    $iframe_src = $safe_portal_url . '?' . $query_params;
    ?>
    <div>
        <div class="wrap" style="padding:0; margin:0;">
            <!-- The height below is a starting value only: portal-bridge.js measures the space
                 left beneath the frame and sets the real one, so the account row at its foot is
                 not pushed past the fold by the admin bar or by notices above it. -->
            <iframe
                id="<?php echo esc_attr(GEOGURU_PORTAL_IFRAME_ID); ?>"
                src="<?php echo esc_url($iframe_src); ?>"
                style="width:100%; height: 100vh; border:none; margin:0; padding:0; overflow:hidden;"
                sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation allow-modals"
                allow="clipboard-read; clipboard-write">
            </iframe>
        </div>
    </div>
    <?php
}

// Render the GeoGuru WordpressSettings page in an iframe (for the GeoGuru > Settings submenu)
function geoguru_render_portal_settings_page() {
    geoguru_prune_expired_iframe_tokens();

    // Validate user has proper permissions
    if (!current_user_can('manage_options')) {
        wp_die(esc_html(__('You do not have sufficient permissions to access this page.', 'lovedbyai-seo-for-llms-and-ai-search')));
    }
    
    $portal_url = get_option('geoguru_portal_url');
    
    // Validate portal URL
    if (empty($portal_url) || !filter_var($portal_url, FILTER_VALIDATE_URL)) {
        echo '<div class="notice notice-error"><p>Invalid or missing portal URL configuration.</p></div>';
        return;
    }
    
    $token = geoguru_create_secure_nonce('geoguru_rest_nonce');
    if (!$token) {
        echo '<div class="notice notice-error"><p>Failed to create secure nonce.</p></div>';
        return;
    }
    
    $rest_url = rest_url('geoguru/v1/');
    
    // Validate and sanitize all URL parameters
    $safe_rest_url = esc_url_raw($rest_url);
    $safe_portal_url = esc_url_raw($portal_url);
    $safe_nonce = sanitize_text_field($token);
    
    // Build query parameters safely
    $params = array(
        'wp_rest_url' => $safe_rest_url,
        'wp_nonce' => $safe_nonce,
    );
    if (geoguru_is_white_label()) {
        $params['wp_white_label'] = 'true';
    }
    $query_params = http_build_query($params);

    $iframe_src = $safe_portal_url . '/wordpress?' . $query_params;
    ?>
    <div class="wrap">
        <div class="wrap" style="padding:0; margin:0;">
            <!-- The height below is a starting value only: portal-bridge.js measures the space
                 left beneath the frame and sets the real one, so the account row at its foot is
                 not pushed past the fold by the admin bar or by notices above it. -->
            <iframe
                id="<?php echo esc_attr(GEOGURU_PORTAL_IFRAME_ID); ?>"
                src="<?php echo esc_url($iframe_src); ?>"
                style="width:100%; height: 100vh; border:none; margin:0; padding:0; overflow:hidden;"
                sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation allow-modals"
                allow="clipboard-read; clipboard-write">
            </iframe>
        </div>
    </div>
    <?php
}

// Auto-start interceptor on plugin load if site is already registered
add_action('plugins_loaded', function () {
    $logger = GeoGuru_Logger::get_instance();
    $logger->info('Plugins loaded event triggered');
    // Only start if not in activation/deactivation process and site is registered
    if (!did_action('activate_' . plugin_basename(__FILE__)) && 
        !did_action('deactivate_' . plugin_basename(__FILE__))) {
        
        $plugin_active = get_option('geoguru_plugin_active', true); // Default to true for backwards compatibility
        $secret_token = get_option('geoguru_secret_token', '');
        $site_id = get_option('geoguru_site_id', '');
        $optimization_method = get_option('geoguru_optimization_method');
        
        if (!$optimization_method) {
            add_option('geoguru_optimization_method', GeoGuru_OptimizationMethod::LLM_LINK_GENERATOR);
            $optimization_method = GeoGuru_OptimizationMethod::LLM_LINK_GENERATOR;
        }
        
        // Only start interceptor if plugin is active and site is properly registered
        if ($plugin_active && !empty($secret_token) && !empty($site_id)) {
            if (class_exists('GeoGuru_RequestsInterceptor')) {
                $interceptor = GeoGuru_RequestsInterceptor::get_instance();
                if (!$interceptor->is_intercepting()) {
                    $interceptor->start_intercepting();
                }
            }
            
        }
    }
});

// Initialize discovery trigger service (independent of llms.txt feature)
add_action('init', function() {
    if (class_exists('GeoGuru_DiscoveryTriggerService')) {
        $discovery_trigger_service = GeoGuru_DiscoveryTriggerService::get_instance();
        $discovery_trigger_service->init();
    }
}, 20); // Priority 20 to ensure it runs after other init hooks

// IndexNow (key file + machine-readable config for platform service)
add_action('init', function() {
    if (class_exists('GeoGuru_IndexNowService')) {
        GeoGuru_IndexNowService::get_instance()->init();
    }
}, 20);

add_action('init', function() {
    if (class_exists('GeoGuru_SettingsSyncService')) {
        GeoGuru_SettingsSyncService::get_instance()->init();
    }
}, 20);

// Ensure security files are updated and default options are set when plugin is updated
add_action('upgrader_process_complete', function($upgrader_object, $options) {
    if ($options['action'] === 'update' && $options['type'] === 'plugin') {
        if (isset($options['plugins']) && in_array(plugin_basename(__FILE__), $options['plugins'])) {
            $logger = GeoGuru_Logger::get_instance();
            $logger->ensure_security_files();
            // Trigger migration check as backup (migrations also run on admin_init)
            if (class_exists('GeoGuru_MigrationManager')) {
                GeoGuru_MigrationManager::get_instance()->check_and_run_migrations();
            }
            // Ensure all default options are set during update
            geoguru_insert_plugin_default_config();
            // Converge to the backend's source of truth on update.
            geoguru_reconcile_synced_settings();
            $logger->info('Default plugin options initialized during update');
        }
    }
}, 10, 2);

// Check plugin version and run migrations on admin pages
// This ensures updates run even if upgrader_process_complete doesn't fire
// (e.g., manual updates, WP-CLI updates, etc.)
add_action('admin_init', function() {
    if (class_exists('GeoGuru_MigrationManager')) {
        // Reconcile settings only when a version change was detected, so this stays a
        // once-per-update event rather than an every-request backend call.
        $version_changed = GeoGuru_MigrationManager::get_instance()->check_and_run_migrations();
        if ($version_changed) {
            geoguru_reconcile_synced_settings();
        }
    }
}, 1);

// Additional protection: Block direct access to log files and directory via WordPress
// This works as a fallback for Nginx servers where .htaccess doesn't work
// Use parse_request which fires very early in the request lifecycle
add_action('parse_request', function($wp) {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    $logger = GeoGuru_Logger::get_instance();
    $logger->debug('parse_request hook of security files protection triggered', [
        'request_uri' => $request_uri,
        'wp_hook' => current_action()
    ]); 

    // Get uploads directory URL for matching (logs are now in uploads/geoguru/)
    $upload_dir = wp_upload_dir();
    if ($upload_dir['error']) {
        return; // Cannot protect if uploads directory is not available
    }
    
    $uploads_url = wp_parse_url($upload_dir['baseurl'], PHP_URL_PATH);
    $logs_path = rtrim($uploads_url, '/') . '/lovedbyai';
    
    // Check if request is trying to access plugin logs directory or files
    if (strpos($request_uri, $logs_path) !== false) {
        $logger->debug('Direct access to log directory detected', [
            'request_uri' => $request_uri,
            'logs_path' => $logs_path,
            'wp_hook' => current_action()
        ]);
        
        // Block access to:
        // 1. Directory itself (ends with /lovedbyai/ or /lovedbyai)
        // 2. Log files (.log or .log.N)
        // 3. Any file in the directory (but not index.php which is allowed)
        $is_directory = preg_match('#' . preg_quote($logs_path, '#') . '/?$#', $request_uri);
        $is_log_file = preg_match('/\.log($|\.\d+$)/', $request_uri);
        $is_in_directory = preg_match('#' . preg_quote($logs_path, '#') . '/#', $request_uri) && 
                          !preg_match('#' . preg_quote($logs_path, '#') . '/index\.php$#', $request_uri);
        
        if ($is_directory || $is_log_file || $is_in_directory) {
            $logger->debug('Blocking access to log directory or file', [
                'request_uri' => $request_uri,
                'is_directory' => $is_directory,
                'is_log_file' => $is_log_file,
                'is_in_directory' => $is_in_directory,
                'wp_hook' => current_action()
            ]);
            
            // Block access - send 403 Forbidden
            status_header(403);
            nocache_headers();
            die('Access Denied');
        }
    }
}, 1);

// Fallback: Also use template_redirect as secondary protection
add_action('template_redirect', function() {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    
    // Get uploads directory URL for matching (logs are now in uploads/geoguru/)
    $upload_dir = wp_upload_dir();
    if ($upload_dir['error']) {
        return; // Cannot protect if uploads directory is not available
    }
    
    $uploads_url = wp_parse_url($upload_dir['baseurl'], PHP_URL_PATH);
    $logs_path = rtrim($uploads_url, '/') . '/lovedbyai';
    
    // Check if request is trying to access plugin logs directory or files
    if (strpos($request_uri, $logs_path) !== false) {
        $logger = GeoGuru_Logger::get_instance();
        $logger->debug('template_redirect hook blocking access', [
            'request_uri' => $request_uri,
            'logs_path' => $logs_path,
            'wp_hook' => current_action()
        ]);
        
        $is_directory = preg_match('#' . preg_quote($logs_path, '#') . '/?$#', $request_uri);
        $is_log_file = preg_match('/\.log($|\.\d+$)/', $request_uri);
        $is_in_directory = preg_match('#' . preg_quote($logs_path, '#') . '/#', $request_uri) && 
                          !preg_match('#' . preg_quote($logs_path, '#') . '/index\.php$#', $request_uri);
        
        if ($is_directory || $is_log_file || $is_in_directory) {
            status_header(403);
            nocache_headers();
            die('Access Denied');
        }
    }
}, 1);