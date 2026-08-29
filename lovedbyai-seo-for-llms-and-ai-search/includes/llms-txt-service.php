<?php

defined('ABSPATH') || exit;

/**
 * GeoGuru llms.txt Service
 * 
 * Handles generation and serving of llms.txt files following the specification at https://llmstxt.org/
 */
class GeoGuru_LlmsTxtService {
    
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
     * Initialize the service - register hooks that need to run on every request
     */
    public function init() {
        // Register rewrite tag on every request (required for query vars to work)
        add_rewrite_tag('%geoguru_llms_txt%', '([^&]+)');
        // Register rewrite rule on every request so it is restored after future rewrite flushes.
        $this->register_llms_txt_endpoint();
        
        // Prevent WordPress from redirecting /llms.txt to /llms.txt/
        add_filter('redirect_canonical', array($this, 'prevent_llms_txt_redirect'), 10, 2);
        // Handle the llms.txt request
        add_action('template_redirect', array($this, 'handle_llms_txt_request'));
        // Add AJAX handlers for portal app
        add_action('wp_ajax_geoguru_get_llms_txt', array($this, 'ajax_get_llms_txt'));
        add_action('wp_ajax_geoguru_regenerate_llms_txt', array($this, 'ajax_regenerate_llms_txt'));
        
        // Add CORS headers for AJAX requests
        add_action('wp_ajax_geoguru_get_llms_txt', 'geoguru_add_cors_headers', 1);
        add_action('wp_ajax_geoguru_regenerate_llms_txt', 'geoguru_add_cors_headers', 1);
    }
    
    /**
     * Check if llms.txt feature is enabled
     */
    public function is_enabled() {
        $plugin_active = get_option('geoguru_plugin_active', 1);
        $llms_txt_enabled = get_option('geoguru_llms_txt_enabled', 1);
        
        return $plugin_active && $llms_txt_enabled;
    }
    
    /**
     * Prevent WordPress from redirecting /llms.txt to /llms.txt/
     * 
     * @param string $redirect_url The redirect URL
     * @param string $requested_url The originally requested URL
     * @return string|false The redirect URL or false to prevent redirect
     */
    public function prevent_llms_txt_redirect($redirect_url, $requested_url) {
        // Check if the request is for /llms.txt (with or without trailing slash)
        $request_path = wp_parse_url($requested_url, PHP_URL_PATH);
        
        // Remove leading slash and check if it's llms.txt
        $path = ltrim($request_path, '/');
        
        if ($path === 'llms.txt' || $path === 'llms.txt/') {
            $this->logger->debug('Preventing canonical redirect for llms.txt', [
                'requested_url' => $requested_url,
                'redirect_url' => $redirect_url
            ]);
            // Return false to prevent the redirect
            return false;
        }
        
        // Allow normal redirects for other URLs
        return $redirect_url;
    }
    
    /**
     * Register the llms.txt endpoint
     * Note: add_rewrite_tag() is called in init() on every request
     * This method only registers the rewrite rule (which persists after flush)
     */
    public function register_llms_txt_endpoint() {
        $this->logger->info('Registering llms.txt endpoint');
        add_rewrite_rule('^llms\.txt$', 'index.php?geoguru_llms_txt=1', 'top');
    }
    

    private function is_llms_txt_request() {
        $query_var = get_query_var('geoguru_llms_txt');
        if ($query_var) {
            $this->logger->debug('llms.txt request detected via query var', [
                'query_var' => $query_var
            ]);
            return true;
        }
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if ($request_uri) {
            // Check if the request is for /llms.txt
            $path = wp_parse_url($request_uri, PHP_URL_PATH);
            $path = trim($path, '/');
            if ($path === 'llms.txt') {
                $this->logger->debug('llms.txt request detected via URI check', [
                    'request_uri' => $request_uri,
                    'path' => $path
                ]);
                return true;
            }
        }
        return false;
    }
    /**
     * Handle llms.txt requests
     */
    public function handle_llms_txt_request() {
        if ($this->is_llms_txt_request()) {
            $this->logger->info('Handling llms.txt request');
            $this->serve_llms_txt();
        }
    }
    
    /**
     * Serve the llms.txt file
     */
    private function serve_llms_txt() {
        // Check if feature is enabled
        if (!$this->is_enabled()) {
            $this->logger->debug('llms.txt feature is disabled, returning 404');
            status_header(404);
            exit;
        }
        
        $this->logger->info('Serving llms.txt request');
        
        // Try to fetch from R2 CDN first
        $r2_content = $this->get_llms_txt_from_r2();
        
        if ($r2_content !== false) {
            $this->output_llms_txt($r2_content);
            return;
        }
        
        // Get cached content as fallback
        $cached_content = $this->get_cached_llms_txt();
        
        if ($cached_content !== false) {
            $this->output_llms_txt($cached_content);
            return;
        }
        
        // Generate new content as last resort
        $content = $this->generate_llms_txt();
        
        if ($content === false) {
            $this->logger->error('Failed to generate llms.txt content');
            status_header(500);
            exit;
        }
        
        // Cache the content
        $this->cache_llms_txt($content);
        
        $this->output_llms_txt($content);
    }
    
    /**
     * Fetch llms.txt content from R2 CDN
     * @return string|false Content if successful, false on failure
     */
    private function get_llms_txt_from_r2() {
        $website_id = get_option('geoguru_site_id', '');
        $cdn_url = get_option('geoguru_optimized_content_cdn_url', '');
        
        if (empty($website_id)) {
            $this->logger->debug('Website ID not set, skipping R2 fetch');
            return false;
        }
        
        if (empty($cdn_url)) {
            $this->logger->debug('CDN URL not configured, skipping R2 fetch');
            return false;
        }
        
        // Construct R2 CDN URL: {CDN_URL}/{websiteId}/llms.txt
        $r2_url = rtrim($cdn_url, '/') . '/' . $website_id . '/llms.txt';
        
        $this->logger->debug('Fetching llms.txt from R2', [
            'r2_url' => $r2_url,
            'website_id' => substr($website_id, 0, 8) . '...'
        ]);
        
        // Fetch from R2 CDN
        $response = wp_remote_get($r2_url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'GeoGuru-WordPress-Plugin/1.0'
            ]
        ]);
        
        if (is_wp_error($response)) {
            $this->logger->debug('Failed to fetch llms.txt from R2', [
                'error' => $response->get_error_message(),
                'r2_url' => $r2_url
            ]);
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            $content = wp_remote_retrieve_body($response);
            $this->logger->info('Successfully fetched llms.txt from R2', [
                'r2_url' => $r2_url,
                'content_length' => strlen($content)
            ]);
            return $content;
        } elseif ($response_code === 404) {
            $this->logger->debug('llms.txt not found in R2 (404), will use fallback', [
                'r2_url' => $r2_url
            ]);
            return false;
        } else {
            $this->logger->warning('Unexpected response code when fetching llms.txt from R2', [
                'response_code' => $response_code,
                'r2_url' => $r2_url
            ]);
            return false;
        }
    }
    
    /**
     * Output llms.txt content with proper headers
     */
    private function output_llms_txt($content) {
        global $wp_query;
        if (isset($wp_query) && is_object($wp_query)) {
            $wp_query->is_404 = false;
        }
        status_header(200);

        // Set proper headers
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
        header('X-Content-Type-Options: nosniff');
        
        // Content is intentionally unescaped as it's plain text output that must be served as-is.
        // Content is generated from trusted WordPress data and sanitized via sanitize_title() and sanitize_summary().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $content;
        exit;
    }
    
    /**
     * Generate llms.txt content
     */
    public function generate_llms_txt() {
        try {
            $this->logger->info('Starting llms.txt generation');
            
            // Get site information
            $site_name = get_bloginfo('name');
            $site_description = get_bloginfo('description');
            $site_url = get_site_url();
            
            // Get configuration
            $config = $this->get_config();
            
            // Discover pages
            $pages = $this->discover_pages();
            
            if (empty($pages)) {
                $this->logger->warning('No pages discovered for llms.txt generation');
                return $this->generate_minimal_llms_txt($site_name, $site_description);
            }
            
            // Generate content
            $content = $this->format_llms_txt($site_name, $site_description, $pages, $config);
            
            $this->logger->info('llms.txt generation completed', [
                'pages_count' => count($pages),
                'content_length' => strlen($content)
            ]);
            
            return $content;
            
        } catch (Exception $e) {
            $this->logger->error('Error generating llms.txt', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Generate minimal llms.txt when no pages are available
     */
    private function generate_minimal_llms_txt($site_name, $site_description) {
        $site_url = get_site_url();
        
        $content = "# " . $site_name . "\n\n";
        
        if (!empty($site_description)) {
            $content .= "> " . $site_description . "\n\n";
        }
        
        $content .= "This website is powered by WordPress and optimized with GeoGuru.\n\n";
        $content .= "## Main Content\n\n";
        $content .= "- [Home Page](" . $site_url . "): Main website homepage\n";
        
        return $content;
    }
    
    /**
     * Whether a permalink is served by this site.
     *
     * Compares hosts ignoring case and a leading "www.". When the site host cannot be
     * determined the URL is kept, so an unusual server configuration never empties the list.
     *
     * @param string $url       Permalink to check.
     * @param string $home_host Lowercased host of home_url(), or '' when unknown.
     * @return bool
     */
    private function is_own_site_url($url, $home_host) {
        if ($home_host === '') {
            return true;
        }
        if (!is_string($url) || $url === '') {
            return false;
        }

        $url_host = wp_parse_url($url, PHP_URL_HOST);
        if (!is_string($url_host) || $url_host === '') {
            return false;
        }

        $strip_www = '/^www\./';

        return preg_replace($strip_www, '', strtolower($url_host)) === preg_replace($strip_www, '', $home_host);
    }

    /**
     * Discover pages on the website
     */
    private function discover_pages() {
        $pages = array();
        
        // Get published posts and pages
        $post_types = array('post', 'page');
        
        // Allow filtering of post types
        $post_types = apply_filters('geoguru_llms_txt_post_types', $post_types);

        // Permalinks are filterable: plugins that turn a page into a link to an external
        // site (a social profile, a playlist, …) make get_permalink() return that URL.
        // Those are not pages of this site, so they must not be listed.
        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $home_host = is_string($home_host) ? strtolower($home_host) : '';

        foreach ($post_types as $post_type) {
            $posts = get_posts(array(
                'post_type' => $post_type,
                'post_status' => 'publish',
                'numberposts' => 100, // Limit to prevent memory issues
                'orderby' => 'date',
                'order' => 'DESC'
            ));

            foreach ($posts as $post) {
                if (!$this->is_own_site_url(get_permalink($post->ID), $home_host)) {
                    continue;
                }

                $pages[] = array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'url' => get_permalink($post->ID),
                    'type' => $post_type,
                    'excerpt' => $this->get_post_summary($post),
                    'date' => $post->post_date,
                    'importance' => $this->calculate_importance($post)
                );
            }
        }
        
        // Sort by importance
        usort($pages, function($a, $b) {
            return $b['importance'] - $a['importance'];
        });
        
        return $pages;
    }
    
    /**
     * Get a summary for a post
     */
    private function get_post_summary($post) {
        // Try to get excerpt first
        if (!empty($post->post_excerpt)) {
            return wp_strip_all_tags($post->post_excerpt);
        }
        
        // Generate excerpt from content
        $content = wp_strip_all_tags($post->post_content);
        $content = wp_trim_words($content, 30, '...');
        
        return $content;
    }
    
    /**
     * Calculate page importance for sorting
     */
    private function calculate_importance($post) {
        $importance = 0;
        
        // Pages are generally more important than posts
        if ($post->post_type === 'page') {
            $importance += 10;
        }
        
        // Recent content gets higher importance
        $days_old = (time() - strtotime($post->post_date)) / (24 * 60 * 60);
        if ($days_old < 30) {
            $importance += 5;
        } elseif ($days_old < 90) {
            $importance += 3;
        }
        
        // Longer content might be more important
        $content_length = strlen($post->post_content);
        if ($content_length > 2000) {
            $importance += 3;
        } elseif ($content_length > 1000) {
            $importance += 2;
        }
        
        return $importance;
    }
    
    /**
     * Format the llms.txt content according to specification
     */
    private function format_llms_txt($site_name, $site_description, $pages, $config) {
        $content = "# " . $site_name . "\n\n";
        
        // Add description if available
        if (!empty($site_description)) {
            $content .= "> " . $site_description . "\n\n";
        }
        
        // Add general information
        $brand_name = function_exists('geoguru_get_display_name') ? geoguru_get_display_name() : 'LovedByAI';
        $content .= "This website is powered by WordPress and optimized with " . $brand_name . " for better AI understanding.\n\n";
        
        // Group pages by type
        $grouped_pages = $this->group_pages_by_type($pages);
        
        // Add main content sections
        foreach ($grouped_pages as $type => $type_pages) {
            if ($type === 'page') {
                $content .= "## Main Pages\n\n";
            } elseif ($type === 'post') {
                $content .= "## Blog Posts\n\n";
            } else {
                $content .= "## " . ucfirst($type) . "\n\n";
            }
            
            $max_items = $config['max_pages_per_section'] ?? 20;
            $items_added = 0;
            
            foreach ($type_pages as $page) {
                if ($items_added >= $max_items) {
                    break;
                }
                
                $title = $this->sanitize_title($page['title']);
                $url = $page['url'];
                $summary = $this->sanitize_summary($page['excerpt']);
                
                if (!empty($summary)) {
                    $content .= "- [{$title}]({$url}): {$summary}\n";
                } else {
                    $content .= "- [{$title}]({$url})\n";
                }
                
                $items_added++;
            }
            
            $content .= "\n";
        }
        
        // Add optional section if enabled
        if ($config['include_optional_sections'] ?? true) {
            $content .= $this->format_optional_section();
        }
        
        return $content;
    }
    
    /**
     * Group pages by their type
     */
    private function group_pages_by_type($pages) {
        $grouped = array();
        
        foreach ($pages as $page) {
            $type = $page['type'];
            if (!isset($grouped[$type])) {
                $grouped[$type] = array();
            }
            $grouped[$type][] = $page;
        }
        
        return $grouped;
    }
    
    /**
     * Format optional section
     */
    private function format_optional_section() {
        $content = "## Optional\n\n";
        
        // Add common WordPress pages
        $optional_pages = array(
            'About' => get_permalink(get_option('page_for_about')),
            'Contact' => get_permalink(get_option('page_for_contact')),
            'Privacy Policy' => get_privacy_policy_url(),
        );
        
        foreach ($optional_pages as $title => $url) {
            if (!empty($url)) {
                $content .= "- [{$title}]({$url}): {$title} page\n";
            }
        }
        
        return $content . "\n";
    }
    
    /**
     * Sanitize title for markdown
     */
    private function sanitize_title($title) {
        return str_replace(array('[', ']'), array('(', ')'), $title);
    }
    
    /**
     * Sanitize summary for markdown
     */
    private function sanitize_summary($summary) {
        // Remove markdown special characters and limit length
        $summary = str_replace(array('[', ']', '(', ')'), '', $summary);
        $summary = wp_trim_words($summary, 20, '...');
        return $summary;
    }
    
    /**
     * Get cached llms.txt content
     */
    private function get_cached_llms_txt() {
        $cache_key = 'geoguru_llms_txt_content';
        return get_transient($cache_key);
    }
    
    /**
     * Cache llms.txt content
     */
    private function cache_llms_txt($content) {
        $cache_key = 'geoguru_llms_txt_content';
        $cache_duration = apply_filters('geoguru_llms_txt_cache_duration', 24 * HOUR_IN_SECONDS);
        
        set_transient($cache_key, $content, $cache_duration);
    }
    
    /**
     * Clear cached llms.txt content
     */
    public function clear_cache() {
        delete_transient('geoguru_llms_txt_content');
        $this->logger->info('llms.txt cache cleared');
    }
    
    /**
     * Get llms.txt configuration
     */
    public function get_config() {
        $defaults = array(
            'enabled' => false,
            'site_name' => get_bloginfo('name'),
            'site_description' => get_bloginfo('description'),
            'include_optional_sections' => true,
            'max_pages_per_section' => 20,
            'post_types' => array('post', 'page')
        );
        
        $config = get_option('geoguru_llms_txt_config', array());
        
        return array_merge($defaults, $config);
    }
    
    /**
     * Update llms.txt configuration
     */
    public function update_config($new_config) {
        $current_config = $this->get_config();
        $updated_config = array_merge($current_config, $new_config);
        
        update_option('geoguru_llms_txt_config', $updated_config);
        
        // Clear cache when config changes
        $this->clear_cache();
        
        $this->logger->info('llms.txt configuration updated', $new_config);
        
        return true;
    }
    
    // AJAX Handlers for Portal App Integration
    
    /**
     * AJAX handler to get llms.txt content
     */
    public function ajax_get_llms_txt() {
        if (!isset($_REQUEST['nonce']) || !wp_verify_nonce(sanitize_text_field( wp_unslash ($_REQUEST['nonce'])), 'geoguru_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $content = $this->get_cached_llms_txt();
        
        if ($content === false) {
            $content = $this->generate_llms_txt();
        }
        
        if ($content === false) {
            wp_send_json_error(array('message' => 'Failed to generate llms.txt content'));
            return;
        }
        
        wp_send_json_success(array(
            'content' => $content,
            'url' => get_site_url() . '/llms.txt',
            'enabled' => $this->is_enabled()
        ));
    }
    
    /**
     * AJAX handler to regenerate llms.txt
     */
    public function ajax_regenerate_llms_txt() {
        if (!isset($_REQUEST['nonce']) || !wp_verify_nonce(sanitize_text_field( wp_unslash ($_REQUEST['nonce'])), 'geoguru_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // Clear cache and regenerate
        $this->clear_cache();
        $content = $this->generate_llms_txt();
        
        if ($content === false) {
            wp_send_json_error(array('message' => 'Failed to regenerate llms.txt content'));
            return;
        }
        
        wp_send_json_success(array(
            'content' => $content,
            'message' => 'llms.txt regenerated successfully'
        ));
    }
}

// Initialize the service on every request (registers hooks)
add_action('init', function() {
    if (!get_option('geoguru_llms_txt_enabled', false)) {
        return;
    }
    $llms_txt_service = GeoGuru_LlmsTxtService::get_instance();
    $llms_txt_service->init(); // Registers hooks that need to run on every request
}, 20); // Priority 20 to ensure it runs after other init hooks 

// Hook to clear cache when posts are updated
add_action('save_post', function($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    
    $llms_txt_service = GeoGuru_LlmsTxtService::get_instance();
    $llms_txt_service->clear_cache();
});

// Hook to clear cache when posts are deleted
add_action('delete_post', function($post_id) {
    $llms_txt_service = GeoGuru_LlmsTxtService::get_instance();
    $llms_txt_service->clear_cache();
});