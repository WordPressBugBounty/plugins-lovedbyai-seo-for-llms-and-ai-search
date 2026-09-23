<?php
/**
 * Shared readers for the overlay envelope stored in post meta.
 *
 * The envelope comes in two shapes. v1 carries a single `payload` that every visitor sees. v2
 * carries `head` slots and body `blocks`, each entry tagged with the places it may be shown:
 * 'original' (the canonical page) and/or 'mirror' (the AI-facing copy). These helpers answer
 * "may this entry be shown here?" and nothing else, so the REST writer and the front-end reader
 * apply one identical rule.
 *
 * The rule is default-deny. An entry with no targets, an empty list, a list that is not a list, or
 * a destination we do not recognise is never shown. Getting that backwards would publish content
 * that was never meant for the live page onto it, so absence is treated as refusal everywhere
 * rather than as "unspecified".
 */

if (!defined('ABSPATH')) {
    exit;
}

// The only destinations an entry may name. Anything else is unknown, and unknown means no.
if (!defined('GEOGURU_OVERLAY_TARGET_ORIGINAL')) {
    define('GEOGURU_OVERLAY_TARGET_ORIGINAL', 'original');
}
if (!defined('GEOGURU_OVERLAY_TARGET_MIRROR')) {
    define('GEOGURU_OVERLAY_TARGET_MIRROR', 'mirror');
}
// How far ahead of this site's clock a stored envelope's stamp may be and still be believed. Clocks
// drift by seconds; a stamp further out than this was written by a clock that was wrong.
if (!defined('GEOGURU_OVERLAY_CLOCK_TOLERANCE')) {
    define('GEOGURU_OVERLAY_CLOCK_TOLERANCE', 600);
}
if (!defined('GEOGURU_OVERLAY_TARGETS')) {
    define('GEOGURU_OVERLAY_TARGETS', array(GEOGURU_OVERLAY_TARGET_ORIGINAL, GEOGURU_OVERLAY_TARGET_MIRROR));
}

/**
 * Which envelope shape this is: 1, 2, or 0 when it is unusable.
 *
 * A version we do not know (a newer plugin's envelope left behind by a downgrade, say) reports 0
 * rather than being guessed at, so callers stop instead of reading fields that may mean something
 * else. Only an integer, or a string of digits, counts -- `true` and "2 or so" are not versions.
 *
 * @param mixed $envelope Decoded envelope.
 * @return int 1, 2, or 0.
 */
function geoguru_overlay_envelope_version($envelope) {
    if (!is_array($envelope) || !isset($envelope['schemaVersion'])) {
        return 0;
    }

    $raw = $envelope['schemaVersion'];
    if (is_string($raw)) {
        // A regex, not ctype_digit(): the ctype extension can be compiled out of PHP.
        if (!preg_match('/^\d+$/', $raw)) {
            return 0;
        }
    } elseif (!is_int($raw)) {
        return 0;
    }

    $version = (int) $raw;
    if ($version === 1 || $version === 2) {
        return $version;
    }

    return 0;
}

/**
 * The destinations an entry (a head slot or a block) names, filtered to the ones we recognise.
 *
 * Anything malformed collapses to an empty list, which is what makes the default-deny in
 * geoguru_overlay_entry_applies() total: there is no shape that produces a target we did not
 * explicitly allow. Order follows the entry; duplicates are dropped.
 *
 * @param mixed $entry One head slot or block.
 * @return string[] Recognised targets, possibly empty.
 */
function geoguru_overlay_entry_targets($entry) {
    if (!is_array($entry) || !isset($entry['targets']) || !is_array($entry['targets'])) {
        return array();
    }

    $targets = array();
    foreach ($entry['targets'] as $target) {
        if (!is_string($target) || !in_array($target, GEOGURU_OVERLAY_TARGETS, true)) {
            continue;
        }
        if (in_array($target, $targets, true)) {
            continue;
        }
        $targets[] = $target;
    }

    return $targets;
}

/**
 * May this entry be shown at this destination?
 *
 * False whenever the entry does not explicitly name the destination -- including when it names
 * nothing at all. The comparison is exact: 'Original' is not 'original'.
 *
 * @param mixed $entry  One head slot or block.
 * @param mixed $target 'original' or 'mirror'.
 * @return bool
 */
function geoguru_overlay_entry_applies($entry, $target) {
    if (!is_string($target) || !in_array($target, GEOGURU_OVERLAY_TARGETS, true)) {
        return false;
    }

    return in_array($target, geoguru_overlay_entry_targets($entry), true);
}

/**
 * Whether an incoming envelope was built before the one this post already stores.
 *
 * Envelopes for one post can arrive from more than one source and out of order, so a slow push can
 * land after a newer one; storing it would put the older content back on the page. Each envelope
 * is stamped with `updatedAt` when it is built, so the stamps order them. The sender writes them as
 * UTC ISO-8601 with milliseconds (`2026-09-20T12:00:00.000Z`), which sorts as a string.
 *
 * Only a provable "older" counts: a missing or differently-shaped stamp on either side, or a stored
 * envelope that belongs to another post (copied meta), is never a reason to refuse.
 *
 * Nor is a stored stamp that lies in the future. One written by a sender whose clock ran ahead
 * would otherwise outrank every correct envelope after it, freezing the page until real time caught
 * up, with nothing able to replace it. A stored stamp more than GEOGURU_OVERLAY_CLOCK_TOLERANCE
 * seconds ahead of this site's clock is treated as unreadable, and the incoming envelope stored.
 *
 * @param mixed    $incoming   Decoded incoming envelope.
 * @param mixed    $stored_raw The post meta value currently stored, as a JSON string.
 * @param int      $post_id    The post the incoming envelope is for.
 * @param int|null $now        Unix time to judge "the future" by; the site's clock when omitted.
 * @return bool
 */
function geoguru_overlay_is_superseded($incoming, $stored_raw, $post_id, $now = null) {
    $stamp = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';

    if (!is_array($incoming) || !isset($incoming['updatedAt']) || !is_string($incoming['updatedAt'])
        || !preg_match($stamp, $incoming['updatedAt'])) {
        return false;
    }
    if (!is_string($stored_raw) || $stored_raw === '') {
        return false;
    }
    $stored = json_decode($stored_raw, true);
    if (!is_array($stored) || !isset($stored['updatedAt']) || !is_string($stored['updatedAt'])
        || !preg_match($stamp, $stored['updatedAt'])) {
        return false;
    }
    if (isset($stored['postId']) && (int) $stored['postId'] !== (int) $post_id) {
        return false;
    }
    $latest_believable = gmdate('Y-m-d\TH:i:s', ($now === null ? time() : (int) $now) + GEOGURU_OVERLAY_CLOCK_TOLERANCE) . '.999Z';
    if (strcmp($stored['updatedAt'], $latest_believable) > 0) {
        return false;
    }

    return strcmp($incoming['updatedAt'], $stored['updatedAt']) < 0;
}

/**
 * The markup a block would add that can run code: a <script> other than JSON-LD, an inline event
 * handler, a javascript: URL, or an embedded frame, object or base URL. Counted per kind.
 *
 * Only inside tags: block text is escaped before it becomes markup, so a "<" in it is "&lt;"
 * and cannot open one.
 *
 * @param string $html
 * @return int[] Matches per kind, in a fixed order.
 */
function geoguru_overlay_active_content($html) {
    $kinds = array(
        '/<script\b(?![^>]*\btype\s*=\s*["\']?application\/ld\+json)[^>]*>/i',
        '/<[^>]*\son[a-z]+\s*=/i',
        '/<[^>]*javascript\s*:/i',
        '/<(?:iframe|object|embed|base)\b/i',
    );
    $counts = array();
    foreach ($kinds as $pattern) {
        $counts[] = (int) preg_match_all($pattern, (string) $html);
    }
    return $counts;
}

/**
 * Whether splicing this block would put markup that can run code onto the page.
 *
 * The blocks this plugin is sent never carry any -- heading text is escaped, and the added blocks
 * are plain sections plus, at most, a JSON-LD document -- so one that does was not composed the way
 * they are, and writing it into a live page would run it for every visitor. It is refused instead.
 *
 * A `replace` is judged against the anchor it replaces, kind by kind: a rewrite keeps the element's
 * own opening tag, so an onclick the site put there itself is carried over, not added. Anything
 * inserted `before` or `after` is new in its entirety.
 *
 * @param string $content  The block's markup.
 * @param string $anchor   The anchor it is placed against.
 * @param string $position 'replace', 'before' or 'after'.
 * @return bool
 */
function geoguru_overlay_block_adds_active_content($content, $anchor, $position) {
    $added = geoguru_overlay_active_content($content);
    $kept = $position === 'replace' ? geoguru_overlay_active_content($anchor) : array_fill(0, count($added), 0);
    foreach ($added as $i => $n) {
        if ($n > $kept[$i]) {
            return true;
        }
    }
    return false;
}

/**
 * Whether an incoming envelope carries exactly the content this post already stores.
 *
 * Judged by the sender's checksum, which covers the content and not the bookkeeping around it --
 * when the envelope was built, or which optimization built it -- taken together with the schema
 * version it was computed for. A missing checksum on either side, or a stored envelope that belongs
 * to another post, is never "the same".
 *
 * @param mixed $incoming   Decoded incoming envelope.
 * @param mixed $stored_raw The post meta value currently stored, as a JSON string.
 * @param int   $post_id    The post the incoming envelope is for.
 * @return bool
 */
function geoguru_overlay_same_content($incoming, $stored_raw, $post_id) {
    if (!is_array($incoming) || !isset($incoming['checksum']) || !is_string($incoming['checksum'])
        || $incoming['checksum'] === '') {
        return false;
    }
    if (!is_string($stored_raw) || $stored_raw === '') {
        return false;
    }
    $stored = json_decode($stored_raw, true);
    if (!is_array($stored) || !isset($stored['checksum']) || !is_string($stored['checksum'])) {
        return false;
    }
    if (isset($stored['postId']) && (int) $stored['postId'] !== (int) $post_id) {
        return false;
    }

    return geoguru_overlay_envelope_version($stored) === geoguru_overlay_envelope_version($incoming)
        && $stored['checksum'] === $incoming['checksum'];
}

/**
 * The v2 body blocks this destination may apply, in the envelope's own order.
 *
 * The sender computes that order deliberately -- inserted blocks come before the text rewrites
 * that could destroy the element they anchor to -- so it is part of the contract. Filter, never
 * re-sort.
 * A v1 or unrecognised envelope has no blocks to give.
 *
 * @param mixed $envelope Decoded envelope.
 * @param mixed $target   'original' or 'mirror'.
 * @return array[] Blocks, possibly empty.
 */
function geoguru_overlay_blocks_for($envelope, $target) {
    if (geoguru_overlay_envelope_version($envelope) !== 2) {
        return array();
    }
    if (!isset($envelope['blocks']) || !is_array($envelope['blocks'])) {
        return array();
    }

    $blocks = array();
    foreach ($envelope['blocks'] as $block) {
        if (!is_array($block) || !geoguru_overlay_entry_applies($block, $target)) {
            continue;
        }
        $blocks[] = $block;
    }

    return $blocks;
}
