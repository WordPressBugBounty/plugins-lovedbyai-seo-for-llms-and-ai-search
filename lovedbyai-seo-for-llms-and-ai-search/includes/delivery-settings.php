<?php
/**
 * Delivery settings: which mechanism applies optimizations to which target.
 *
 * Two independent targets -- the canonical page and the ?llm_view=1 mirror -- each carrying one
 * mechanism. This replaces the nested pair of geoguru_optimization_method and
 * geoguru_apply_non_visible_overlay_on_original_page, which between them could not express the
 * states we need.
 *
 * Nothing here decides which changes a target gets. The plugin applies whatever each entry is
 * targeted at; what goes into the payload is decided before it is sent.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('GEOGURU_DELIVERY_OPTION')) {
    define('GEOGURU_DELIVERY_OPTION', 'geoguru_optimization_delivery');
}

/**
 * Mechanisms accepted on the canonical page.
 *
 * off              -- leave the page alone
 * page_replacement -- buffer the WordPress render and swap content in
 * cdn_fetch        -- echo the pre-built optimized document (the legacy on-page method)
 */
function geoguru_delivery_original_mechanisms() {
    return array('off', 'page_replacement', 'cdn_fetch');
}

/**
 * Mechanisms accepted on the mirror.
 *
 * off              -- no mirror handling; a ?llm_view=1 request renders as a normal WordPress page
 * page_replacement -- apply the overlay payload stored in the WordPress database to the WordPress
 *                      render, the same mechanism `original` uses on the canonical page
 * cdn_fetch        -- serve the document at the CDN object key, whatever the optimizer put there
 *
 * Whether that CDN document holds every change or only some of them is decided during optimization,
 * by the optimizer -- the plugin has no notion of it. It either applies the stored payload or serves
 * whatever sits at the object key.
 */
function geoguru_delivery_mirror_mechanisms() {
    return array('off', 'page_replacement', 'cdn_fetch');
}

/**
 * The default pair: optimizations applied to the canonical page, and a CDN document behind the
 * link. This is the state the vast majority of sites already run, so it is what a site with nothing
 * else to go on gets, and what turning delivery back on restores.
 */
function geoguru_delivery_default() {
    return array('original' => 'page_replacement', 'mirror' => 'cdn_fetch');
}

/**
 * Validate a candidate delivery pair.
 *
 * All-or-nothing on purpose. Accepting a half-valid object would let a site run one target from a
 * pushed value and the other from legacy derivation -- a combination nobody chose.
 *
 * @param mixed $value
 * @return array|null The normalized pair, or null if anything about it is wrong.
 */
function geoguru_delivery_validate($value) {
    if (!is_array($value)) {
        return null;
    }
    if (!isset($value['original']) || !isset($value['mirror'])) {
        return null;
    }
    if (!is_string($value['original']) || !is_string($value['mirror'])) {
        return null;
    }
    if (!in_array($value['original'], geoguru_delivery_original_mechanisms(), true)) {
        return null;
    }
    if (!in_array($value['mirror'], geoguru_delivery_mirror_mechanisms(), true)) {
        return null;
    }
    // The CDN path on the canonical page returns before any mirror handling or link code runs,
    // so a mirror alongside it would be reported but never served. Refuse the state rather than
    // ship a site into it.
    if ($value['original'] === 'cdn_fetch' && $value['mirror'] !== 'off') {
        return null;
    }
    return array(
        'original' => $value['original'],
        'mirror'   => $value['mirror'],
    );
}

/**
 * The resolved delivery pair. The only function anything should read delivery state from.
 *
 * Either the site was told what to do, or it gets what its retired optimization method implies
 * (geoguru_derive_delivery_from_legacy_method() below), which is the default for most sites. A malformed stored value resolves to
 * the default rather than to half of itself, which is the same all-or-nothing rule the writers
 * apply.
 *
 * @return array array('original' => string, 'mirror' => string)
 */
function geoguru_get_delivery_settings() {
    $validated = geoguru_delivery_validate(get_option(GEOGURU_DELIVERY_OPTION, null));
    if ($validated !== null) {
        return $validated;
    }
    return geoguru_derive_delivery_from_legacy_method(get_option('geoguru_optimization_method', ''));
}

/**
 * Store a validated delivery pair, and purge cached pages when that changes what renders do.
 *
 * Every render reads the resolved pair, so a change hidden behind a page cache stays invisible
 * until the cache expires -- a site switched off would keep serving overlaid pages. Compared on the
 * resolved pair rather than the raw option, so writing down the pair a site already resolves to
 * (the default, say) does not flush every cached page for nothing.
 *
 * @param array $pair A pair geoguru_delivery_validate() accepted.
 * @return bool Whether the resolved pair changed.
 */
function geoguru_delivery_store($pair) {
    $before = geoguru_get_delivery_settings();
    update_option(GEOGURU_DELIVERY_OPTION, $pair);
    $changed = geoguru_get_delivery_settings() !== $before;
    if ($changed && function_exists('geoguru_overlay_purge_caches')) {
        geoguru_overlay_purge_caches(0);
    }
    return $changed;
}

/**
 * The delivery pair implied by the retired geoguru_optimization_method option.
 *
 * A site that served complete CDN documents on the canonical page keeps doing so; every other site
 * gets the default. The 2.0.0 migration writes this down once. geoguru_get_delivery_settings() also
 * falls back on it until the migration has run: an update performed outside the admin -- an
 * automatic update, WP-CLI, or a single-plugin update still handled by the previous version's code --
 * runs migrations only on the next admin page load, and a site must not lose its mechanism in the
 * meantime. A stored pair always wins over this.
 *
 * @param string $method The geoguru_optimization_method option value.
 * @return array array('original' => string, 'mirror' => string)
 */
function geoguru_derive_delivery_from_legacy_method($method) {
    // The old front-end took the CDN path for ANY non-empty value other than 'llm_link_generator',
    // not only the canonical 'requests_interceptor'. Such a value can only come from a direct
    // database write, but it served CDN documents, so it keeps doing so.
    if ($method && $method !== 'llm_link_generator') {
        return array('original' => 'cdn_fetch', 'mirror' => 'off');
    }
    return geoguru_delivery_default();
}
