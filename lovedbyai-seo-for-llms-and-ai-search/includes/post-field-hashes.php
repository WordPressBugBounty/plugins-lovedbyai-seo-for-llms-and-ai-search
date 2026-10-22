<?php
// Change fingerprints of a post's raw fields: sent with every publish / update post event, and by
// GET /discovery/posts when fields=field_hashes is asked for. One hash per field, with no idea which
// fields matter -- the service decides that. Nothing is rendered and no raw value is sent, though an
// unsalted hash of a very short value (a price, a flag) can be guessed; only public posts are hashed.

defined('ABSPATH') || exit;

define('GEOGURU_POST_PATTERN_POST_TYPE', 'wp_block');
// Block nesting levels searched for synced patterns, the post's top level being 0. Three finds a
// pattern placed in a column of a columns block inside a group.
define('GEOGURU_POST_PATTERN_MAX_DEPTH', 3);

/** sha256 of the value, with CRLF normalised to LF and outer whitespace trimmed. */
function geoguru_post_hash_value($value) {
    return hash('sha256', trim(str_replace("\r\n", "\n", (string) $value)));
}

/**
 * title, content and excerpt hash the raw post_title / post_content / post_excerpt; meta has one
 * hash per key of get_post_meta($id), its values hashed as wp_json_encode($values); patterns has
 * one per synced pattern the post uses. meta and patterns are objects, so an empty map -- or one
 * keyed like a list -- still encodes as {}, never [].
 *
 * Reads every meta key of the post: a caller hashing many posts primes their meta first
 * (update_meta_cache('post', $ids)).
 *
 * @param WP_Post $post
 * @return array
 */
function geoguru_post_field_hashes($post) {
    $meta = array();
    $all_meta = get_post_meta($post->ID);
    if (is_array($all_meta)) {
        foreach ($all_meta as $key => $values) {
            $meta[(string) $key] = geoguru_post_hash_value((string) wp_json_encode($values));
        }
    }
    ksort($meta, SORT_STRING);

    $patterns = array();
    geoguru_post_pattern_hashes(parse_blocks((string) $post->post_content), 0, $patterns);
    ksort($patterns);

    return array(
        'title' => geoguru_post_hash_value($post->post_title),
        'content' => geoguru_post_hash_value($post->post_content),
        'excerpt' => geoguru_post_hash_value($post->post_excerpt),
        'meta' => (object) $meta,
        'patterns' => (object) $patterns,
    );
}

/**
 * Synced patterns (core/block with attrs.ref) in $blocks, searched through nested blocks and into
 * the patterns themselves down to GEOGURU_POST_PATTERN_MAX_DEPTH, into $hashes (pattern id =>
 * hash of its post_content). Each pattern is hashed once, which also stops two patterns that
 * contain each other.
 */
function geoguru_post_pattern_hashes($blocks, $depth, array &$hashes) {
    if (!is_array($blocks) || $depth > GEOGURU_POST_PATTERN_MAX_DEPTH) {
        return;
    }
    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $name = isset($block['blockName']) ? $block['blockName'] : null;
        if ($name === 'core/block' && isset($block['attrs']['ref'])) {
            $ref = (int) $block['attrs']['ref'];
            if ($ref > 0 && !isset($hashes[$ref])) {
                $pattern = get_post($ref);
                if ($pattern instanceof WP_Post && $pattern->post_type === GEOGURU_POST_PATTERN_POST_TYPE) {
                    $hashes[$ref] = geoguru_post_hash_value($pattern->post_content);
                    geoguru_post_pattern_hashes(parse_blocks((string) $pattern->post_content), $depth + 1, $hashes);
                }
            }
        }
        if (!empty($block['innerBlocks'])) {
            geoguru_post_pattern_hashes($block['innerBlocks'], $depth + 1, $hashes);
        }
    }
}
