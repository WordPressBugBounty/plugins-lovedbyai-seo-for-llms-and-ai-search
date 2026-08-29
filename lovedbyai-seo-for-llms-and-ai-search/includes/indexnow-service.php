<?php

defined('ABSPATH') || exit;

/**
 * GeoGuru IndexNow (LovedByAI)
 *
 * Hosts the IndexNow key file, feature flag, and server-to-server config endpoint.
 * Submissions are performed by the platform IndexNow service (Cloud Run), not by WordPress.
 */
class GeoGuru_IndexNowService {

    const OPTION_ENABLED = 'geoguru_indexnow_enabled';
    const OPTION_KEY     = 'geoguru_indexnow_key';

    /** @var string Query var when virtual key route matches */
    const QUERY_VAR = 'geoguru_indexnow_txt';

    /** IndexNow key charset (see protocol docs). */
    const KEY_CHARSET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-';

    /** @var GeoGuru_IndexNowService|null */
    private static $instance = null;

    /** @var GeoGuru_Logger */
    private $logger;

    private function __construct() {
        $this->logger = GeoGuru_Logger::get_instance();
    }

    /**
     * Non-secret context for log lines (never include full keys or bearer tokens).
     *
     * @return array
     */
    private function base_log_context() {
        $site_id = get_option('geoguru_site_id', '');
        return array(
            'component'      => 'indexnow',
            'site_id_prefix' => is_string($site_id) && $site_id !== '' ? substr($site_id, 0, 8) : '',
        );
    }

    /**
     * @return GeoGuru_IndexNowService
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Plugin active + IndexNow enabled.
     *
     * @return bool
     */
    public function is_feature_enabled() {
        $plugin_active = (bool) get_option('geoguru_plugin_active', 1);
        $enabled       = (bool) get_option(self::OPTION_ENABLED, 1);
        return $plugin_active && $enabled;
    }

    /**
     * Register hooks that run on every request.
     */
    public function init() {
        $this->logger->debug('IndexNow service registering WordPress hooks', $this->base_log_context());
        add_rewrite_tag('%' . self::QUERY_VAR . '%', '([^&]+)');
        $this->register_key_endpoint();
        add_filter('redirect_canonical', array($this, 'prevent_key_file_redirect'), 10, 2);
        add_action('template_redirect', array($this, 'handle_public_key_request'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    /**
     * Register rewrite rule for /{key}.txt (current key only).
     */
    public function register_key_endpoint() {
        $key = $this->get_stored_key();
        if ($key === '' || ! $this->is_valid_key_format($key)) {
            return;
        }
        $escaped = preg_quote($key, '/');
        add_rewrite_rule('^' . $escaped . '\.txt$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
        $this->logger->debug(
            'IndexNow rewrite rule registered for key file',
            array_merge($this->base_log_context(), array('key_length' => strlen($key)))
        );
    }

    /**
     * @param string $key Optional; if empty, reads from options.
     * @return bool
     */
    public function try_write_physical_key_file($key = '') {
        if ($key === '') {
            $key = $this->get_stored_key();
        }
        if ($key === '' || ! $this->is_valid_key_format($key)) {
            return false;
        }
        if (! is_string(ABSPATH) || ABSPATH === '') {
            return false;
        }
        // Key is restricted to [a-zA-Z0-9-]; no path segments.
        $path = ABSPATH . $key . '.txt';

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $written = false;
        if ($wp_filesystem instanceof WP_Filesystem_Base) {
            $written = $wp_filesystem->put_contents($path, $key, FS_CHMOD_FILE) !== false;
        }
        if (! $written) {
            $written = @file_put_contents($path, $key, LOCK_EX) !== false;
        }

        if ($written) {
            $this->logger->info(
                'IndexNow physical key file written',
                array_merge($this->base_log_context(), array('key_length' => strlen($key)))
            );
        } else {
            $this->logger->warning(
                'IndexNow physical key file write failed; virtual route may still serve the key',
                array_merge($this->base_log_context(), array('key_length' => strlen($key)))
            );
        }
        return $written;
    }

    /**
     * Delete physical key file if present (best effort).
     *
     * @param string $key
     */
    public function delete_physical_key_file($key) {
        if ($key === '' || ! $this->is_valid_key_format($key)) {
            return;
        }
        $path = ABSPATH . $key . '.txt';
        if (file_exists($path)) {
            wp_delete_file($path);
            if (file_exists($path)) {
                $this->logger->warning(
                    'IndexNow physical key file could not be removed (check permissions)',
                    array_merge($this->base_log_context(), array('key_length' => strlen($key)))
                );
            } else {
                $this->logger->info(
                    'IndexNow physical key file removed',
                    array_merge($this->base_log_context(), array('key_length' => strlen($key)))
                );
            }
        }
    }

    /**
     * @return string
     */
    public function get_stored_key() {
        $key = get_option(self::OPTION_KEY, '');
        return is_string($key) ? $key : '';
    }

    /**
     * @param string $key
     * @return bool
     */
    public function is_valid_key_format($key) {
        if (! is_string($key)) {
            return false;
        }
        $len = strlen($key);
        if ($len < 8 || $len > 128) {
            return false;
        }
        return (bool) preg_match('/^[a-zA-Z0-9\-]+$/', $key);
    }

    /**
     * Generate and persist a new key (does not enable feature).
     *
     * @return string|WP_Error
     */
    public function generate_and_store_key() {
        $key = $this->generate_key_string();
        if (is_wp_error($key)) {
            $this->logger->error(
                'IndexNow key generation failed',
                array_merge($this->base_log_context(), array('code' => $key->get_error_code()))
            );
            return $key;
        }
        update_option(self::OPTION_KEY, $key);
        $this->try_write_physical_key_file($key);
        $this->register_key_endpoint();
        flush_rewrite_rules(false);
        $this->logger->info(
            'IndexNow key generated and stored',
            array_merge($this->base_log_context(), array('key_length' => strlen($key)))
        );
        return $key;
    }

    /**
     * If the feature is enabled but no key exists yet, generate one.
     *
     * Default-on installs and upgrades add `geoguru_indexnow_enabled` before a key is created;
     * the REST update path only ran key generation on explicit toggle. Call this from settings GET
     * so the portal shows a key preview on first load without requiring disable/enable.
     *
     * @return true|WP_Error True when no generation was needed or it succeeded.
     */
    public function ensure_key_if_enabled() {
        if (! (bool) get_option(self::OPTION_ENABLED, 1)) {
            return true;
        }
        if ($this->get_stored_key() !== '') {
            return true;
        }
        $gen = $this->generate_and_store_key();
        return is_wp_error($gen) ? $gen : true;
    }

    /**
     * @return string|WP_Error
     */
    private function generate_key_string() {
        $charset = self::KEY_CHARSET;
        $clen    = strlen($charset);
        if ($clen < 2) {
            return new WP_Error('geoguru_indexnow_rng', 'Invalid charset');
        }
        $bytes = random_bytes(64);
        $out   = '';
        for ($i = 0; $i < 32; $i++) {
            $out .= $charset[ord($bytes[$i]) % $clen];
        }
        if (! $this->is_valid_key_format($out)) {
            return new WP_Error('geoguru_indexnow_key', 'Generated key failed validation');
        }
        return $out;
    }

    /**
     * @param string $redirect_url
     * @param string $requested_url
     * @return string|false
     */
    public function prevent_key_file_redirect($redirect_url, $requested_url) {
        $key = $this->get_stored_key();
        if ($key === '' || ! $this->is_valid_key_format($key)) {
            return $redirect_url;
        }
        $path = wp_parse_url($requested_url, PHP_URL_PATH);
        if (! is_string($path)) {
            return $redirect_url;
        }
        $path = rawurldecode($path);
        if ($this->url_path_matches_stored_key_file($key, $path)) {
            $this->logger->debug(
                'IndexNow blocked canonical redirect for key file path',
                $this->base_log_context()
            );
            return false;
        }
        return $redirect_url;
    }

    /**
     * Whether a URL path equals the configured IndexNow key file path for this site (home URL).
     *
     * @param string $key
     * @param string $actual_path Path component only (e.g. from wp_parse_url).
     * @return bool
     */
    private function url_path_matches_stored_key_file($key, $actual_path) {
        if ($key === '' || ! $this->is_valid_key_format($key)) {
            return false;
        }
        if (! is_string($actual_path) || $actual_path === '') {
            return false;
        }
        $expected = wp_parse_url(home_url('/' . $key . '.txt'), PHP_URL_PATH);
        if (! is_string($expected) || $expected === '') {
            return false;
        }
        $expected_norm = untrailingslashit($expected);
        $actual_norm   = untrailingslashit($actual_path);
        if (strlen($expected_norm) !== strlen($actual_norm)) {
            return false;
        }
        return hash_equals($expected_norm, $actual_norm);
    }

    /**
     * Whether the current HTTP request path is exactly the IndexNow key URL for this key.
     *
     * Stale rewrite rules after regenerate could still set our query var for an old key path;
     * we must not echo the current key unless the URL path matches that key (prevents leaking
     * the new key at the old URL).
     *
     * @param string $key
     * @return bool
     */
    private function request_uri_matches_stored_key_file($key) {
        if (! isset($_SERVER['REQUEST_URI'])) {
            return false;
        }
        $actual = wp_parse_url(rawurldecode(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH);
        if (! is_string($actual)) {
            return false;
        }
        return $this->url_path_matches_stored_key_file($key, $actual);
    }

    /**
     * End request with 404 for key file URLs that must not expose any key material.
     */
    private function respond_indexnow_key_file_not_found() {
        nocache_headers();
        status_header(404);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        exit;
    }

    /**
     * Serve public key file body.
     */
    public function handle_public_key_request() {
        $qv = get_query_var(self::QUERY_VAR);
        if (! $qv) {
            return;
        }
        if (! $this->is_feature_enabled()) {
            $this->logger->debug(
                'IndexNow key file request: feature or plugin disabled; returning 404',
                $this->base_log_context()
            );
            $this->respond_indexnow_key_file_not_found();
        }
        $key = $this->get_stored_key();
        if ($key === '' || ! $this->is_valid_key_format($key)) {
            $this->logger->warning(
                'IndexNow key file request matched rewrite but no valid key in options; returning 404',
                $this->base_log_context()
            );
            $this->respond_indexnow_key_file_not_found();
        }
        if (! $this->request_uri_matches_stored_key_file($key)) {
            $this->logger->debug(
                'IndexNow key file request path does not match stored key; returning 404',
                $this->base_log_context()
            );
            $this->respond_indexnow_key_file_not_found();
        }
        $this->logger->debug(
            'IndexNow serving public key file response',
            array_merge($this->base_log_context(), array('key_length' => strlen($key)))
        );
        nocache_headers();
        status_header(200);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo $key; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw key bytes per IndexNow
        exit;
    }

    /**
     * Register GET /wp-json/geoguru-api/indexnow-config
     */
    public function register_rest_routes() {
        register_rest_route(
            'geoguru-api',
            '/indexnow-config',
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'rest_get_indexnow_config'),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'website_id' => array(
                        'required' => false,
                        'type'     => 'string',
                    ),
                ),
            )
        );
        $this->logger->debug('IndexNow REST route registered', $this->base_log_context());
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_get_indexnow_config($request) {
        $this->logger->debug(
            'IndexNow config REST request received',
            $this->base_log_context()
        );

        $bearer = $this->get_bearer_token_from_request($request);
        $stored = get_option('geoguru_secret_token', '');
        if ($stored === '' || ! is_string($bearer) || $bearer === '' || ! hash_equals($stored, $bearer)) {
            $this->logger->warning(
                'IndexNow config request rejected: invalid or missing bearer',
                $this->base_log_context()
            );
            return new WP_Error('unauthorized', 'Unauthorized', array('status' => 401));
        }

        $param_wid = $request->get_param('website_id');
        $site_id   = get_option('geoguru_site_id', '');
        if ($param_wid !== null && $param_wid !== '' && (string) $param_wid !== (string) $site_id) {
            $this->logger->warning(
                'IndexNow config request rejected: website_id query does not match site',
                array_merge(
                    $this->base_log_context(),
                    array(
                        'param_prefix' => is_string($param_wid) ? substr($param_wid, 0, 8) : '',
                    )
                )
            );
            return new WP_Error('forbidden', 'Website ID mismatch', array('status' => 403));
        }

        // Lazily create key when IndexNow is enabled but no key yet (same as portal GET /settings).
        // This path matters for the Cloud Run worker without anyone opening the portal first.
        $ensured = $this->ensure_key_if_enabled();
        if (is_wp_error($ensured)) {
            $this->logger->warning(
                'IndexNow: could not ensure key for indexnow-config',
                array_merge($this->base_log_context(), array('code' => $ensured->get_error_code()))
            );
        }

        if (! $this->is_feature_enabled()) {
            $this->logger->debug(
                'IndexNow config response: feature disabled',
                $this->base_log_context()
            );
            return new WP_REST_Response(
                array(
                    'enabled' => false,
                ),
                200
            );
        }

        $key = $this->get_stored_key();
        if ($key === '' || ! $this->is_valid_key_format($key)) {
            $this->logger->info(
                'IndexNow config response: no valid stored key',
                $this->base_log_context()
            );
            return new WP_REST_Response(array('enabled' => false), 200);
        }

        $blog_public = (bool) get_option('blog_public');
        if (! $blog_public) {
            $this->logger->info(
                'IndexNow config response: blog not public',
                $this->base_log_context()
            );
            return new WP_REST_Response(
                array(
                    'enabled'      => false,
                    'blog_public'  => false,
                ),
                200
            );
        }

        $home = home_url('/');
        $host = wp_parse_url($home, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            $this->logger->error(
                'IndexNow config failed: could not parse site host',
                $this->base_log_context()
            );
            return new WP_Error('geoguru_indexnow_host', 'Could not determine site host', array('status' => 500));
        }

        $key_location = trailingslashit($home) . $key . '.txt';

        $this->logger->info(
            'IndexNow config returned with credentials for backend',
            array_merge(
                $this->base_log_context(),
                array(
                    'host'        => $host,
                    'key_length'  => strlen($key),
                )
            )
        );

        return new WP_REST_Response(
            array(
                'enabled'       => true,
                'key'             => $key,
                'host'            => $host,
                'key_location'    => $key_location,
                'blog_public'     => true,
                'website_id'      => $site_id,
            ),
            200
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return string
     */
    private function get_bearer_token_from_request($request) {
        $auth = $request->get_header('authorization');
        if (empty($auth) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        }
        if (! is_string($auth) || $auth === '') {
            return '';
        }
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return '';
    }
}
