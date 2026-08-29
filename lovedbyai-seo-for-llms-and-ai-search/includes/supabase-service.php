<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// packages/wp-plugin/includes/supabase-service.php
// Service for interacting with Supabase database
if (!class_exists('GeoGuru_SupabaseService')) {
    class GeoGuru_SupabaseService {
        private static $instance = null;
        private $supabase_url;
        private $supabase_anon_key;
        private $fire_and_forget_worker_url;
        private $logger;

        private function __construct() {
            $this->logger = GeoGuru_Logger::get_instance();
            $this->supabase_url = get_option('geoguru_supabase_url');
            $this->supabase_anon_key = get_option('geoguru_supabase_anon_key');
            $this->fire_and_forget_worker_url = get_option('geoguru_fire_and_forget_worker_url');
            
            if (empty($this->supabase_url) || empty($this->supabase_anon_key) || empty($this->fire_and_forget_worker_url)) {
                $this->logger->error('Supabase URL or anon key or fire and forget worker URL not configured');
            } else {
                $this->logger->debug('Supabase service initialized', [
                    'url' => $this->supabase_url
                ]);
            }
        }

        public static function get_instance() {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Verify if website credentials exist in Supabase
         * 
         * @param string $site_id The website ID
         * @param string $secret_token The secret token
         * @return bool True if credentials exist, false otherwise
         */
        public function verify_website_credentials($site_id, $secret_token) {
            if (empty($this->supabase_url) || empty($this->supabase_anon_key)) {
                $this->logger->error('Supabase not configured properly for verification');
                return false;
            }

            $verify_url = $this->supabase_url . '/functions/v1/verify-website-credentials';
            
            $data = [
                'site_id' => $site_id,
                'secret_token' => $secret_token
            ];

            $headers = [
                'apikey' => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'Content-Type' => 'application/json'
            ];

            $this->logger->info('Verifying website credentials', [
                'site_id_prefix' => substr($site_id, 0, 8) . '...',
                'url' => $verify_url
            ]);

            $response = wp_remote_post($verify_url, [
                'headers' => $headers,
                'body' => json_encode($data),
                'timeout' => 30
            ]);

            if (is_wp_error($response)) {
                $this->logger->error('WordPress HTTP Error during verification', [
                    'error' => $response->get_error_message()
                ]);
                return false;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            

            if ($response_code === 200) {
                $decoded_response = json_decode($response_body, true);
                
                if (isset($decoded_response['data']['valid']) && $decoded_response['data']['valid'] === true) {
                    $this->logger->info('Website credentials verified successfully');
                    return true;
                }
            } elseif ($response_code === 404) {
                $this->logger->warning('Website credentials not found in database');
                return false;
            } elseif ($response_code === 429) {
                $this->logger->warning('Rate limit exceeded during verification');
                return false;
            }

            $this->logger->error('Verification failed with unexpected response', [
                'code' => $response_code,
                'body' => $response_body
            ]);
            return false;
        }

        /**
         * Fetch the site's synced settings from the backend so the plugin can reconcile local
         * options to the source of truth on activation. Authenticated by site_id + secret_token.
         *
         * @param string $site_id      The website ID
         * @param string $secret_token The secret token
         * @return array|null Settings map when credentials are valid, null on invalid creds or error
         */
        public function get_synced_settings($site_id, $secret_token) {
            if (empty($this->supabase_url) || empty($this->supabase_anon_key)) {
                return null;
            }

            $settings_url = $this->supabase_url . '/functions/v1/get-website-settings';
            $response = wp_remote_post($settings_url, [
                'headers' => [
                    'apikey' => $this->supabase_anon_key,
                    'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'site_id' => $site_id,
                    'secret_token' => $secret_token
                ]),
                'timeout' => 30
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return null;
            }

            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            if (!isset($decoded['data']['valid']) || $decoded['data']['valid'] !== true) {
                return null;
            }

            return (isset($decoded['data']['settings']) && is_array($decoded['data']['settings']))
                ? $decoded['data']['settings']
                : array();
        }

        /**
         * Set website status (active/inactive) via Edge Function. Uses site_id + secret_token for auth.
         *
         * @param string $site_id      The website ID
         * @param string $secret_token The secret token
         * @param string $status       Must be 'active' or 'inactive'
         * @return bool True on success
         */
        public function set_website_status_via_edge($site_id, $secret_token, $status) {
            if ($status !== 'active' && $status !== 'inactive') {
                $this->logger->error('set_website_status_via_edge: invalid status', ['status' => $status]);
                return false;
            }

            if (empty($this->supabase_url) || empty($this->supabase_anon_key)) {
                $this->logger->error('Supabase not configured properly for set_website_status');
                return false;
            }

            if (empty($site_id) || empty($secret_token)) {
                $this->logger->error('Site ID and secret token are required for set_website_status');
                return false;
            }

            $url = $this->supabase_url . '/functions/v1/set-website-status';
            $body = json_encode(array(
                'site_id' => $site_id,
                'secret_token' => $secret_token,
                'status' => $status,
            ));

            $headers = array(
                'apikey' => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'Content-Type' => 'application/json',
            );

            $this->logger->info('Setting website status via Edge Function', array(
                'site_id_prefix' => substr($site_id, 0, 8) . '...',
                'status' => $status,
                'url' => $url,
            ));

            $response = wp_remote_post($url, array(
                'headers' => $headers,
                'body' => $body,
                'timeout' => 30,
            ));

            if (is_wp_error($response)) {
                $this->logger->error('WordPress HTTP Error during set_website_status', array(
                    'error' => $response->get_error_message(),
                ));
                return false;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code === 200) {
                $decoded = json_decode($response_body, true);
                if (!empty($decoded['success']) && $decoded['success'] === true) {
                    $this->logger->info('Website status updated via Edge Function', array('status' => $status));
                    return true;
                }
            }

            $this->logger->warning('set_website_status_via_edge failed', array(
                'code' => $response_code,
                'body' => $response_body,
            ));
            return false;
        }

        /**
         * Create a crawling log entry via Fire and Forget Worker
         * 
         * @param string $site_id The website ID
         * @param string $secret_token The secret token for verification
         * @param array $log_data Additional log data (url, user_agent, etc.)
         * @return array|false Returns the created log record or false on failure
         */
        public function create_crawling_log($site_id, $secret_token, $log_data = []) {
            if (empty($this->fire_and_forget_worker_url)) {
                $this->logger->error('Fire and forget worker URL not configured properly for crawling log');
                return;
            }

            if (empty($site_id) || empty($secret_token)) {
                $this->logger->error('Site ID and secret token are required for crawling log');
                return;
            }

            $edge_function_url = $this->fire_and_forget_worker_url;
            
            $data = [
                'function_name' => 'create-crawling-log',
                'function_params'=> array_merge([
                    'site_id' => $site_id,
                    'secret_token' => $secret_token,
                    'url' => GeoGuru_Utils::get_current_full_url(),
                    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field( wp_unslash ($_SERVER['HTTP_USER_AGENT'])) : 'Unknown',
                    'bot_type' => 'ai-search',
                    'bot_name' => 'crawler',
                    'status' => 'success'], $log_data)
            ];

            $headers = [
                'Content-Type' => 'application/json'
            ];

            $this->logger->debug('Creating crawling log entry via Fire and Forget Worker', [
                'function_url' => $edge_function_url,
                'url' => $data['function_params']['url'],
            ]);

            $response = wp_remote_post($edge_function_url, [
                'headers' => $headers,
                'body' => json_encode($data),
                'timeout' => 30
            ]);

            if (is_wp_error($response)) {
                $this->logger->error('WordPress HTTP Error during crawling log creation', [
                    'error' => $response->get_error_message()
                ]);
                return;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            $this->logger->debug('Crawling log creation response', [
                'response_code' => $response_code
            ]);

            if ($response_code !== 202) {
                $this->logger->error('Crawling log creation failed', [
                    'response_code' => $response_code,
                    'response_body' => $response_body
                ]);
                return;
            }
            
            $this->logger->debug('Crawling log created successfully via Fire and Forget Worker', [
                'url' => $data['function_params']['url']
            ]);
        }

        /**
         * Create LLM source event log entry via Fire and Forget Worker
         * Logs requests that come with specific utm_source parameters (e.g., chatgpt.com)
         *
         * @param string $site_id The site ID
         * @param string $secret_token The secret token for authentication
         * @param array $log_data Additional log data
         * @return array|false The created log record or false on failure
         */
        public function create_llm_source_event($site_id, $secret_token, $log_data = []) {
            if (empty($this->fire_and_forget_worker_url)) {
                $this->logger->error('Fire and forget worker URL not configured properly for LLM source event log');
                return false;
            }

            if (empty($site_id) || empty($secret_token)) {
                $this->logger->error('Site ID and secret token are required for LLM source event log');
                return false;
            }

            $edge_function_url = $this->fire_and_forget_worker_url;
            
            // Build base data with defaults
            // Priority: log_data['utm_source'] > $_GET['utm_source'] > 'unknown'
            // This allows referer-based detection to pass the detected source via log_data['utm_source']
            $data = [
                'function_name' => 'create-llm-source-event',
                'function_params' => array_merge([
                    'site_id' => $site_id,
                    'secret_token' => $secret_token,
                    'url' => GeoGuru_Utils::get_current_full_url(),
                    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field( wp_unslash($_SERVER['HTTP_USER_AGENT'])) : 'Unknown'
                ], 
                $log_data)
            ];

            $headers = [
                'Content-Type' => 'application/json'
            ];

            $this->logger->info('Creating LLM source event log entry via Fire and Forget Worker', [
                'function_url' => $edge_function_url,
                'site_id' => substr($site_id, 0, 8) . '...',
                'url' => $data['function_params']['url'] ?? null,
                'utm_source' => $data['function_params']['utm_source'] ?? null,
                'referer' => $data['function_params']['referer'] ?? null
            ]);

            $response = wp_remote_post($edge_function_url, [
                'headers' => $headers,
                'body' => json_encode($data),
                'timeout' => 30
            ]);

            if (is_wp_error($response)) {
                $this->logger->error('WordPress HTTP Error during LLM source event log creation', [
                    'error' => $response->get_error_message()
                ]);
                return false;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            $this->logger->debug('LLM source event log creation response', [
                'response_code' => $response_code
            ]);

            if ($response_code !== 202) {
                $this->logger->error('LLM source event log creation failed', [
                    'response_code' => $response_code,
                    'response_body' => $response_body
                ]);
                return;
            }
            
            $this->logger->info('LLM source event log created successfully via Fire and Forget Worker', [
                'url' => $data['function_params']['url'] ?? null,
                'utm_source' => $data['function_params']['utm_source'] ?? null,
                'referer' => $data['function_params']['referer'] ?? null
            ]);
        }

        /**
         * Create LLM source event by calling the Supabase edge function directly with flat JSON.
         * Used by the plugin proxy (client-side tracking flow); no fire-and-forget worker.
         *
         * @param string $site_id     The site ID
         * @param string $secret_token The secret token for authentication
         * @param string $url        Page URL
         * @param string $user_agent  User agent string
         * @param string $referer    Referer value
         * @param string $utm_source Normalized utm_source (e.g. chatgpt.com)
         * @return array|false Response body as array on success, false on failure
         */
        public function create_llm_source_event_via_edge($site_id, $secret_token, $url, $user_agent, $referer, $utm_source) {
            if (empty($this->supabase_url) || empty($this->supabase_anon_key)) {
                $this->logger->error('Supabase URL or anon key not configured for LLM source event');
                return false;
            }
            if (empty($site_id) || empty($secret_token)) {
                $this->logger->error('Site ID and secret token are required for LLM source event');
                return false;
            }

            $edge_function_url = $this->supabase_url . '/functions/v1/create-llm-source-event';
            $body = array(
                'site_id'      => $site_id,
                'secret_token' => $secret_token,
                'url'          => $url ?: '/',
                'user_agent'   => $user_agent ?: 'Unknown',
                'referer'      => $referer ?: 'Unknown',
                'utm_source'   => $utm_source ?: 'Unknown',
            );

            $headers = array(
                'apikey'        => $this->supabase_anon_key,
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
                'Content-Type'  => 'application/json',
            );

            $response = wp_remote_post($edge_function_url, array(
                'headers' => $headers,
                'body'    => wp_json_encode($body),
                'timeout' => 10,
            ));

            if (is_wp_error($response)) {
                $this->logger->error('LLM source event via edge failed', array(
                    'error' => $response->get_error_message(),
                ));
                return false;
            }

            $code = wp_remote_retrieve_response_code($response);
            $res_body = wp_remote_retrieve_body($response);
            if ($code !== 201 && $code !== 200) {
                $this->logger->error('LLM source event via edge returned error', array(
                    'code' => $code,
                    'body' => $res_body,
                ));
                return false;
            }

            $this->logger->debug('LLM source event created via edge', array(
                'url' => $url,
                'utm_source' => $utm_source,
            ));
            $decoded = json_decode($res_body, true);
            return is_array($decoded) ? $decoded : array('success' => true);
        }
    }
}
