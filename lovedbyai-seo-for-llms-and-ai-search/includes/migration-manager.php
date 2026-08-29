<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GeoGuru Migration Manager
 * 
 * Handles version-based migrations for plugin updates.
 * Automatically detects and runs migration files when plugin version changes.
 */
if (!class_exists('GeoGuru_MigrationManager')) {
    class GeoGuru_MigrationManager {
        private static $instance = null;
        private $logger;
        private $migrations_dir;
        
        /**
         * Get singleton instance
         * 
         * @return GeoGuru_MigrationManager
         */
        public static function get_instance() {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        private function __construct() {
            $this->logger = GeoGuru_Logger::get_instance();
            $this->migrations_dir = plugin_dir_path(dirname(__FILE__)) . 'includes/migrations/';
        }
        
        /**
         * Get stored plugin version from database
         * 
         * @return string Plugin version (defaults to '0.0.0' if not set)
         */
        public function get_stored_version() {
            return get_option('geoguru_plugin_version', '0.0.0');
        }
        
        /**
         * Update stored plugin version in database
         * 
         * @param string $version Version to store
         * @return bool True on success, false on failure
         */
        public function update_stored_version($version) {
            return update_option('geoguru_plugin_version', $version);
        }
        
        /**
         * Get all migration files from migrations directory
         * 
         * @return array Array of migration file paths sorted by version
         */
        public function get_migration_files() {
            $migrations = array();
            
            if (!is_dir($this->migrations_dir)) {
                $this->logger->warning('Migrations directory does not exist', [
                    'directory' => $this->migrations_dir
                ]);
                return $migrations;
            }
            
            $files = glob($this->migrations_dir . 'migration-*.php');
            
            if ($files === false) {
                $this->logger->warning('Failed to scan migrations directory', [
                    'directory' => $this->migrations_dir
                ]);
                return $migrations;
            }
            
            foreach ($files as $file) {
                // Extract version from filename (e.g., migration-1.1.0.php -> 1.1.0)
                if (preg_match('/migration-([0-9]+\.[0-9]+\.[0-9]+)\.php$/', basename($file), $matches)) {
                    $version = $matches[1];
                    $migrations[$version] = $file;
                }
            }
            
            // Sort by version number
            uksort($migrations, 'version_compare');
            
            return $migrations;
        }
        
        /**
         * Run a specific migration file
         * 
         * @param string $migration_file Path to migration file
         * @param string $from_version Version we're migrating from
         * @param string $to_version Version we're migrating to
         * @return bool True on success, false on failure
         */
        public function run_migration($migration_file, $from_version, $to_version) {
            if (!file_exists($migration_file)) {
                $this->logger->error('Migration file not found', [
                    'file' => $migration_file,
                    'from_version' => $from_version,
                    'to_version' => $to_version
                ]);
                return false;
            }
            
            // Extract version from filename to get function name
            if (!preg_match('/migration-([0-9]+\.[0-9]+\.[0-9]+)\.php$/', basename($migration_file), $matches)) {
                $this->logger->error('Invalid migration file name format', [
                    'file' => $migration_file
                ]);
                return false;
            }
            
            $target_version = $matches[1];
            $function_name = 'geoguru_migrate_to_' . str_replace('.', '_', $target_version);
            
            // Include the migration file
            require_once $migration_file;
            
            // Check if migration function exists
            if (!function_exists($function_name)) {
                $this->logger->error('Migration function not found', [
                    'file' => $migration_file,
                    'function' => $function_name,
                    'from_version' => $from_version,
                    'to_version' => $to_version
                ]);
                return false;
            }
            
            // Run the migration
            try {
                $this->logger->info('Running migration', [
                    'file' => basename($migration_file),
                    'function' => $function_name,
                    'from_version' => $from_version,
                    'to_version' => $target_version
                ]);
                
                call_user_func($function_name, $from_version, $this->logger);
                
                $this->logger->info('Migration completed successfully', [
                    'file' => basename($migration_file),
                    'from_version' => $from_version,
                    'to_version' => $target_version
                ]);
                
                return true;
            } catch (Exception $e) {
                $this->logger->error('Migration failed with exception', [
                    'file' => basename($migration_file),
                    'function' => $function_name,
                    'error' => $e->getMessage(),
                    'from_version' => $from_version,
                    'to_version' => $target_version
                ]);
                return false;
            }
        }
        
        /**
         * Check plugin version and run migrations if needed
         * This is the main entry point for the migration system
         * 
         * @return bool True if migrations were run, false otherwise
         */
        public function check_and_run_migrations() {
            // Get current plugin version
            $current_version = defined('GEOGURU_PLUGIN_VERSION') ? GEOGURU_PLUGIN_VERSION : '1.0.0';
            
            // Get stored version from database
            $stored_version = $this->get_stored_version();
            
            // Compare versions
            if (version_compare($stored_version, $current_version, '>=')) {
                // No migration needed
                return false;
            }
            
            $this->logger->info('Plugin version update detected', [
                'stored_version' => $stored_version,
                'current_version' => $current_version
            ]);
            
            // Get all migration files
            $migration_files = $this->get_migration_files();
            
            if (empty($migration_files)) {
                $this->logger->info('No migration files found, updating version only', [
                    'stored_version' => $stored_version,
                    'current_version' => $current_version
                ]);
                
                // Update version and ensure default config
                $this->update_stored_version($current_version);
                geoguru_insert_plugin_default_config();
                
                return true;
            }
            
            // Run migrations in order (they're already sorted by version)
            $last_version = $stored_version;
            $migrations_run = 0;
            $migrations_failed = 0;
            
            foreach ($migration_files as $version => $migration_file) {
                // Only run migrations that are newer than stored version
                if (version_compare($version, $stored_version, '>') && 
                    version_compare($version, $current_version, '<=')) {
                    
                    $success = $this->run_migration($migration_file, $last_version, $version);
                    
                    if ($success) {
                        $migrations_run++;
                        $last_version = $version;
                    } else {
                        $migrations_failed++;
                        // Continue with other migrations even if one fails
                    }
                }
            }
            
            // Update stored version to current version (even if some migrations failed)
            // This prevents infinite loops if migrations have issues
            $this->update_stored_version($current_version);
            
            // Ensure default config is set (in case new options were added)
            geoguru_insert_plugin_default_config();
            
            $this->logger->info('Plugin version update completed', [
                'stored_version' => $stored_version,
                'current_version' => $current_version,
                'migrations_run' => $migrations_run,
                'migrations_failed' => $migrations_failed
            ]);
            
            return true;
        }
    }
}
