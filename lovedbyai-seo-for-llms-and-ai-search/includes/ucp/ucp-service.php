<?php

// Publishes the store's catalog profile at /.well-known/ucp so AI shopping agents can find its products.

if (!defined('ABSPATH')) {
    exit;
}

class GeoGuru_UcpService {
    const OPTION_ENABLED = 'geoguru_ucp_catalog_enabled';
    const OPTION_ENTITLED = 'geoguru_ucp_catalog_entitled';
    const REST_NAMESPACE = 'ucp-store/v1';
    const PROFILE_PATH = '/.well-known/ucp';
    const STORE_API_BLOCKED = 'geoguru_ucp_store_api_blocked';

    // Plugin directory slugs of other plugins that publish a profile at the same address.
    const KNOWN_PROVIDER_PLUGINS = [
        'wt-ucp-enabler-for-woocommerce',
        'ucp-acp-agent-for-woocommerce',
        'ucp-adapter-for-woocommerce',
        'ucphub-for-woocommerce',
        'agentic-commerce-for-woocommerce',
        'universal-commerce-protocol-ucp-for-woocommerce',
        'feedship-ai-catalog-feeds',
        'shopping-agent-with-ucp',
        'vixns-commerce-profile-ucp',
    ];

    private static $instance = null;

    private function __construct() {
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function is_switched_on() {
        return (int) get_option(self::OPTION_ENABLED, 1) === 1;
    }

    public function is_entitled() {
        return (int) get_option(self::OPTION_ENTITLED, 0) === 1;
    }

    // Options only, so it is cheap enough to ask on every request.
    public function is_available() {
        return (bool) get_option('geoguru_plugin_active', 1)
            && $this->is_entitled()
            && $this->is_switched_on()
            && class_exists('WooCommerce');
    }

    // Null when the profile is served, else the first check that fails.
    public function reason() {
        // A Store API that refused our own call counts as missing for a while.
        if (!class_exists('Automattic\WooCommerce\StoreApi\StoreApi') || get_transient(self::STORE_API_BLOCKED)) {
            return 'no_store_api';
        }
        if (!is_ssl()) {
            return 'not_https';
        }
        $path = wp_parse_url(home_url(), PHP_URL_PATH);
        if ($path !== null && $path !== false && $path !== '' && $path !== '/') {
            return 'not_root_install';
        }
        if (!get_option('permalink_structure', '')) {
            return 'plain_permalinks';
        }
        $counts = wp_count_posts('product');
        if (!$counts || (int) $counts->publish === 0) {
            return 'no_products';
        }
        if ($this->other_provider()) {
            return 'other_provider';
        }
        return null;
    }

    private function other_provider() {
        if (file_exists(ABSPATH . '.well-known/ucp')) {
            return true;
        }
        $rules = get_option('rewrite_rules', []);
        if (is_array($rules)) {
            foreach (array_keys($rules) as $pattern) {
                if (strpos((string) $pattern, 'well-known/ucp') !== false) {
                    return true;
                }
            }
        }
        $active = (array) get_option('active_plugins', []);
        $sitewide = get_option('active_sitewide_plugins', []);
        if (is_array($sitewide)) {
            $active = array_merge($active, array_keys($sitewide));
        }
        foreach ($active as $plugin) {
            if (in_array(dirname((string) $plugin), self::KNOWN_PROVIDER_PLUGINS, true)) {
                return true;
            }
        }
        return false;
    }

    public function status() {
        $entitled = $this->is_entitled();
        $reason = null;
        if (!$entitled) {
            $state = 'not_entitled';
        } elseif (!$this->is_available()) {
            $state = 'off';
        } else {
            $reason = $this->reason();
            $state = $reason === null ? 'serving' : 'not_serving';
        }
        return [
            'enabled' => $this->is_switched_on(),
            'entitled' => $entitled,
            'status' => $state,
            'reason' => $reason,
        ];
    }

    // The query is ignored; the path is matched exactly.
    public static function match_profile_path($request_uri) {
        $path = (string) $request_uri;
        $query = strpos($path, '?');
        if ($query !== false) {
            $path = substr($path, 0, $query);
        }
        return $path === self::PROFILE_PATH || $path === self::PROFILE_PATH . '/';
    }

    public function profile_response($method, $if_none_match) {
        $endpoint = untrailingslashit(rest_url(self::REST_NAMESPACE));
        $body = wp_json_encode(geoguru_ucp_profile($endpoint));
        $etag = '"' . md5($body) . '"';
        $headers = [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=300',
            'Access-Control-Allow-Origin' => '*',
            'ETag' => $etag,
        ];
        if (trim((string) $if_none_match) === $etag) {
            return ['status' => 304, 'headers' => $headers, 'body' => ''];
        }
        return ['status' => 200, 'headers' => $headers, 'body' => $method === 'HEAD' ? '' : $body];
    }

    private function send($response) {
        status_header($response['status']);
        foreach ($response['headers'] as $name => $value) {
            header("$name: $value");
        }
        echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document, not HTML
        exit;
    }

    // Runs on `init`, on every request.
    public function init() {
        add_action('rest_api_init', [$this, 'register_status_route']);
        $uri = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $serving = self::match_profile_path($uri);
        if (!$this->is_available()) {
            return;
        }
        if ($serving && $this->reason() === null) {
            $method = strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET')));
            $match = sanitize_text_field(wp_unslash($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
            $this->send($this->profile_response($method, $match));
        }
        add_action('rest_api_init', [$this, 'register_endpoints']);
    }

    public function register_status_route() {
        register_rest_route('geoguru-api', '/ucp', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_status'],
            'permission_callback' => '__return_true', // checked in handle_status()
        ]);
    }

    // Null without WooCommerce, as in the settings response.
    public function handle_status($request) {
        $bearer = GeoGuru_Utils::read_bearer_token($request);
        $stored = (string) get_option('geoguru_secret_token', '');
        if ($stored === '' || $bearer === '' || !hash_equals($stored, $bearer)) {
            return new WP_Error('unauthorized', 'Unauthorized', ['status' => 401]);
        }
        return class_exists('WooCommerce') ? $this->status() : null;
    }

    // The profile may only point agents at an https endpoint.
    public function register_endpoints() {
        if (is_ssl() && class_exists('GeoGuru_UcpEndpoints')) {
            GeoGuru_UcpEndpoints::get_instance()->register_routes();
        }
    }
}
