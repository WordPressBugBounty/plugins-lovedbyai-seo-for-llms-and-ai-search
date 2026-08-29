<?php

defined('ABSPATH') || exit;

class GeoGuru_MixpanelService {
    private static $instance = null;
    private $token;
    private $host;
    private $logger;

    private function __construct() {
        $config = GeoGuru_ConfigService::get_instance();
        $this->host  = rtrim('https://api.mixpanel.com', '/');
        $this->logger = GeoGuru_Logger::get_instance();
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function track(string $event, array $properties = [], array $request_properties = []): bool {
        // Re-read token on each call to handle cases where it's set after singleton creation
        $token = get_option('geoguru_mixpanel_token');
        if (empty($token)) {
            return false; // silently no-op if not configured
        }

        $distinct_id = get_option('geoguru_site_id', md5(get_site_url()));
        $defaults = [
            'token'          => $token,
            'distinct_id'    => $distinct_id,
            'site_id'        => get_option('geoguru_site_id', ''),
            'site_url'       => get_site_url(),
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
            'plugin_version' => defined('GEOGURU_PLUGIN_VERSION') ? GEOGURU_PLUGIN_VERSION : null,
            'white_label'    => function_exists('geoguru_is_white_label') && geoguru_is_white_label(),
        ];

        $default_request_properties = [
            'blocking' => false,
            'timeout' => 2,
        ];

        $payload = [[
            'event' => $event,
            'properties' => array_filter(array_merge($defaults, $properties), function($v) { return $v !== null; })
        ]];

        $body = [
            'data' => base64_encode(wp_json_encode($payload)),
            'ip'   => 1,
        ];

        $merged_request_properties = array_merge($default_request_properties, $request_properties ?? []);
        $response = wp_remote_post($this->host . '/track', [
            'timeout'  => $merged_request_properties['timeout'],
            'blocking' => $merged_request_properties['blocking'],
            'body'     => $body,
        ]);

        if (is_wp_error($response)) {
            $this->logger->warning('Mixpanel track failed', ['event' => $event, 'error' => $response->get_error_message()]);
            return false;
        }

        return true;
    }
}