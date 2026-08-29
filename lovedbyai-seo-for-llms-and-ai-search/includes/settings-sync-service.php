<?php

defined('ABSPATH') || exit;

class GeoGuru_SettingsSyncService {

    private static $instance = null;
    private $logger;

    private function __construct() {
        $this->logger = GeoGuru_Logger::get_instance();
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Supabase wp_synced_settings key => array(WP option name, value type).
    // Keys not listed here are ignored on inbound sync.
    private function allowlist() {
        return array(
            'apply_non_visible_overlay_on_original_page' => array('geoguru_apply_non_visible_overlay_on_original_page', 'bool'),
        );
    }

    public function init() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    public function register_rest_routes() {
        register_rest_route(
            'geoguru-api',
            '/settings-sync',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_inbound_sync'),
                'permission_callback' => '__return_true', // authenticated via secret token in handler
            )
        );
    }

    public function handle_inbound_sync($request) {
        $bearer = $this->get_bearer_token_from_request($request);
        $stored = get_option('geoguru_secret_token', '');
        if ($stored === '' || $bearer === '' || !hash_equals($stored, $bearer)) {
            $this->logger->warning('Settings sync push rejected: invalid or missing bearer');
            return new WP_Error('unauthorized', 'Unauthorized', array('status' => 401));
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            return new WP_Error('invalid_request', 'Invalid request body', array('status' => 400));
        }

        $body_site = isset($params['site_id']) ? sanitize_text_field($params['site_id']) : '';
        $site_id = get_option('geoguru_site_id', '');
        if ($body_site === '' || (string) $body_site !== (string) $site_id) {
            $this->logger->warning('Settings sync push rejected: site_id mismatch');
            return new WP_Error('forbidden', 'Site ID mismatch', array('status' => 403));
        }

        $settings = (isset($params['settings']) && is_array($params['settings'])) ? $params['settings'] : array();
        $result = $this->apply_synced_settings($settings);

        // Echo site_id so the caller can correlate this response to the site it applied to.
        return new WP_REST_Response(
            array_merge(array('success' => true, 'site_id' => $site_id), $result),
            200
        );
    }

    // Returns array{applied: array, ignored: array}.
    public function apply_synced_settings($settings) {
        $applied = array();
        $ignored = array();

        if (!is_array($settings)) {
            return array('applied' => $applied, 'ignored' => $ignored);
        }

        if (array_key_exists('_sync_probe', $settings)) {
            set_transient('geoguru_sync_probe', sanitize_text_field((string) $settings['_sync_probe']), HOUR_IN_SECONDS);
        }

        $allowlist = $this->allowlist();

        foreach ($settings as $key => $_value) {
            if ($key !== '_sync_probe' && !array_key_exists($key, $allowlist)) {
                $ignored[] = $key;
            }
        }

        foreach ($allowlist as $key => $spec) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            $option_name = $spec[0];
            $type = $spec[1];
            $value = $settings[$key];

            switch ($type) {
                case 'bool':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                    break;
                case 'string':
                    $value = sanitize_text_field((string) $value);
                    break;
                case 'int':
                    $value = (int) $value;
                    break;
            }

            $previous = get_option($option_name, null);
            update_option($option_name, $value);
            $changed = (string) $previous !== (string) $value;
            $this->logger->debug('Applied synced setting', array('option' => $option_name));

            $applied[] = array(
                'key' => $key,
                'option' => $option_name,
                'previous' => $previous,
                'value' => $value,
                'changed' => $changed,
            );

            // Overlay gate affects every render; purge caches on change so the toggle isn't
            // masked by cached HTML until the TTL expires.
            if ($option_name === 'geoguru_apply_non_visible_overlay_on_original_page'
                && $changed
                && function_exists('geoguru_overlay_purge_caches')) {
                geoguru_overlay_purge_caches(0);
            }
        }

        return array('applied' => $applied, 'ignored' => $ignored);
    }

    private function get_bearer_token_from_request($request) {
        $auth = $request->get_header('authorization');
        if (empty($auth) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        }
        if (!is_string($auth) || $auth === '') {
            return '';
        }
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return '';
    }
}
