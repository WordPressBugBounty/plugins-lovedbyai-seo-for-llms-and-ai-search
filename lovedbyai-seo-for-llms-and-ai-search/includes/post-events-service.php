<?php

defined('ABSPATH') || exit;

// The post-list route's type rule, REST base and item builder: an event and a route walk describe
// a post the same way. require_once, so the plugin's own include of that file is a no-op.
require_once __DIR__ . '/discovery-posts-service.php';

/**
 * Post events: at the end of a request, tells the discovery service which public posts were
 * published, updated or removed during it. A new or edited post is then picked up in minutes rather
 * than at the next sweep, and a removed one is picked up at all -- the sweep only ever sees
 * published posts.
 *
 * Events are collected in memory, one per post (the last one wins), and sent once at shutdown.
 * Nothing is stored and nothing is retried: a lost publish or update is caught by the sweep.
 *
 * Which types are reported is the post-list route's rule (public, viewable, not denied), so types
 * hidden from REST are reported too. The service applies each site's own type settings, and accepts
 * events whatever the site's discovery source.
 */
class GeoGuru_PostEventsService {

    // The synced kill switch (settings-sync key `send_post_events`). Absent means on, so no site has
    // to be sent the key for events to flow.
    const SETTING_OPTION = 'geoguru_send_post_events';

    // The events endpoint's per-call limits (C6).
    const MAX_UPSERT_EVENTS = 200;
    const MAX_REMOVE_EVENTS_PER_CALL = 500;
    const SEND_TIMEOUT_SECONDS = 10;
    // After WordPress's own shutdown work (wp_ob_end_flush_all runs at 1) and other plugins'
    // default-priority callbacks, so flushing the response early cuts nobody's output off.
    const SHUTDOWN_PRIORITY = 1000;

    // Posts whose meta is primed (and then dropped) together when building publish / update events.
    const BUILD_CHUNK = 20;

    private static $instance = null;
    private $logger;
    private $hooks_registered = false;
    private $response_finished = false;
    // The blog this request started on. Hooks are global to the PHP process, so on multisite a
    // plugin that switch_to_blog()s and writes a post there fires them too.
    private $blog_id = 1;

    /**
     * This request's events, keyed by post id; a later event for a post replaces an earlier one.
     *  - publish / update: array('type' => 'publish'|'update', 'event' => null). Built at shutdown from
     *    the post as it ends up, so meta another plugin saves after the hook is still hashed.
     *  - remove: array('type' => 'remove', 'event' => identity). Built now: a permanently deleted post
     *    is gone by shutdown.
     */
    private $pending = array();

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
     * Whether this site sends post events: the conditions the request interceptor starts on (plugin
     * active, site registered), a known service URL, and the kill switch not off.
     */
    public function is_enabled() {
        if (!get_option('geoguru_plugin_active', true)) {
            return false;
        }
        if ((string) get_option('geoguru_site_id', '') === '' || (string) get_option('geoguru_secret_token', '') === '') {
            return false;
        }
        if ((string) get_option('geoguru_discovery_service_url', '') === '') {
            return false;
        }
        return (bool) (int) get_option(self::SETTING_OPTION, 1);
    }

    public function init() {
        if ($this->hooks_registered || !$this->is_enabled()) {
            return;
        }
        $this->hooks_registered = true;
        $this->blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        add_action('transition_post_status', array($this, 'on_transition_post_status'), 10, 3);
        // Fires once meta and terms are saved too (WordPress 5.6+), REST saves included.
        add_action('wp_after_insert_post', array($this, 'on_after_insert_post'), 10, 4);
        add_action('before_delete_post', array($this, 'on_before_delete_post'), 10, 2);
        add_action('elementor/document/after_save', array($this, 'on_elementor_document_saved'), 10, 2);
        add_action('shutdown', array($this, 'flush'), self::SHUTDOWN_PRIORITY);
    }

    /**
     * publish when a post goes live -- a scheduled post published by cron and a restore included --
     * and remove when it stops being live, with the new WordPress status.
     */
    public function on_transition_post_status($new_status, $old_status, $post) {
        // The collect callbacks run inside the user's own save, trash or delete: whatever goes wrong
        // here (a third-party permalink filter that throws, say) must never break that action.
        try {
            if ($this->is_other_blog() || $new_status === $old_status || !$this->is_trackable($post)) {
                return;
            }
            if ($new_status === 'publish') {
                $this->queue_from_saved_state($post, null);
            } elseif ($old_status === 'publish') {
                $this->queue_remove($post, (string) $new_status);
            }
        } catch (\Throwable $e) {
            $this->log_collect_failure($e);
        }
    }

    /** A save: update for a post that stays published, and where a password added or removed is seen. */
    public function on_after_insert_post($post_id, $post, $update = true, $post_before = null) {
        try {
            if ($this->is_other_blog() || !$this->is_trackable($post)) {
                return;
            }
            $this->queue_from_saved_state($post, $post_before);
        } catch (\Throwable $e) {
            $this->log_collect_failure($e);
        }
    }

    /**
     * A permanent delete: remove with status 'deleted', sent even when a trash event already was
     * (the service treats a repeated remove as a no-op). Only for a post that was public: one that
     * is published now, or sits in the trash having been published. Anything else never was public,
     * or already sent its remove when it left publish; a draft that merely carries a slug (WordPress
     * does not make those unique) must not be able to point the remove at a live page's URL.
     */
    public function on_before_delete_post($post_id, $post = null) {
        try {
            if ($this->is_other_blog()) {
                return;
            }
            if (!($post instanceof WP_Post)) {
                $post = get_post($post_id);
            }
            if (!$this->is_trackable($post)) {
                return;
            }
            $was_public = $post->post_status === 'publish'
                || ($post->post_status === 'trash' && get_post_meta($post->ID, '_wp_trash_meta_status', true) === 'publish');
            if (!$was_public) {
                return;
            }
            $this->queue_remove($post, 'deleted');
        } catch (\Throwable $e) {
            $this->log_collect_failure($e);
        }
    }

    /**
     * Elementor saves through its own AJAX action and announces it here. Its document is the post
     * being edited; an autosave document is skipped.
     */
    public function on_elementor_document_saved($document, $data = array()) {
        try {
            if ($this->is_other_blog()) {
                return;
            }
            if (!is_object($document) || !method_exists($document, 'get_post')) {
                return;
            }
            if (method_exists($document, 'is_autosave') && $document->is_autosave()) {
                return;
            }
            $post = $document->get_post();
            if (!$this->is_trackable($post)) {
                return;
            }
            $this->queue_from_saved_state($post, $post);
        } catch (\Throwable $e) {
            $this->log_collect_failure($e);
        }
    }

    /**
     * Whether another blog is current, on a multisite network: a plugin has switch_to_blog()ed and is
     * writing that blog's posts. Their ids and URLs belong to another site, so they are never queued
     * under this one's id. Compared with the blog recorded at init() rather than ms_is_switched(),
     * which a nested switch-and-back can fool.
     */
    private function is_other_blog() {
        return function_exists('get_current_blog_id') && (int) get_current_blog_id() !== $this->blog_id;
    }

    private function log_collect_failure(\Throwable $e) {
        // The class only: the message can name a host or another plugin.
        $this->logger->error('Post events collect failed', array('error' => get_class($e)));
    }

    /**
     * Not a revision or autosave, and of a type the post-list route lists by default: public,
     * publicly viewable, and not on its denylist (which covers revisions, menu items, attachments
     * and coupons). Which of those discovery wants is the service's decision, not the plugin's.
     */
    private function is_trackable($post) {
        if (!($post instanceof WP_Post)) {
            return false;
        }
        if (wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return false;
        }
        return geoguru_discovery_posts_type_is_listed(get_post_type_object($post->post_type));
    }

    /**
     * What a published post's saved state calls for: remove 'protected' when it has a password;
     * otherwise update when it was public before this save, and publish when it was not (new,
     * scheduled, restored, or its password just removed). Not published: nothing -- leaving publish
     * is a status transition, which on_transition_post_status() reports.
     */
    private function queue_from_saved_state($post, $post_before) {
        if ($post->post_status !== 'publish') {
            return;
        }
        if ((string) $post->post_password !== '') {
            $this->queue_remove($post, 'protected');
            return;
        }
        $was_public = $post_before instanceof WP_Post
            && $post_before->post_status === 'publish'
            && (string) $post_before->post_password === '';
        $type = $was_public ? 'update' : 'publish';
        // It went live earlier in this same request; saving it again does not make that an update.
        $post_id = (int) $post->ID;
        if (isset($this->pending[$post_id]) && $this->pending[$post_id]['type'] === 'publish') {
            $type = 'publish';
        }
        $this->pending[$post_id] = array('type' => $type, 'event' => null);
    }

    private function queue_remove($post, $status) {
        $url = $this->last_public_url($post);
        // No slug means it never had a public URL (a draft deleted before it was named), and an empty
        // URL would let the service fall back to the home page's row. Nothing to remove.
        if ($url === '') {
            return;
        }
        $type_object = get_post_type_object($post->post_type);
        $this->pending[(int) $post->ID] = array(
            'type' => 'remove',
            // What every event carries (C6): identity and status. rest_base is null for a type
            // hidden from REST, as the post-list route reports it.
            'event' => array(
                'type' => 'remove',
                'wp_post_id' => (int) $post->ID,
                'wp_post_type' => (string) $post->post_type,
                'rest_base' => $type_object ? geoguru_discovery_posts_rest_base($type_object) : null,
                'status' => (string) $status,
                'url' => $url,
            ),
        );
    }

    /**
     * The URL a removed post was public at, or '' when it never had one. get_permalink() on the post as
     * it now is gets this wrong twice: a draft, pending or scheduled post links as ?p=ID, and trashing
     * renames the slug to "slug__trashed" (or "slug__trashed-2"; core keeps the real one in
     * _wp_desired_post_slug). So the link is built for a published copy carrying the real slug; the
     * post itself is not touched. A post with no slug at all would link to the site root, so it gets ''.
     *
     * Known limit: a child page trashed after its parent in the same bulk trash links as
     * /parent__trashed/child/. The service matches a remove by wp_post_id first, so the URL is only a fallback.
     */
    private function last_public_url($post) {
        $public = clone $post;
        $public->post_status = 'publish';
        // get_post() hands a 'raw' post object back as is instead of re-reading it from the database.
        $public->filter = 'raw';
        $slug = (string) $post->post_name;
        $renamed = preg_match('/__trashed(-\d+)?$/', $slug) === 1;
        if ($post->post_status === 'trash' || $renamed) {
            $desired = get_post_meta($post->ID, '_wp_desired_post_slug', true);
            if (is_string($desired) && $desired !== '') {
                $slug = $desired;
            } elseif ($renamed) {
                // Trashed with no real slug on record: all that is left is the "__trashed" marker.
                $slug = '';
            }
        }
        if ($slug === '') {
            return '';
        }
        $public->post_name = $slug;
        $url = get_permalink($public);
        return is_string($url) ? $url : '';
    }

    /**
     * Publish / update events (C6) for the queued posts, from each post as it is now. The fields are
     * built by the post-list route's item builder, so an event and a route walk describe a post
     * identically, language included; the event adds its type and rest_base, and the field hashes.
     *
     * Built the way the route builds a page: the posts loaded whole with their terms, and their
     * languages and page-builder lengths in one query each. The meta is primed a chunk of posts at a
     * time and dropped again after it: field_hashes reads every meta key of a post, and a page
     * builder's data can run to megabytes, so priming up to 200 posts at once could exceed a shared
     * host's memory limit. That bound covers meta only: the posts themselves (post_content included)
     * are loaded whole, once, for the batch. A post that is no longer published, or now has a password, is skipped:
     * whatever changed it queued an event of its own.
     *
     * @param array $upserts post id => 'publish' | 'update', in the order they were queued
     * @return array[] the events, in the same order
     */
    public function build_upsert_events(array $upserts) {
        if (!$upserts) {
            return array();
        }
        $ids = array_map('intval', array_keys($upserts));
        $loaded = geoguru_discovery_posts_load($ids, true);
        $posts = array();
        foreach ($ids as $id) {
            if (isset($loaded[$id]) && $loaded[$id]->post_status === 'publish' && (string) $loaded[$id]->post_password === '') {
                $posts[$id] = $loaded[$id];
            }
        }
        if (!$posts) {
            return array();
        }

        $languages = geoguru_discovery_posts_languages($posts, geoguru_discovery_posts_multilingual_plugin());
        $meta = geoguru_discovery_posts_meta_rows(
            array_keys($posts),
            geoguru_discovery_posts_builder_keys(),
            geoguru_noindex_meta_keys()
        );
        $builder_lengths = geoguru_discovery_posts_builder_lengths(array_keys($posts), $meta);
        // Built at shutdown, after the SEO plugin saved its settings for the post.
        $noindex = geoguru_discovery_posts_noindex($posts, $meta);
        // The route's fields plus its opt-in ones (contract C6): field_hashes and noindex.
        $fields = array_merge(GEOGURU_DISCOVERY_POSTS_FIELDS, GEOGURU_DISCOVERY_POSTS_OPT_IN_FIELDS);

        $events = array();
        foreach (array_chunk($posts, self::BUILD_CHUNK, true) as $chunk) {
            $chunk_ids = array_keys($chunk);
            update_meta_cache('post', $chunk_ids);
            foreach ($chunk as $id => $post) {
                $event = $this->build_upsert_event($post, $upserts[$id], $fields, $languages, $builder_lengths, $noindex);
                if ($event !== null) {
                    $events[] = $event;
                }
            }
            if (function_exists('wp_cache_delete_multiple')) {
                // WordPress 6.0+: one call for the chunk.
                wp_cache_delete_multiple($chunk_ids, 'post_meta');
            } else {
                foreach ($chunk_ids as $id) {
                    wp_cache_delete($id, 'post_meta');
                }
            }
        }
        return $events;
    }

    /** One publish / update event, or null for a post that has no public URL or no date at all. */
    private function build_upsert_event($post, $type, array $fields, array $languages, array $builder_lengths, array $noindex) {
        $item = geoguru_discovery_posts_item($post, $fields, $languages, $builder_lengths, $noindex);
        if ($item['url'] === '') {
            return null;
        }
        $type_object = get_post_type_object($post->post_type);
        $event = array('type' => $type);
        foreach ($item as $key => $value) {
            $event[$key] = $value;
            if ($key === 'wp_post_type') {
                // Identity first, as on a remove: null for a type hidden from REST.
                $event['rest_base'] = $type_object ? geoguru_discovery_posts_rest_base($type_object) : null;
            }
        }
        // A null modified_gmt reads as "now" on the service, which outranks every real save of
        // the post (R4). Fall back to the publish date; with no date at all, send nothing and
        // leave the post to the sweep.
        if ($event['modified_gmt'] === null) {
            $event['modified_gmt'] = $event['date_gmt'];
        }
        if ($event['modified_gmt'] === null) {
            return null;
        }
        // The service rejects an event with no rest_base key. The item always carries wp_post_type, so
        // this only guards a future change to the item's fields; the key then goes last, not missing.
        if (!array_key_exists('rest_base', $event)) {
            $event['rest_base'] = $type_object ? geoguru_discovery_posts_rest_base($type_object) : null;
        }
        return $event;
    }

    /** Send this request's events (shutdown). A failed send is logged and dropped, never retried. */
    public function flush() {
        if (empty($this->pending)) {
            return;
        }
        // A plugin that switched blogs and never switched back would make the options below (site id,
        // token) and the posts read here another blog's. Nothing is sent on its behalf.
        if ((function_exists('ms_is_switched') && ms_is_switched()) || $this->is_other_blog()) {
            return;
        }
        $pending = $this->pending;
        $this->pending = array();
        // Asked again rather than trusted from init(): a settings push earlier in this request may
        // have switched events off.
        if (!$this->is_enabled()) {
            return;
        }

        // Before anything else runs: building loads posts and calls other plugins' filters, and the
        // editor's save response must not wait on any of that.
        $this->finish_response();

        // Shutdown has no caller to catch an exception, and whatever a filter or the HTTP layer prints
        // (a debug plugin's http_api_debug handler, say) would land in the response on hosts that
        // cannot flush early (mod_php, WP-CLI). One buffer covers the build and the send. The batch is
        // already taken out of $pending, so a failure here drops it and nothing is sent twice.
        $level = ob_get_level();
        ob_start();
        try {
            $bodies = $this->build_bodies($pending);
            if ($bodies) {
                $this->send($bodies);
            }
        } catch (\Throwable $e) {
            // The class only: the message can name a host or another plugin.
            $this->logger->error('Post events flush failed', array('error' => get_class($e)));
        } finally {
            // Drop what was printed, and any buffer that was opened and left behind.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /** The request bodies for a batch of pending events; none when there is nothing left to send. */
    private function build_bodies(array $pending) {
        $upserts = array();
        $removes = array();
        foreach ($pending as $post_id => $entry) {
            if ($entry['type'] === 'remove') {
                $removes[] = $entry['event'];
            } else {
                $upserts[$post_id] = $entry['type'];
            }
        }

        // Past the cap nothing is built: a bulk import of thousands of posts must not load and hash
        // them all at shutdown. The service runs one full discovery instead.
        $bulk_change = count($upserts) > self::MAX_UPSERT_EVENTS;
        $upsert_events = $bulk_change ? array() : $this->build_upsert_events($upserts);
        // Which multilingual plugin the site runs, and its default language, so the service can
        // tell a translation from an original (C6). The same block GET /discovery/posts returns.
        $site = geoguru_discovery_posts_site(geoguru_discovery_posts_multilingual_plugin());
        if (!$bulk_change && !$upsert_events && !$removes) {
            return array();
        }
        return self::build_request_bodies($site, $upsert_events, $removes, $bulk_change);
    }

    /**
     * Split events into bodies the endpoint accepts: at most MAX_UPSERT_EVENTS publish / update
     * events (or bulk_change in their place) and MAX_REMOVE_EVENTS_PER_CALL removes each, so a bulk
     * trash of any size is delivered. site rides on every body, bulk_change on the first only.
     *
     * @return array[] list of array('site' => array, 'events' => array, 'bulk_change' => bool)
     */
    public static function build_request_bodies(array $site, array $upsert_events, array $remove_events, $bulk_change) {
        $bulk_change = $bulk_change || count($upsert_events) > self::MAX_UPSERT_EVENTS;
        $first_events = $bulk_change ? array() : array_values($upsert_events);
        $remove_chunks = array_chunk(array_values($remove_events), self::MAX_REMOVE_EVENTS_PER_CALL);

        $bodies = array();
        if ($bulk_change || !empty($first_events) || !empty($remove_chunks)) {
            $first_removes = empty($remove_chunks) ? array() : array_shift($remove_chunks);
            $bodies[] = array('site' => $site, 'events' => array_merge($first_events, $first_removes), 'bulk_change' => $bulk_change);
        }
        foreach ($remove_chunks as $chunk) {
            $bodies[] = array('site' => $site, 'events' => $chunk, 'bulk_change' => false);
        }
        return $bodies;
    }

    private function send(array $bodies) {
        $url = rtrim((string) get_option('geoguru_discovery_service_url', ''), '/')
            . '/api/v1/discovery/websites/' . rawurlencode((string) get_option('geoguru_site_id', '')) . '/events';
        $headers = array(
            'Authorization' => 'Bearer ' . (string) get_option('geoguru_secret_token', ''),
            'Content-Type' => 'application/json',
        );
        foreach ($bodies as $index => $body) {
            $json = wp_json_encode($body);
            if ($json === false) {
                // Nothing of the payload in the log; the first failure ends the send like any other.
                $this->logger->error('Post events encode failed, events dropped', array(
                    'dropped_events' => $this->count_events(array_slice($bodies, $index)),
                    'bulk_change' => (bool) $body['bulk_change'],
                ));
                return;
            }
            $response = wp_remote_post($url, array(
                'headers' => $headers,
                'body' => $json,
                'timeout' => self::SEND_TIMEOUT_SECONDS,
                'blocking' => true,
            ));
            $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                // The first failure ends the send. A service that is down would otherwise hold this
                // process for the full timeout once per chunk; everything not yet sent is dropped.
                $this->logger->error('Post events send failed, events dropped', array(
                    'response_code' => $code,
                    // The code, not the message: cURL's text carries the API host, and a white-label site's
                    // admin can read this log.
                    'error' => is_wp_error($response) ? $response->get_error_code() : '',
                    'dropped_events' => $this->count_events(array_slice($bodies, $index)),
                    'bulk_change' => (bool) $body['bulk_change'],
                ));
                return;
            }
            $this->logger->debug('Post events sent', array(
                'response_code' => $code,
                'events' => count($body['events']),
                'bulk_change' => (bool) $body['bulk_change'],
            ));
        }
    }

    private function count_events(array $bodies) {
        $count = 0;
        foreach ($bodies as $body) {
            $count += count($body['events']);
        }
        return $count;
    }

    /**
     * Hand the response to the client before sending, where the server allows it, so a save is
     * never slower for the editor. Without either function (mod_php, WP-CLI) the send runs inline.
     * Only reached when there is something to send, so most requests never flush early.
     */
    private function finish_response() {
        if ($this->response_finished) {
            return;
        }
        $this->response_finished = true;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }
}
