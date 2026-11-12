<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('geoguru_detect_ecommerce_platform')) {
    /**
     * The e-commerce platform this site runs, reported to the backend on every settings sync.
     * Empty string = not a store. A new platform is added here and in the backend's known list.
     *
     * Read at sync time only, so a store plugin added or removed later is reported on the next
     * sync (our next update, or a reconnect), not right away.
     *
     * Only meaningful once every active plugin has loaded. During our own activation, plugins
     * activated after ours in the same request are missing, so that path does not report it.
     *
     * @return string 'woocommerce' or ''
     */
    function geoguru_detect_ecommerce_platform() {
        return class_exists('WooCommerce', false) ? 'woocommerce' : '';
    }
}

if (!function_exists('geoguru_site_connection_changed')) {
    /**
     * Whether a settings save just connected this site, or pointed it at different credentials.
     * That is the moment for a first sync: a new site has never reported its platform, and nothing
     * else syncs until our next update.
     *
     * An empty incoming value means "not sent", and keeps what is stored. A save that repeats the
     * stored credentials is not a change, so ordinary settings saves do not sync every time.
     *
     * @param string $stored_site_id Stored site id before the save
     * @param string $stored_token   Stored secret token before the save
     * @param string $site_id        Site id in the request, '' when not sent
     * @param string $token          Secret token in the request, '' when not sent
     * @return bool True when the credentials changed and both are now present
     */
    function geoguru_site_connection_changed($stored_site_id, $stored_token, $site_id, $token) {
        $new_site_id = $site_id !== '' ? $site_id : $stored_site_id;
        $new_token = $token !== '' ? $token : $stored_token;
        if ($new_site_id === '' || $new_token === '') {
            return false;
        }
        return $new_site_id !== $stored_site_id || $new_token !== $stored_token;
    }
}
