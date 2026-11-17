<?php

defined('ABSPATH') || exit;

class GeoGuru_WebmcpToolsService {

    const OPTION = 'geoguru_webmcp_tools';
    const LOCK = 'geoguru_webmcp_tools_lock';
    const LOCK_TTL = 30;
    const FRESH_SECONDS = 600;
    const OUTAGE_RETRY_SECONDS = 300;
    const GOOD_SECONDS = 86400;

    private static $instance = null;
    private $logger;
    private $scheduled = false;

    protected function __construct() {
        $this->logger = GeoGuru_Logger::get_instance();
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function register_hooks() {
        add_action('geoguru_webmcp_refresh', array(__CLASS__, 'run_scheduled_refresh'));
    }

    public static function run_scheduled_refresh() {
        self::get_instance()->refresh_now();
    }

    protected function now() {
        return time();
    }

    public function get_copy() {
        $copy = get_option(self::OPTION, array());
        return is_array($copy) ? $copy : array();
    }

    public function last_kind() {
        $copy = $this->get_copy();
        return isset($copy['last_kind']) ? $copy['last_kind'] : null;
    }

    public function has_tools() {
        $copy = $this->get_copy();
        if (empty($copy['allowed'])) {
            return false;
        }
        if (empty($copy['manifest']['tools']) || !is_array($copy['manifest']['tools'])) {
            return false;
        }
        $good_at = isset($copy['good_at']) ? (int) $copy['good_at'] : 0;
        return ($this->now() - $good_at) <= self::GOOD_SECONDS;
    }

    public function needs_refresh() {
        $copy = $this->get_copy();
        if (empty($copy) || !isset($copy['fetched_at'])) {
            return true;
        }
        $age = $this->now() - (int) $copy['fetched_at'];
        $limit = $this->last_kind() === 'outage' ? self::OUTAGE_RETRY_SECONDS : self::FRESH_SECONDS;
        return $age > $limit;
    }

    public function refresh_now($force = false) {
        if (get_transient(self::LOCK)) {
            return 'locked';
        }
        set_transient(self::LOCK, 1, self::LOCK_TTL);

        $kind = 'outage';
        try {
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete(self::OPTION, 'options');
            }
            if (!$force && !$this->needs_refresh()) {
                delete_transient(self::LOCK);
                return 'fresh';
            }
            $answer = GeoGuru_SupabaseService::get_instance()->get_webmcp_tools_via_edge(
                get_option('geoguru_site_id', ''),
                get_option('geoguru_secret_token', '')
            );
            $kind = $this->apply_answer(is_array($answer) ? $answer : array('kind' => 'outage'));
        } catch (Throwable $e) {
            try {
                $this->logger->warning('WebMCP tools refresh failed', array('error' => $e->getMessage()));
                $this->apply_answer(array('kind' => 'outage'));
            } catch (Throwable $inner) {
                $kind = 'outage';
            }
        }

        delete_transient(self::LOCK);
        return $kind;
    }

    private function apply_answer($answer) {
        $now = $this->now();
        $kind = isset($answer['kind']) ? $answer['kind'] : 'outage';
        $copy = $this->get_copy();

        if ($kind === 'allowed' && isset($answer['manifest']) && is_array($answer['manifest'])) {
            $copy = array(
                'allowed' => true,
                'manifest' => $answer['manifest'],
                'executors' => isset($answer['executors']) && is_array($answer['executors']) ? $answer['executors'] : array(),
                'fetched_at' => $now,
                'good_at' => $now,
                'last_kind' => 'allowed',
            );
        } elseif ($kind === 'not_allowed' || $kind === 'invalid') {
            $copy = array(
                'allowed' => false,
                'manifest' => null,
                'executors' => array(),
                'fetched_at' => $now,
                'good_at' => $now,
                'last_kind' => $kind,
            );
        } else {
            $kind = 'outage';
            $good_at = isset($copy['good_at']) ? (int) $copy['good_at'] : 0;
            $copy['fetched_at'] = $now;
            $copy['last_kind'] = 'outage';
            $copy['good_at'] = $good_at;
            if (($now - $good_at) > self::GOOD_SECONDS) {
                $copy['allowed'] = false;
                $copy['manifest'] = null;
                $copy['executors'] = array();
            }
            if (!isset($copy['allowed'])) {
                $copy['allowed'] = false;
                $copy['manifest'] = null;
                $copy['executors'] = array();
            }
        }

        update_option(self::OPTION, $copy, false);
        return $kind;
    }

    public function schedule_refresh() {
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;

        $service = $this;
        $finish = null;
        if (function_exists('fastcgi_finish_request')) {
            $finish = 'fastcgi_finish_request';
        } elseif (function_exists('litespeed_finish_request')) {
            $finish = 'litespeed_finish_request';
        }
        if ($finish !== null) {
            add_action('shutdown', function () use ($service, $finish) {
                $finish();
                $service->refresh_now();
            });
            return;
        }

        wp_schedule_single_event($this->now(), 'geoguru_webmcp_refresh');

        if ($this->is_overdue()) {
            add_action('shutdown', function () use ($service) {
                if (function_exists('wp_ob_end_flush_all')) {
                    wp_ob_end_flush_all();
                }
                flush();
                $service->refresh_now();
            });
        }
    }

    private function is_overdue() {
        $copy = $this->get_copy();
        if (!isset($copy['fetched_at'])) {
            return true;
        }
        return ($this->now() - (int) $copy['fetched_at']) > (2 * self::FRESH_SECONDS);
    }

    public function executor_source($tool_name) {
        $copy = $this->get_copy();
        if (empty($copy['manifest']['tools']) || !is_array($copy['manifest']['tools'])) {
            return null;
        }
        foreach ($copy['manifest']['tools'] as $tool) {
            if (isset($tool['name']) && $tool['name'] === $tool_name) {
                $executor = isset($tool['executor']) ? $tool['executor'] : null;
                if ($executor !== null && isset($copy['executors'][$executor]) && is_string($copy['executors'][$executor])) {
                    return $copy['executors'][$executor];
                }
                return null;
            }
        }
        return null;
    }

    public function manifest_tools() {
        $copy = $this->get_copy();
        if (empty($copy['manifest']['tools']) || !is_array($copy['manifest']['tools'])) {
            return array();
        }
        return $copy['manifest']['tools'];
    }

    public function tool_summaries() {
        $copy = $this->get_copy();
        $summaries = array();
        if (empty($copy['manifest']['tools']) || !is_array($copy['manifest']['tools'])) {
            return $summaries;
        }
        foreach ($copy['manifest']['tools'] as $tool) {
            $summaries[] = array(
                'name' => isset($tool['name']) ? $tool['name'] : '',
                'title' => isset($tool['title']) ? $tool['title'] : '',
                'description' => isset($tool['description']) ? $tool['description'] : '',
            );
        }
        return $summaries;
    }
}
