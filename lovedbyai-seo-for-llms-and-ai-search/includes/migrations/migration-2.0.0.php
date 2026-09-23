<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Migration to version 2.0.0
 *
 * Writes geoguru_optimization_delivery once, from the optimization method the site was running,
 * so the resolver no longer has to derive it on every request.
 *
 * Only sites on the CDN-document mechanism need a row: every other site resolves to
 * geoguru_delivery_default(), which is what they were already doing. Writing nothing for them
 * keeps presence of the option meaning "this site was set deliberately".
 *
 * @param string         $from_version The version we're migrating from.
 * @param GeoGuru_Logger $logger       Logger instance.
 */
function geoguru_migrate_to_2_0_0($from_version, $logger) {
    if (!function_exists('geoguru_delivery_validate')) {
        $logger->error('Delivery settings unavailable, cannot migrate delivery');
        return;
    }

    // An explicit pair already present is strictly better information than the option below:
    // the backend pushed it, or someone set it through the settings route.
    if (geoguru_delivery_validate(get_option(GEOGURU_DELIVERY_OPTION, null)) !== null) {
        $logger->info('Delivery pair already set, nothing to migrate');
        return;
    }

    $method = get_option('geoguru_optimization_method', '');
    $pair = geoguru_derive_delivery_from_legacy_method($method);

    if ($pair === geoguru_delivery_default()) {
        $logger->info('Site resolves to the default delivery pair, leaving it unwritten', array(
            'method' => $method,
        ));
        return;
    }

    update_option(GEOGURU_DELIVERY_OPTION, $pair);
    $logger->info('Delivery pair written from the previous optimization method', array(
        'method' => $method,
    ));
}
