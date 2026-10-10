<?php
// GET /discovery/posts: every published post of every public, viewable type, in every language,
// a page at a time, for the page-discovery service. Metadata only: no rendered body, no post meta.

defined('ABSPATH') || exit;

define('GEOGURU_DISCOVERY_POSTS_SCHEMA_VERSION', 1);
define('GEOGURU_DISCOVERY_POSTS_DEFAULT_PER_PAGE', 200);
// With url or excerpt the page's posts are loaded whole (bodies included, see
// geoguru_discovery_posts_load); without them a page of rows is cheap.
define('GEOGURU_DISCOVERY_POSTS_MAX_PER_PAGE', 200);
define('GEOGURU_DISCOVERY_POSTS_MAX_PER_PAGE_LIGHT', 1000);
define('GEOGURU_DISCOVERY_POSTS_EXCERPT_MAX_CHARS', 500);

// Never listed, even when named: no front-end pages, and some hold private data (a published
// coupon's title is its code).
define('GEOGURU_DISCOVERY_POSTS_DENIED_TYPES', array(
    'attachment',
    'shop_coupon',
    'shop_order',
    'shop_order_refund',
    'user_request',
    'customize_changeset',
    'oembed_cache',
    'revision',
    'nav_menu_item',
    'wp_global_styles',
));

// Page builders that keep a page's content in post meta, leaving post_content (nearly) empty. REST's
// content.rendered includes what they render, so content_length counts their stored data instead
// when it is longer. Data key => the meta that says the builder renders the post, and its value.
// A page switched back to the block editor keeps its old builder data, which then renders nothing;
// Oxygen has no such switch, so its data always counts.
define('GEOGURU_DISCOVERY_POSTS_BUILDERS', array(
    '_elementor_data' => array('_elementor_edit_mode', 'builder'),
    '_fl_builder_data' => array('_fl_builder_enabled', '1'),
    '_bricks_page_content_2' => array('_bricks_editor_mode', 'bricks'),
    'ct_builder_json' => null,
));

// In response order. wp_post_id is always returned: it is the cursor.
define('GEOGURU_DISCOVERY_POSTS_FIELDS', array(
    'wp_post_type',
    'url',
    'status',
    'language',
    'title',
    'excerpt',
    'date_gmt',
    'modified_gmt',
    'content_length',
    'sticky',
));

function geoguru_register_discovery_posts_routes() {
    register_rest_route('geoguru/v1', '/discovery/posts', array(
        'methods' => 'GET',
        'callback' => 'geoguru_rest_discovery_posts',
        'permission_callback' => 'geoguru_rest_discovery_posts_permission_callback',
        'args' => array(
            'website_id' => array('required' => true, 'type' => 'string'),
            'request_id' => array(
                'required' => true,
                'type' => 'string',
                'validate_callback' => 'geoguru_discovery_posts_valid_request_id',
            ),
            // The cursor: the last ID of the previous page, 0 to start. Pages follow `order`.
            'after_id' => array('type' => 'integer', 'minimum' => 0, 'default' => 0),
            // asc (default): oldest first. desc: newest first, for a caller that keeps only the newest.
            'order' => array('type' => 'string', 'enum' => array('asc', 'desc'), 'default' => 'asc'),
            'per_page' => array('type' => 'integer', 'minimum' => 1, 'default' => GEOGURU_DISCOVERY_POSTS_DEFAULT_PER_PAGE),
            'post_types' => array('type' => 'string'),
            'fields' => array('type' => 'string'),
        ),
    ));
}

// The route is exempt from "REST requires login" plugins (our namespace), so this token check is
// the only thing keeping a site's full post list private.
function geoguru_rest_discovery_posts_permission_callback($request) {
    $logger = GeoGuru_Logger::get_instance();

    $stored_secret = get_option('geoguru_secret_token', '');
    $stored_site_id = get_option('geoguru_site_id', '');
    if ($stored_secret === '' || $stored_site_id === '') {
        $logger->warning('REST discovery-posts: missing site credentials');
        return false;
    }

    $bearer = GeoGuru_Utils::read_bearer_token($request);
    if ($bearer === '' || !hash_equals($stored_secret, $bearer)) {
        $logger->warning('REST discovery-posts: invalid or missing bearer token');
        return false;
    }

    $website_id = $request->get_param('website_id');
    $website_id = is_string($website_id) ? sanitize_text_field($website_id) : '';
    if ($website_id === '' || !hash_equals((string) $stored_site_id, $website_id)) {
        $logger->warning('REST discovery-posts: website_id does not match this site');
        return false;
    }

    return true;
}

// Echoed back so the caller can tell a fresh answer from a cached one.
function geoguru_discovery_posts_valid_request_id($value) {
    return is_string($value) && preg_match('/^[A-Za-z0-9-]{8,64}\z/', $value) === 1;
}

/** Requested fields in response order; every field when none are named. Unknown names are ignored. */
function geoguru_discovery_posts_parse_fields($raw) {
    if (!is_string($raw) || trim($raw) === '') {
        return GEOGURU_DISCOVERY_POSTS_FIELDS;
    }
    $requested = array_map('trim', explode(',', $raw));
    return array_values(array_intersect(GEOGURU_DISCOVERY_POSTS_FIELDS, $requested));
}

function geoguru_discovery_posts_rest_base($type) {
    if (empty($type->show_in_rest)) {
        return null;
    }
    return is_string($type->rest_base) && $type->rest_base !== '' ? $type->rest_base : $type->name;
}

/**
 * The types to list, slug => rest_base (null when hidden from REST), and the named ones ignored.
 *
 * With none named: every public, viewable type. A named type skips only the viewable check, so a
 * public type without front-end URLs can still be asked for. A type can be named by its slug or by
 * its rest_base (the key discovery stores it under). Unknown, non-public and denied types are all
 * simply "ignored", so the answer never shows whether a hidden type exists.
 */
function geoguru_discovery_posts_resolve_types($raw) {
    $types = array();
    $ignored = array();

    if (!is_string($raw) || trim($raw) === '') {
        foreach (get_post_types(array('public' => true), 'objects') as $name => $type) {
            if (in_array($name, GEOGURU_DISCOVERY_POSTS_DENIED_TYPES, true) || !is_post_type_viewable($type)) {
                continue;
            }
            $types[$name] = geoguru_discovery_posts_rest_base($type);
        }
        return array('types' => $types, 'ignored' => $ignored);
    }

    foreach (array_unique(array_map('sanitize_key', explode(',', $raw))) as $name) {
        if ($name === '') {
            continue;
        }
        $type = get_post_type_object($name);
        if (!$type) {
            $type = geoguru_discovery_posts_type_by_rest_base($name);
        }
        if (!$type || empty($type->public) || in_array($type->name, GEOGURU_DISCOVERY_POSTS_DENIED_TYPES, true)) {
            $ignored[] = $name;
            continue;
        }
        $types[$type->name] = geoguru_discovery_posts_rest_base($type);
    }
    return array('types' => $types, 'ignored' => $ignored);
}

/** The public type whose rest_base is $rest_base, or null. */
function geoguru_discovery_posts_type_by_rest_base($rest_base) {
    foreach (get_post_types(array('public' => true), 'objects') as $type) {
        if (geoguru_discovery_posts_rest_base($type) === $rest_base) {
            return $type;
        }
    }
    return null;
}

/**
 * Published, password-free post IDs after $after_id in walk order: lowest first, or highest first
 * when $desc ($after_id 0 starts at the top).
 *
 * A direct query on purpose: WPML adds its language condition through WP_Query's posts_where /
 * posts_join filters, and Polylang through a taxonomy query, so WP_Query would list only the
 * current language. Neither touches this query, so every language is listed with no plugin flag
 * and no change to the site's settings. Ordering by ID keeps the cursor stable: a deleted post
 * shifts nothing, and in ascending order a post published during a walk gets a higher ID and shows
 * up on a later page.
 */
function geoguru_discovery_posts_list_ids($type_names, $after_id, $limit, $desc = false) {
    global $wpdb;
    $placeholders = implode(', ', array_fill(0, count($type_names), '%s'));
    if ($desc) {
        $after = (int) $after_id > 0 ? 'AND ID < %d' : 'AND ID > %d';
        $after_id = (int) $after_id > 0 ? (int) $after_id : 0;
        $order = 'DESC';
    } else {
        $after = 'AND ID > %d';
        $order = 'ASC';
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_type IN ($placeholders) AND post_status = 'publish' AND post_password = ''
                $after
              ORDER BY ID $order
              LIMIT %d",
            array_merge(array_values($type_names), array((int) $after_id, (int) $limit))
        )
    );
    return array_map('intval', (array) $ids);
}

/**
 * The posts, by ID.
 *
 * Whole ($whole, when url or excerpt is asked for): primed into the object cache with their terms,
 * two queries for the page. get_permalink() looks a post (and its terms) up again by ID on many
 * permalink structures (%category%, WooCommerce %product_cat%, WPML's link filters); without the
 * cache that is a full-row query per post. The excerpt needs the bodies anyway.
 *
 * Otherwise without their bodies: one post with pasted images can be several megabytes. CHAR_LENGTH()
 * gives the body's size. These partial posts are never put in the object cache, where a persistent
 * cache would hand their empty bodies to other requests.
 */
function geoguru_discovery_posts_load($ids, $whole) {
    global $wpdb;
    if (!$ids) {
        return array();
    }
    if ($whole) {
        _prime_post_caches($ids, true, false);
        $by_id = array();
        foreach ($ids as $id) {
            $post = get_post($id);
            if ($post instanceof WP_Post) {
                $by_id[(int) $id] = $post;
            }
        }
        return $by_id;
    }
    $columns = 'ID, post_author, post_date, post_date_gmt, post_title, post_excerpt, post_status, comment_status,'
        . ' ping_status, post_password, post_name, post_modified, post_modified_gmt, post_parent, guid, menu_order,'
        . ' post_type, post_mime_type, comment_count, CHAR_LENGTH(post_content) AS geoguru_content_length';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT $columns FROM {$wpdb->posts} WHERE ID IN (" . implode(', ', array_fill(0, count($ids), '%d')) . ')',
            $ids
        )
    );
    $by_id = array();
    foreach ((array) $rows as $row) {
        $row->filter = 'raw';
        if (!isset($row->post_content)) {
            $row->post_content = '';
        }
        $by_id[(int) $row->ID] = new WP_Post($row);
    }
    return $by_id;
}

/**
 * post id => length of the longest page-builder data the post has, counting a builder only while it
 * renders the post (see GEOGURU_DISCOVERY_POSTS_BUILDERS). One query for the page: each data key's
 * length, and the start of each switch key's value.
 */
function geoguru_discovery_posts_builder_lengths($ids) {
    global $wpdb;
    if (!$ids) {
        return array();
    }
    $keys = array_keys(GEOGURU_DISCOVERY_POSTS_BUILDERS);
    foreach (GEOGURU_DISCOVERY_POSTS_BUILDERS as $switch) {
        if ($switch !== null) {
            $keys[] = $switch[0];
        }
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_id, meta_key, CHAR_LENGTH(meta_value) AS geoguru_length, LEFT(meta_value, 20) AS geoguru_head
               FROM {$wpdb->postmeta}
              WHERE post_id IN (" . implode(', ', array_fill(0, count($ids), '%d')) . ')
                AND meta_key IN (' . implode(', ', array_fill(0, count($keys), '%s')) . ')',
            array_merge(array_map('intval', $ids), $keys)
        )
    );
    $meta = array();
    foreach ((array) $rows as $row) {
        $meta[(int) $row->post_id][(string) $row->meta_key] = $row;
    }
    $lengths = array();
    foreach ($meta as $id => $by_key) {
        foreach (GEOGURU_DISCOVERY_POSTS_BUILDERS as $data_key => $switch) {
            if (!isset($by_key[$data_key])) {
                continue;
            }
            if ($switch !== null
                && (!isset($by_key[$switch[0]]) || (string) $by_key[$switch[0]]->geoguru_head !== $switch[1])) {
                continue;
            }
            $lengths[$id] = max(isset($lengths[$id]) ? $lengths[$id] : 0, (int) $by_key[$data_key]->geoguru_length);
        }
    }
    return $lengths;
}

/** 'wpml', 'polylang' or null. WPML wins when both are active. */
function geoguru_discovery_posts_multilingual_plugin() {
    if (defined('ICL_SITEPRESS_VERSION')) {
        return 'wpml';
    }
    if (function_exists('pll_get_post_language')) {
        return 'polylang';
    }
    return null;
}

function geoguru_discovery_posts_site($plugin) {
    $default = null;
    $languages = array();
    if ($plugin === 'wpml') {
        $default = apply_filters('wpml_default_language', null);
        $active = apply_filters('wpml_active_languages', null, array('skip_missing' => 0));
        $languages = is_array($active) ? array_map('strval', array_keys($active)) : array();
    } elseif ($plugin === 'polylang') {
        $default = pll_default_language('slug');
        $list = pll_languages_list(array('fields' => 'slug'));
        $languages = is_array($list) ? array_values(array_map('strval', $list)) : array();
    }
    return array(
        'home_url' => home_url(),
        'multilingual' => $plugin,
        'default_language' => is_string($default) && $default !== '' ? $default : null,
        'languages' => $languages,
    );
}

/** post id => language code (or null) for the posts on this page. */
function geoguru_discovery_posts_languages($posts, $plugin) {
    global $wpdb;
    $languages = array();
    if (!$posts || $plugin === null) {
        return $languages;
    }
    $ids = array_keys($posts);

    if ($plugin === 'polylang') {
        update_object_term_cache($ids, array_unique(array_map(function ($post) { return $post->post_type; }, $posts)));
        foreach ($ids as $id) {
            $code = pll_get_post_language($id, 'slug');
            $languages[$id] = is_string($code) && $code !== '' ? $code : null;
        }
        return $languages;
    }

    // WPML keeps one row per post in icl_translations; one query covers the page.
    $table = $wpdb->prefix . 'icl_translations';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT element_id, language_code FROM $table
              WHERE element_id IN (" . implode(', ', array_fill(0, count($ids), '%d')) . ')
                AND element_type LIKE %s',
            array_merge($ids, array($wpdb->esc_like('post_') . '%'))
        )
    );
    // wpdb returns an empty array on a failed query too; last_error tells the two apart.
    if ($wpdb->last_error === '' && is_array($rows)) {
        foreach ($ids as $id) {
            $languages[$id] = null;
        }
        foreach ($rows as $row) {
            $languages[(int) $row->element_id] = (string) $row->language_code;
        }
        return $languages;
    }
    // The table couldn't be read: ask WPML post by post.
    foreach ($ids as $id) {
        $details = apply_filters('wpml_post_language_details', null, $id);
        $languages[$id] = is_array($details) && !empty($details['language_code']) ? (string) $details['language_code'] : null;
    }
    return $languages;
}

/**
 * The manual excerpt, or the first 55 words of the body with blocks and shortcodes (and whatever
 * they enclose) removed. Plain text, never the_content: rendering would run every plugin's filters,
 * and a membership shortcode's protected content must not leave the site.
 */
function geoguru_discovery_posts_excerpt($post) {
    $manual = trim((string) $post->post_excerpt);
    $text = $manual !== ''
        ? wp_strip_all_tags($manual)
        : wp_trim_words(strip_shortcodes(excerpt_remove_blocks((string) $post->post_content)), 55);
    $text = trim(preg_replace('/\s+/', ' ', $text));
    return function_exists('mb_substr')
        ? mb_substr($text, 0, GEOGURU_DISCOVERY_POSTS_EXCERPT_MAX_CHARS)
        : substr($text, 0, GEOGURU_DISCOVERY_POSTS_EXCERPT_MAX_CHARS);
}

/** ISO 8601 UTC from a post's GMT column, falling back to its local one; null when neither is set. */
function geoguru_discovery_posts_gmt($gmt, $local) {
    $zero = '0000-00-00 00:00:00';
    if (is_string($gmt) && $gmt !== '' && $gmt !== $zero) {
        $value = $gmt;
    } elseif (is_string($local) && $local !== '' && $local !== $zero) {
        $value = get_gmt_from_date($local);
    } else {
        return null;
    }
    $ts = strtotime($value . ' UTC');
    return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
}

function geoguru_discovery_posts_item($post, $fields, $languages, $builder_lengths) {
    $item = array('wp_post_id' => (int) $post->ID);
    foreach ($fields as $field) {
        switch ($field) {
            case 'wp_post_type':
                $item[$field] = (string) $post->post_type;
                break;
            case 'url':
                $item[$field] = (string) get_permalink($post);
                break;
            case 'status':
                $item[$field] = (string) $post->post_status;
                break;
            case 'language':
                $item[$field] = isset($languages[(int) $post->ID]) ? $languages[(int) $post->ID] : null;
                break;
            case 'title':
                // As REST's title.rendered: get_the_title(), entity-encoded.
                $item[$field] = (string) get_the_title($post);
                break;
            case 'excerpt':
                $item[$field] = geoguru_discovery_posts_excerpt($post);
                break;
            case 'date_gmt':
                $item[$field] = geoguru_discovery_posts_gmt($post->post_date_gmt, $post->post_date);
                break;
            case 'modified_gmt':
                $item[$field] = geoguru_discovery_posts_gmt($post->post_modified_gmt, $post->post_modified);
                break;
            case 'content_length':
                // In characters, not bytes, as REST's content.rendered.length is measured: a Hebrew
                // character is two bytes, which would double a Hebrew page's length.
                if (isset($post->geoguru_content_length)) {
                    $body = (int) $post->geoguru_content_length;
                } else {
                    $content = (string) $post->post_content;
                    $body = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
                }
                $builder = isset($builder_lengths[(int) $post->ID]) ? $builder_lengths[(int) $post->ID] : 0;
                $item[$field] = max($body, $builder);
                break;
            case 'sticky':
                $item[$field] = is_sticky((int) $post->ID);
                break;
        }
    }
    return $item;
}

// The answer is per-site and private: no page cache or CDN may keep a copy.
function geoguru_discovery_posts_mark_uncacheable() {
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    do_action('litespeed_control_set_nocache', 'post list for discovery');
}

function geoguru_rest_discovery_posts($request) {
    geoguru_discovery_posts_mark_uncacheable();

    $fields = geoguru_discovery_posts_parse_fields($request->get_param('fields'));
    $whole = in_array('excerpt', $fields, true) || in_array('url', $fields, true);
    $max_per_page = $whole ? GEOGURU_DISCOVERY_POSTS_MAX_PER_PAGE : GEOGURU_DISCOVERY_POSTS_MAX_PER_PAGE_LIGHT;
    $per_page = (int) $request->get_param('per_page');
    $per_page = min($per_page > 0 ? $per_page : GEOGURU_DISCOVERY_POSTS_DEFAULT_PER_PAGE, $max_per_page);
    $after_id = max(0, (int) $request->get_param('after_id'));
    $desc = $request->get_param('order') === 'desc';

    $resolved = geoguru_discovery_posts_resolve_types($request->get_param('post_types'));
    $ids = $resolved['types']
        ? geoguru_discovery_posts_list_ids(array_keys($resolved['types']), $after_id, $per_page + 1, $desc)
        : array();
    $has_more = count($ids) > $per_page;
    $ids = array_slice($ids, 0, $per_page);

    $posts = geoguru_discovery_posts_load($ids, $whole);
    $plugin = geoguru_discovery_posts_multilingual_plugin();
    $languages = in_array('language', $fields, true) ? geoguru_discovery_posts_languages($posts, $plugin) : array();
    $builder_lengths = in_array('content_length', $fields, true) ? geoguru_discovery_posts_builder_lengths($ids) : array();

    $items = array();
    foreach ($ids as $id) {
        if (isset($posts[$id])) {
            $items[] = geoguru_discovery_posts_item($posts[$id], $fields, $languages, $builder_lengths);
        }
    }

    $post_types = array();
    foreach ($resolved['types'] as $slug => $rest_base) {
        $post_types[] = array('slug' => $slug, 'rest_base' => $rest_base);
    }

    $response = new WP_REST_Response(array(
        'schema_version' => GEOGURU_DISCOVERY_POSTS_SCHEMA_VERSION,
        'request_id' => (string) $request->get_param('request_id'),
        'site' => geoguru_discovery_posts_site($plugin),
        'post_types' => $post_types,
        'ignored_post_types' => $resolved['ignored'],
        'posts' => $items,
        'next_after_id' => $ids ? (int) end($ids) : $after_id,
        'has_more' => $has_more,
    ), 200);
    // array_filter: core marks "remove this header" with false (Last-Modified), which the REST server
    // would otherwise send as an empty header.
    $response->set_headers(array_merge(array_filter(wp_get_nocache_headers()), array('Vary' => 'Authorization')));
    return $response;
}
