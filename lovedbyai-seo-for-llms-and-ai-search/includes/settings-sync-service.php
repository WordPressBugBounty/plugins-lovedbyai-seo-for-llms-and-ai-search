<?php

defined('ABSPATH') || exit;

class GeoGuru_SettingsSyncService {

    // The value each setting last took from a synced blob, hashed and keyed by setting, so a re-send
    // of a value this site already applied is recognised as a replay rather than a fresh instruction.
    const APPLIED_VALUES_OPTION = 'geoguru_synced_settings_applied';

    private static $instance = null;
    private $logger;

    private function __construct() {
        $this->logger = GeoGuru_Logger::get_instance();
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Supabase wp_synced_settings key => array(WP option name, value type).
    // Keys not listed here are ignored on inbound sync.
    private function allowlist() {
        return array(
            // Retired as a stored value, still accepted: it is translated into the canonical target
            // of the delivery pair so that pushes written before this key existed keep working.
            // Listed FIRST on purpose -- a blob carrying both keys applies them in this order, so an
            // explicit pair lands last and wins over the boolean's coarser meaning.
            'apply_non_visible_overlay_on_original_page' => array(GEOGURU_DELIVERY_OPTION, 'overlay_bool'),
            // Composite on purpose. Applied as one object or not at all: this machinery applies each
            // key independently, so two flat keys would let a blob carrying only one of them leave a
            // live site running one target from a pushed value and the other from legacy derivation.
            'optimization_delivery' => array(GEOGURU_DELIVERY_OPTION, 'delivery'),
        );
    }

    public function init() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    public function register_rest_routes() {
        register_rest_route(
            'geoguru-api',
            '/settings-sync',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_inbound_sync'),
                'permission_callback' => '__return_true', // authenticated via secret token in handler
            )
        );
    }

    public function handle_inbound_sync($request) {
        $bearer = $this->get_bearer_token_from_request($request);
        $stored = get_option('geoguru_secret_token', '');
        if ($stored === '' || $bearer === '' || !hash_equals($stored, $bearer)) {
            $this->logger->warning('Settings sync push rejected: invalid or missing bearer');
            return new WP_Error('unauthorized', 'Unauthorized', array('status' => 401));
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            return new WP_Error('invalid_request', 'Invalid request body', array('status' => 400));
        }

        $body_site = isset($params['site_id']) ? sanitize_text_field($params['site_id']) : '';
        $site_id = get_option('geoguru_site_id', '');
        if ($body_site === '' || (string) $body_site !== (string) $site_id) {
            $this->logger->warning('Settings sync push rejected: site_id mismatch');
            return new WP_Error('forbidden', 'Site ID mismatch', array('status' => 403));
        }

        $settings = (isset($params['settings']) && is_array($params['settings'])) ? $params['settings'] : array();
        $result = $this->apply_synced_settings($settings);

        return new WP_REST_Response(
            array_merge(
                array(
                    'success'  => true,
                    'site_id'  => $site_id,
                    // What this site resolves to after applying the blob, so the caller learns the
                    // effective state rather than just which keys it accepted.
                    'delivery' => geoguru_get_delivery_settings(),
                ),
                $result
            ),
            200
        );
    }

    // Returns array{applied: array, ignored: array, rejected: array}.
    public function apply_synced_settings($settings) {
        $applied = array();
        $ignored = array();
        $rejected = array();

        if (!is_array($settings)) {
            return array('applied' => $applied, 'ignored' => $ignored, 'rejected' => $rejected);
        }

        if (array_key_exists('_sync_probe', $settings)) {
            set_transient('geoguru_sync_probe', sanitize_text_field((string) $settings['_sync_probe']), HOUR_IN_SECONDS);
        }

        $allowlist = $this->allowlist();

        foreach ($settings as $key => $_value) {
            if ($key !== '_sync_probe' && $key !== '_sync_force' && !array_key_exists($key, $allowlist)) {
                $ignored[] = $key;
            }
        }

        // A value this site already applied is a replay, not an instruction. The whole blob is
        // fetched again after every plugin update, so without this a setting a site changed locally
        // would be reverted on its next update by a value sent long ago and never revisited.
        //
        // Judged per setting, never per blob. One fingerprint for the whole blob also swallowed the
        // keys this build did not understand: a later build that knows one receives the same blob,
        // finds it "already applied", and never applies the key. It also let one changed key
        // re-apply every other key over local changes nobody revisited. So only a setting that was
        // actually applied is recorded, and an ignored or rejected key is looked at again every
        // time it arrives. Unknown keys are still reported above either way, and the probe is not
        // a setting, so it is never recorded.
        //
        // A push carrying a truthy `_sync_force` skips the guard and applies every setting it carries,
        // even a value already applied -- how a setting that drifted locally is put back without
        // changing it twice. It belongs in a one-off push only: kept in the stored blob, it would
        // switch the guard off for every fetch.
        $stored_record = get_option(self::APPLIED_VALUES_OPTION, array());
        if (!is_array($stored_record)) {
            $stored_record = array();
        }
        $recorded = $stored_record;
        $seen = empty($settings['_sync_force']) ? $stored_record : array();

        // 'apply_non_visible_overlay_on_original_page' and 'optimization_delivery' both write
        // GEOGURU_DELIVERY_OPTION, so for replay purposes they are one setting: a blob carrying both
        // applies them together, the pair winning, and a change to either re-decides the pair.
        // Resolved together, before the generic per-key loop below, so that an invalid pair can
        // never be preceded by a boolean write it then gets rejected "around" -- see
        // resolve_delivery_option()'s docblock for why per-key eager writes broke this.
        $delivery_group = array('apply_non_visible_overlay_on_original_page', 'optimization_delivery');
        $delivery_setting = implode('+', $delivery_group);
        $delivery_values = array_intersect_key($settings, array_flip($delivery_group));
        if (!empty($delivery_values)) {
            if ($this->is_replay($seen, $delivery_setting, $delivery_values)) {
                $this->logger->debug('Skipped synced delivery: unchanged since it was last applied');
            } else {
                $applied_before = count($applied);
                $this->resolve_delivery_option($delivery_values, $applied, $ignored, $rejected);
                if (count($applied) > $applied_before) {
                    $recorded[$delivery_setting] = $this->value_hash($delivery_values);
                }
            }
        }

        foreach ($allowlist as $key => $spec) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            if (in_array($key, $delivery_group, true)) {
                continue; // already resolved by resolve_delivery_option() above
            }
            if ($this->is_replay($seen, $key, $settings[$key])) {
                continue;
            }
            $option_name = $spec[0];
            $type = $spec[1];
            $value = $settings[$key];

            switch ($type) {
                case 'bool':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                    break;
                case 'string':
                    $value = sanitize_text_field((string) $value);
                    break;
                case 'int':
                    $value = (int) $value;
                    break;
            }

            $previous = get_option($option_name, null);
            update_option($option_name, $value);
            $changed = (is_array($previous) || is_array($value))
                ? wp_json_encode($previous) !== wp_json_encode($value)
                : (string) $previous !== (string) $value;
            $this->logger->debug('Applied synced setting', array('option' => $option_name));

            $applied[] = array(
                'key' => $key,
                'option' => $option_name,
                'previous' => $previous,
                'value' => $value,
                'changed' => $changed,
            );
            $recorded[$key] = $this->value_hash($settings[$key]);
        }

        // A setting that DID apply is recorded even when its value happened to match what was
        // already stored: it was acted upon, so an identical re-send is a replay. That is why this
        // follows what applied, not any entry's `changed` flag.
        if ($recorded !== $stored_record) {
            update_option(self::APPLIED_VALUES_OPTION, $recorded);
        }

        return array('applied' => $applied, 'ignored' => $ignored, 'rejected' => $rejected);
    }

    private function value_hash($value) {
        return md5((string) wp_json_encode($value));
    }

    /** Whether this setting already took exactly this value from an earlier sync. */
    private function is_replay($seen, $setting, $value) {
        return isset($seen[$setting]) && $seen[$setting] === $this->value_hash($value);
    }

    /**
     * Resolve, and if warranted perform, the single write to GEOGURU_DELIVERY_OPTION for a push
     * that may carry either or both of 'apply_non_visible_overlay_on_original_page' (overlay_bool)
     * and 'optimization_delivery' (delivery). Appends to $applied/$ignored/$rejected exactly as
     * the generic per-key loop in apply_synced_settings() would for a single key.
     *
     * Both keys write the same option, and update_option() is not transactional across two keys
     * processed one after another in a loop: writing the boolean's coarse value first and only
     * then discovering the pair is invalid leaves the option holding the boolean's value while the
     * log line claims the previous value was kept -- a lie, and a site left silently switched off
     * on its canonical page. So the pair is validated in full, and the final value for the option
     * (if any) is resolved from both inputs, BEFORE either key is allowed to write anything. If
     * the pair is present and invalid, NEITHER key writes: the option is left exactly as it was.
     *
     * @param array $settings The inbound settings blob.
     * @param array $applied  By reference; entries are appended, never removed.
     * @param array $ignored  By reference; keys are appended, never removed.
     * @param array $rejected By reference; keys are appended, never removed.
     */
    private function resolve_delivery_option($settings, &$applied, &$ignored, &$rejected) {
        $has_overlay = array_key_exists('apply_non_visible_overlay_on_original_page', $settings);
        $has_pair = array_key_exists('optimization_delivery', $settings);

        if (!$has_overlay && !$has_pair) {
            return;
        }

        // Validate the pair first and unconditionally. An invalid pair blocks the option for both
        // keys in this push -- the boolean does not get to write its own value "around" it.
        if ($has_pair) {
            $validated_pair = geoguru_delivery_validate($settings['optimization_delivery']);
            if ($validated_pair === null) {
                $this->logger->warning('Rejected invalid optimization_delivery; keeping previous value');
                $rejected[] = 'optimization_delivery';
                if ($has_overlay) {
                    // This build understands the key; it refused it because the blob it arrived in
                    // was incoherent, not because the key is unrecognized. That is a rejection, not
                    // an ignore -- ignored[] means "this build does not know the key", and a caller
                    // reads it that way, so reporting a blocked push there would misdescribe the
                    // build.
                    $this->logger->warning('Rejected overlay push: accompanying optimization_delivery was rejected in the same push');
                    $rejected[] = 'apply_non_visible_overlay_on_original_page';
                }
                return;
            }
        }

        $previous = get_option(GEOGURU_DELIVERY_OPTION, null);
        $current = geoguru_get_delivery_settings();

        // Resolve the final value in allowlist order: the boolean first, then the explicit pair --
        // which, if present and valid, always wins. See allowlist()'s docblock for why the pair is
        // ordered last on purpose.
        $final = $current;
        $overlay_applies = false;

        if ($has_overlay) {
            if ($current['original'] === 'cdn_fetch') {
                // The CDN document path never consulted the overlay boolean, so a push carrying
                // it has no effect on those sites today. Report it rather than quietly moving them
                // onto a mechanism nobody selected.
                $this->logger->warning('Ignored overlay push: site serves a CDN document on the canonical page');
                $ignored[] = 'apply_non_visible_overlay_on_original_page';
            } else {
                $final = array(
                    'original' => filter_var($settings['apply_non_visible_overlay_on_original_page'], FILTER_VALIDATE_BOOLEAN) ? 'page_replacement' : 'off',
                    'mirror'   => $current['mirror'],
                );
                $overlay_applies = true;
            }
        }

        if ($has_pair) {
            $final = $validated_pair; // always wins over the boolean, computed above
        }

        if (!$overlay_applies && !$has_pair) {
            return; // only the CDN no-op overlay case reaches here with nothing left to write
        }

        // `changed` is reported against the stored option, which is what a caller asked to change.
        // Whether to purge cached pages is a different question -- whether renders now do anything
        // differently -- and geoguru_delivery_store() answers it on the resolved pair. Asked of the
        // stored option instead, the first sync after an upgrade would purge every site's whole
        // cache: it writes down, for the first time, the pair the site was already resolving to.
        $changed = (is_array($previous) || is_array($final))
            ? wp_json_encode($previous) !== wp_json_encode($final)
            : (string) $previous !== (string) $final;

        geoguru_delivery_store($final);
        $this->logger->debug('Applied synced setting', array('option' => GEOGURU_DELIVERY_OPTION));

        if ($overlay_applies) {
            $applied[] = array(
                'key' => 'apply_non_visible_overlay_on_original_page',
                'option' => GEOGURU_DELIVERY_OPTION,
                'previous' => $previous,
                'value' => $final,
                'changed' => $changed,
            );
        }
        if ($has_pair) {
            $applied[] = array(
                'key' => 'optimization_delivery',
                'option' => GEOGURU_DELIVERY_OPTION,
                'previous' => $previous,
                'value' => $final,
                'changed' => $changed,
            );
        }
    }

    private function get_bearer_token_from_request($request) {
        $auth = $request->get_header('authorization');
        if (empty($auth) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        }
        if (!is_string($auth) || $auth === '') {
            return '';
        }
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return '';
    }
}
