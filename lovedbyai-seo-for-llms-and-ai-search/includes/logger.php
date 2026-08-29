<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * GeoGuru Plugin Logger
 * 
 * Provides structured logging with multiple log levels, rotation, and context support.
 * Logs to both WordPress error log and custom plugin log file.
 */
class GeoGuru_Logger {
    private $config;
    // Log levels (following PSR-3 standard)
    const EMERGENCY = 'emergency';
    const ALERT     = 'alert';
    const CRITICAL  = 'critical';
    const ERROR     = 'error';
    const WARNING   = 'warning';
    const NOTICE    = 'notice';
    const INFO      = 'info';
    const DEBUG     = 'debug';
    
    private static $instance = null;
    private $log_file;
    private $max_file_size;
    private $max_files;
    private $min_log_level;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Only log if WP_DEBUG is enabled
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            $this->log_file = null;
            $this->max_file_size = 10 * 1024 * 1024; // 10MB
            $this->max_files = 5;
            $this->min_log_level = get_option('geoguru_log_level', self::ERROR);
            return;
        }
        
        // Use WordPress uploads directory instead of plugin directory
        // Plugin folders are deleted during upgrades, so we must store logs outside
        $upload_dir = wp_upload_dir();
        
        if ($upload_dir['error']) {
            // Don't write logs if uploads directory is not available
            $this->log_file = null;
            $this->max_file_size = 10 * 1024 * 1024; // 10MB
            $this->max_files = 5;
            $this->min_log_level = get_option('geoguru_log_level', self::ERROR);
            return;
        }
        
        // Create plugin-specific folder in uploads directory
        $log_dir = $upload_dir['basedir'] . '/lovedbyai';
        
        // Ensure log directory exists
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        // Always ensure security files are up to date
        $this->ensure_security_files();
        
        $this->log_file = $log_dir . '/lovedbyai.log';
        $this->max_file_size = 10 * 1024 * 1024; // 10MB
        $this->max_files = 5;
        $this->min_log_level = get_option('geoguru_log_level', self::ERROR);
    }
    
    /**
     * Ensure security files (.htaccess and index.php) are created/updated
     * This method always updates the files, regardless of whether they exist
     * Called during plugin activation and updates to ensure security
     * 
     * @return void
     */
    public function ensure_security_files() {
        // Only create security files if WP_DEBUG is enabled
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        // Use WordPress uploads directory instead of plugin directory
        $upload_dir = wp_upload_dir();
        
        if ($upload_dir['error']) {
            // Cannot create security files if uploads directory is not available
            return;
        }
        
        // Create plugin-specific folder in uploads directory
        $log_dir = $upload_dir['basedir'] . '/lovedbyai';
        
        // Ensure log directory exists
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        // Always create/update .htaccess file (support both Apache 2.2 and 2.4)
        // Include both directory-level and file-specific rules for maximum protection
        $htaccess_file = $log_dir . '/.htaccess';
        $htaccess_content = "# LovedByAI Log Directory Protection\n";
        $htaccess_content .= "# Deny access to all files in this directory\n\n";
        
        // Disable directory listing
        $htaccess_content .= "Options -Indexes\n\n";
        
        // File-specific rules for log files (most restrictive)
        $htaccess_content .= "# Block access to all .log files\n";
        $htaccess_content .= "<FilesMatch \"\\.log($|\\.[0-9]+)$\">\n";
        $htaccess_content .= "  # Apache 2.2\n";
        $htaccess_content .= "  <IfModule !mod_authz_core.c>\n";
        $htaccess_content .= "    Order Deny,Allow\n";
        $htaccess_content .= "    Deny from all\n";
        $htaccess_content .= "  </IfModule>\n";
        $htaccess_content .= "  # Apache 2.4+\n";
        $htaccess_content .= "  <IfModule mod_authz_core.c>\n";
        $htaccess_content .= "    Require all denied\n";
        $htaccess_content .= "  </IfModule>\n";
        $htaccess_content .= "</FilesMatch>\n\n";
        
        // Directory-level protection (fallback)
        $htaccess_content .= "# Deny access to all files in directory\n";
        $htaccess_content .= "# Apache 2.2\n";
        $htaccess_content .= "<IfModule !mod_authz_core.c>\n";
        $htaccess_content .= "  Order Deny,Allow\n";
        $htaccess_content .= "  Deny from all\n";
        $htaccess_content .= "</IfModule>\n\n";
        $htaccess_content .= "# Apache 2.4+\n";
        $htaccess_content .= "<IfModule mod_authz_core.c>\n";
        $htaccess_content .= "  Require all denied\n";
        $htaccess_content .= "</IfModule>\n";
        
        file_put_contents($htaccess_file, $htaccess_content);
        
        // Always create/update index.php to prevent directory listing
        $index_file = $log_dir . '/index.php';
        file_put_contents($index_file, "<?php\n// Silence is golden.\n");
    }
    
    /**
     * Log a message at the specified level
     */
    public function log($level, $message, array $context = []) {
        if (!$this->should_log($level)) {
            return;
        }
        
        $formatted_message = $this->format_message($level, $message, $context);
        
        // Log to custom file
        $this->write_to_file($formatted_message);
    }
    
    /**
     * Convenience methods for each log level
     */
    public function emergency($message, array $context = []) {
        $this->log(self::EMERGENCY, $message, $context);
    }
    
    public function alert($message, array $context = []) {
        $this->log(self::ALERT, $message, $context);
    }
    
    public function critical($message, array $context = []) {
        $this->log(self::CRITICAL, $message, $context);
    }
    
    public function error($message, array $context = []) {
        $this->log(self::ERROR, $message, $context);
    }
    
    public function warning($message, array $context = []) {
        $this->log(self::WARNING, $message, $context);
    }
    
    public function notice($message, array $context = []) {
        $this->log(self::NOTICE, $message, $context);
    }
    
    public function info($message, array $context = []) {
        $this->log(self::INFO, $message, $context);
    }
    
    public function debug($message, array $context = []) {
        $this->log(self::DEBUG, $message, $context);
    }
    
    /**
     * Check if we should log at this level
     */
    private function should_log($level) {
        return $this->get_level_priority($level) >= $this->get_level_priority($this->min_log_level);
    }
    
    /**
     * Get numeric priority for log level
     */
    private function get_level_priority($level) {
        $priorities = [
            self::EMERGENCY => 8,
            self::ALERT     => 7,
            self::CRITICAL  => 6,
            self::ERROR     => 5,
            self::WARNING   => 4,
            self::NOTICE    => 3,
            self::INFO      => 2,
            self::DEBUG     => 1,
        ];
        
        return isset($priorities[$level]) ? $priorities[$level] : 0;
    }
    
    /**
     * Format the log message
     */
    private function format_message($level, $message, array $context = []) {
        $timestamp = gmdate('Y-m-d H:i:s');
        $level_upper = strtoupper($level);
        
        // Interpolate context variables in message
        $message = $this->interpolate($message, $context);
        
        // Add context as JSON if present
        $context_string = '';
        if (!empty($context)) {
            $context_string = ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        
        return "[{$timestamp}] GeoGuru.{$level_upper}: {$message}{$context_string}";
    }
    
    /**
     * Interpolate context values into message placeholders
     */
    private function interpolate($message, array $context = []) {
        $replace = [];
        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }
        
        return strtr($message, $replace);
    }
    
    /**
     * Write message to log file with rotation
     */
    private function write_to_file($message) {
        // Don't write if logging is disabled (WP_DEBUG false or uploads directory unavailable)
        if ($this->log_file === null) {
            return;
        }
        
        // Double-check WP_DEBUG is still enabled
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        // Check if rotation is needed
        if (file_exists($this->log_file) && filesize($this->log_file) > $this->max_file_size) {
            $this->rotate_logs();
        }
        
        // Write to file
        file_put_contents($this->log_file, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Rotate log files
     */
    private function rotate_logs() {
        // Initialize WP_Filesystem if needed
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        
        // Remove oldest log file
        $oldest_log = $this->log_file . '.' . $this->max_files;
        if (file_exists($oldest_log)) {
            wp_delete_file($oldest_log);
        }
        
        // Rotate existing log files
        for ($i = $this->max_files - 1; $i >= 1; $i--) {
            $old_file = $this->log_file . '.' . $i;
            $new_file = $this->log_file . '.' . ($i + 1);
            
            if (file_exists($old_file)) {
                $wp_filesystem->move($old_file, $new_file);
            }
        }
        
        // Move current log to .1
        if (file_exists($this->log_file)) {
            $wp_filesystem->move($this->log_file, $this->log_file . '.1');
        }
    }
    
    /**
     * Get log file path for debugging
     */
    public function get_log_file_path() {
        return $this->log_file;
    }
    
    /**
     * Get log directory path
     */
    public function get_log_dir() {
        $upload_dir = wp_upload_dir();
        if ($upload_dir['error']) {
            return null;
        }
        return $upload_dir['basedir'] . '/lovedbyai';
    }
    
    /**
     * Clear all log files
     */
    public function clear_logs() {
        // Don't attempt to clear if logging is disabled
        if ($this->log_file === null) {
            return;
        }
        
        // Remove main log file
        if (file_exists($this->log_file)) {
            wp_delete_file($this->log_file);
        }
        
        // Remove rotated log files
        for ($i = 1; $i <= $this->max_files; $i++) {
            $log_file = $this->log_file . '.' . $i;
            if (file_exists($log_file)) {
                wp_delete_file($log_file);
            }
        }
        
        $this->info('Log files cleared');
    }
}
