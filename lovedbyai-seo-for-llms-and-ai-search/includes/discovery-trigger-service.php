<?php

defined('ABSPATH') || exit;

/**
 * GeoGuru Discovery Trigger Service
 * 
 * Handles page discovery trigger requests from the discovery service.
 * When REST API is blocked, the service triggers this endpoint, which then
 * queries WordPress internally and calls back to the service with the data.
 */
class GeoGuru_DiscoveryTriggerService {
    
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
    
    /**
     * Initialize the service - register REST API endpoint
     */
    public function init() {
        $this->logger->debug('Initializing discovery trigger service');
        // Register REST API endpoint (WordPress handles JSON parsing internally)
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }
    
    /**
     * Register REST API routes
     * WordPress REST API handles JSON request body parsing internally
     */
    public function register_rest_routes() {
        $this->logger->debug('Registering REST API routes for discovery trigger');
        // Route: index.php?rest_route=/geoguru-api/trigger-discovery
        register_rest_route(
            'geoguru-api',
            '/trigger-discovery',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_trigger_request'),
                'permission_callback' => '__return_true', // Authorization enforced in handler: Bearer secret_token (hash_equals) + website_id match
            )
        );
    }
    
    /**
     * Handle trigger requests via REST API
     * WordPress REST API handles JSON parsing internally via get_json_params()
     * 
     * @param WP_REST_Request $request The REST API request object
     * @return WP_REST_Response|WP_Error
     */
    public function handle_trigger_request($request) {
        $this->logger->debug('Discovery trigger request detected via REST API');
        
        // WordPress REST API automatically parses JSON from request body
        // using get_json_params() - no need to touch php://input ourselves
        $data = $request->get_json_params();
        
        if (empty($data) || !is_array($data)) {
            $this->logger->error('Invalid request body in discovery trigger');
            return new WP_Error(
                'invalid_request',
                'Invalid request body',
                array('status' => 400)
            );
        }
        
        // Only extract the specific fields we need
        $website_id = isset($data['website_id']) ? sanitize_text_field($data['website_id']) : '';
        $post_types = isset($data['post_types']) && is_array($data['post_types']) 
            ? array_map('sanitize_text_field', $data['post_types']) 
            : array('post', 'page');
        $job_id = isset($data['job_id']) ? sanitize_text_field($data['job_id']) : '';
        
        if (empty($website_id)) {
            $this->logger->error('Website ID missing in discovery trigger request');
            return new WP_Error(
                'missing_website_id',
                'Website ID is required',
                array('status' => 400)
            );
        }

        // Authorization (primary): verify the per-site secret token sent by the
        // discovery service as `Authorization: Bearer <secret_token>`. This
        // endpoint was previously permission_callback __return_true with no
        // check at all, letting any anonymous caller trigger a full-content dump
        // (WP_Query posts_per_page => -1, all post meta) and push it to the
        // discovery service. hash_equals gives a constant-time comparison,
        // matching settings-sync-service.php / overlay-rest.php.
        $bearer = $this->get_bearer_token_from_request($request);
        $stored_secret = get_option('geoguru_secret_token', '');
        if ($stored_secret === '' || $bearer === '' || !hash_equals($stored_secret, $bearer)) {
            $this->logger->warning('Discovery trigger rejected: invalid or missing secret token');
            return new WP_Error(
                'unauthorized',
                'Unauthorized',
                array('status' => 401)
            );
        }

        // Authorization (secondary, consistency): the body's website_id must
        // match this site's registered id.
        $expected_site_id = get_option('geoguru_site_id', '');
        if (empty($expected_site_id) || (string) $website_id !== (string) $expected_site_id) {
            $this->logger->warning('Discovery trigger rejected: website_id does not match this site');
            return new WP_Error(
                'forbidden',
                'Forbidden',
                array('status' => 403)
            );
        }

        $this->logger->info('Processing discovery trigger', [
            'website_id' => substr($website_id, 0, 8) . '...',
            'post_types' => $post_types,
            'job_id' => !empty($job_id) ? substr($job_id, 0, 8) . '...' : 'none'
        ]);
        
        // Query WordPress for posts and pages
        $posts_data = array();
        foreach ($post_types as $post_type) {
            $posts = $this->query_wordpress_posts($post_type);
            $posts_data[$post_type] = $posts;
        }
        
        // Send data to service
        $result = $this->send_to_service($website_id, $posts_data, $job_id);
        
        if ($result) {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Discovery data sent to service'
            ), 200);
        } else {
            return new WP_Error(
                'service_error',
                'Failed to send data to service',
                array('status' => 500)
            );
        }
    }
    
    /**
     * Extract the bearer token from the request's Authorization header.
     * Mirrors GeoGuru_SettingsSyncService::get_bearer_token_from_request().
     *
     * @param WP_REST_Request $request
     * @return string The token, or '' when absent/malformed.
     */
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

    /**
     * Query WordPress for posts of a specific type
     */
    private function query_wordpress_posts($post_type) {
        $this->logger->info('Querying WordPress posts', [
            'post_type' => $post_type
        ]);
        
        $query_args = array(
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => -1, // Get all posts
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => false
        );
        
        $query = new WP_Query($query_args);
        $posts = array();
        
        foreach ($query->posts as $post) {
            $posts[] = $this->format_post_for_api($post);
        }
        
        $this->logger->info('WordPress posts queried', [
            'post_type' => $post_type,
            'count' => count($posts)
        ]);
        
        return $posts;
    }
    
    /**
     * Format a WordPress post to match REST API format
     */
    private function format_post_for_api($post) {
        $post_data = array(
            'id' => $post->ID,
            'date' => mysql2date('c', $post->post_date, false),
            'date_gmt' => mysql2date('c', $post->post_date_gmt, false),
            'guid' => array('rendered' => get_the_guid($post->ID)),
            'modified' => mysql2date('c', $post->post_modified, false),
            'modified_gmt' => mysql2date('c', $post->post_modified_gmt, false),
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'link' => get_permalink($post->ID),
            'title' => array('rendered' => get_the_title($post->ID)),
            'content' => array(
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the_content is a valid hook
                'rendered' => apply_filters('the_content', $post->post_content),
                'protected' => !empty($post->post_password)
            ),
            'excerpt' => array(
                'rendered' => $post->post_excerpt ?: wp_trim_excerpt($post->post_content),
                'protected' => false
            ),
            'author' => (int) $post->post_author,
            'featured_media' => (int) get_post_thumbnail_id($post->ID),
            'comment_status' => $post->comment_status,
            'ping_status' => $post->ping_status,
            'sticky' => is_sticky($post->ID),
            'template' => get_page_template_slug($post->ID),
            'format' => get_post_format($post->ID) ?: 'standard',
            'meta' => get_post_meta($post->ID),
            'categories' => wp_get_post_categories($post->ID),
            'tags' => wp_get_post_tags($post->ID, array('fields' => 'ids'))
        );
        
        return $post_data;
    }
    
    /**
     * Send discovery data to the service
     */
    private function send_to_service($website_id, $posts_data, $job_id = '') {
        $discovery_service_url = get_option('geoguru_discovery_service_url', '');
        $secret_token = get_option('geoguru_secret_token', '');
        
        if (empty($discovery_service_url)) {
            $this->logger->error('Discovery service URL not configured');
            return false;
        }
        
        if (empty($secret_token)) {
            $this->logger->error('Secret token not configured');
            return false;
        }
        
        // Construct callback URL
        $callback_url = rtrim($discovery_service_url, '/') . '/api/v1/discovery/websites/' . urlencode($website_id) . '/receive';
        
        // Prepare request body
        $request_body = array(
            'posts' => isset($posts_data['posts']) ? $posts_data['posts'] : array(),
            'pages' => isset($posts_data['pages']) ? $posts_data['pages'] : array()
        );
        
        // Add other post types if present
        foreach ($posts_data as $post_type => $posts) {
            if ($post_type !== 'posts' && $post_type !== 'pages') {
                $request_body[$post_type] = $posts;
            }
        }
        
        // Add job_id if provided
        if (!empty($job_id)) {
            $request_body['job_id'] = $job_id;
        }
        
        $this->logger->info('Sending discovery data to service', [
            'callback_url' => $callback_url,
            'website_id' => substr($website_id, 0, 8) . '...',
            'posts_count' => count($request_body['posts']),
            'pages_count' => count($request_body['pages']),
            'job_id' => !empty($job_id) ? substr($job_id, 0, 8) . '...' : 'none'
        ]);
        
        // Make HTTP request to service
        $response = wp_remote_post($callback_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 60 // Longer timeout for large datasets
        ));
        
        if (is_wp_error($response)) {
            $this->logger->error('Failed to send data to service', [
                'error' => $response->get_error_message()
            ]);
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            $this->logger->error('Service returned error', [
                'response_code' => $response_code,
                'response_body' => $response_body
            ]);
            return false;
        }
        
        $this->logger->info('Successfully sent discovery data to service', [
            'response_code' => $response_code
        ]);
        
        return true;
    }
}
