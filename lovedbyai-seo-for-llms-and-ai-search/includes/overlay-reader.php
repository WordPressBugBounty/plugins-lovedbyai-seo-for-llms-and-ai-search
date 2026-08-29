<?php
/**
 * Original-page overlay reader: merges the optimizer's non-visible head fields
 * (meta <title> + JSON-LD by @type) from post meta into the rendered page <head>
 * for opted-in sites. Gated to normal-visitor renders; any failure flushes unchanged.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('GEOGURU_OVERLAY_POST_META_KEY')) {
    define('GEOGURU_OVERLAY_POST_META_KEY', '_geoguru_overlay_payload_v1');
}

function geoguru_overlay_register() {
    if (!geoguru_overlay_get_boolean_option('geoguru_apply_non_visible_overlay_on_original_page', 0)) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only surface check, no nonce needed
    if (isset($_GET['llm_view']) && $_GET['llm_view'] == '1') {
        return;
    }
    // Never overlay one of our own fetches. The optimizer requests the plain URL, so with the
    // overlay applied it reads its own previous output back as the page's "original content" and
    // rewrites it again on the next run. Measured on the meta title: 0% of re-optimizations before
    // this overlay shipped (2026-06-22), 26% the month after, 39% two months after. Today the
    // overlay only touches <head>, so only the title loops; extending it to the body would put
    // every visible heading in the same loop.
    if (geoguru_overlay_is_lovedbyai_fetch()) {
        // Our fetch shares its URL with real visitors, so a page cache populated from THIS
        // response would go on serving the un-overlaid page to everyone. Keep it out of the cache.
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        return;
    }

    add_action('template_redirect', 'geoguru_overlay_start_buffer', 10);
}

/**
 * Is this request one of ours, rather than a visitor's or a crawler's?
 *
 * X-GeoGuru-Optimizer is sent by the optimizer service when it fetches a page to optimize;
 * GeoGuru_Requests_Interceptor::maybe_disable_litespeed_for_optimizer() already reads the same
 * header. X-LovedByAI-Verify is the per-website service-identification header carried by our
 * outbound requests generally. Either one means the HTML is coming back to us, and what we need
 * back is the site's own content -- not the content we previously produced.
 *
 * The value is deliberately not verified. This only decides whether to ADD our optimization to a
 * page, so the most a forged header can do is show the caller the page the site already serves.
 */
function geoguru_overlay_is_lovedbyai_fetch() {
    return isset($_SERVER['HTTP_X_GEOGURU_OPTIMIZER']) || isset($_SERVER['HTTP_X_LOVEDBYAI_VERIFY']);
}

function geoguru_overlay_start_buffer() {
    $post_id = (int) get_queried_object_id();
    if ($post_id <= 0) {
        return; // overlay is post-scoped; non-singular views have nothing to apply
    }

    $raw = get_post_meta($post_id, GEOGURU_OVERLAY_POST_META_KEY, true);
    if (!is_string($raw) || $raw === '') {
        return;
    }

    $envelope = json_decode($raw, true);
    if (!is_array($envelope) || (isset($envelope['schemaVersion']) && (int) $envelope['schemaVersion'] !== 1)) {
        return;
    }
    $payload = isset($envelope['payload']) && is_array($envelope['payload']) ? $envelope['payload'] : null;
    if ($payload === null || (empty($payload['metaTitle']) && empty($payload['jsonLd']))) {
        return;
    }

    $GLOBALS['geoguru_overlay_payload'] = $payload;
    $GLOBALS['geoguru_overlay_post_id'] = $post_id;
    ob_start('geoguru_overlay_filter_output');
}

function geoguru_overlay_filter_output($html) {
    $payload = isset($GLOBALS['geoguru_overlay_payload']) ? $GLOBALS['geoguru_overlay_payload'] : null;
    if (!is_array($payload) || !is_string($html)) {
        return $html;
    }

    $logger = class_exists('GeoGuru_Logger') ? GeoGuru_Logger::get_instance() : null;
    $started = microtime(true);
    try {
        $merged = geoguru_overlay_merge_head($html, $payload);
        $decision = ($merged !== $html) ? 'applied' : 'missing';
        if ($logger) {
            $logger->info('Overlay merge on original page', array(
                'response_surface' => 'original_page',
                'original_page_overlay_enabled' => true,
                'overlay_decision' => $decision,
                'post_id' => isset($GLOBALS['geoguru_overlay_post_id']) ? (int) $GLOBALS['geoguru_overlay_post_id'] : 0,
                'merge_latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'seo_plugin' => geoguru_overlay_detected_seo_plugin(),
            ));
        }
        return $merged;
    } catch (\Throwable $e) {
        if ($logger) {
            $logger->error('Overlay merge failed; serving original', array(
                'overlay_decision' => 'merge_error',
                'error' => $e->getMessage(),
            ));
        }
        return $html;
    }
}

function geoguru_overlay_merge_head($html, $payload) {
    if (!is_string($html) || $html === '' || !is_array($payload)) {
        return $html;
    }
    if (!preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $html, $m, PREG_OFFSET_CAPTURE)) {
        return $html;
    }
    $head_inner = $m[1][0];
    $head_inner_start = $m[1][1];
    $head_inner_len = strlen($head_inner);
    $new_head = $head_inner;

    // 1) Meta title — overwrite even when an SEO plugin/theme produced one.
    if (isset($payload['metaTitle']['value']) && is_string($payload['metaTitle']['value']) && $payload['metaTitle']['value'] !== '') {
        $title_tag = '<title>' . esc_html($payload['metaTitle']['value']) . '</title>';
        if (preg_match('/<title\b[^>]*>.*?<\/title>/is', $new_head)) {
            // preg_replace_callback, not preg_replace: a replacement STRING interprets $N / \N / ${N}
            // as backreferences, which would mangle a title containing e.g. "$5". The callback
            // returns the title verbatim.
            $new_head = preg_replace_callback(
                '/<title\b[^>]*>.*?<\/title>/is',
                function () use ($title_tag) {
                    return $title_tag;
                },
                $new_head,
                1
            );
        } else {
            $new_head .= "\n" . $title_tag;
        }
    }

    // 2) JSON-LD — merge each block into the first existing block sharing a root @type; append the rest.
    if (!empty($payload['jsonLd']) && is_array($payload['jsonLd'])) {
        $remaining = array();
        foreach ($payload['jsonLd'] as $block) {
            if (is_array($block)) {
                $remaining[] = $block;
            }
        }

        if (!empty($remaining)) {
            $new_head = preg_replace_callback(
                '/(<script\b[^>]*type=(["\'])application\/ld\+json\2[^>]*>)(.*?)<\/script>/is',
                function ($mm) use (&$remaining) {
                    if (empty($remaining)) {
                        return $mm[0];
                    }
                    $doc = json_decode(trim($mm[3]), true);
                    if (!is_array($doc)) {
                        return $mm[0];
                    }
                    $consumed = array();
                    foreach ($remaining as $i => $block) {
                        $merged = geoguru_overlay_jsonld_merge($doc, $block);
                        if ($merged !== null) {
                            $doc = $merged;
                            $consumed[] = $i;
                        }
                    }
                    if (empty($consumed)) {
                        return $mm[0];
                    }
                    // json_encode keeps "/" escaped, so "</script>" in values can't break the tag.
                    $encoded = wp_json_encode($doc, JSON_UNESCAPED_UNICODE);
                    if (!is_string($encoded)) {
                        return $mm[0]; // encode failed: leave script and $remaining intact for append
                    }
                    foreach ($consumed as $i) {
                        unset($remaining[$i]);
                    }
                    $remaining = array_values($remaining);
                    return geoguru_overlay_add_class($mm[1], 'lovedbyai-schema') . $encoded . '</script>';
                },
                $new_head
            );

            foreach ($remaining as $block) {
                $encoded = wp_json_encode($block, JSON_UNESCAPED_UNICODE);
                if (is_string($encoded)) {
                    $new_head .= "\n" . '<script type="application/ld+json" class="lovedbyai-schema">' . $encoded . '</script>';
                }
            }
        }
    }

    if ($new_head === $head_inner) {
        return $html;
    }
    return substr($html, 0, $head_inner_start) . $new_head . substr($html, $head_inner_start + $head_inner_len);
}

function geoguru_overlay_jsonld_root_types($obj) {
    if (!is_array($obj) || !isset($obj['@type'])) {
        return array();
    }
    $t = $obj['@type'];
    if (is_string($t)) {
        return array($t);
    }
    if (is_array($t)) {
        return array_values(array_filter($t, 'is_string'));
    }
    return array();
}

function geoguru_overlay_jsonld_merge($existing, $new) {
    if (!is_array($existing) || !is_array($new)) {
        return null;
    }
    $has_graph = isset($existing['@graph']) && is_array($existing['@graph']);
    $items = $has_graph ? array_values($existing['@graph']) : array($existing);

    $new_types = geoguru_overlay_jsonld_root_types($new);
    $match = -1;
    foreach ($items as $idx => $item) {
        if (array_intersect($new_types, geoguru_overlay_jsonld_root_types($item))) {
            $match = $idx;
            break;
        }
    }
    if ($match === -1) {
        return null;
    }

    // Existing fields win: overlay first, existing overwrites.
    $merged = array_merge($new, $items[$match]);

    if (isset($new['sameAs'])) {
        $existing_same = isset($items[$match]['sameAs']) ? $items[$match]['sameAs'] : null;
        $merged['sameAs'] = geoguru_overlay_jsonld_union($new['sameAs'], $existing_same);
    }

    $new_author = isset($new['author']) ? $new['author'] : null;
    $existing_author = isset($items[$match]['author']) ? $items[$match]['author'] : null;
    if (is_array($new_author) && isset($new_author['sameAs']) && is_array($existing_author)) {
        $author = isset($merged['author']) && is_array($merged['author']) ? $merged['author'] : $existing_author;
        $existing_author_same = isset($existing_author['sameAs']) ? $existing_author['sameAs'] : null;
        $author['sameAs'] = geoguru_overlay_jsonld_union($new_author['sameAs'], $existing_author_same);
        $merged['author'] = $author;
    }

    $items[$match] = $merged;

    if ($has_graph) {
        $existing['@graph'] = $items;
        return $existing;
    }
    return $items[0];
}

function geoguru_overlay_jsonld_union($primary, $secondary) {
    $a = is_array($primary) ? $primary : ($primary !== null ? array($primary) : array());
    $b = is_array($secondary) ? $secondary : ($secondary !== null ? array($secondary) : array());
    $out = array();
    foreach (array_merge($a, $b) as $v) {
        if (!in_array($v, $out, true)) {
            $out[] = $v;
        }
    }
    return $out;
}

function geoguru_overlay_add_class($open_tag, $class) {
    if (preg_match('/\sclass\s*=\s*(["\'])(.*?)\1/is', $open_tag, $m)) {
        $classes = preg_split('/\s+/', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY);
        if (in_array($class, $classes, true)) {
            return $open_tag; // already stamped
        }
        $classes[] = $class;
        $rebuilt = ' class=' . $m[1] . implode(' ', $classes) . $m[1];
        return str_replace($m[0], $rebuilt, $open_tag);
    }
    // No class attribute — the opening tag ends in ">"; insert one before it.
    return substr($open_tag, 0, -1) . ' class="' . $class . '">';
}

function geoguru_overlay_detected_seo_plugin() {
    if (defined('WPSEO_VERSION')) {
        return 'yoast';
    }
    if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
        return 'rankmath';
    }
    return 'none';
}

function geoguru_overlay_get_boolean_option($name, $default = 0) {
    return filter_var(get_option($name, $default), FILTER_VALIDATE_BOOLEAN);
}

// Best-effort full-page cache purge across common WP caching plugins (each call is a
// no-op when its plugin is inactive). $post_id scopes the purge; 0 purges site-wide.
function geoguru_overlay_purge_caches($post_id = 0) {
    $post_id = (int) $post_id;

    if ($post_id > 0) {
        clean_post_cache($post_id);
    }

    if ($post_id > 0) {
        do_action('litespeed_purge_post', $post_id);
    } else {
        do_action('litespeed_purge_all');
    }

    if ($post_id > 0 && function_exists('rocket_clean_post')) {
        rocket_clean_post($post_id);
    } elseif (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
    }

    if ($post_id > 0 && function_exists('w3tc_flush_post')) {
        w3tc_flush_post($post_id);
    } elseif (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
    }

    if ($post_id > 0 && function_exists('wpsc_delete_post_cache')) {
        wpsc_delete_post_cache($post_id);
    } elseif (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }
}
