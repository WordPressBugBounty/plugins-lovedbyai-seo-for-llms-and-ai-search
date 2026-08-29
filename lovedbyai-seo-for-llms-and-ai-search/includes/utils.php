<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * GeoGuru Utility Functions
 * 
 * Shared utility functions used across the plugin
 */
class GeoGuru_Utils {
    
    /**
     * Get the current full URL including protocol, host, and request URI
     * @return string
     */
    public static function get_current_full_url() {
        // Start with the site URL to get protocol and host
        $site_url = get_site_url();
        
        // Get the request URI (path + query string)
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        
        // Parse the site URL to get components
        $parsed_site_url = wp_parse_url($site_url);
        
        // Construct the full URL
        $protocol = $parsed_site_url['scheme'] ?? 'http';
        $host = $parsed_site_url['host'] ?? (isset($_SERVER['HTTP_HOST']) ? esc_url_raw( wp_unslash($_SERVER['HTTP_HOST'])) : 'localhost');
        $port = '';
        
        // Add port if it's not standard (80 for HTTP, 443 for HTTPS)
        if (isset($parsed_site_url['port'])) {
            $port = ':' . $parsed_site_url['port'];
        }
        
        return $protocol . '://' . $host . $port . $request_uri;
    }


    /**
     * User agents we recognise, grouped by category.
     *
     * This is the ONLY bot detection that runs on a live request: it gates both
     * the crawling-log write and the optimized-content swap in
     * requests-interceptor.php, so a token missing here means the bot is neither
     * reported to the customer nor served the optimized page. Keep it in sync
     * with packages/common/src/crawlers.ts (the documented list, and the
     * portal's Mentions allowlist) — packages/common/src/__tests__/unit/crawlers.test.ts
     * fails the build when the two drift.
     *
     * Matching is a case-insensitive substring test over the categories in the
     * order written below and, within a category, in array order. A token that
     * is a substring of another must therefore come AFTER it, or it shadows the
     * more specific one; tests/bot-detection-test.php enforces that invariant.
     */
    private static $bot_detection_map = array(
        // AI Training Crawlers (Success)
        // GoogleOther and Google-Extended stay here, unchanged, even though
        // crawlers.ts lists them under aiOnDemandFetchers. That split is the one
        // divergence KNOWN_DIVERGENCES in crawlers.test.ts tolerates: bot_type is
        // frozen at write time and the daily-stats rollup keys on it, so moving
        // them without a row backfill would contradict stored history and shift
        // Mentions retroactively. Reclassifying them is its own PR.
        'AI Training' => array(
            'GPTBot', 'ClaudeBot', 'anthropic-ai', 'Google-Extended',
            'GoogleOther', 'Google-CloudVertexBot', 'Amazonbot',
            'Bytespider', 'Applebot-Extended', 'cohere-ai',
            'FacebookBot', 'Meta-ExternalAgent', 'Meta-WebIndexer',
            'ImagesiftBot', 'Diffbot', 'CCBot',
            'DeepSeekBot', 'DeepSeek-Crawler'
        ),
        // AI Search Index Crawlers (Success) — build an AI search index queried
        // later by ChatGPT Search, Perplexity, You.com (not a live user fetch).
        // Checked before 'AI On Demand' so PerplexityBot matches here, not Perplexity-User.
        'AI Search' => array(
            'OAI-SearchBot', 'PerplexityBot', 'Claude-SearchBot', 'YouBot'
        ),
        // AI On-Demand Fetchers (Success) — a live fetch made while answering one
        // person's prompt. get_attribution_data counts only this category as a
        // Mention, so a miss here costs the customer a visible number.
        // Google-NotebookLM is the former Gemini Notebook agent (supported until
        // August 2026); Google-GeminiNotebook replaces it, and Google-Agent is what
        // a Google-hosted agent sends when it browses on a user's request — that
        // trio is the Gemini on-demand coverage. GoogleOther and Google-Extended
        // stay under 'AI Training' above, unchanged (see the note there).
        // Bare 'Perplexity' is a catch-all and must stay LAST of the Perplexity
        // tokens so Perplexity-User keeps its own name (PerplexityBot is already
        // safe: 'AI Search' is checked first).
        // xAI's tokens are documented but not observed in real traffic (Grok
        // fetches with a spoofed browser UA), so they are insurance only — Grok
        // visits reach us through the referral path, as the synthetic
        // 'Grok-User' written by the create-llm-source-event edge function.
        'AI On Demand' => array(
            'ChatGPT-User', 'Claude-User', 'Claude-Web',
            'DuckAssistBot', 'Meta-ExternalFetcher',
            'Google-NotebookLM', 'Google-GeminiNotebook', 'Google-Agent',
            'MistralAI-User', 'Perplexity-User', 'Perplexity',
            'DeepSeek-User',
            'Grok-User', 'GrokBot', 'Grok-bot', 'xAI-Bot'
        ),
        // Search Engine Bots (Ignored)
        'Search Engine' => array(
            'Googlebot', 'Bingbot', 'Slurp', 'DuckDuckBot', 
            'Baiduspider', 'YandexBot', 'Sogou', 'Exabot', 
            'Applebot', 'LinkedInBot', 'Twitterbot', 'Pinterestbot', 'Yeti'
        ),
        // Social Media Bots (Ignored)
        'Social Media' => array(
            'facebookexternalhit', 'LinkedInBot', 'Twitterbot', 'Pinterestbot'
        ),
        // SEO Tools Bots (Ignored)
        'SEO Tools' => array(
            'AhrefsBot', 'SemrushBot', 'Rogerbot', 'Majestic-12'
        )
    );

    /**
     * Marker carried by every request the crawlability checker makes.
     * Mirrors CRAWLABILITY_PROBE_UA_MARKER in @geoguru/common crawlabilityTypes.ts.
     * Brand-free on purpose: it shows up in this site's own access logs, and a
     * white-labelled install must not reveal the upstream vendor there.
     */
    const CRAWLABILITY_PROBE_UA_MARKER = 'crawlability-check';

    public static function detect_bot($user_agent) {
        $ua_lower = strtolower($user_agent);

        // Our own crawlability checker probes the site once per AI crawler,
        // presenting that crawler's product token so the site's WAF rules fire
        // as they would for the real bot. That also matches the substring
        // checks below, so without this guard every check would record ~25
        // fabricated AI-bot hits and serve them optimized content. Not a bot.
        if (strpos($ua_lower, self::CRAWLABILITY_PROBE_UA_MARKER) !== false) {
            return array(
                'bot_name' => null,
                'bot_type' => 'unknown',
                'is_bot' => false,
                'is_success' => false
            );
        }

        // Check each bot type in priority order (AI bots first)
        foreach (self::$bot_detection_map as $bot_type => $bots) {
            foreach ($bots as $bot_name) {
                if (strpos($ua_lower, strtolower($bot_name)) !== false) {
                    return array(
                        'bot_name' => $bot_name,
                        'bot_type' => $bot_type,
                        'is_bot' => true
                    );
                }
            }
        }
        
        return array(
            'bot_name' => null,
            'bot_type' => 'unknown',
            'is_bot' => false,
            'is_success' => false
        );
    }
    
    /**
     * Sanitize a string for logging (truncate and remove sensitive data)
     * @param string $data The data to sanitize
     * @param int $max_length Maximum length to keep
     * @return string
     */
    public static function sanitize_for_logging($data, $max_length = 200) {
        if (empty($data)) {
            return 'empty';
        }
        
        // Remove sensitive patterns (basic implementation)
        $data = preg_replace('/[a-f0-9]{32,}/', '[REDACTED_TOKEN]', $data);
        $data = preg_replace('/password[=:]\s*[^\s&]+/i', 'password=[REDACTED]', $data);
        $data = preg_replace('/token[=:]\s*[^\s&]+/i', 'token=[REDACTED]', $data);
        
        // Truncate if too long
        if (strlen($data) > $max_length) {
            $data = substr($data, 0, $max_length) . '...';
        }
        
        return $data;
    }
    
    /**
     * Validate hex color format
     * 
     * @param string $color Hex color string (e.g., '#FFFFFF') or empty string
     * @return bool True if valid hex color or empty string, false otherwise
     */
    public static function validate_hex_color($color) {
        if (empty($color)) {
            return true; // Empty is valid (uses default)
        }
        return preg_match('/^#[a-fA-F0-9]{6}$/', $color) === 1;
    }

    /**
     * Load an inline script from includes/js/, preferring the minified build
     * (<name>.min.js) over the readable source (<name>.js).
     *
     * @param string $name Script basename without extension, e.g. 'llm-tracking'.
     * @return string JavaScript source, or empty string if neither file is readable.
     */
    public static function inline_js($name) {
        $dir = plugin_dir_path(__FILE__) . 'js/';
        $candidates = array($name . '.min.js', $name . '.js');
        foreach ($candidates as $file) {
            $path = $dir . $file;
            if (is_readable($path)) {
                $script = file_get_contents($path);
                if (is_string($script) && $script !== '') {
                    return $script;
                }
            }
        }
        GeoGuru_Logger::get_instance()->error('Inline script file missing or unreadable', array('script' => $name));
        return '';
    }
}
