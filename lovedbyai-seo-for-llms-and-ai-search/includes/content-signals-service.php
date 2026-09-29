<?php
// GET /content-signals: publishing cadence, thin or stale pages and the newest posts.

defined('ABSPATH') || exit;

define('GEOGURU_CONTENT_SIGNALS_SCHEMA_VERSION', 1);

// Wider than the service's own thresholds, so it can tune them without a plugin release.
define('GEOGURU_CONTENT_SIGNALS_CANDIDATE_MAX_CHARS', 2000);
define('GEOGURU_CONTENT_SIGNALS_CANDIDATE_MAX_AGE_DAYS', 180);

define('GEOGURU_CONTENT_SIGNALS_MAX_ITEMS', 1500);
// Bodies are read a few at a time and cut to a maximum length: one post with pasted images can
// be several megabytes.
define('GEOGURU_CONTENT_SIGNALS_SCAN_PAGE_SIZE', 20);
define('GEOGURU_CONTENT_SIGNALS_MAX_BODY_CHARS', 500000);
define('GEOGURU_CONTENT_SIGNALS_MAX_POSTS_SCANNED', 10000);
// Keeps a cold scan inside the service's request timeout; a stop marks the list truncated.
define('GEOGURU_CONTENT_SIGNALS_SCAN_BUDGET_SECONDS', 15);
// Per post type, so products or builder templates cannot crowd out the articles.
define('GEOGURU_CONTENT_SIGNALS_RECENT_COUNT', 20);
define('GEOGURU_CONTENT_SIGNALS_MAX_CADENCE_ROWS', 500);

define('GEOGURU_CONTENT_SIGNALS_TRANSIENT', 'geoguru_content_signals');
define('GEOGURU_CONTENT_SIGNALS_CACHE_TTL', 15 * MINUTE_IN_SECONDS);

function geoguru_register_content_signals_routes() {
    register_rest_route('geoguru/v1', '/content-signals', array(
        'methods' => 'GET',
        'callback' => 'geoguru_rest_content_signals',
        'permission_callback' => 'geoguru_rest_content_signals_permission_callback',
        'args' => array(
            'website_id' => array(
                'required' => true,
                'type' => 'string',
            ),
        ),
    ));
}

// The website_id check stops a token copied to the wrong site from returning that site's content.
function geoguru_rest_content_signals_permission_callback($request) {
    $logger = GeoGuru_Logger::get_instance();

    $stored_secret = get_option('geoguru_secret_token', '');
    $stored_site_id = get_option('geoguru_site_id', '');
    if ($stored_secret === '' || $stored_site_id === '') {
        $logger->warning('REST content-signals: missing site credentials');
        return false;
    }

    $bearer = GeoGuru_Utils::read_bearer_token($request);
    if ($bearer === '' || !hash_equals($stored_secret, $bearer)) {
        $logger->warning('REST content-signals: invalid or missing bearer token');
        return false;
    }

    $website_id = $request->get_param('website_id');
    $website_id = is_string($website_id) ? sanitize_text_field($website_id) : '';
    if ($website_id === '' || !hash_equals((string) $stored_site_id, $website_id)) {
        $logger->warning('REST content-signals: website_id does not match this site');
        return false;
    }

    return true;
}

// The body as a reader sees it: markup and shortcode wrappers removed, whitespace collapsed.
function geoguru_content_signals_text($content) {
    if (!is_string($content) || $content === '') {
        return '';
    }
    $text = (string) wp_strip_all_tags($content, true);
    // preg_replace() returns null when it gives up on a huge body; keep the text rather than lose it.
    $without_shortcodes = preg_replace('/\[\/?[a-zA-Z0-9_-][^\]]*\]/', '', $text);
    $text = is_string($without_shortcodes) ? $without_shortcodes : $text;
    $collapsed = preg_replace('/\s+/', ' ', $text);
    return trim(is_string($collapsed) ? $collapsed : $text);
}

function geoguru_content_signals_text_chars($text) {
    if ($text === '') {
        return 0;
    }
    return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
}

function geoguru_content_signals_text_words($text) {
    return $text === '' ? 0 : count(preg_split('/\s+/', $text));
}

function geoguru_content_signals_post_types() {
    $custom = get_post_types(array('public' => true, '_builtin' => false), 'names');
    if (!is_array($custom)) {
        $custom = array();
    }
    $types = array_merge(array('post', 'page'), array_values($custom));
    $types = array_values(array_unique(array_diff($types, array('attachment'))));
    sort($types);
    return $types;
}

function geoguru_content_signals_median($values) {
    $values = array_values(array_filter($values, 'is_numeric'));
    $count = count($values);
    if ($count === 0) {
        return null;
    }
    sort($values);
    $mid = intdiv($count, 2);
    if ($count % 2 === 1) {
        return (float) $values[$mid];
    }
    return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
}

// $published_ts: recent publish timestamps, any order. $last_published_ts: the newest of all time.
function geoguru_content_signals_cadence($published_ts, $last_published_ts, $now) {
    $published_ts = array_values(array_filter(array_map('intval', (array) $published_ts)));
    rsort($published_ts);

    $within = function ($days) use ($published_ts, $now) {
        $cutoff = $now - ($days * DAY_IN_SECONDS);
        $n = 0;
        foreach ($published_ts as $ts) {
            if ($ts >= $cutoff) {
                $n++;
            }
        }
        return $n;
    };

    $year_cutoff = $now - (365 * DAY_IN_SECONDS);
    $in_year = array_values(array_filter($published_ts, function ($ts) use ($year_cutoff) {
        return $ts >= $year_cutoff;
    }));
    $gaps = array();
    for ($i = 0; $i < count($in_year) - 1; $i++) {
        $gaps[] = ($in_year[$i] - $in_year[$i + 1]) / DAY_IN_SECONDS;
    }
    $median_gap = geoguru_content_signals_median($gaps);

    return array(
        'last_published_gmt' => $last_published_ts ? gmdate('c', $last_published_ts) : null,
        'posts_last_365d' => $within(365),
        'median_gap_days_365d' => $median_gap === null ? null : round($median_gap, 1),
    );
}

// Parsed as UTC: mysql2date() would apply the site's timezone. 0 for the legacy zero date.
function geoguru_content_signals_gmt_ts($mysql_gmt) {
    if (!is_string($mysql_gmt) || $mysql_gmt === '' || strpos($mysql_gmt, '0000-00-00') === 0) {
        return 0;
    }
    $ts = strtotime($mysql_gmt . ' UTC');
    return $ts ? $ts : 0;
}

// Dates only, newest first: WP_Query would load every post body to return them.
function geoguru_content_signals_publish_dates() {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT post_date_gmt FROM {$wpdb->posts}
              WHERE post_type = 'post' AND post_status = 'publish'
              ORDER BY post_date_gmt DESC
              LIMIT %d",
            GEOGURU_CONTENT_SIGNALS_MAX_CADENCE_ROWS
        )
    );
    $published_ts = array_values(array_filter(array_map('geoguru_content_signals_gmt_ts', (array) $rows)));

    return array($published_ts, $published_ts ? $published_ts[0] : null);
}

// Published, unprotected post IDs. IDs only, so no post is loaded or cached.
function geoguru_content_signals_ids($args) {
    $query = new WP_Query(array_merge(array(
        'post_status' => 'publish',
        'has_password' => false,
        'fields' => 'ids',
        'no_found_rows' => true,
        'cache_results' => false,
        'ignore_sticky_posts' => true,
    ), $args));
    return array_map('intval', (array) $query->posts);
}

// Skips post_content_filtered, which some plugins fill with a second copy of the body.
function geoguru_content_signals_fetch_posts($ids) {
    global $wpdb;
    if (!$ids) {
        return array();
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID, post_author, post_date, post_date_gmt, LEFT(post_content, %d) AS post_content,
                    post_title, post_excerpt, post_status, comment_status, ping_status, post_password,
                    post_name, post_modified, post_modified_gmt, post_parent, guid, menu_order,
                    post_type, post_mime_type, comment_count
               FROM {$wpdb->posts}
              WHERE ID IN (" . implode(', ', array_fill(0, count($ids), '%d')) . ')',
            array_merge(array(GEOGURU_CONTENT_SIGNALS_MAX_BODY_CHARS), $ids)
        )
    );
    $by_id = array();
    foreach ((array) $rows as $row) {
        $by_id[(int) $row->ID] = $row;
    }
    return $by_id;
}

// Evicts the post afterwards: other plugins' link filters can load and cache the full post.
function geoguru_content_signals_link_and_title($post) {
    $link = array(get_permalink($post), get_the_title($post));
    wp_cache_delete($post->ID, 'posts');
    wp_cache_delete($post->ID, 'post_meta');
    return $link;
}

// Lists IDs once in (modified, ID) order, then reads bodies a batch at a time. Full WP_Query
// results are avoided because they cache every post they load.
function geoguru_content_signals_scan($now, $budget_seconds = GEOGURU_CONTENT_SIGNALS_SCAN_BUDGET_SECONDS) {
    $deadline = microtime(true) + $budget_seconds;
    $stale_cutoff = $now - (GEOGURU_CONTENT_SIGNALS_CANDIDATE_MAX_AGE_DAYS * DAY_IN_SECONDS);

    $listed = geoguru_content_signals_ids(array(
        'post_type' => geoguru_content_signals_post_types(),
        'orderby' => array('modified' => 'ASC', 'ID' => 'ASC'),
        'posts_per_page' => GEOGURU_CONTENT_SIGNALS_MAX_POSTS_SCANNED + 1,
    ));
    $stopped = count($listed) > GEOGURU_CONTENT_SIGNALS_MAX_POSTS_SCANNED;
    $batches = array_chunk(array_slice($listed, 0, GEOGURU_CONTENT_SIGNALS_MAX_POSTS_SCANNED), GEOGURU_CONTENT_SIGNALS_SCAN_PAGE_SIZE);

    $candidates = array();
    $candidate_total = 0;
    $posts_scanned = 0;
    foreach ($batches as $ids) {
        if (microtime(true) >= $deadline) {
            $stopped = true;
            break;
        }
        $by_id = geoguru_content_signals_fetch_posts($ids);

        foreach ($ids as $id) {
            if (microtime(true) >= $deadline) {
                $stopped = true;
                break 2;
            }
            if (!isset($by_id[$id])) {
                continue; // Deleted since it was listed.
            }
            $row = $by_id[$id];
            unset($by_id[$id]);
            $posts_scanned++;

            $text = geoguru_content_signals_text($row->post_content);
            $char_count = geoguru_content_signals_text_chars($text);
            $modified_ts = geoguru_content_signals_gmt_ts($row->post_modified_gmt);
            if (!$modified_ts
                || ($char_count >= GEOGURU_CONTENT_SIGNALS_CANDIDATE_MAX_CHARS && $modified_ts >= $stale_cutoff)) {
                continue;
            }

            $candidate_total++;
            if ($candidate_total > GEOGURU_CONTENT_SIGNALS_MAX_ITEMS) {
                continue;
            }
            $post = get_post($row);
            $post->post_content = '';
            $candidates[] = array(
                'post' => $post,
                'modified_ts' => $modified_ts,
                'char_count' => $char_count,
                'word_count' => geoguru_content_signals_text_words($text),
            );
        }
    }

    $items = array();
    foreach ($candidates as $candidate) {
        if (microtime(true) >= $deadline) {
            $stopped = true;
            break;
        }
        $post = $candidate['post'];
        list($url, $title) = geoguru_content_signals_link_and_title($post);
        $items[] = array(
            'id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'url' => $url,
            'title' => $title,
            'modified_gmt' => gmdate('c', $candidate['modified_ts']),
            'char_count' => $candidate['char_count'],
            'word_count' => $candidate['word_count'],
        );
    }

    return array(
        'items' => $items,
        'candidate_total' => $candidate_total,
        'posts_scanned' => $posts_scanned,
        'items_truncated' => $stopped || $candidate_total > GEOGURU_CONTENT_SIGNALS_MAX_ITEMS,
    );
}

function geoguru_content_signals_recent_posts() {
    $recent = array();
    foreach (array_diff(geoguru_content_signals_post_types(), array('page')) as $type) {
        $ids = geoguru_content_signals_ids(array(
            'post_type' => $type,
            'orderby' => array('date' => 'DESC', 'ID' => 'DESC'),
            'posts_per_page' => GEOGURU_CONTENT_SIGNALS_RECENT_COUNT,
        ));
        $rows = geoguru_content_signals_fetch_posts($ids);

        foreach ($ids as $id) {
            if (!isset($rows[$id])) {
                continue;
            }
            $row = $rows[$id];
            unset($rows[$id]);
            $published_ts = geoguru_content_signals_gmt_ts($row->post_date_gmt);
            if (!$published_ts) {
                continue;
            }
            $modified_ts = geoguru_content_signals_gmt_ts($row->post_modified_gmt);
            $text = geoguru_content_signals_text($row->post_content);
            $post = get_post($row);
            $post->post_content = '';
            list($url, $title) = geoguru_content_signals_link_and_title($post);
            $recent[] = array(
                'id' => $id,
                'post_type' => (string) $row->post_type,
                'url' => $url,
                'title' => $title,
                'date_gmt' => gmdate('c', $published_ts),
                'modified_gmt' => gmdate('c', $modified_ts ? $modified_ts : $published_ts),
                'char_count' => geoguru_content_signals_text_chars($text),
                'categories' => geoguru_content_signals_term_names($id, 'category'),
                'tags' => geoguru_content_signals_term_names($id, 'post_tag'),
                'excerpt' => wp_trim_words(
                    $row->post_excerpt !== '' ? geoguru_content_signals_text($row->post_excerpt) : $text,
                    60,
                    ''
                ),
            );
        }
    }

    // UTC ISO dates compare correctly as strings.
    usort($recent, function ($a, $b) {
        $by_date = strcmp($b['date_gmt'], $a['date_gmt']);
        return $by_date !== 0 ? $by_date : $b['id'] - $a['id'];
    });
    return $recent;
}

function geoguru_content_signals_term_names($post_id, $taxonomy) {
    $names = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'names'));
    return is_array($names) ? array_values($names) : array();
}

function geoguru_content_signals_build_payload() {
    $now = time();

    list($published_ts, $last_published_ts) = geoguru_content_signals_publish_dates();
    $scan = geoguru_content_signals_scan($now);

    return array(
        'schema_version' => GEOGURU_CONTENT_SIGNALS_SCHEMA_VERSION,
        'plugin_version' => defined('GEOGURU_PLUGIN_VERSION') ? GEOGURU_PLUGIN_VERSION : 'unknown',
        'generated_at' => gmdate('c', $now),
        'site' => array(
            'home_url' => home_url(),
            'admin_url' => admin_url(),
            'locale' => get_locale(),
        ),
        'cadence' => geoguru_content_signals_cadence($published_ts, $last_published_ts, $now),
        'recent' => geoguru_content_signals_recent_posts(),
        'items' => $scan['items'],
        'candidate_total' => $scan['candidate_total'],
        'posts_scanned' => $scan['posts_scanned'],
        'items_truncated' => $scan['items_truncated'],
    );
}

function geoguru_rest_content_signals($_request) {
    $payload = get_transient(GEOGURU_CONTENT_SIGNALS_TRANSIENT);
    if (!is_array($payload)) {
        $payload = geoguru_content_signals_build_payload();
        set_transient(GEOGURU_CONTENT_SIGNALS_TRANSIENT, $payload, GEOGURU_CONTENT_SIGNALS_CACHE_TTL);
    }

    $response = new WP_REST_Response($payload, 200);
    // The transient is the only cache; a proxy copy would serve stale data.
    $response->set_headers(array(
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
    ));
    return $response;
}

function geoguru_content_signals_clear_cache($_post_id = 0) {
    delete_transient(GEOGURU_CONTENT_SIGNALS_TRANSIENT);
}
add_action('save_post', 'geoguru_content_signals_clear_cache');
add_action('trashed_post', 'geoguru_content_signals_clear_cache');
add_action('deleted_post', 'geoguru_content_signals_clear_cache');
