<?php
/**
 * Original-page overlay reader: merges the optimizer's non-visible head fields
 * (meta <title> + JSON-LD by @type) from post meta into the rendered page <head>
 * for opted-in sites, and splices the body blocks a v2 envelope targets at this page into the render.
 * Gated to normal-visitor renders; any failure flushes unchanged.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Required here rather than from the plugin's central include list: it is a data table this
// file alone consumes, and requiring it locally keeps the standalone tests/overlay-merge-test.php
// runnable without booting the plugin.
require_once __DIR__ . '/schema-organization-types.php';

if (!defined('GEOGURU_OVERLAY_POST_META_KEY')) {
    define('GEOGURU_OVERLAY_POST_META_KEY', '_geoguru_overlay_payload_v1');
}

function geoguru_overlay_register() {
    $delivery = geoguru_get_delivery_settings();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only surface check, no nonce needed
    $is_mirror_request = isset($_GET['llm_view']) && $_GET['llm_view'] == '1';

    if ($is_mirror_request) {
        // The mirror decides this render on its own axis, not the canonical page's (`original`).
        // cdn_fetch serves a complete document from the CDN, and overlaying it would merge our
        // fields into a page that already carries them.
        if ($delivery['mirror'] === 'cdn_fetch') {
            return;
        }
        // Mirror off leaves ?llm_view=1 as nothing more than the canonical page under a query arg
        // -- those URLs have been linked and crawled for months, so it is overlaid on exactly the
        // condition the canonical page itself is, not left to serve unoptimized HTML.
        if ($delivery['mirror'] === 'off' && $delivery['original'] !== 'page_replacement') {
            return;
        }
        // Otherwise mirror === 'page_replacement': apply the overlay to this render regardless of
        // what the canonical page (`original`) is doing. That is the whole point of the mechanism
        // on the mirror -- the canonical page can stay untouched while ?llm_view=1 still carries it.
    } elseif ($delivery['original'] !== 'page_replacement') {
        // The canonical page is untouched when its own mechanism is not page_replacement,
        // regardless of what the mirror is doing.
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

    // Which surface this render is, resolved once here where the delivery pair is already in
    // hand. start_buffer() reads this instead of looking at $_GET again -- two places deciding the
    // same thing is how they come to disagree. With the mirror off, ?llm_view=1 is the canonical
    // page under a query arg (see above): it is overlaid on the canonical page's own condition, so
    // it carries the canonical page's content, not the mirror's.
    $GLOBALS['geoguru_overlay_target'] = ($is_mirror_request && $delivery['mirror'] === 'page_replacement')
        ? GEOGURU_OVERLAY_TARGET_MIRROR
        : GEOGURU_OVERLAY_TARGET_ORIGINAL;

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

/**
 * Decode a stored overlay envelope for the post currently being rendered.
 * Returns the payload array, or null when it must not be applied.
 *
 * Pure (no WP calls) so tests/overlay-payload-ownership-test.php can exercise the
 * ownership rule without booting WordPress.
 *
 * The ownership check is the point. This payload lives in post meta, and WordPress
 * duplication plugins copy post meta wholesale — so a duplicated post inherits the
 * source post's optimized <title> and JSON-LD and, before this guard, served them as
 * its own. Seen in production: a post published 2026-09-15 on dcf-contracting.com
 * serving the optimized title of an older post it was duplicated from.
 *
 * An ABSENT stamp is deliberately allowed. Every payload already stored in the field
 * predates this field, and refusing those would disable the overlay fleet-wide until
 * each page is re-pushed. New writes are stamped, so the window closes as pages are
 * re-optimized rather than all at once.
 */
function geoguru_overlay_envelope_from_meta($raw, $post_id) {
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $envelope = json_decode($raw, true);
    if (geoguru_overlay_envelope_version($envelope) === 0) {
        return null; // v1 and v2 are both readable; any other shape is bailed on, never guessed at
    }

    // Loose-typed on purpose: post meta round-trips ids as strings, and treating
    // "1001935" as a mismatch for 1001935 would silently switch the overlay off.
    if (isset($envelope['postId']) && (int) $envelope['postId'] !== (int) $post_id) {
        return null;
    }

    return $envelope;
}

function geoguru_overlay_start_buffer() {
    // get_queried_object_id() is not post-scoped. On a term archive it returns the TERM id, and
    // feeding that to get_post_meta() below reads the payload of whichever POST happens to share
    // that id -- so a category archive can render an unrelated post's optimized title. Seen in a
    // repro: /product-category/training/ queries object id 2, and post 2 is "Sample Page".
    //
    // The ownership stamp cannot catch this. It compares the stamp to the id being asked for, and
    // that payload's stamp IS that id -- it is the right payload for the wrong kind of object.
    // Only the object's type distinguishes them.
    //
    // WP_Post, not is_singular(): the posts page is a real page the overlay does cover, and
    // is_singular() is false there.
    if (!(get_queried_object() instanceof WP_Post)) {
        return;
    }

    $post_id = (int) get_queried_object_id();
    if ($post_id <= 0) {
        return; // overlay is post-scoped; non-singular views have nothing to apply
    }

    $raw = get_post_meta($post_id, GEOGURU_OVERLAY_POST_META_KEY, true);
    $envelope = geoguru_overlay_envelope_from_meta($raw, $post_id);
    if ($envelope === null) {
        return;
    }

    // register() resolved the surface for this render. Falling back to the canonical page is the
    // conservative reading: mirror-only entries then stay off a page they were never targeted at.
    $target = isset($GLOBALS['geoguru_overlay_target'])
        ? $GLOBALS['geoguru_overlay_target']
        : GEOGURU_OVERLAY_TARGET_ORIGINAL;

    $payload = geoguru_overlay_head_payload($envelope, $target);
    // Blocks are filtered once, here, by the same default-deny helper the REST writer uses. The
    // filter callback below is handed the result and never re-decides what this render may show.
    $blocks = geoguru_overlay_blocks_for($envelope, $target);
    if ($payload === null && empty($blocks)) {
        return; // nothing this render may apply
    }

    $GLOBALS['geoguru_overlay_payload'] = $payload;
    $GLOBALS['geoguru_overlay_blocks'] = $blocks;
    $GLOBALS['geoguru_overlay_post_id'] = $post_id;
    // Normalised, not re-read: the fallback above has already been applied, so the output filter
    // reports the same surface the blocks were filtered for.
    $GLOBALS['geoguru_overlay_target'] = $target;
    ob_start('geoguru_overlay_filter_output');
}

/**
 * The head fields this render may apply, in the single shape geoguru_overlay_merge_head() reads,
 * or null when nothing survives.
 *
 * v1 and v2 default in opposite directions, deliberately:
 *  - A v1 payload predates targets entirely, so it applies unconditionally, exactly as it always
 *    has. Reading its absent target list as "deny" would strip the overlay from every page that
 *    still stores a v1 envelope.
 *  - A v2 entry is default-deny: no targets, an empty list, a list that is not a list, or a
 *    destination we do not recognise all mean "nowhere". geoguru_overlay_entry_applies() is the
 *    one place that rule lives, so the reader and the REST writer cannot drift apart.
 *
 * v2 is normalised into the v1 shape here so the merge goes on reading one payload shape and never
 * has to know about targets.
 *
 * @param mixed $envelope Decoded envelope.
 * @param mixed $target   'original' or 'mirror'.
 * @return array|null v1-shaped payload, or null when this render has nothing to apply.
 */
function geoguru_overlay_head_payload($envelope, $target) {
    $version = geoguru_overlay_envelope_version($envelope);

    if ($version === 1) {
        $payload = isset($envelope['payload']) && is_array($envelope['payload']) ? $envelope['payload'] : null;
        if ($payload === null || (empty($payload['metaTitle']) && empty($payload['jsonLd']))) {
            return null;
        }
        return $payload;
    }

    if ($version !== 2) {
        return null;
    }

    $head = isset($envelope['head']) && is_array($envelope['head']) ? $envelope['head'] : array();
    $payload = array();

    if (isset($head['metaTitle']) && geoguru_overlay_entry_applies($head['metaTitle'], $target)) {
        $value = isset($head['metaTitle']['value']) ? $head['metaTitle']['value'] : null;
        if (is_string($value) && $value !== '') {
            $payload['metaTitle'] = array('value' => $value);
        }
    }

    if (isset($head['jsonLd']) && is_array($head['jsonLd'])) {
        $json_ld = array();
        foreach ($head['jsonLd'] as $type => $entry) {
            if (!geoguru_overlay_entry_applies($entry, $target)) {
                continue;
            }
            // v2 nests the document under `node` so the entry can carry `targets` and `changeId`
            // beside it. An entry without one is skipped rather than read as the node itself,
            // which would publish those bookkeeping keys into the page as JSON-LD.
            if (!isset($entry['node']) || !is_array($entry['node'])) {
                continue;
            }
            $json_ld[$type] = $entry['node'];
        }
        if (!empty($json_ld)) {
            $payload['jsonLd'] = $json_ld;
        }
    }

    return empty($payload) ? null : $payload;
}

function geoguru_overlay_filter_output($html) {
    $payload = isset($GLOBALS['geoguru_overlay_payload']) ? $GLOBALS['geoguru_overlay_payload'] : null;
    $blocks = isset($GLOBALS['geoguru_overlay_blocks']) && is_array($GLOBALS['geoguru_overlay_blocks'])
        ? $GLOBALS['geoguru_overlay_blocks']
        : array();
    if (!is_string($html) || (!is_array($payload) && empty($blocks))) {
        return $html;
    }

    // Which surface this render is, as start_buffer() resolved it, so the log line below describes
    // the render that actually happened: a ?llm_view=1 render is not reported as the canonical page.
    $is_original = !isset($GLOBALS['geoguru_overlay_target'])
        || $GLOBALS['geoguru_overlay_target'] !== GEOGURU_OVERLAY_TARGET_MIRROR;

    $logger = class_exists('GeoGuru_Logger') ? GeoGuru_Logger::get_instance() : null;
    $started = microtime(true);
    $applied_ids = array();
    $skipped_ids = array();
    try {
        // Head first, then the body. The head merge works on <head> alone and the blocks anchor in
        // the body, so the order is not a correctness constraint -- but both belong to ONE try, so
        // a throw in either gives the visitor the site's own page rather than a half-written one.
        $merged = is_array($payload) ? geoguru_overlay_merge_head($html, $payload) : $html;
        $merged = geoguru_overlay_apply_blocks($merged, $blocks, $applied_ids, $skipped_ids);
        $decision = ($merged !== $html) ? 'applied' : 'missing';
        if ($logger) {
            $logger->info('Overlay merge on original page', array(
                'response_surface' => $is_original ? 'original_page' : 'llm_view_mirror',
                'original_page_overlay_enabled' => $is_original,
                'overlay_decision' => $decision,
                'post_id' => isset($GLOBALS['geoguru_overlay_post_id']) ? (int) $GLOBALS['geoguru_overlay_post_id'] : 0,
                'merge_latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'seo_plugin' => geoguru_overlay_detected_seo_plugin(),
                // An anchor that has drifted is a silent no-op on the page, so this line is the only
                // place it shows up. Ids as well as counts, so a persistently skipped entry can be
                // identified.
                'overlay_blocks_applied' => $applied_ids,
                'overlay_blocks_skipped' => $skipped_ids,
                'overlay_blocks_applied_count' => count($applied_ids),
                'overlay_blocks_skipped_count' => count($skipped_ids),
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

/** The three places a block may sit relative to its anchor. Anything else is not a placement. */
function geoguru_overlay_block_positions() {
    return array('replace', 'before', 'after');
}

/**
 * Splice the targeted body blocks into the render, in the order they arrived.
 *
 * Bytes only. `content` is the FINAL MARKUP the optimizer composed -- the same composition that
 * builds the mirror document -- so there is nothing to escape, no tag to rebuild and no snippet to
 * template here. A second composition in PHP would drift from that one, and the canonical page and
 * the page an assistant reads would stop agreeing about what the change says.
 *
 * Order is the sender's and is never re-sorted: inserted blocks come before the text rewrites that
 * can consume the element they anchor to.
 *
 * @param string   $html        The buffered render.
 * @param array[]  $blocks      Blocks this render may apply, already target-filtered.
 * @param string[] $applied_ids Out: the changeIds actually spliced.
 * @param string[] $skipped_ids Out: the changeIds that could not be placed.
 * @return string The render, with whatever could be placed.
 */
function geoguru_overlay_apply_blocks($html, $blocks, &$applied_ids, &$skipped_ids) {
    if (!is_string($html) || !is_array($blocks)) {
        return $html;
    }

    foreach ($blocks as $block) {
        $id = (is_array($block) && isset($block['changeId']) && is_string($block['changeId']))
            ? $block['changeId']
            : '';

        $anchor = (is_array($block) && isset($block['anchorHtml']) && is_string($block['anchorHtml']))
            ? $block['anchorHtml']
            : '';
        $content = (is_array($block) && isset($block['content']) && is_string($block['content']))
            ? $block['content']
            : '';
        $position = (is_array($block) && isset($block['anchorPosition']) && is_string($block['anchorPosition']))
            ? $block['anchorPosition']
            : '';

        // An empty `content` is refused along with the malformed shapes: on a `replace` it would
        // delete the customer's element, which reads as a successful apply right up until someone
        // notices the paragraph is gone.
        if ($anchor === '' || $content === '' || !in_array($position, geoguru_overlay_block_positions(), true)) {
            $skipped_ids[] = $id;
            continue;
        }

        // Never splice markup that can run code -- see geoguru_overlay_block_adds_active_content().
        // Logged at error, which the default log level keeps: a block like this should never be
        // sent at all, so its arrival is worth knowing about.
        if (geoguru_overlay_block_adds_active_content($content, $anchor, $position)) {
            $skipped_ids[] = $id;
            if (class_exists('GeoGuru_Logger')) {
                GeoGuru_Logger::get_instance()->error('Overlay block refused: it would add markup that can run code', array(
                    'change_id' => $id,
                ));
            }
            continue;
        }

        // EXACTLY once, matched literally. Zero means the page has moved on since this change was
        // made against it; two or more means the anchor does not identify a place and applying to
        // the first could write into the wrong element. There is deliberately no fuzzy fallback --
        // a near match means the page was edited, and new copy written over an edited paragraph is
        // worse than no copy at all.
        if (substr_count($html, $anchor) !== 1) {
            $skipped_ids[] = $id;
            continue;
        }

        if ($position === 'after') {
            $replacement = $anchor . $content;
        } elseif ($position === 'before') {
            $replacement = $content . $anchor;
        } else {
            $replacement = $content;
        }

        // str_replace, never preg_replace: both the anchor and the replacement are the customer's
        // own markup, and the regex engine would read `$1`, `$&` or `\1` in it as substitution
        // patterns. One occurrence is guaranteed by the count above, so no limit is needed.
        $html = str_replace($anchor, $replacement, $html);
        $applied_ids[] = $id;
    }

    return $html;
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

/**
 * Strips the vocabulary prefix from a written @type so the bare schema.org term is left.
 *
 * Documents setting "@context": "https://schema.org" write the bare term and that is the
 * common case, but the full IRI and the "schema:" compact IRI name the same class.
 */
function geoguru_overlay_jsonld_bare_type($type) {
    $type = trim((string) $type);
    $type = preg_replace('#^https?://schema\.org/#i', '', $type);
    return preg_replace('#^schema:#i', '', $type);
}

/** True when any of $types is Organization or one of its schema.org subclasses. */
function geoguru_overlay_jsonld_is_organization($types) {
    $organizations = geoguru_schema_organization_types();
    foreach ($types as $type) {
        if (isset($organizations[geoguru_overlay_jsonld_bare_type($type)])) {
            return true;
        }
    }
    return false;
}

/**
 * Whether our block and an existing node describe the same kind of thing.
 *
 * A shared @type name is the ordinary signal. Organization needs the extra arm because we
 * always emit the bare "Organization" while sites publish their business under a subclass --
 * LocalBusiness, Corporation, InsuranceAgency. Those name the same class of entity, so a
 * literal comparison found no match and our block was appended as a second node. Since we
 * emit it under the @id the site's own Organization already uses, JSON-LD then requires
 * consumers to merge the two into one entity holding both sets of values.
 */
function geoguru_overlay_jsonld_types_match($new_types, $existing_types) {
    if (array_intersect($new_types, $existing_types)) {
        return true;
    }
    return geoguru_overlay_jsonld_is_organization($new_types)
        && geoguru_overlay_jsonld_is_organization($existing_types);
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
        if (geoguru_overlay_jsonld_types_match($new_types, geoguru_overlay_jsonld_root_types($item))) {
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
