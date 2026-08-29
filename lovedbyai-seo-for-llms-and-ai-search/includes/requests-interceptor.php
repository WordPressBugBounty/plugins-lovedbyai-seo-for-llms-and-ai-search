<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('GeoGuru_RequestsInterceptor')) {
    class GeoGuru_RequestsInterceptor {
        private static $instance = null;
        private $logger;
        private $is_intercepting = false;
        private $shortcode_rendered = false;
        private $supabase_service;

        public static function get_instance(): GeoGuru_RequestsInterceptor {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            $this->logger = GeoGuru_Logger::get_instance();
            $this->supabase_service = GeoGuru_SupabaseService::get_instance();
        }

        /**
         * Get boolean option value, handling empty strings and null values
         * Returns integer (0 or 1) to ensure WordPress doesn't delete the option
         * 
         * @param string $option_name The option name
         * @param int $default Default value (0 or 1)
         * @return int Returns 0 or 1
         */
        private function get_boolean_option($option_name, $default = 1) {
            $value = get_option($option_name, $default);
            // Handle empty string, null, or false - use default
            if ($value === '' || $value === null || $value === false) {
                return $default;
            }
            // Convert to integer (0 or 1)
            return (int)(bool)$value;
        }

        /**
         * Marketing / click-tracking query params that should never appear on the LLM-view link.
         * Stripping them eliminates cross-visitor leakage from full-page HTML caches and avoids
         * forwarding ad attribution to the AI-rendered version of the page.
         *
         * @return string[]
         */
        private function get_marketing_query_params() {
            return array(
                // Standard UTM
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
                // Ad-network click identifiers
                'fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'ttclid', 'twclid', 'li_fat_id', 'yclid',
                // Common email-platform identifiers
                'mc_cid', 'mc_eid', 'mkt_tok',
            );
        }

        /**
         * Remove known marketing / click-tracking params from a URL.
         */
        private function strip_marketing_params($url) {
            return remove_query_arg($this->get_marketing_query_params(), $url);
        }

        /**
         * Build the LLM-view URL for the current request: strip marketing params, then add llm_view=1.
         */
        private function build_llm_view_url() {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            return add_query_arg('llm_view', '1', $this->strip_marketing_params($request_uri));
        }

        /**
         * Start intercepting requests
         */
        public function start_intercepting() {
            if (!$this->is_intercepting) {
                // Only add the action if not already registered
                if (false === has_action('init', [$this, 'intercept_request'])) {
                    add_action('init', [$this, 'intercept_request']);
                    $this->is_intercepting = true;
                    $this->logger->debug('Request interceptor started', ['wp_hook' => current_action()]);
                }
            }
        }

        /**
         * Stop intercepting requests
         */
        public function stop_intercepting() {
            if ($this->is_intercepting) {
                remove_action('init', [$this, 'intercept_request']);
                $this->is_intercepting = false;
                $this->logger->debug('Request interceptor stopped', ['wp_hook' => current_action()]);
            }
        }

        /**
         * Check if currently intercepting
         */
        public function is_intercepting() {
            return $this->is_intercepting;
        }

        /**
         * Enqueue dynamic styles for LLM version link
         */
        public function enqueue_llm_link_styles() {
            // Check if we should show the link (same logic as display_footer_links)
            $options = get_option('geoguru_llm_version_settings', array());
            
            // Register and enqueue a style handle (using a dummy source or empty)
            wp_register_style('geoguru-llm-link-styles', false, array(), GEOGURU_PLUGIN_VERSION);
            wp_enqueue_style('geoguru-llm-link-styles');
            
            // Get appearance settings
            $link_position = isset($options['link_position']) ? $options['link_position'] : 'center';
            $font_background_color = isset($options['font_background_color']) ? $options['font_background_color'] : '';
            $font_color = isset($options['font_color']) ? $options['font_color'] : '';
            $font_size = isset($options['font_size']) ? $options['font_size'] : 'medium';
            $offset_x = isset($options['offset_x']) ? (int)$options['offset_x'] : 0;
            $offset_y = isset($options['offset_y']) ? (int)$options['offset_y'] : 0;
            
            // Map font sizes to CSS pixel values
            $font_size_map = array(
                'small' => '12px',
                'medium' => '14px',
                'large' => '16px'
            );
            $font_size_css = isset($font_size_map[$font_size]) ? $font_size_map[$font_size] : '14px';
            
            // Generate CSS
            $css_styles = $this->generate_link_css($link_position, $font_background_color, $font_color, $font_size_css, $offset_x, $offset_y);
            
            // Add inline style using WordPress API
            wp_add_inline_style('geoguru-llm-link-styles', wp_strip_all_tags($css_styles));
        }

        /**
         * Enqueue inline JavaScript for LLM version link positioning
         */
        public function enqueue_llm_link_script() {
            // Check if we should show the link (same logic as display_footer_links)
            $options = get_option('geoguru_llm_version_settings', array());
            $show_on_posts = isset($options['show_on_posts']) ? $options['show_on_posts'] : 1;
            $show_on_pages = isset($options['show_on_pages']) ? $options['show_on_pages'] : 1;
            
            $should_show = false;
            if (is_single() && $show_on_posts) {
                $should_show = true;
            }
            if (is_page() || is_home() && $show_on_pages) {
                $should_show = true;
            }
            
            if (!$should_show) {
                return;
            }
            
            $link_position = isset($options['link_position']) ? $options['link_position'] : 'center';
            $css_selector = isset($options['css_selector']) ? $options['css_selector'] : '';
            
            // Only enqueue script if using CSS selector positioning
            if ($link_position !== 'css_selector' || empty($css_selector)) {
                return;
            }
            
            // Register and enqueue a script handle (using empty source since it's inline)
            wp_register_script('geoguru-llm-link-script', false, array(), GEOGURU_PLUGIN_VERSION, true); // true = in footer
            wp_enqueue_script('geoguru-llm-link-script');
            
            // Get the values we need
            $llm_page_url = $this->build_llm_view_url();
            $link_text = isset($options['link_text']) ? $options['link_text'] : 'Hey AI, learn about this page';
            
            // Build the inline script
            $inline_script = "(function() {
                var link = document.createElement('a');
                link.id = 'gg-llm-version-link';
                link.className = 'gg-llm-footer-link';
                link.href = " . wp_json_encode($llm_page_url) . ";
                link.textContent = " . wp_json_encode($link_text) . ";
                link.setAttribute('aria-label', 'View LLM version of this page');
                
                var cssSelector = " . wp_json_encode($css_selector) . ";
                var target = document.querySelector(cssSelector);
                if (target) {
                    target.appendChild(link);
                } else {
                    // Fallback to bottom center if selector not found
                    document.body.appendChild(link);
                    link.style.position = 'fixed';
                    link.style.bottom = '0';
                    link.style.left = '50%';
                    link.style.transform = 'translateX(-50%)';
                }
            })();";
            
            // Add inline script using WordPress API
            wp_add_inline_script('geoguru-llm-link-script', $inline_script);
        }

        /**
         * Shortcode handler for [lovedbyai_link]
         * Renders the LLM link inline and sets flag to skip footer output.
         */
        public function render_llm_link_shortcode($atts) {
            $options = get_option('geoguru_llm_version_settings', array());
            $link_text = isset($options['link_text']) ? $options['link_text'] : 'Hey AI, learn about this page';

            $atts = shortcode_atts(array(
                'text' => $link_text,
                'style' => '',
            ), $atts, 'lovedbyai_link');

            $llm_page_url = $this->build_llm_view_url();

            $this->shortcode_rendered = true;

            $style_attr = !empty($atts['style']) ? ' style="' . esc_attr($atts['style']) . '"' : '';

            return '<a href="' . esc_url($llm_page_url) . '"' . $style_attr . ' aria-label="View LLM version of this page">' . esc_html($atts['text']) . '</a>';
        }

        public function intercept_request() {
            $this->logger->debug('Init request interceptor', [
                'url' => GeoGuru_Utils::get_current_full_url(),
                'method' => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field( wp_unslash ($_SERVER['REQUEST_METHOD'])) : 'unknown',
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field( wp_unslash ($_SERVER['HTTP_USER_AGENT'])) : 'unknown',
                'wp_hook' => current_action(),
            ]);
            
            if ($this->should_skip()) {
                $this->logger->debug('Skipping', ['wp_hook' => current_action()]);
                return;
            }

            // Enqueue client-side LLM source tracking script when enabled and credentials exist
            $site_id = get_option('geoguru_site_id', '');
            $secret_token = get_option('geoguru_secret_token', '');
            $llm_tracking_enabled = $this->get_boolean_option('geoguru_llm_tracking_enabled', 1);
            if ($llm_tracking_enabled && !empty($site_id) && !empty($secret_token)) {
                add_action('wp_enqueue_scripts', array($this, 'enqueue_llm_tracking_script'));
            }

            // Check if plugin is still active
            if (!get_option('geoguru_plugin_active', true)) {
                $this->logger->debug('Plugin deactivated, skipping interception', ['wp_hook' => current_action()]);
                return;
            }

            $this->maybe_disable_litespeed_for_optimizer();

            $optimization_method = get_option('geoguru_optimization_method');
            if (!$optimization_method || $optimization_method === '') {
                $optimization_method = 'llm_link_generator';
            }

            if ($optimization_method === 'llm_link_generator') {
                // Enqueue styles and script properly
                add_action('wp_enqueue_scripts', array($this, 'enqueue_llm_link_styles'));
                add_action('wp_enqueue_scripts', array($this, 'enqueue_llm_link_script'));

                // Register shortcode so users can place the link anywhere via [lovedbyai_link]
                add_shortcode('lovedbyai_link', array($this, 'render_llm_link_shortcode'));

                // Original-page overlay: merge non-visible head fields into the NORMAL WP render.
                // Scoped to this method only — the requests_interceptor method (handle_optimize_on_page
                // below) echoes a full optimized document for bots, which must never be buffered/merged.
                // geoguru_overlay_register() also bails on ?llm_view=1 (that path serves the full document).
                if (function_exists('geoguru_overlay_register')) {
                    geoguru_overlay_register();
                }

                $this->handle_llm_version_optimization();
                return;
            }

            $this->handle_optimize_on_page();
        }

        private function should_skip(): bool {
            return (
                is_admin() ||
                defined('DOING_AJAX') && DOING_AJAX ||
                defined('DOING_CRON') && DOING_CRON ||
                (defined('REST_REQUEST') && REST_REQUEST)
            );
        }

        /**
         * When the optimizer service fetches a page it sends a header
         * so we can disable caching-plugin page optimizations (e.g. LiteSpeed's
         * script deferral / CSS combining) that would pollute the HTML the optimizer
         * processes.
         */
        private function maybe_disable_litespeed_for_optimizer() {
            if (!isset($_SERVER['HTTP_X_GEOGURU_OPTIMIZER'])) {
                return;
            }

            $this->logger->debug('Optimizer fetch detected, disabling LiteSpeed page optimization');
            add_filter('litespeed_can_optm', '__return_false');
        }

        /**
         * Enqueue the client-side LLM source tracking script (no secret is exposed).
         * The script posts to this site's own endpoint first and falls back to the
         * backend directly when that endpoint is unreachable; the direct call
         * carries only the public site id and is authorised by request origin.
         */
        public function enqueue_llm_tracking_script() {
            $proxy_url = rest_url('geoguru/v1/llm-source-event');
            $site_id = get_option('geoguru_site_id', '');
            $backend_url = get_option('geoguru_supabase_url', '');

            $fallback_url = '';
            if (!empty($site_id) && !empty($backend_url)) {
                $fallback_url = rtrim($backend_url, '/') . '/functions/v1/create-llm-source-event';
            }

            $config = array(
                'proxyUrl'    => $proxy_url,
                'fallbackUrl' => $fallback_url,
                'siteId'      => $site_id,
            );

            wp_register_script('geoguru-llm-tracking', false, array(), GEOGURU_PLUGIN_VERSION, true);
            wp_enqueue_script('geoguru-llm-tracking');
            wp_add_inline_script(
                'geoguru-llm-tracking',
                'window.geoguru_llm_tracking = ' . wp_json_encode($config) . ';',
                'before'
            );
            $script = GeoGuru_Utils::inline_js('llm-tracking');
            if ($script !== '') {
                wp_add_inline_script('geoguru-llm-tracking', $script);
            }
        }

        private function handle_llm_version_optimization() {
            $this->logger->debug('Handling LLM link generator', ['wp_hook' => current_action()]);

            // After modification completes, log the crawl and trigger async optimization with the captured content
            add_action('shutdown', function() {
                $this->logger->debug('running shutdown function', ['wp_hook' => current_action()]);
                $site_id = get_option('geoguru_site_id', '');
                $secret_token = get_option('geoguru_secret_token', '');

                if (empty($site_id) || empty($secret_token)) {
                    $this->logger->warning('Site credentials not found, cannot log crawl request', ['wp_hook' => current_action()]);
                    return;
                }

                $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field( wp_unslash ($_SERVER['HTTP_USER_AGENT'])) : '';
                $bot_parameters = GeoGuru_Utils::detect_bot($user_agent);
                if (!$bot_parameters['is_bot']) {
                    $this->logger->debug('Request is not a bot, skipping log request', ['wp_hook' => current_action()]);
                    return;
                }
                $log_data = [
                    'bot_type' => sanitize_text_field($bot_parameters['bot_type']),
                    'bot_name' => sanitize_text_field($bot_parameters['bot_name']),
                    'status' => 'success'
                ];

                // Check if LLM tracking is enabled for this request
                $llm_tracking_enabled = $this->get_boolean_option('geoguru_llm_tracking_enabled', 1);
                if ($llm_tracking_enabled) {
                    $this->supabase_service->create_crawling_log($site_id, $secret_token, $log_data);
                }
            });

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is not required for this request
            if (isset($_GET['llm_view']) && $_GET['llm_view'] == '1') {
                $this->handle_llm_version_page();
            } else {
                // Display footer link with collected URLs
                add_action('wp_footer', array($this, 'display_footer_links'));
            }
        }

        public function handle_llm_version_page() {
            $this->logger->debug('Handling LLM version page', ['wp_hook' => current_action()]);

            // Disable LiteSpeed Cache page optimization (CSS/JS combining, script
            // deferral) for ?llm_view=1 requests.  When LiteSpeed defers scripts it
            // rewrites their type to "litespeed/javascript" and relies on its own
            // loader to re-enable them.  If the page bypasses the LiteSpeed page
            // cache (e.g. because of a Set-Cookie / PHP session), the loader never
            // fires and all JS/CSS remains disabled, breaking the page.  Disabling
            // page optimization here is safe: when CDN content IS found the plugin
            // calls exit() before LiteSpeed can act, and when CDN content is NOT
            // found the page falls through to normal WordPress rendering unharmed.
            add_filter('litespeed_can_optm', '__return_false');

            // Check if automatic optimization is enabled
            $automatic_optimization_enabled = $this->get_boolean_option('geoguru_automatic_optimization_enabled', 1);
            if (!$automatic_optimization_enabled) {
                $this->logger->debug('Automatic optimization is disabled, skipping LLM version page', ['wp_hook' => current_action()]);
                return;
            }
            
            $optimized_content = $this->get_optimized_content();

            if (!$optimized_content) {
                return;
            }
            
            $this->logger->debug('Registering template_redirect to output optimized content', ['wp_hook' => current_action()]);
            
            // Use template_redirect instead of template_include - fires earlier and allows us to stop processing. some themes continue rendering using template include although it returns null, therefore, it might cause duplicated content issues. the exist is neccesary to avoid this.
            add_action('template_redirect', function() use ($optimized_content) {
                if (!$optimized_content) {
                    $this->logger->debug('Optimized content not found, returning original content', ['wp_hook' => current_action()]);    
                    return;
                }
                $this->logger->debug('Displaying LLM version page', ['wp_hook' => current_action()]);
                
                // Set proper headers
                status_header(200);
                header('Content-Type: text/html; charset=UTF-8');
                
                // Output our optimized content (complete HTML document with meta tags already included)
                // SECURITY NOTE: Content is intentionally unescaped because:
                // 1. Content is a complete HTML document that must be served as-is to preserve functionality
                // 2. Content originates from our trusted CDN (not user-generated or untrusted source)
                // 3. Content is generated by our service from the website owner's own pages
                // 4. wp_kses would strip necessary HTML/JavaScript and break page functionality
                // 5. Access is controlled (only served to verified bot requests with site credentials)
                // 6. Content is isolated via template_redirect + exit, preventing WordPress template processing
                // Exception request submitted to WordPress Plugin Review Team - awaiting approval
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $optimized_content;
                
                // Stop WordPress from processing further - prevents duplication
                exit;
            }, PHP_INT_MAX);
        }

        /**
         * Generate dynamic CSS for LLM link based on appearance settings
         * 
         * @param string $position Link position (left, right, center, css_selector)
         * @param string $bg_color Background color (hex or empty)
         * @param string $text_color Text color (hex or empty)
         * @param string $font_size Font size in pixels
         * @param int $offset_x Horizontal offset in pixels
         * @param int $offset_y Vertical offset in pixels
         * @return string Generated CSS string
         */
        private function generate_link_css($position, $bg_color, $text_color, $font_size, $offset_x, $offset_y) {
            $css = '.gg-llm-footer-links-container {';
            $css .= 'position: absolute;';
            $css .= 'display: flex;';
            $css .= 'flex-direction: row;';
            $css .= 'direction: ltr;';
            $css .= 'gap: 0.25rem;';
            $css .= 'font-size: ' . esc_attr($font_size) . ';';
            $css .= 'z-index: 999 !important;';
            
            // Apply offsets
            if ($offset_x !== 0 || $offset_y !== 0) {
                $css .= 'transform: ';
                if ($position === 'center') {
                    $css .= 'translateX(calc(-50% + ' . floatval($offset_x) . 'px)) translateY(' . floatval($offset_y) . 'px);';
                } else {
                    $css .= 'translateX(' . floatval($offset_x) . 'px) translateY(' . floatval($offset_y) . 'px);';
                }
            } elseif ($position === 'center') {
                $css .= 'transform: translateX(-50%);';
            }
            
            $css .= '}';
            
            // Position classes
            $css .= '.gg-llm-footer-links-container-center { left: 50%; }';
            $css .= '.gg-llm-footer-links-container-center > a { transform: translateX(-50%); }';
            $css .= '.gg-llm-footer-links-container-right { right: 140px; }';
            $css .= '.gg-llm-footer-links-container-left { left: 0; }';
            
            // Link styles
            $css .= '.gg-llm-footer-link {';
            $css .= 'bottom: 0;';
            $css .= 'z-index: 999 !important;';
            $css .= 'visibility: visible;';
            $css .= 'position: absolute;';
            $css .= 'width: max-content;';
            
            if (!empty($bg_color)) {
                $css .= 'background-color: ' . esc_attr($bg_color) . ' !important;';
            }
            if (!empty($text_color)) {
                $css .= 'color: ' . esc_attr($text_color) . ' !important;';
            }
            
            $css .= '}';

            return $css;
        }

        public function display_footer_links() {
            // Skip footer output if shortcode already rendered the link
            if ($this->shortcode_rendered) {
                $this->logger->debug('LLM link already rendered via shortcode, skipping footer', ['wp_hook' => current_action()]);
                return;
            }

            $this->logger->debug('Displaying footer links', ['wp_hook' => current_action()]);
            $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            
            // Get plugin settings
            $options = get_option('geoguru_llm_version_settings', array());
            $show_on_posts = isset($options['show_on_posts']) ? $options['show_on_posts'] : 1;
            $show_on_pages = isset($options['show_on_pages']) ? $options['show_on_pages'] : 1;
            $link_text = isset($options['link_text']) ? $options['link_text'] : 'Hey AI, learn about this page';
            $link_position = isset($options['link_position']) ? $options['link_position'] : 'center';
            $css_selector = isset($options['css_selector']) ? $options['css_selector'] : '';

            
            // Check if we should show the link
            $should_show = false;
            
            if (is_single() && $show_on_posts) {
                $should_show = true;
            }
            
            if (is_page() || is_home() && $show_on_pages) {
                $should_show = true;
            }
            
            if (!$should_show) {
                return;
            }
            
            $this->logger->debug('Displaying footer links', array('url' => $request_uri, 'wp_hook' => current_action()));
            
            $llm_page_url = $this->build_llm_view_url();
            
            // Check if no CSS Selector is set, otherwise, use the standard positioning.
            if ($link_position !== 'css_selector' || empty($css_selector)) {
                // Standard positioning (left, right, center)
                echo '<div class="gg-llm-footer-links-container gg-llm-footer-links-container-' . esc_attr($link_position) . '">';
                echo '<a id="gg-llm-version-link" class="gg-llm-footer-link" href="' . esc_url($llm_page_url) . '" aria-label="View LLM version of this page">' . esc_html($link_text) . '</a>';
                echo '</div>';
                // Fix 1: hide link when it causes a double scrollbar (both html+body are scroll containers)
                // Fix 2: fall back to position:fixed when absolute positioning lands off-screen (body has no height)
                echo '<script>(function(){var c=document.querySelector(".gg-llm-footer-links-container");if(!c)return;var h=document.documentElement,b=document.body,hs=getComputedStyle(h),bs=getComputedStyle(b);var hy=hs.overflowY,by=bs.overflowY;var both=(hy==="auto"||hy==="scroll")&&(by==="auto"||by==="scroll");if(both){var before=h.scrollHeight;c.style.display="none";var after=h.scrollHeight;c.style.display="";if(after<before){c.style.display="none";return;}}var r=c.getBoundingClientRect();if(r.bottom<=0){c.style.position="fixed";c.style.bottom="0";}})();</script>';
            }
        }

        private function handle_optimize_on_page() {
            $site_id = get_option('geoguru_site_id', '');
            $secret_token = get_option('geoguru_secret_token', '');
    
            if (empty($site_id) || empty($secret_token)) {
                $this->logger->warning('Site credentials not found, cannot log crawl request', ['wp_hook' => current_action()]);
                return;
            }

            // Check if automatic optimization is enabled
            $automatic_optimization_enabled = $this->get_boolean_option('geoguru_automatic_optimization_enabled', 1);
            if (!$automatic_optimization_enabled) {
                $this->logger->debug('Automatic optimization is disabled, skipping interception', ['wp_hook' => current_action()]);
                return;
            }

            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field( wp_unslash ($_SERVER['HTTP_USER_AGENT'])) : '';
            $bot_parameters = GeoGuru_Utils::detect_bot($user_agent);
            if (!$bot_parameters['is_bot']) {
                $this->logger->debug('Request is not a bot, skipping interception', ['wp_hook' => current_action()]);
                return;
            }
            $this->logger->info('Request is a bot, starting interception', ['wp_hook' => current_action()]);
    
            $serving_optimized_content = $this->get_optimized_content();

            add_filter('template_include', function($template) use ($serving_optimized_content, $site_id, $secret_token) {
                if ($serving_optimized_content) {
                    // SECURITY NOTE: Content is intentionally unescaped because:
                    // 1. Content is a complete HTML document that must be served as-is to preserve functionality
                    // 2. Content originates from our trusted CDN (not user-generated or untrusted source)
                    // 3. Content is generated by our service from the website owner's own pages
                    // 4. wp_kses would strip necessary HTML/JavaScript and break page functionality
                    // 5. Access is controlled (only served to verified bot requests with site credentials)
                    // 6. Content is isolated via template_include filter, preventing default template loading
                    // Exception request submitted to WordPress Plugin Review Team - awaiting approval
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo $serving_optimized_content;
                    return null; // Prevent default template loading
                }
                return $template;
            }, PHP_INT_MAX);

    
            // After the response, log the crawl and trigger async optimization with the captured content
            add_action('shutdown', function() use ($site_id, $secret_token, $serving_optimized_content, $bot_parameters) {
                $log_data = [
                    'bot_type' => sanitize_text_field($bot_parameters['bot_type']),
                    'bot_name' => sanitize_text_field($bot_parameters['bot_name']),
                    'status' => $serving_optimized_content ? 'success' : 'processing'
                ];
                
                // Check if LLM tracking is enabled for this request
                $llm_tracking_enabled = $this->get_boolean_option('geoguru_llm_tracking_enabled', 1);
                if ($llm_tracking_enabled) {
                    // TODO: check if it is possible to do this in the background
                    $this->supabase_service->create_crawling_log($site_id, $secret_token, $log_data);
                }
            });
        }
        private function get_optimized_content() {
            $url = GeoGuru_Utils::get_current_full_url();
            $website_id = get_option('geoguru_site_id', '');
    
            $optimized_content = $this->get_optimized_content_from_cdn($url, $website_id);
    
            if ($optimized_content) {
                return $optimized_content;
            }
            return false;
        }
    
        private function get_optimized_content_from_cdn($url, $website_id) {
            // Get CDN base URL from configuration
            $cdn_url = get_option('geoguru_optimized_content_cdn_url');
            if (empty($cdn_url)) {
                $this->logger->error('CDN URL not found, cannot fetch optimized content', ['wp_hook' => current_action()]);
                return null;
            }
            
            // Hostname + pathname only; use path as-is (percent-encoded) and lowercase to match optimizer (Node URL.pathname is not decoded)
            $parsed_url = wp_parse_url($url);
            $hostname = isset($parsed_url['host']) ? strtolower($parsed_url['host']) : '';
            $path_raw = $parsed_url['path'] ?? '/';
            $pathname = $path_raw === '' ? '/' : strtolower($path_raw);
            $normalized_path = $hostname . $pathname;
            
            // Generate the same hash as the optimizer service (SHA256 of normalized path)
            $route_hash = hash('sha256', $normalized_path);
            
            // Construct CDN URL: {website_id}/{route_hash}.html (must match optimizer service)
            $optimized_content_url = "$cdn_url/$website_id/$route_hash.html";
            
            $this->logger->debug('Fetching optimized content from CDN', [
                'url' => $url,
                'normalized_path' => $normalized_path,
                'route_hash' => $route_hash,
                'cdn_url' => $optimized_content_url,
                'wp_hook' => current_action()
            ]);
            
            // Fetch optimized content directly from CDN
            $response = wp_remote_get($optimized_content_url, [
                'timeout' => 10, // Faster timeout for CDN
                'headers' => [
                    'User-Agent' => 'GeoGuru-WordPress-Plugin/1.0'
                ]
            ]);
            
            if (is_wp_error($response)) {
                $this->logger->error('WordPress HTTP Error during CDN fetch', [
                    'error' => $response->get_error_message(),
                    'error_code' => $response->get_error_code(),
                    'cdn_url' => $optimized_content_url
                ]);
                return null;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $this->logger->info('CDN response', [
                'response_code' => $response_code,
                'cdn_url' => $optimized_content_url,
                'wp_hook' => current_action()
            ]);
            
            if ($response_code === 200) {
                $optimized_content = wp_remote_retrieve_body($response);
                if (strlen($optimized_content) > 0) {
                $this->logger->info('Successfully fetched optimized content from CDN', [
                        'content_length' => strlen($optimized_content),
                        'wp_hook' => current_action()
                    ]);
                    return $optimized_content;
                }
                $this->logger->warning('Optimized content is empty, will trigger async optimization', [
                    'cdn_url' => $optimized_content_url,
                    'wp_hook' => current_action()
                ]);
                return null;
            } elseif ($response_code === 404) {
                $this->logger->info('Optimized content not found on CDN, will trigger async optimization', [
                    'cdn_url' => $optimized_content_url,
                    'wp_hook' => current_action()
                ]);
                // Content not found - this will trigger async optimization in the main flow
                return null;
            } else {
                $this->logger->warning('Unexpected CDN response', [
                    'response_code' => $response_code,
                    'cdn_url' => $optimized_content_url,
                    'wp_hook' => current_action()
                ]);
                return null;
            }
        }
    }
}

