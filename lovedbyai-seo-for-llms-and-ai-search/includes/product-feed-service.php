<?php

// The WooCommerce product feed that ChatGPT fetches from the store's own domain.
//
// The store owner pastes one URL into ChatGPT's merchant portal, once. The URL carries a random
// token, so it cannot be guessed and any request for it means it was attached there. The URL only
// streams a file saved under uploads.
//
// The file follows events, never the WordPress scheduler:
//   - A product is added, changed, trashed or deleted: at the end of that same request, its rows
//     are replaced in the file. Nothing else is read.
//   - Something every row depends on changes (the store name, the currency, a category or brand is
//     renamed), or there is no file yet: the whole catalog is read again, in slices. Each slice
//     runs in a request of its own that this plugin starts, and starts the next one.
//   - Safety net: once the last full build is a day old, a full build is started again. This fixes
//     a deleted file or a missed event within a day.
// Upkeep (a first build, a build whose next slice never came, the daily safety net) is only checked
// where no visitor waits: an admin page, a fetch of the feed link, the daily plan job, and the
// moment the feed is switched on. A public page view never checks anything.
//
// Two separate things decide whether the feed exists:
//   - the owner's switch (`acp_feed_enabled`), which is ON by default; and
//   - whether the site's plan includes the feed (`product_feed_entitled`). The plugin cannot know
//     the plan, so the backend says so in its settings answer, and until it has said yes the plan
//     counts as not included.
// On a site whose plan does not include it, nothing is built and the feed URL is a 404, whatever the
// switch says. A WooCommerce store asks the backend again once a day, so an upgrade or a downgrade
// takes effect within a day.
//
// Everything this serves is public and is read on white-label sites: the path, the query argument
// and every row are neutral. No plugin or vendor name may appear in any of them.

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/product-feed-mapper.php';

class GeoGuru_ProductFeedService {

    const OPTION_ENABLED = 'geoguru_acp_feed_enabled';
    const OPTION_TOKEN = 'geoguru_product_feed_token';
    const OPTION_STATE = 'geoguru_product_feed_state';
    const OPTION_LAST_FETCH = 'geoguru_product_feed_last_fetch';
    // Set only from the backend's settings answer. Absent means the plan does not include the feed.
    const OPTION_ENTITLED = 'geoguru_product_feed_entitled';

    // Products left out of the live file, by product id, each with its reasons. Kept apart from the
    // state because it can be large; the second one collects a full build still in progress.
    const OPTION_SKIPS = 'geoguru_product_feed_skips';
    const OPTION_SKIPS_BUILDING = 'geoguru_product_feed_skips_building';
    // What was last reported to the backend, so an unchanged status is not sent again.
    const OPTION_REPORTED = 'geoguru_product_feed_reported';
    // A fetch alone is reported at most this often.
    const FETCH_REPORT_SECONDS = 3600;

    const DAILY_HOOK = 'geoguru_product_feed_daily';
    const LOCK_TRANSIENT = 'geoguru_product_feed_lock';
    const KICK_TRANSIENT = 'geoguru_product_feed_kick';

    // Public names: keep them neutral.
    const PUBLIC_PATH = 'product-feed';
    const PUBLIC_QUERY_ARG = 'product_feed';
    const UPLOADS_DIR = 'product-feed';

    const PAGE_SIZE = 50;
    const TIME_BUDGET_SECONDS = 20;
    const LOCK_SECONDS = 120;
    // What a slice may take when it runs at the end of a visitor's request that could not be
    // answered first: the visitor is still waiting on it.
    const SHORT_BUDGET_SECONDS = 3;
    // A full build that has not moved for this long lost its next slice; any request restarts it.
    const STALL_SECONDS = 90;
    // How old the last full build may get before the safety net reads the whole catalog again.
    const REFRESH_SECONDS = 86400;
    // Held by the request that starts a safety-net build, so a busy site starts it only once.
    const REFRESH_CLAIM_TRANSIENT = 'geoguru_product_feed_refresh_claim';
    // More changed products than this at once are cheaper to pick up with a full build.
    const MAX_PENDING = 2000;
    // After the post events flush (1000), which is what answers the request before slow work.
    const SHUTDOWN_PRIORITY = 1001;

    private static $instance = null;
    private $logger;
    private $store = null;
    // This request's events: the products that changed, whether everything must be read again, and
    // whether a full build has to be started or carried on.
    private $changed = array();
    private $rebuild_all = false;
    private $build_due = false;
    private $refresh_due = false;
    // Report the feed's status to the backend at the end of this request; $report_forced sends it
    // even when nothing changed.
    private $report_due = false;
    private $report_forced = false;
    // Whether this request has been answered, once finish_response() has run.
    private $answered = null;

    private function __construct() {
        $this->logger = GeoGuru_Logger::get_instance();
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Which feed a request asks for, from its path and its `product_feed` query value.
     *
     * Two shapes, because a site with plain permalinks never routes an unknown path to WordPress:
     *   /product-feed/<token>.jsonl.gz   (or .jsonl for the uncompressed file)
     *   /?product_feed=<token>           (add &format=jsonl for the uncompressed file)
     *
     * @return array|null array('token' => 32 hex chars, 'gzip' => bool)
     */
    public static function match_request($path, $query_token, $query_format = '') {
        $pattern = '#/' . self::PUBLIC_PATH . '/([a-f0-9]{32})\.jsonl(\.gz)?/?$#';
        if (is_string($path) && preg_match($pattern, $path, $m)) {
            return array('token' => $m[1], 'gzip' => !empty($m[2]));
        }
        if (is_string($query_token) && preg_match('/^[a-f0-9]{32}$/', $query_token)) {
            return array('token' => $query_token, 'gzip' => $query_format !== 'jsonl');
        }
        return null;
    }

    /** The owner's switch. On unless the owner switched it off. */
    public function is_switched_on() {
        return (int) get_option(self::OPTION_ENABLED, 1) === 1;
    }

    /** Whether the backend has said this site's plan includes the feed. */
    public function is_entitled() {
        return (int) get_option(self::OPTION_ENTITLED, 0) === 1;
    }

    /** Whether the feed is built and served: the plan includes it and the owner left it on. */
    public function is_enabled() {
        return (bool) get_option('geoguru_plugin_active', 1)
            && $this->is_entitled()
            && $this->is_switched_on()
            && class_exists('WooCommerce')
            && function_exists('wc_get_product');
    }

    /** Runs on `init`, on every request. */
    public function init() {
        add_action(self::DAILY_HOOK, array($this, 'run_daily'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        if (!class_exists('WooCommerce') || !(bool) get_option('geoguru_plugin_active', 1)) {
            $this->stop(false);
            return;
        }
        // Switched on, or the plan now includes the feed: build it at the end of that same request.
        // Before the is_enabled() return below, since that is exactly the state these change.
        add_action('update_option_' . self::OPTION_ENABLED, array($this, 'on_feed_settings_changed'), 10, 0);
        add_action('update_option_' . self::OPTION_ENTITLED, array($this, 'on_feed_settings_changed'), 10, 0);
        if (!$this->is_enabled()) {
            $this->stop(false);
            return;
        }

        $this->maybe_serve();

        // A product, or one of its variations, changed.
        foreach (array(
            'woocommerce_new_product',
            'woocommerce_update_product',
            'woocommerce_new_product_variation',
            'woocommerce_update_product_variation',
            'before_delete_post',
            'wp_trash_post',
            'untrashed_post',
        ) as $hook) {
            add_action($hook, array($this, 'on_post_changed'), 10, 1);
        }
        add_action('transition_post_status', array($this, 'on_status_changed'), 10, 3);
        add_action('woocommerce_product_set_stock', array($this, 'on_product_object_changed'), 10, 1);
        add_action('woocommerce_variation_set_stock', array($this, 'on_product_object_changed'), 10, 1);
        // Something every row, or many rows, are built from changed.
        foreach (array(
            'update_option_blogname',
            'update_option_woocommerce_currency',
            'update_option_woocommerce_weight_unit',
            'update_option_woocommerce_dimension_unit',
            'edited_product_cat',
            'delete_product_cat',
            'edited_product_brand',
            'delete_product_brand',
        ) as $hook) {
            add_action($hook, array($this, 'on_catalog_changed'), 10, 0);
        }
        add_action('shutdown', array($this, 'on_shutdown'), self::SHUTDOWN_PRIORITY);

        // Upkeep is checked on admin pages only, never on a public page view. admin-ajax is left
        // out: front-end features call it too.
        if (is_admin() && !wp_doing_ajax()) {
            $this->check_upkeep();
        }
    }

    /**
     * Note whether a full build has to start, be carried on, or be redone as the daily safety net.
     * Reads only the state, an autoloaded option: no query, no disk.
     */
    private function check_upkeep() {
        $state = $this->state();
        if (!empty($state['building'])) {
            $beat = isset($state['building']['beat']) ? (int) $state['building']['beat'] : 0;
            $this->build_due = time() - $beat > self::STALL_SECONDS;
        } elseif (empty($state['built_at'])) {
            $this->build_due = true;
        } else {
            $full = isset($state['full_built_at']) ? (int) $state['full_built_at'] : 0;
            $this->refresh_due = time() - $full > self::REFRESH_SECONDS;
        }
    }

    // ---- URL and files ---------------------------------------------------------------------------

    private function token($create = true) {
        $token = get_option(self::OPTION_TOKEN, '');
        if (is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token)) {
            return $token;
        }
        if (!$create) {
            return '';
        }
        try {
            $token = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $token = md5(uniqid((string) wp_rand(), true) . wp_generate_password(32, true, true));
        }
        update_option(self::OPTION_TOKEN, $token);
        return $token;
    }

    public function feed_url() {
        $token = $this->token();
        if ((string) get_option('permalink_structure', '') !== '') {
            return home_url('/' . self::PUBLIC_PATH . '/' . $token . '.jsonl.gz');
        }
        return add_query_arg(self::PUBLIC_QUERY_ARG, $token, home_url('/'));
    }

    private function feed_dir() {
        $uploads = wp_upload_dir(null, false);
        return rtrim($uploads['basedir'], '/') . '/' . self::UPLOADS_DIR;
    }

    private function feed_file() {
        return $this->feed_dir() . '/feed-' . $this->token() . '.jsonl.gz';
    }

    /** The saved file is only ever read through maybe_serve(), never straight from uploads. */
    private function ensure_dir() {
        $dir = $this->feed_dir();
        if (!wp_mkdir_p($dir)) {
            return false;
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents(
                $dir . '/.htaccess',
                "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order Deny,Allow\n  Deny from all\n</IfModule>\n"
            );
        }
        return true;
    }

    private function delete_files() {
        $files = glob($this->feed_dir() . '/feed-*');
        if (is_array($files)) {
            foreach ($files as $file) {
                wp_delete_file($file);
            }
        }
    }

    // ---- Serving ---------------------------------------------------------------------------------

    private function maybe_serve() {
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only feed URL
        $query_token = isset($_GET[self::PUBLIC_QUERY_ARG]) ? sanitize_text_field(wp_unslash($_GET[self::PUBLIC_QUERY_ARG])) : '';
        $query_format = isset($_GET['format']) ? sanitize_text_field(wp_unslash($_GET['format'])) : '';
        // phpcs:enable
        if ($query_token === '' && strpos($uri, '/' . self::PUBLIC_PATH . '/') === false) {
            return;
        }
        $match = self::match_request((string) wp_parse_url($uri, PHP_URL_PATH), $query_token, $query_format);
        $token = $this->token(false);
        if ($match === null || $token === '' || !hash_equals($token, $match['token'])) {
            return; // not ours, or a wrong token: WordPress answers with its own 404
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        // Nothing another plugin buffered may end up in front of, or instead of, the feed.
        @ini_set('zlib.output_compression', 'Off');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('X-Robots-Tag: noindex');

        $file = $this->feed_file();
        // Changes that could not be applied when they happened (a full build or another change was
        // running) are applied now, so a fetch never gets a file that is behind.
        $state = $this->state();
        if (is_readable($file) && empty($state['building']) && !empty($state['pending'])) {
            $this->apply_changes(array());
        }
        if (!is_readable($file)) {
            // Start, or carry on, the build once this answer has gone out. This runs before init()
            // has registered the shutdown hook, and ends the request itself.
            $this->build_due = true;
            add_action('shutdown', array($this, 'on_shutdown'), self::SHUTDOWN_PRIORITY);
            $this->logger->info('Product feed requested before its first build finished');
            status_header(503);
            header('Retry-After: 900');
            exit;
        }

        $this->record_fetch($method);
        $this->report_due = true;
        add_action('shutdown', array($this, 'on_shutdown'), self::SHUTDOWN_PRIORITY);

        // The feed link is one place upkeep is checked. Any build it starts runs after this answer
        // has gone out: ChatGPT gets the current file now and the refreshed one on its next fetch.
        $this->check_upkeep();
        if ($this->build_due || $this->refresh_due) {
            add_action('shutdown', array($this, 'on_shutdown'), self::SHUTDOWN_PRIORITY);
        }

        status_header(200);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($file)) . ' GMT');
        if ($match['gzip']) {
            header('Content-Type: application/gzip');
            header('Content-Length: ' . filesize($file));
        } else {
            header('Content-Type: application/x-ndjson; charset=utf-8');
        }
        if ($method === 'GET') {
            if ($match['gzip']) {
                // Streamed straight from disk: WP_Filesystem can only return a whole file as a string.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
                readfile($file);
            } else {
                readgzfile($file);
            }
        }
        exit;
    }

    private function record_fetch($method) {
        $previous = get_option(self::OPTION_LAST_FETCH, array());
        $count = is_array($previous) && isset($previous['count']) ? (int) $previous['count'] : 0;
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        update_option(self::OPTION_LAST_FETCH, array(
            'at' => time(),
            'method' => $method,
            'user_agent' => substr($agent, 0, 200),
            'count' => $count + 1,
        ), false);
    }

    // ---- Events ----------------------------------------------------------------------------------

    private function state() {
        $state = get_option(self::OPTION_STATE, array());
        return is_array($state) ? $state : array();
    }

    /**
     * Once a day: ask the backend whether this site's plan still includes the feed, then check
     * upkeep, so a store nobody visits in wp-admin still gets its daily safety-net build.
     */
    public function run_daily() {
        // Registered on every site, so a store that installs WooCommerce later needs no new step.
        if (!class_exists('WooCommerce')) {
            return;
        }
        if (function_exists('geoguru_reconcile_synced_settings')) {
            geoguru_reconcile_synced_settings();
        }
        if ($this->is_enabled()) {
            $this->check_upkeep();
        }
        // Once a day the backend hears the status even if nothing changed, so a lost report or a
        // plan change made elsewhere is corrected.
        $this->report_due = true;
        $this->report_forced = true;
        add_action('shutdown', array($this, 'on_shutdown'), self::SHUTDOWN_PRIORITY);
    }

    /**
     * Register the daily job. Called on plugin activation and on each plugin update, never per
     * request. It is the one scheduled thing the feed has, and it is not a rebuild: once a day the
     * store asks the backend whether its plan includes the feed, then checks upkeep.
     */
    public function ensure_daily_job() {
        if (!wp_next_scheduled(self::DAILY_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::DAILY_HOOK);
        }
    }

    /** The owner's switch or the plan changed: build the feed now, or remove it now. */
    public function on_feed_settings_changed() {
        $this->report_due = true;
        add_action('shutdown', array($this, 'on_shutdown'), self::SHUTDOWN_PRIORITY);
        if (!$this->is_enabled()) {
            $this->stop(false);
            return;
        }
        $this->check_upkeep();
    }

    /** A product or variation, by post id. Anything else is ignored. */
    public function on_post_changed($post_id) {
        $post_id = (int) $post_id;
        $type = get_post_type($post_id);
        if ($type === 'product_variation') {
            $post_id = (int) wp_get_post_parent_id($post_id);
            $type = 'product';
        }
        if ($type === 'product' && $post_id > 0) {
            $this->changed[$post_id] = true;
        }
    }

    /** Published, unpublished, made private: the product enters or leaves the feed. */
    public function on_status_changed($new_status, $old_status, $post) {
        if (is_object($post) && $new_status !== $old_status) {
            $this->on_post_changed($post->ID);
        }
    }

    /** Stock changes hand over the product itself, not its id. */
    public function on_product_object_changed($product) {
        if (is_object($product) && method_exists($product, 'get_id')) {
            $this->on_post_changed($product->get_id());
        }
    }

    public function on_catalog_changed() {
        $this->rebuild_all = true;
    }

    /**
     * End of the request: act on what happened during it. The response goes out first where the
     * server allows it, so the person who saved a product does not wait on the feed.
     */
    public function on_shutdown() {
        $changed = array_keys($this->changed);
        $rebuild_all = $this->rebuild_all;
        $build_due = $this->build_due;
        $refresh_due = $this->refresh_due;
        $report_due = $this->report_due;
        $report_forced = $this->report_forced;
        // Cleared first, so nothing carries over if this runs again in the same process.
        $this->changed = array();
        $this->rebuild_all = $this->build_due = $this->refresh_due = false;
        $this->report_due = $this->report_forced = false;

        if (empty($changed) && !$rebuild_all && !$build_due && !$refresh_due && !$report_due) {
            return;
        }
        if (!$this->is_enabled()) {
            if ($report_due) {
                $this->finish_response();
                $this->report_status($report_forced);
            }
            return;
        }
        $this->upkeep($changed, $rebuild_all, $build_due, $refresh_due);
        // A build or a change above also asks for a report.
        if ($report_due || $this->report_due) {
            $this->report_due = false;
            $this->finish_response();
            $this->report_status($report_forced);
        }
    }

    /** The feed work on_shutdown() decided on. */
    private function upkeep($changed, $rebuild_all, $build_due, $refresh_due) {
        if ($refresh_due && !$rebuild_all && !get_transient(self::REFRESH_CLAIM_TRANSIENT)) {
            set_transient(self::REFRESH_CLAIM_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS);
            $rebuild_all = true;
        }
        if (empty($changed) && !$rebuild_all && !$build_due) {
            return;
        }
        $answered = $this->finish_response();

        if ($rebuild_all) {
            $this->request_full_build();
            $build_due = true;
        } elseif (!empty($changed)) {
            $this->apply_changes($changed);
            $build_due = $build_due || $this->build_due; // the file was gone
            $this->build_due = false;
        }
        if ($build_due) {
            $this->run_build($answered ? self::TIME_BUDGET_SECONDS : self::SHORT_BUDGET_SECONDS);
        }
    }

    /**
     * Send the status to the backend, unless it is what was last sent. A fetch on its own is sent
     * at most once an hour. A failed send is not recorded, so the next event or the daily job
     * sends it again.
     */
    private function report_status($forced = false) {
        if (!class_exists('GeoGuru_SupabaseService')) {
            return;
        }
        $site_id = (string) get_option('geoguru_site_id', '');
        $secret = (string) get_option('geoguru_secret_token', '');
        if ($site_id === '' || $secret === '') {
            return;
        }
        $feed = $this->status();
        unset($feed['woocommerce_active']);
        $fetch = is_array($feed['last_fetch']) ? $feed['last_fetch'] : null;
        $rest = $feed;
        unset($rest['last_fetch']);
        $hash = md5((string) wp_json_encode($rest));

        $last = get_option(self::OPTION_REPORTED, array());
        $last = is_array($last) ? $last : array();
        $changed = !isset($last['hash']) || $last['hash'] !== $hash;
        $new_fetch = $fetch !== null
            && (int) $fetch['at'] > (isset($last['fetch_at']) ? (int) $last['fetch_at'] : 0)
            && time() - (isset($last['sent_at']) ? (int) $last['sent_at'] : 0) >= self::FETCH_REPORT_SECONDS;
        if (!$forced && !$changed && !$new_fetch) {
            return;
        }
        if (!GeoGuru_SupabaseService::get_instance()->report_product_feed_via_edge($site_id, $secret, $feed)) {
            return;
        }
        update_option(self::OPTION_REPORTED, array(
            'hash' => $hash,
            'fetch_at' => $fetch !== null ? (int) $fetch['at'] : 0,
            'sent_at' => time(),
        ), false);
    }

    /** @return bool Whether whoever made this request has their answer and is no longer waiting. */
    private function finish_response() {
        if ($this->answered !== null) {
            return $this->answered;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            $this->answered = true;
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
            $this->answered = true;
        } else {
            $this->answered = PHP_SAPI === 'cli' || (function_exists('wp_doing_cron') && wp_doing_cron());
        }
        return $this->answered;
    }

    // ---- One product changed ---------------------------------------------------------------------

    /**
     * Replace the rows of the given products in the live file: their old rows are dropped while the
     * file is copied, and their current rows, if they still belong in the feed, are added.
     *
     * While a full build runs, or when the file is busy, the ids are remembered instead and applied
     * when the build finishes, at the next change, or at the next fetch.
     */
    public function apply_changes($ids) {
        if (!$this->is_enabled()) {
            return;
        }
        $state = $this->state();
        $pending = isset($state['pending']) && is_array($state['pending']) ? $state['pending'] : array();
        $ids = array_values(array_unique(array_map('intval', array_merge($pending, $ids))));
        if (empty($ids)) {
            return;
        }
        if (count($ids) > self::MAX_PENDING) {
            $this->request_full_build();
            $this->build_due = true;
            return;
        }
        $file = $this->feed_file();
        if (!empty($state['building']) || !is_readable($file) || get_transient(self::LOCK_TRANSIENT) || !function_exists('gzopen')) {
            if (empty($state['building']) && !is_readable($file)) {
                $this->build_due = true; // the file is gone: build it again, these products included
            }
            $state['pending'] = $ids;
            update_option(self::OPTION_STATE, $state);
            return;
        }
        set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_SECONDS);

        // The same question a full build asks, for these ids only: published, not password
        // protected, and in the store's default language.
        $current = get_posts($this->catalog_query(array('post__in' => $ids, 'posts_per_page' => count($ids))));
        $skips = get_option(self::OPTION_SKIPS, array());
        $skips = is_array($skips) ? $skips : array();
        $lines = array();
        foreach ($ids as $id) {
            unset($skips[$id]);
        }
        foreach ($current as $id) {
            $this->collect_rows((int) $id, $lines, $skips);
        }

        $staged = $file . '.new';
        $in = @gzopen($file, 'rb');
        $out = @gzopen($staged, 'wb6');
        if (!$in || !$out) {
            if ($in) {
                gzclose($in);
            }
            if ($out) {
                gzclose($out);
            }
            delete_transient(self::LOCK_TRANSIENT);
            $state['pending'] = $ids;
            update_option(self::OPTION_STATE, $state);
            $this->logger->error('Product feed could not be opened to apply product changes');
            return;
        }
        $changed = array_flip($ids);
        $items = 0;
        while (!gzeof($in)) {
            $line = gzgets($in, 1048576);
            if ($line === false) {
                break;
            }
            $line = rtrim($line, "\n");
            $row = $line === '' ? null : json_decode($line, true);
            if (!is_array($row) || !isset($row['item_id'])) {
                continue;
            }
            // A variation's row belongs to its parent product.
            $owner = (int) (isset($row['group_id']) ? $row['group_id'] : $row['item_id']);
            if (isset($changed[$owner])) {
                continue;
            }
            gzwrite($out, $line . "\n");
            $items++;
        }
        foreach ($lines as $line) {
            gzwrite($out, $line . "\n");
            $items++;
        }
        gzclose($in);
        gzclose($out);

        // rename() replaces the live file in one step; WP_Filesystem::move() deletes it first, which
        // would leave a moment with no feed, and may need FTP credentials this request does not have.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
        if (@rename($staged, $file)) {
            unset($state['pending']);
            $state['built_at'] = time();
            $state['items'] = $items;
            update_option(self::OPTION_SKIPS, $skips, false);
            $this->report_due = true;
        } else {
            $state['pending'] = $ids;
            wp_delete_file($staged);
            $this->logger->error('Product feed could not be replaced after product changes');
        }
        update_option(self::OPTION_STATE, $state);
        delete_transient(self::LOCK_TRANSIENT);
    }

    // ---- The whole catalog -----------------------------------------------------------------------

    private function catalog_query($extra) {
        return array_merge(array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'has_password' => false,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            // Language plugins keep their filter: the feed lists the store's default language.
            'suppress_filters' => false,
        ), $extra);
    }

    /** Add one product's rows, as JSON lines, to $lines, and what was left out of it to $skips. */
    private function collect_rows($id, &$lines, &$skips) {
        foreach ($this->rows_for_product($id) as $result) {
            if ($result['row'] === null) {
                $skips[$id][] = $result['skip'];
                continue;
            }
            $line = wp_json_encode($result['row'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($line)) {
                $lines[] = $line;
            }
        }
    }

    private function working_file() {
        return $this->feed_dir() . '/feed-' . $this->token() . '.jsonl.tmp';
    }

    /** Throw away any build in progress and start the catalog again from its first product. */
    public function request_full_build() {
        $state = $this->state();
        $state['building'] = array('offset' => 0, 'items' => 0, 'started_at' => time(), 'beat' => time());
        unset($state['pending']); // a full build reads every product anyway
        update_option(self::OPTION_STATE, $state);
        delete_option(self::OPTION_SKIPS_BUILDING);
        if (is_dir($this->feed_dir())) {
            wp_delete_file($this->working_file());
        }
    }

    /**
     * Start the next slice in a request of its own, without waiting for it. This is what keeps a
     * full build going with no scheduler and no visitor: each slice ends by starting the next.
     */
    private function kick() {
        $key = get_transient(self::KICK_TRANSIENT);
        if (!is_string($key) || $key === '') {
            $key = wp_generate_password(32, false);
        }
        set_transient(self::KICK_TRANSIENT, $key, 10 * MINUTE_IN_SECONDS);
        wp_remote_post(rest_url('geoguru-api/product-feed/build'), array(
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body' => array('key' => $key),
        ));
    }

    /** The request kick() makes. The key is known only to this site, and only while a build runs. */
    public function handle_build($request) {
        $key = get_transient(self::KICK_TRANSIENT);
        $given = (string) $request->get_param('key');
        if (!is_string($key) || $key === '' || !hash_equals($key, $given)) {
            return new WP_Error('unauthorized', 'Unauthorized', array('status' => 401));
        }
        ignore_user_abort(true);
        $this->run_build(self::TIME_BUDGET_SECONDS);
        return array('ok' => true);
    }

    /**
     * One slice of a full build. Reads products for up to $budget seconds and appends their rows to
     * a working file, then either finishes the feed or starts the next slice.
     *
     * Does nothing when there is a live file and no full build was asked for.
     */
    public function run_build($budget = self::TIME_BUDGET_SECONDS) {
        if (!$this->is_enabled() || get_transient(self::LOCK_TRANSIENT)) {
            return;
        }
        if (!function_exists('gzopen') || !$this->ensure_dir()) {
            $this->logger->error('Product feed build cannot start: zlib or the uploads folder is unavailable');
            return;
        }
        $working = $this->working_file();
        $state = $this->state();
        if (empty($state['building'])) {
            if (file_exists($this->feed_file())) {
                return;
            }
            $this->request_full_build();
            $state = $this->state();
        } elseif ((int) $state['building']['offset'] > 0 && !file_exists($working)) {
            $this->request_full_build(); // its working file is gone: start over
            $state = $this->state();
        }
        set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_SECONDS);
        $progress = $state['building'];

        // The working file is appended to slice by slice and later read back in chunks, so a large
        // catalog is never held in memory. WP_Filesystem only reads and writes whole files, which is
        // why this build uses PHP's stream functions directly.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = @fopen($working, 'ab');
        if (!$handle) {
            delete_transient(self::LOCK_TRANSIENT);
            $this->logger->error('Product feed build cannot write its working file');
            return;
        }

        $started = microtime(true);
        $finished = false;
        $skips = get_option(self::OPTION_SKIPS_BUILDING, array());
        $skips = is_array($skips) ? $skips : array();
        $suspended = wp_suspend_cache_addition(true); // a full catalog must not pile up in memory
        while (true) {
            $ids = get_posts($this->catalog_query(array(
                'posts_per_page' => self::PAGE_SIZE,
                'offset' => (int) $progress['offset'],
            )));
            if (empty($ids)) {
                $finished = true;
                break;
            }
            $lines = array();
            foreach ($ids as $id) {
                $this->collect_rows((int) $id, $lines, $skips);
            }
            foreach ($lines as $line) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                fwrite($handle, $line . "\n");
            }
            $progress['items'] += count($lines);
            $progress['offset'] += count($ids);
            $progress['beat'] = time();
            // Changes noted by other requests while this slice ran are in the option, not in $state.
            $state = $this->state();
            $state['building'] = $progress;
            update_option(self::OPTION_STATE, $state);
            set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_SECONDS);
            if (microtime(true) - $started > $budget) {
                break;
            }
        }
        wp_suspend_cache_addition($suspended);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);
        update_option(self::OPTION_SKIPS_BUILDING, $skips, false);

        if (!$finished) {
            delete_transient(self::LOCK_TRANSIENT);
            $this->kick();
            return;
        }

        $published = $this->publish($working);
        $state = $this->state();
        unset($state['building']);
        if ($published) {
            $state['built_at'] = time();
            $state['full_built_at'] = $state['built_at'];
            $state['items'] = (int) $progress['items'];
            update_option(self::OPTION_SKIPS, $skips, false);
            $this->logger->info('Product feed built', array('items' => $state['items']));
            $this->report_due = true;
        } else {
            $this->logger->error('Product feed build could not publish its file');
        }
        update_option(self::OPTION_STATE, $state);
        delete_option(self::OPTION_SKIPS_BUILDING);
        delete_transient(self::KICK_TRANSIENT);
        delete_transient(self::REFRESH_CLAIM_TRANSIENT);
        delete_transient(self::LOCK_TRANSIENT);
        // Products that changed while the catalog was being read: some were read before they changed.
        if ($published && !empty($state['pending'])) {
            $this->apply_changes(array());
        }
    }

    /** Compress the working file and swap it in, so a reader never sees a half-written feed. */
    private function publish($working) {
        $staged = $working . '.gz';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $in = @fopen($working, 'rb');
        $out = @gzopen($staged, 'wb6');
        if (!$in || !$out) {
            if ($in) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($in);
            }
            if ($out) {
                gzclose($out);
            }
            return false;
        }
        while (!feof($in)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $chunk = fread($in, 262144);
            if ($chunk === false) {
                break;
            }
            gzwrite($out, $chunk);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($in);
        gzclose($out);
        wp_delete_file($working);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- one-step swap, see apply_changes()
        return @rename($staged, $this->feed_file());
    }

    // ---- Reading WooCommerce ---------------------------------------------------------------------

    private function store() {
        if ($this->store === null) {
            $name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
            if (trim($name) === '') {
                $name = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            }
            $this->store = array(
                'seller_name' => $name,
                'seller_url' => home_url('/'),
                'currency' => get_woocommerce_currency(),
                'decimals' => wc_get_price_decimals(),
                'weight_unit' => (string) get_option('woocommerce_weight_unit', ''),
                'dimension_unit' => (string) get_option('woocommerce_dimension_unit', ''),
            );
        }
        return $this->store;
    }

    /** @return array[] One mapper result per feed row: the product itself, or each of its variations. */
    private function rows_for_product($id) {
        $product = wc_get_product($id);
        if (!$product) {
            return array();
        }
        if ($product->get_catalog_visibility() === 'hidden') {
            return array(array('row' => null, 'skip' => 'hidden'));
        }
        if (!$product->is_type('variable')) {
            return array(geoguru_product_feed_row($this->read($product, null), $this->store()));
        }
        $results = array();
        foreach ($product->get_children() as $child_id) {
            $variation = wc_get_product($child_id);
            if (!$variation || $variation->get_status() !== 'publish') {
                continue;
            }
            $results[] = geoguru_product_feed_row($this->read($variation, $product), $this->store());
        }
        return $results;
    }

    /** @param WC_Product $product @param WC_Product|null $parent The variable product, for a variation. */
    private function read($product, $parent) {
        $owner = $parent ? $parent : $product;

        $description = (string) $product->get_description();
        if ($description === '' && $parent) {
            $description = (string) $parent->get_description();
        }
        if ($description === '') {
            $description = (string) $owner->get_short_description();
        }

        $regular = $product->get_regular_price();
        $sale = $product->is_on_sale() ? $product->get_sale_price() : '';

        $images = array();
        foreach (array_slice((array) $owner->get_gallery_image_ids(), 0, 10) as $image_id) {
            $images[] = (string) wp_get_attachment_image_url($image_id, 'full');
        }

        $variant = array();
        if ($parent) {
            foreach ((array) $product->get_attributes() as $name => $unused) {
                $variant[wc_attribute_label($name, $parent)] = (string) $product->get_attribute($name);
            }
        }

        return array(
            'id' => $product->get_id(),
            'parent_id' => $parent ? $parent->get_id() : 0,
            'title' => $product->get_name(),
            'description' => $description,
            'url' => $product->get_permalink(),
            'image' => (string) wp_get_attachment_image_url($product->get_image_id(), 'full'),
            'images' => $images,
            // What a shopper sees on the product page, with the store's tax display setting.
            'regular_price' => $regular === '' ? '' : wc_get_price_to_display($product, array('price' => $regular)),
            'sale_price' => $sale === '' ? '' : wc_get_price_to_display($product, array('price' => $sale)),
            'stock_status' => $product->is_on_backorder(1) ? 'onbackorder' : ($product->is_in_stock() ? 'instock' : 'outofstock'),
            'brand' => $this->brand($owner->get_id()),
            'gtin' => method_exists($product, 'get_global_unique_id') ? $product->get_global_unique_id() : '',
            'category' => $this->category_path($owner->get_id()),
            'weight' => $product->get_weight(),
            'length' => $product->get_length(),
            'width' => $product->get_width(),
            'height' => $product->get_height(),
            'review_count' => $owner->get_review_count(),
            'rating' => $owner->get_average_rating(),
            'variant' => $variant,
        );
    }

    /** WooCommerce's own brands, then the two common brand plugins, then a "Brand" attribute. */
    private function brand($product_id) {
        foreach (array('product_brand', 'pwb-brand', 'yith_product_brand', 'pa_brand') as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $names = wp_get_post_terms($product_id, $taxonomy, array('fields' => 'names'));
            if (is_array($names) && !empty($names)) {
                return (string) $names[0];
            }
        }
        return '';
    }

    /** "Home > Kitchen > Mugs" for the product's most specific category. */
    private function category_path($product_id) {
        $terms = get_the_terms($product_id, 'product_cat');
        if (!is_array($terms)) {
            return '';
        }
        $default = (int) get_option('default_product_cat', 0);
        $best = array();
        foreach ($terms as $term) {
            if ((int) $term->term_id === $default) {
                continue; // "Uncategorized"
            }
            $path = array();
            foreach (array_reverse(get_ancestors($term->term_id, 'product_cat', 'taxonomy')) as $ancestor_id) {
                $ancestor = get_term($ancestor_id, 'product_cat');
                if ($ancestor && !is_wp_error($ancestor)) {
                    $path[] = $ancestor->name;
                }
            }
            $path[] = $term->name;
            if (count($path) > count($best)) {
                $best = $path;
            }
        }
        return implode(' > ', $best);
    }

    // ---- Backend status --------------------------------------------------------------------------

    public function register_rest_routes() {
        register_rest_route(
            'geoguru-api',
            '/product-feed',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'handle_status'),
                'permission_callback' => '__return_true', // authenticated via secret token in handler
            )
        );
        register_rest_route(
            'geoguru-api',
            '/product-feed/build',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_build'),
                'permission_callback' => '__return_true', // authenticated via the one-build key in handler
            )
        );
    }

    /** What the portal needs to show the feed: its URL, its last build and whether it is being fetched. */
    public function handle_status($request) {
        $bearer = GeoGuru_Utils::read_bearer_token($request);
        $stored = get_option('geoguru_secret_token', '');
        if ($stored === '' || $bearer === '' || !hash_equals($stored, $bearer)) {
            return new WP_Error('unauthorized', 'Unauthorized', array('status' => 401));
        }
        return $this->status();
    }

    public function status() {
        $enabled = $this->is_enabled();
        $entitled = $this->is_entitled();
        $state = $this->state();
        $fetch = get_option(self::OPTION_LAST_FETCH, null);
        $ready = $enabled && file_exists($this->feed_file());
        return array(
            // The owner's switch, whether or not the plan lets the feed run.
            'enabled' => $this->is_switched_on(),
            // Whether the plan includes the feed. False also before the backend has been asked.
            'entitled' => $entitled,
            'woocommerce_active' => class_exists('WooCommerce'),
            'status' => !$entitled ? 'not_entitled' : (!$enabled ? 'off' : ($ready ? 'ready' : 'building')),
            'url' => $enabled ? $this->feed_url() : null,
            'built_at' => isset($state['built_at']) ? (int) $state['built_at'] : null,
            'items' => isset($state['items']) ? (int) $state['items'] : null,
            'skipped' => (object) $this->skipped_by_reason(),
            'last_fetch' => is_array($fetch) ? $fetch : null,
        );
    }

    /** How many rows the live file leaves out, by reason. */
    private function skipped_by_reason() {
        $skips = get_option(self::OPTION_SKIPS, array());
        $counts = array();
        if (is_array($skips)) {
            foreach ($skips as $reasons) {
                foreach ((array) $reasons as $reason) {
                    $counts[$reason] = isset($counts[$reason]) ? $counts[$reason] + 1 : 1;
                }
            }
        }
        return $counts;
    }

    // ---- Switching off ---------------------------------------------------------------------------

    /**
     * Switched off: stop building and remove the saved file. Cheap when idle.
     *
     * @param bool $daily_too Also drop the daily plan check: WooCommerce is gone or the plugin is off.
     */
    public function stop($daily_too) {
        if ($daily_too && wp_next_scheduled(self::DAILY_HOOK)) {
            wp_clear_scheduled_hook(self::DAILY_HOOK);
        }
        // Emptied, not deleted: reading an option that does not exist costs a query on every request,
        // and this runs on every request of every site where the feed is off.
        if (!empty($this->state())) {
            $this->delete_files();
            update_option(self::OPTION_STATE, array());
            delete_option(self::OPTION_SKIPS);
            delete_option(self::OPTION_SKIPS_BUILDING);
            delete_transient(self::LOCK_TRANSIENT);
            delete_transient(self::KICK_TRANSIENT);
        }
    }

    /** Plugin deactivated. The token stays, so the URL the owner pasted works again on reactivation. */
    public function deactivate() {
        $this->stop(true);
    }

    public function uninstall() {
        $this->stop(true);
        $this->delete_files();
        wp_delete_file($this->feed_dir() . '/index.php');
        wp_delete_file($this->feed_dir() . '/.htaccess');
        // Our own, now empty, folder. WP_Filesystem may need credentials an uninstall does not have.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        @rmdir($this->feed_dir());
        delete_option(self::OPTION_ENABLED);
        delete_option(self::OPTION_ENTITLED);
        delete_option(self::OPTION_TOKEN);
        delete_option(self::OPTION_STATE);
        delete_option(self::OPTION_SKIPS);
        delete_option(self::OPTION_SKIPS_BUILDING);
        delete_option(self::OPTION_LAST_FETCH);
        delete_option(self::OPTION_REPORTED);
    }
}
