<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Migration to version 1.1.0
 * 
 * Updates existing options to ensure they have the latest default values
 * if they contain old values. This migration runs when updating from any
 * version prior to 1.1.0.
 * 
 * @param string $from_version The version we're migrating from
 * @param GeoGuru_Logger $logger Logger instance for logging migration actions
 */
function geoguru_migrate_to_1_1_0($from_version, $logger) {
    // Check if migration is needed
    if (version_compare($from_version, '1.1.0', '>=')) {
        $logger->info('Migration to 1.1.0 not needed, already at or past this version', [
            'from_version' => $from_version
        ]);
        return;
    }
    
    $logger->info('Starting migration to 1.1.0', [
        'from_version' => $from_version
    ]);
    
    $config = GeoGuru_ConfigService::get_instance();
    $options_updated = 0;
    
    // Update portal_url if it has an old value or is missing
    $portal_url = get_option('geoguru_portal_url');
    $new_portal_url = $config->get('PORTAL_URL', 'https://app.lovedby.ai');
    if (filter_var($new_portal_url, FILTER_VALIDATE_URL)) { 
        update_option('geoguru_portal_url', esc_url_raw($new_portal_url));
        $options_updated++;
        $logger->info('Updated geoguru_portal_url', [
            'old_value' => $portal_url,
            'new_value' => $new_portal_url
        ]);
    }
    
    // Update supabase_url if it has an old value or is missing
    $supabase_url = get_option('geoguru_supabase_url');
    $new_supabase_url = $config->get('SUPABASE_URL', 'https://sup.lovedby.ai');
    if (filter_var($new_supabase_url, FILTER_VALIDATE_URL)) {
        update_option('geoguru_supabase_url', esc_url_raw($new_supabase_url));
        $options_updated++;
        $logger->info('Updated geoguru_supabase_url', [
            'old_value' => $supabase_url,
            'new_value' => $new_supabase_url
        ]);
    }
    
    // Update fire_and_forget_worker_url if it's missing
    $new_fire_and_forget_worker_url = $config->get('FIRE_AND_FORGET_WORKER_URL', 'https://api.lovedby.ai/fire-and-forget');
    update_option('geoguru_fire_and_forget_worker_url', $new_fire_and_forget_worker_url);
    $options_updated++;
    $logger->info('Updated geoguru_fire_and_forget_worker_url');
    
    // Update optimized_content_cdn_url if it's missing
    $new_optimized_content_cdn_url = $config->get('OPTIMIZED_CONTENT_CDN_URL', 'https://cdn.lovedby.ai');
    update_option('geoguru_optimized_content_cdn_url', $new_optimized_content_cdn_url);
    $options_updated++;
    $logger->info('Updated geoguru_optimized_content_cdn_url');
    
    // Update optimizer_service_url if it's missing
    $new_optimizer_service_url = $config->get('OPTIMIZER_URL', 'https://api.lovedby.ai');
    update_option('geoguru_optimizer_service_url', $new_optimizer_service_url);
    $options_updated++;
    $logger->info('Updated geoguru_optimizer_service_url');
    
    // Update discovery_service_url if it has an old value or is missing
    $discovery_service_url = get_option('geoguru_discovery_service_url');
    $new_discovery_service_url = $config->get('DISCOVERY_SERVICE_URL', 'https://api.lovedby.ai');
    if (filter_var($new_discovery_service_url, FILTER_VALIDATE_URL)) {
        update_option('geoguru_discovery_service_url', esc_url_raw($new_discovery_service_url));
        $options_updated++;
        $logger->info('Updated geoguru_discovery_service_url', [
            'old_value' => $discovery_service_url,
            'new_value' => $new_discovery_service_url
        ]);
    }
    
    $logger->info('Migration to 1.1.0 completed', [
        'from_version' => $from_version,
        'options_updated' => $options_updated
    ]);
}
