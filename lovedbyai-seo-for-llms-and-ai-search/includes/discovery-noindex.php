<?php
// Whether a published post's own page says robots noindex, read from the site's settings instead of
// fetching the page: WordPress's "Discourage search engines" switch, WooCommerce's private pages and
// the stored settings of Yoast SEO, Rank Math, All in One SEO, SEOPress and The SEO Framework. Used by
// GET /discovery/posts (fields=noindex) and by post events, so discovery learns the flag for a page of
// 200 posts in one request instead of one page fetch each.
//
// Each plugin's rule follows its own front-end code, read from the free plugins' source (Yoast 28.8,
// Rank Math 1.0.281, AIOSEO 5.0.5, SEOPress 10.4, TSF 5.2.0). Their front-end APIs are not used: they
// depend on the main query, and Yoast's and AIOSEO's can write to the database. What this cannot see:
// an X-Robots-Tag set by the server, a theme or another plugin printing its own robots tag, and the SEO
// plugins' robots filters. With none of those plugins active, a post is noindex only when the whole
// site is, or when it is one of WooCommerce's private pages.

defined('ABSPATH') || exit;

// Per-post meta keys, by SEO plugin. AIOSEO keeps its per-post setting in its own table.
define('GEOGURU_NOINDEX_META_KEYS', array(
    'yoast' => '_yoast_wpseo_meta-robots-noindex',
    'rank_math' => 'rank_math_robots',
    'seopress' => '_seopress_robots_index',
    'tsf' => '_genesis_noindex',
));

/** The supported SEO plugins that are active, in a fixed order. */
function geoguru_noindex_active_seo_plugins() {
    $active = array();
    if (defined('WPSEO_VERSION')) {
        $active[] = 'yoast';
    }
    // Rank Math prints nothing on the front end until its account step is connected or skipped
    // (rank-math.php init_frontend); its saved settings then don't reach the page.
    if (defined('RANK_MATH_VERSION') && !geoguru_noindex_rank_math_unregistered()) {
        $active[] = 'rank_math';
    }
    if (defined('AIOSEO_VERSION')) {
        $active[] = 'aioseo';
    }
    if (defined('SEOPRESS_VERSION')) {
        $active[] = 'seopress';
    }
    if (defined('THE_SEO_FRAMEWORK_PRESENT') || defined('THE_SEO_FRAMEWORK_VERSION')) {
        $active[] = 'tsf';
    }
    return $active;
}

/** Rank Math's own check (helpers/class-conditional.php): option reads only. Unknown = registered. */
function geoguru_noindex_rank_math_unregistered() {
    if (class_exists('\RankMath\Helper') && method_exists('\RankMath\Helper', 'is_invalid_registration')) {
        return (bool) \RankMath\Helper::is_invalid_registration();
    }
    return false;
}

/** The post meta keys the active SEO plugins keep their per-post setting under. */
function geoguru_noindex_meta_keys() {
    $keys = array();
    foreach (geoguru_noindex_active_seo_plugins() as $plugin) {
        if (array_key_exists($plugin, GEOGURU_NOINDEX_META_KEYS)) {
            $keys[] = GEOGURU_NOINDEX_META_KEYS[$plugin];
        }
    }
    return $keys;
}

/**
 * post id => true (noindex), false (indexable) or null (could not tell) for these posts.
 *
 * @param WP_Post[]  $posts By ID. Only ID and post_type are read, so light rows are fine.
 * @param array|null $meta  The posts' meta rows from geoguru_discovery_posts_meta_rows(), holding the
 *                          values of geoguru_noindex_meta_keys(); read here when not given.
 * @return array
 */
function geoguru_discovery_posts_noindex($posts, $meta = null) {
    $result = array();
    if (!$posts) {
        return $result;
    }
    $ids = array_map('intval', array_keys($posts));

    // Every SEO plugin, and core when none is active, prints noindex on a site that discourages search
    // engines, whatever the post says.
    if ((string) get_option('blog_public') === '0') {
        foreach ($ids as $id) {
            $result[$id] = true;
        }
        return $result;
    }

    $plugins = geoguru_noindex_active_seo_plugins();
    if ($meta === null) {
        $keys = geoguru_noindex_meta_keys();
        $meta = $keys ? geoguru_discovery_posts_meta_rows($ids, array(), $keys) : array();
    }
    // Read once for the whole page, not per post.
    $context = array(
        'front_page_id' => get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0,
        'woo_private_ids' => geoguru_noindex_woo_private_page_ids(),
        'yoast_indexables' => in_array('yoast', $plugins, true) ? geoguru_noindex_yoast_indexables($ids) : null,
        'aioseo_rows' => in_array('aioseo', $plugins, true) ? geoguru_noindex_aioseo_rows($ids) : null,
        'aioseo_dynamic' => in_array('aioseo', $plugins, true) ? geoguru_noindex_aioseo_option('aioseo_options_dynamic') : array(),
        'aioseo_global' => in_array('aioseo', $plugins, true) ? geoguru_noindex_aioseo_global_robots() : array(),
    );

    foreach ($posts as $id => $post) {
        $id = (int) $id;
        $values = array();
        foreach (isset($meta[$id]) ? $meta[$id] : array() as $key => $row) {
            $values[$key] = $row['value'];
        }
        $type = (string) $post->post_type;
        $woo_private = in_array($id, $context['woo_private_ids'], true);

        if (!$plugins) {
            // WooCommerce's own wp_robots filter (wc_page_no_robots) noindexes its private pages.
            $result[$id] = $woo_private;
            continue;
        }

        // Two SEO plugins at once print two robots tags, and a search engine obeys the stricter one.
        $noindex = false;
        foreach ($plugins as $plugin) {
            switch ($plugin) {
                case 'yoast':
                    $answer = geoguru_noindex_yoast($id, $type, $values, $woo_private, $context);
                    break;
                case 'rank_math':
                    $answer = geoguru_noindex_rank_math($type, $values, $woo_private);
                    break;
                case 'aioseo':
                    $answer = geoguru_noindex_aioseo($id, $type, $woo_private, $context);
                    break;
                case 'seopress':
                    $answer = geoguru_noindex_seopress($type, $values);
                    break;
                case 'tsf':
                    $answer = geoguru_noindex_tsf($id, $type, $values, $woo_private, $context);
                    break;
                default:
                    $answer = false;
            }
            if ($answer === null) {
                $noindex = null;
                break;
            }
            $noindex = $noindex || $answer;
        }
        $result[$id] = $noindex;
    }
    return $result;
}

/** The IDs of WooCommerce's cart, checkout and account pages (none without WooCommerce). */
function geoguru_noindex_woo_private_page_ids() {
    if (!function_exists('wc_get_page_id')) {
        return array();
    }
    $ids = array();
    foreach (array('cart', 'checkout', 'myaccount') as $page) {
        $id = (int) wc_get_page_id($page);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * post id => Yoast's stored is_robots_noindex (1, 0 or null) from its indexable table, the value its
 * front end prints from; null when the table can't be read. A post with no indexable yet gets one built
 * from its meta on the first view, so the meta is the answer for those.
 */
function geoguru_noindex_yoast_indexables($ids) {
    global $wpdb;
    $table = $wpdb->prefix . 'yoast_indexable';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT object_id, is_robots_noindex FROM $table
              WHERE object_type = 'post' AND object_id IN (" . implode(', ', array_fill(0, count($ids), '%d')) . ')',
            $ids
        )
    );
    // wpdb returns an empty array on a failed query too; last_error tells the two apart.
    if ($wpdb->last_error !== '' || !is_array($rows)) {
        return null;
    }
    $by_id = array();
    foreach ($rows as $row) {
        $by_id[(int) $row->object_id] = $row->is_robots_noindex === null ? null : (int) $row->is_robots_noindex;
    }
    return $by_id;
}

/**
 * Yoast: the post's own setting (1 noindex, 0 index) wins; with none, the post type's "show in search
 * results" setting (src/presentations/indexable-post-type-presentation.php, src/helpers/post-type-helper.php).
 * The own setting comes from the indexable when there is one, else from the meta it is built from ('1'
 * noindex, '2' index). Yoast merges core's robots, so WooCommerce's private pages stay noindex.
 */
function geoguru_noindex_yoast($id, $type, $values, $woo_private, $context) {
    if ($woo_private) {
        return true;
    }
    $indexables = $context['yoast_indexables'];
    if (is_array($indexables) && array_key_exists($id, $indexables)) {
        $own = $indexables[$id];
    } else {
        $key = GEOGURU_NOINDEX_META_KEYS['yoast'];
        $value = isset($values[$key]) ? (int) $values[$key] : 0;
        $own = $value === 1 ? 1 : ($value === 2 ? 0 : null);
    }
    if ($own !== null) {
        return $own === 1;
    }
    $titles = get_option('wpseo_titles');
    $titles = is_array($titles) ? $titles : array();
    return !empty($titles['disable-' . $type]) || !empty($titles['noindex-' . $type]);
}

/**
 * Rank Math: the post's robots when it has any, else the post type's when "custom robots" is on for it,
 * else the site-wide default (frontend/paper/class-singular.php, class-paper.php). A non-empty set
 * without noindex is index. It noindexes WooCommerce's private pages and Elementor templates itself.
 */
function geoguru_noindex_rank_math($type, $values, $woo_private) {
    if ($woo_private || $type === 'elementor_library') {
        return true;
    }
    $key = GEOGURU_NOINDEX_META_KEYS['rank_math'];
    $robots = isset($values[$key]) ? maybe_unserialize($values[$key]) : array();
    if (is_array($robots) && $robots) {
        return in_array('noindex', $robots, true);
    }
    $titles = get_option('rank-math-options-titles');
    $titles = is_array($titles) ? $titles : array();
    $custom = isset($titles['pt_' . $type . '_custom_robots']) ? $titles['pt_' . $type . '_custom_robots'] : 'off';
    if ($custom === 'on' || $custom === true) {
        $robots = isset($titles['pt_' . $type . '_robots']) ? $titles['pt_' . $type . '_robots'] : array();
        // An empty set here reads as index (robots_combine with its default on).
        return is_array($robots) && in_array('noindex', $robots, true);
    }
    $robots = isset($titles['robots_global']) ? $titles['robots_global'] : array();
    return is_array($robots) && in_array('noindex', $robots, true);
}

/**
 * post id => array(robots_default, robots_noindex) from AIOSEO's own table; null when the table can't
 * be read (then every AIOSEO answer is unknown).
 */
function geoguru_noindex_aioseo_rows($ids) {
    global $wpdb;
    $table = $wpdb->prefix . 'aioseo_posts';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_id, robots_default, robots_noindex FROM $table
              WHERE post_id IN (" . implode(', ', array_fill(0, count($ids), '%d')) . ')',
            $ids
        )
    );
    if ($wpdb->last_error !== '' || !is_array($rows)) {
        return null;
    }
    $by_id = array();
    foreach ($rows as $row) {
        $by_id[(int) $row->post_id] = array((int) $row->robots_default, (int) $row->robots_noindex);
    }
    return $by_id;
}

/** An AIOSEO option: stored as a JSON string, decoded to nested arrays. */
function geoguru_noindex_aioseo_option($name) {
    $raw = get_option($name);
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
    return is_array($decoded) ? $decoded : array();
}

/** AIOSEO's site-wide robots setting (searchAppearance.advanced.globalRobotsMeta). */
function geoguru_noindex_aioseo_global_robots() {
    $options = geoguru_noindex_aioseo_option('aioseo_options');
    return isset($options['searchAppearance']['advanced']['globalRobotsMeta']) && is_array($options['searchAppearance']['advanced']['globalRobotsMeta'])
        ? $options['searchAppearance']['advanced']['globalRobotsMeta']
        : array();
}

/**
 * AIOSEO (app/Common/Meta/Robots.php): the first page of the homepage is never noindex. A post whose
 * row says "not default" uses its own noindex; WooCommerce's private pages without a row default to
 * noindex (Models/Post.php setDynamicDefaults). Otherwise, for a type AIOSEO lists (viewable, with a
 * label: Helpers/Wp.php getPublicPostTypes), the type's "show in search results", then its robots
 * setting, or the global one when the type's is "default". Missing keys are the defaults: shown, and
 * robots "default" (nothing set).
 */
function geoguru_noindex_aioseo($id, $type, $woo_private, $context) {
    $rows = $context['aioseo_rows'];
    if ($rows === null) {
        return null;
    }
    if ($id === $context['front_page_id']) {
        return false;
    }
    if (isset($rows[$id])) {
        if ($rows[$id][0] === 0) {
            return $rows[$id][1] === 1;
        }
    } elseif ($woo_private) {
        return true;
    }

    $type_object = get_post_type_object($type);
    if (!$type_object || empty($type_object->label) || !is_post_type_viewable($type_object)) {
        return false;
    }
    $dynamic = $context['aioseo_dynamic'];
    $type_options = isset($dynamic['searchAppearance']['postTypes'][$type]) && is_array($dynamic['searchAppearance']['postTypes'][$type])
        ? $dynamic['searchAppearance']['postTypes'][$type]
        : array();
    $hidden = array_key_exists('show', $type_options) && !$type_options['show'];

    $robots = isset($type_options['advanced']['robotsMeta']) && is_array($type_options['advanced']['robotsMeta'])
        ? $type_options['advanced']['robotsMeta']
        : null;
    if ($robots === null || !array_key_exists('default', $robots) || $robots['default']) {
        $robots = $context['aioseo_global'];
    }
    if (!array_key_exists('default', $robots) || $robots['default']) {
        return $hidden;
    }
    return $hidden || !empty($robots['noindex']);
}

/**
 * SEOPress (inc/functions/options-titles-metas.php): prints robots only with its Titles module on;
 * then the site-wide noindex, the post type's, or the post's own "yes" -- any of them, with no
 * per-post override. It empties core's robots on WooCommerce's private pages (seopress-functions.php
 * seopress_robots_wc_pages), so those follow its own rules like any page.
 */
function geoguru_noindex_seopress($type, $values) {
    $toggle = get_option('seopress_toggle');
    if (!is_array($toggle) || !isset($toggle['toggle-titles']) || (string) $toggle['toggle-titles'] !== '1') {
        return false;
    }
    $titles = get_option('seopress_titles_option_name');
    $titles = is_array($titles) ? $titles : array();
    if (!empty($titles['seopress_titles_noindex'])) {
        return true;
    }
    if (!empty($titles['seopress_titles_single_titles'][$type]['noindex'])) {
        return true;
    }
    $key = GEOGURU_NOINDEX_META_KEYS['seopress'];
    return isset($values[$key]) && $values[$key] === 'yes';
}

/**
 * The SEO Framework (inc/classes/meta/robots/front.class.php): no output for a type it's disabled
 * for. Otherwise the post's qubit: -1 forces index past every global, 1 is noindex; 0 falls through to
 * WooCommerce's private pages (compat/plugin-woocommerce.php: noindex by default), the site-wide
 * setting, the homepage's (on the front page) and the post type's.
 */
function geoguru_noindex_tsf($id, $type, $values, $woo_private, $context) {
    $settings = get_option('autodescription-site-settings');
    $settings = is_array($settings) ? $settings : array();
    if (!empty($settings['disabled_post_types'][$type])) {
        return false;
    }
    $key = GEOGURU_NOINDEX_META_KEYS['tsf'];
    $qubit = isset($values[$key]) ? (int) $values[$key] : 0;
    if ($qubit < 0) {
        return false;
    }
    if ($qubit > 0 || $woo_private) {
        return true;
    }
    if (!empty($settings['site_noindex'])) {
        return true;
    }
    if ($id === $context['front_page_id'] && !empty($settings['homepage_noindex'])) {
        return true;
    }
    return !empty($settings['noindex_post_types'][$type]);
}
