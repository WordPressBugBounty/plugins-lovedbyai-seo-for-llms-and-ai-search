<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// packages/wp-plugin/includes/config-service.php
// Centralized config service for loading and exposing environment variables

if (!class_exists('GeoGuru_ConfigService')) {
    class GeoGuru_ConfigService {
        private static $instance = null;
        private $env = [];

        private function __construct() {
            // Load .env if not already loaded
            if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
                if (class_exists('Dotenv\\Dotenv')) {
                    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
                    $this->env = $dotenv->safeLoad();
                }
            }
        }

        public static function get_instance() {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function get($key, $default = null) {
            if (isset($this->env[$key])) {
                return $this->env[$key];
            }
            $value = getenv($key);
            return $value !== false ? $value : $default;
        }

        public function all() {
            return $this->env;
        }
    }
}
