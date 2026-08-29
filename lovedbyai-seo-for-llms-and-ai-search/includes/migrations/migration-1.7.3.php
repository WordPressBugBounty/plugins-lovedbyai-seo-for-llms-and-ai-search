<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Migration to version 1.7.3
 *
 * Ensures geoguru_indexnow_service_url exists for Environment diagnosis reachability
 * (IndexNow service base URL). New installs still get this via geoguru_insert_plugin_default_config()
 * on activation; this migration covers upgrades from releases that did not set the option.
 *
 * @param string         $from_version The version we're migrating from (last successful migration / stored).
 * @param GeoGuru_Logger $logger       Logger instance.
 */
function geoguru_migrate_to_1_7_3($from_version, $logger) {
    if (version_compare($from_version, '1.7.3', '>=')) {
        $logger->info('Migration to 1.7.3 not needed, already at or past this version', array(
            'from_version' => $from_version,
        ));
        return;
    }

    $logger->info('Starting migration to 1.7.3', array(
        'from_version' => $from_version,
    ));

    $existing = get_option('geoguru_indexnow_service_url', '');
    if ($existing !== '' && is_string($existing) && filter_var($existing, FILTER_VALIDATE_URL)) {
        $logger->info('geoguru_indexnow_service_url already valid, skipping', array(
            'has_value' => true,
        ));
        return;
    }

    $config = GeoGuru_ConfigService::get_instance();
    $url    = $config->get('INDEXNOW_SERVICE_URL', 'https://api.lovedby.ai');
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        update_option('geoguru_indexnow_service_url', esc_url_raw($url));
        $logger->info('Set geoguru_indexnow_service_url from config', array(
            'value' => $url,
        ));
    } else {
        update_option('geoguru_indexnow_service_url', 'https://api.lovedby.ai');
        $logger->warning('INDEXNOW_SERVICE_URL invalid in config, defaulted to https://api.lovedby.ai');
    }

    $logger->info('Migration to 1.7.3 completed', array(
        'from_version' => $from_version,
    ));
}
