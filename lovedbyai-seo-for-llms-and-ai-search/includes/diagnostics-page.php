<?php
/**
 * Environment diagnosis admin page (in-plugin equivalent of packages/wp-ts-plugin).
 *
 * @package LovedByAI
 */

defined('ABSPATH') || exit;

if (!defined('GEOGURU_DIAGNOSTIC_PAGE_SLUG')) {
    define('GEOGURU_DIAGNOSTIC_PAGE_SLUG', 'ai-optimization-environment-diagnosis');
}

define('GEOGURU_DIAGNOSTIC_OPTION_TEST_KEY', 'geoguru_diagnostic_test_option');
define('GEOGURU_DIAGNOSTIC_TRANSIENT_TEST_KEY', 'geoguru_diagnostic_test_transient');

/**
 * Default args for server-to-self HTTP probes (self-signed or incomplete TLS chains on staging/local).
 *
 * @param array $extra Merged on top (e.g. method, headers).
 * @return array
 */
function geoguru_diagnostic_same_site_http_probe_args($extra = array()) {
    return array_merge(
        array(
            'timeout' => 15,
            'sslverify' => false,
            'reject_unsafe_urls' => false,
        ),
        $extra
    );
}

/**
 * Forces sslverify off for requests to this site (belt-and-suspenders if another filter alters args).
 *
 * @param array  $args Request args.
 * @param string $url  Request URL.
 * @return array
 */
function geoguru_diagnostic_http_request_args_disable_sslverify($args, $url) {
    $request_host = wp_parse_url($url, PHP_URL_HOST);
    $site_host = wp_parse_url(site_url(), PHP_URL_HOST);
    if ($request_host && $site_host && strcasecmp($request_host, $site_host) === 0) {
        $args['sslverify'] = false;
    }
    return $args;
}

/**
 * Read one response header (case-insensitive name) from a wp_remote_* result.
 *
 * @param array|WP_Error $response HTTP response.
 * @param string         $header_name Header name.
 * @return string
 */
function geoguru_diagnostic_get_response_header_value($response, $header_name) {
    if (is_wp_error($response) || empty($response)) {
        return '';
    }
    $headers = wp_remote_retrieve_headers($response);
    if (empty($headers)) {
        return '';
    }
    $want = strtolower(trim($header_name));
    if (is_object($headers)) {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $want) {
                if (is_array($value)) {
                    return isset($value[0]) ? (string) $value[0] : '';
                }
                return (string) $value;
            }
        }
    } elseif (is_array($headers)) {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $want) {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }
        }
    }
    return '';
}

/**
 * Validate CORS headers on an OPTIONS preflight response (detects stripped/overridden headers).
 *
 * @param array|WP_Error $response HTTP response.
 * @param string         $origin   Origin header sent on the request.
 * @return array { pass: bool, message: string }
 */
function geoguru_diagnostic_validate_preflight_cors_headers($response, $origin) {
    $allow_origin = geoguru_diagnostic_get_response_header_value($response, 'access-control-allow-origin');
    if ($allow_origin === '') {
        return array(
            'pass' => false,
            'message' => __('OPTIONS returned success but Access-Control-Allow-Origin is missing. A plugin, mu-plugin, theme, or reverse proxy may be stripping or overriding CORS headers.', 'lovedbyai-seo-for-llms-and-ai-search'),
        );
    }
    if ($allow_origin !== $origin) {
        return array(
            'pass' => false,
            'message' => sprintf(
                /* translators: 1: value of Access-Control-Allow-Origin, 2: expected Origin URL */
                __('OPTIONS: Access-Control-Allow-Origin is "%1$s" but must echo the allowed request Origin "%2$s". Another layer may be overriding CORS.', 'lovedbyai-seo-for-llms-and-ai-search'),
                $allow_origin,
                $origin
            ),
        );
    }
    $allow_credentials = strtolower(trim(geoguru_diagnostic_get_response_header_value($response, 'access-control-allow-credentials')));
    if ($allow_credentials !== 'true') {
        return array(
            'pass' => false,
            'message' => __('OPTIONS: Access-Control-Allow-Credentials must be "true" for embedded admin (cookies). It is missing or incorrect.', 'lovedbyai-seo-for-llms-and-ai-search'),
        );
    }
    $allow_methods = geoguru_diagnostic_get_response_header_value($response, 'access-control-allow-methods');
    $methods_compact = strtolower(preg_replace('/\s+/', '', $allow_methods));
    if ($methods_compact === '' || strpos($methods_compact, 'options') === false || strpos($methods_compact, 'post') === false) {
        return array(
            'pass' => false,
            'message' => sprintf(
                /* translators: %s: Access-Control-Allow-Methods value or (empty) */
                __('OPTIONS: Access-Control-Allow-Methods must include POST and OPTIONS. Got: %s', 'lovedbyai-seo-for-llms-and-ai-search'),
                $allow_methods !== '' ? $allow_methods : __('(empty)', 'lovedbyai-seo-for-llms-and-ai-search')
            ),
        );
    }
    return array('pass' => true, 'message' => '');
}

/**
 * Validate CORS headers on an authenticated GET with Origin (non-preflight path).
 *
 * @param string $url    Full REST URL including token.
 * @param string $origin Allowed origin to send.
 * @return array { pass: bool, skipped: bool, message: string }
 */
function geoguru_diagnostic_check_rest_get_cors_headers($url, $origin) {
    $response = wp_remote_get(
        $url,
        geoguru_diagnostic_same_site_http_probe_args(
            array(
                'timeout' => 10,
                'headers' => array(
                    'Origin' => $origin,
                ),
            )
        )
    );
    if (is_wp_error($response)) {
        return array(
            'pass' => false,
            'skipped' => false,
            'message' => sprintf(
                /* translators: %s: error message */
                __('GET with Origin failed: %s', 'lovedbyai-seo-for-llms-and-ai-search'),
                $response->get_error_message()
            ),
        );
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return array(
            'pass' => false,
            'skipped' => false,
            'message' => sprintf(
                /* translators: %d: HTTP status code */
                __('Authenticated GET with Origin returned HTTP %d (expected 200).', 'lovedbyai-seo-for-llms-and-ai-search'),
                $code
            ),
        );
    }
    $allow_origin = geoguru_diagnostic_get_response_header_value($response, 'access-control-allow-origin');
    if ($allow_origin === '') {
        return array(
            'pass' => false,
            'skipped' => false,
            'message' => __('GET with Origin: Access-Control-Allow-Origin is missing. CORS may work on OPTIONS only; another layer could be removing headers on real API responses.', 'lovedbyai-seo-for-llms-and-ai-search'),
        );
    }
    if ($allow_origin !== $origin) {
        return array(
            'pass' => false,
            'skipped' => false,
            'message' => sprintf(
                /* translators: 1: Allow-Origin header, 2: expected Origin */
                __('GET with Origin: Access-Control-Allow-Origin is "%1$s" but should echo "%2$s".', 'lovedbyai-seo-for-llms-and-ai-search'),
                $allow_origin,
                $origin
            ),
        );
    }
    $allow_credentials = strtolower(trim(geoguru_diagnostic_get_response_header_value($response, 'access-control-allow-credentials')));
    if ($allow_credentials !== 'true') {
        return array(
            'pass' => false,
            'skipped' => false,
            'message' => __('GET with Origin: Access-Control-Allow-Credentials must be "true".', 'lovedbyai-seo-for-llms-and-ai-search'),
        );
    }
    return array(
        'pass' => true,
        'skipped' => false,
        'message' => __('GET with Origin returns expected CORS headers (Allow-Origin echoes request, credentials enabled).', 'lovedbyai-seo-for-llms-and-ai-search'),
    );
}

/**
 * Whether a REST JSON error code is this plugin's own permission rejection.
 * rest_forbidden proves the request reached the route; any other code on a
 * 401/403 means something rejected it earlier. An allowlist on purpose —
 * blocking rules invent their own codes, so unknown codes must not pass.
 *
 * @param string $code REST JSON "code" field.
 * @return bool
 */
function geoguru_diagnostic_is_own_permission_rejection($code) {
    return $code === 'rest_forbidden';
}

/**
 * Whether a REST JSON error code is a known "you must be logged in" rejection.
 * Used on routes that require no login, where any such code is a failure whatever
 * the HTTP status — an error carrying no status is served as HTTP 500.
 *
 * @param string $code REST JSON "code" field.
 * @return bool
 */
function geoguru_diagnostic_is_global_rest_login_code($code) {
    if ($code === '' || geoguru_diagnostic_is_own_permission_rejection($code)) {
        return false;
    }
    $known = array(
        'rest_api_authentication_required',
        'rest_not_logged_in',
        'rest_cannot_access',
        'restx_logged_out',
    );
    return in_array($code, $known, true) || strpos($code, 'logged') !== false;
}

/**
 * Parse WP REST JSON error code from response body.
 *
 * @param array|WP_Error $response wp_remote_* result.
 * @return string
 */
function geoguru_diagnostic_rest_json_error_code($response) {
    if (is_wp_error($response)) {
        return '';
    }
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if (is_array($data) && isset($data['code'])) {
        return (string) $data['code'];
    }
    return '';
}

/**
 * GET /settings with a freshly created session token — simulates cross-origin admin (no WordPress auth cookies in wp_remote_*).
 *
 * @param string $token     Session token from geoguru_create_secure_nonce.
 * @param string $auth_mode 'query' = ?token= (portal GET style); 'header' = X-GeoGuru-Token only (POST style).
 * @return array { pass: bool, message: string }
 */
function geoguru_diagnostic_check_rest_settings_with_token($token, $auth_mode) {
    if ($auth_mode === 'query') {
        $url = rest_url('geoguru/v1/settings') . '?token=' . rawurlencode($token);
        $args = geoguru_diagnostic_same_site_http_probe_args(array('timeout' => 10));
    } elseif ($auth_mode === 'header') {
        $url = rest_url('geoguru/v1/settings');
        $args = geoguru_diagnostic_same_site_http_probe_args(
            array(
                'timeout' => 10,
                'headers' => array(
                    'X-GeoGuru-Token' => $token,
                ),
            )
        );
    } else {
        return array(
            'pass' => false,
            'message' => __('Invalid probe mode.', 'lovedbyai-seo-for-llms-and-ai-search'),
        );
    }

    $response = wp_remote_get($url, $args);
    if (is_wp_error($response)) {
        return array(
            'pass' => false,
            'message' => sprintf(
                /* translators: %s: WP_Error message */
                __('Request failed: %s', 'lovedbyai-seo-for-llms-and-ai-search'),
                $response->get_error_message()
            ),
        );
    }
    $http = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $err = geoguru_diagnostic_rest_json_error_code($response);

    if ($http === 200 && strpos($body, '"site_id"') !== false) {
        if ($auth_mode === 'query') {
            $msg = __('OK — 200 with ?token= (same as portal GET; no WP login cookie is sent by this server probe).', 'lovedbyai-seo-for-llms-and-ai-search');
        } else {
            $msg = __('OK — 200 with plugin\'s token header only (same token channel as POST; guest browsers rely on this).', 'lovedbyai-seo-for-llms-and-ai-search');
        }
        return array('pass' => true, 'message' => $msg);
    }

    if ($http === 401 || $http === 403) {
        $mode_hint = $auth_mode === 'query'
            ? __('Token was sent in the URL query (?token=).', 'lovedbyai-seo-for-llms-and-ai-search')
            : __('Token was sent only in the X-GeoGuru-Token header.', 'lovedbyai-seo-for-llms-and-ai-search');
        return array(
            'pass' => false,
            'message' => sprintf(
                /* translators: 1: HTTP status, 2: REST JSON error code or em dash, 3: how the token was sent */
                __('HTTP %1$d with a freshly created session token (REST code: %2$s). %3$s The embedded admin behaves like a guest without WordPress cookies — only this token should authorize. If create/verify passed here but this fails, check object cache, proxies stripping query strings or headers, or security plugins.', 'lovedbyai-seo-for-llms-and-ai-search'),
                $http,
                $err !== '' ? $err : '—',
                $mode_hint
            ),
        );
    }

    if ($http === 404) {
        return array(
            'pass' => false,
            'message' => __('HTTP 404 — route not found.', 'lovedbyai-seo-for-llms-and-ai-search'),
        );
    }

    return array(
        'pass' => false,
        'message' => sprintf(
            /* translators: 1: HTTP status, 2: short body snippet or empty */
            __('Unexpected HTTP %1$d (expected 200 JSON with site_id). REST code: %2$s', 'lovedbyai-seo-for-llms-and-ai-search'),
            $http,
            $err !== '' ? $err : __('(none)', 'lovedbyai-seo-for-llms-and-ai-search')
        ),
    );
}

/**
 * Verify GeoGuru REST namespace is not blocked by site-wide "must be logged in" REST rules.
 * Anonymous GET /settings (no token) should hit this plugin’s permission check (e.g. rest_forbidden), not rest_api_authentication_required.
 *
 * @return array { pass: bool, message: string }
 */
function geoguru_diagnostic_check_rest_namespace_not_globally_locked() {
    $url = rest_url('geoguru/v1/settings');
    $response = wp_remote_get(
        $url,
        geoguru_diagnostic_same_site_http_probe_args(array('timeout' => 10))
    );
    if (is_wp_error($response)) {
        return array(
            'pass' => false,
            'message' => sprintf(
                /* translators: %s: error message */
                __('Anonymous GET /settings failed: %s', 'lovedbyai-seo-for-llms-and-ai-search'),
                $response->get_error_message()
            ),
        );
    }
    $http = (int) wp_remote_retrieve_response_code($response);
    $err = geoguru_diagnostic_rest_json_error_code($response);
    $body = wp_remote_retrieve_body($response);
    // Any REST error code that is not our own rejection means something answered
    // before our route did. Checked regardless of status: a blocking WP_Error that
    // carries no status is served as HTTP 500, which no status test would catch.
    $blocked_early = ($err !== '' && !geoguru_diagnostic_is_own_permission_rejection($err));
    if ($http === 401 || $http === 403 || $blocked_early) {
        if (geoguru_diagnostic_is_own_permission_rejection($err)) {
            return array(
                'pass' => true,
                'message' => sprintf(
                    /* translators: 1: HTTP status, 2: JSON error code */
                    __('OK — HTTP %1$d without token (expected). Error code: %2$s. Global REST is not blocking plugin before the token permission check.', 'lovedbyai-seo-for-llms-and-ai-search'),
                    $http,
                    $err
                ),
            );
        }
        $hint = $err !== ''
            ? sprintf(
                /* translators: %s: REST error code */
                __('Search the site for that code to find what added it, for example: grep -rn "%s" wp-content/', 'lovedbyai-seo-for-llms-and-ai-search'),
                $err
            )
            : __('The response was not JSON, so the blocking code could not be read. Check server-level rules and security plugins.', 'lovedbyai-seo-for-llms-and-ai-search');
        return array(
            'pass' => false,
            'message' => sprintf(
                /* translators: 1: HTTP status, 2: REST error code or a placeholder, 3: remediation hint */
                __('Bad: anonymous request returned HTTP %1$d with code "%2$s" instead of "rest_forbidden". Something is rejecting REST traffic before this plugin\'s routes run — usually a plugin, theme or code snippet that requires a WordPress login for every REST request (see the rest_authentication_errors filter). %3$s', 'lovedbyai-seo-for-llms-and-ai-search'),
                $http,
                $err !== '' ? $err : __('(unparsed)', 'lovedbyai-seo-for-llms-and-ai-search'),
                $hint
            ),
        );
    }
    if ($http === 404) {
        return array(
            'pass' => false,
            'message' => __('HTTP 404 — REST route not found. Check pretty permalinks and that /wp-json/ is not blocked.', 'lovedbyai-seo-for-llms-and-ai-search'),
        );
    }
    if ($http === 200 && strpos($body, '"site_id"') !== false) {
        return array(
            'pass' => true,
            'message' => __('OK — namespace responded 200 (unusual without a token; confirm this is intentional).', 'lovedbyai-seo-for-llms-and-ai-search'),
        );
    }
    return array(
        'pass' => true,
        'message' => sprintf(
            /* translators: %d: HTTP status code */
            __('OK — HTTP %d. GeoGuru REST route is reachable; not failing with site-wide REST login errors.', 'lovedbyai-seo-for-llms-and-ai-search'),
            $http
        ),
    );
}

/**
 * Verify public POST /llm-source-event is not blocked by site-wide REST auth (anonymous JSON body).
 *
 * @return array { pass: bool, message: string }
 */
function geoguru_diagnostic_check_rest_public_llm_event_not_globally_locked() {
    $url = rest_url('geoguru/v1/llm-source-event');
    $response = wp_remote_post(
        $url,
        geoguru_diagnostic_same_site_http_probe_args(
            array(
                'timeout' => 15,
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => '{}',
            )
        )
    );
    if (is_wp_error($response)) {
        return array(
            'pass' => false,
            'message' => sprintf(
                /* translators: %s: error message */
                __('Anonymous POST /llm-source-event failed: %s', 'lovedbyai-seo-for-llms-and-ai-search'),
                $response->get_error_message()
            ),
        );
    }
    $http = (int) wp_remote_retrieve_response_code($response);
    $err = geoguru_diagnostic_rest_json_error_code($response);
    // A login-required code on any status counts: an error carrying no status is
    // served as HTTP 500, so a status-only test would miss it.
    $login_required_code = geoguru_diagnostic_is_global_rest_login_code($err);
    if ($http === 401 || $http === 403 || $login_required_code) {
        return array(
            'pass' => false,
            'message' => sprintf(
                /* translators: 1: HTTP status, 2: error code (used twice) */
                __('Bad: this route requires no login, so HTTP %1$d (code: %2$s) means anonymous clients cannot reach it at all — site-wide REST login requirements are blocking every /wp-json/ route. Expect 400 validation or 5xx upstream here, never 401/403. Search the site for that code, for example: grep -rn "%2$s" wp-content/', 'lovedbyai-seo-for-llms-and-ai-search'),
                $http,
                $err !== '' ? $err : __('(unparsed)', 'lovedbyai-seo-for-llms-and-ai-search')
            ),
        );
    }
    if ($http === 404) {
        return array(
            'pass' => false,
            'message' => __('HTTP 404 — public REST route not found. Check permalinks.', 'lovedbyai-seo-for-llms-and-ai-search'),
        );
    }
    return array(
        'pass' => true,
        'message' => sprintf(
            /* translators: 1: HTTP status, 2: JSON error code or OK */
            __('OK — HTTP %1$d (code: %2$s). Anonymous request reached the plugin (not blocked by site-wide REST auth).', 'lovedbyai-seo-for-llms-and-ai-search'),
            $http,
            $err !== '' ? $err : __('OK', 'lovedbyai-seo-for-llms-and-ai-search')
        ),
    );
}

/**
 * Remediation hint for LovedByAI backend → WordPress reachability classifications.
 *
 * @param string $classification Machine classification from the Node service JSON.
 * @return string
 */
function geoguru_diagnostic_reachability_remediation_hint($classification) {
    switch ($classification) {
        case 'blocked':
            return __('A WAF, CDN, or security plugin may be blocking requests to /wp-json/geoguru/v1/. Allow-list LovedByAI egress (e.g. the X-LovedByAI-Verify header or documented service IPs).', 'lovedbyai-seo-for-llms-and-ai-search');
        case 'not_plugin':
            return __('Confirm this plugin is active, pretty permalinks are enabled, and nothing is serving a substitute response at /wp-json/geoguru/v1/reachability.', 'lovedbyai-seo-for-llms-and-ai-search');
        case 'tls':
            return __('Fix TLS certificate issues on the public site URL so HTTPS clients can verify the chain.', 'lovedbyai-seo-for-llms-and-ai-search');
        case 'unreachable':
            return __('Check DNS, firewall, and hosting connectivity so the public URL resolves and accepts HTTPS from the internet.', 'lovedbyai-seo-for-llms-and-ai-search');
        case 'bad_url':
            return __('Verify the website URL stored in LovedByAI matches this WordPress site (including subdirectory installs).', 'lovedbyai-seo-for-llms-and-ai-search');
        default:
            return '';
    }
}

/**
 * Ask a LovedByAI backend service to probe this WordPress site's public reachability REST route from the internet.
 *
 * @param string $label        Admin-visible row label.
 * @param string $option_name  WordPress option holding the service base URL (geoguru_*_service_url).
 * @param string $path_suffix  Path after base URL with one %s for rawurlencoded site UUID.
 * @return array { pass: bool, message: string }
 */
function geoguru_diagnostic_check_backend_service_site_reachability($label, $option_name, $path_suffix) {
    $base    = get_option($option_name, '');
    $site_id = get_option('geoguru_site_id', '');
    $secret  = get_option('geoguru_secret_token', '');

    if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL)) {
        return array(
            'pass' => false,
            'message' => sprintf(
                /* translators: 1: service label, 2: WordPress option name */
                __('Skipped — %1$s URL (%2$s) is empty or invalid. Configure it in plugin settings.', 'lovedbyai-seo-for-llms-and-ai-search'),
                $label,
                $option_name
            ),
        );
    }
    if ($site_id === '' || $secret === '') {
        return array(
            'pass' => false,
            'message' => __('Skipped — site is not linked to LovedByAI (missing site id or secret token).', 'lovedbyai-seo-for-llms-and-ai-search'),
        );
    }

    $url = rtrim(esc_url_raw($base), '/') . sprintf($path_suffix, rawurlencode($site_id));
    $response = wp_remote_get(
        $url,
        geoguru_diagnostic_same_site_http_probe_args(
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret,
                    'Accept'          => 'application/json',
                ),
            )
        )
    );

    if (is_wp_error($response)) {
        return array(
            'pass' => false,
            'message' => sprintf(
                /* translators: 1: service label, 2: error message */
                __('Could not reach %1$s: %2$s', 'lovedbyai-seo-for-llms-and-ai-search'),
                $label,
                $response->get_error_message()
            ),
        );
    }

    $http = (int) wp_remote_retrieve_response_code($response);
    $body  = wp_remote_retrieve_body($response);
    $data  = json_decode($body, true);

    if ($http === 401) {
        return array(
            'pass' => false,
            'message' => sprintf(
                /* translators: %s: service label */
                __('%s rejected the site secret token (HTTP 401). Re-link the plugin or rotate the site secret in LovedByAI.', 'lovedbyai-seo-for-llms-and-ai-search'),
                $label
            ),
        );
    }

    if ($http !== 200) {
        $snippet = function_exists('wp_trim_words')
            ? wp_trim_words(wp_strip_all_tags($body), 30, '…')
            : substr(wp_strip_all_tags($body), 0, 200);
        return array(
            'pass' => false,
            'message' => sprintf(
                /* translators: 1: service label, 2: HTTP status code, 3: response body snippet */
                __('%1$s returned HTTP %2$d (expected 200 with probe JSON). Body: %3$s', 'lovedbyai-seo-for-llms-and-ai-search'),
                $label,
                $http,
                $snippet
            ),
        );
    }

    if (!is_array($data) || !isset($data['ok']) || !isset($data['classification'])) {
        return array(
            'pass' => false,
            'message' => sprintf(
                /* translators: %s: service label */
                __('%s returned 200 but the JSON payload was not a reachability result.', 'lovedbyai-seo-for-llms-and-ai-search'),
                $label
            ),
        );
    }

    $pass           = !empty($data['ok']);
    $msg            = (isset($data['message']) && is_string($data['message'])) ? $data['message'] : '';
    $classification = isset($data['classification']) ? (string) $data['classification'] : '';
    $http_status    = isset($data['httpStatus']) ? (int) $data['httpStatus'] : 0;
    $latency        = isset($data['latencyMs']) ? (int) $data['latencyMs'] : 0;

    $detail = sprintf(
        /* translators: 1: classification, 2: HTTP status from service to customer site, 3: latency in ms */
        __('classification=%1$s, customer_site_http=%2$d, latency_ms=%3$d.', 'lovedbyai-seo-for-llms-and-ai-search'),
        $classification !== '' ? $classification : '—',
        $http_status,
        $latency
    );

    if (!$pass && $classification !== '') {
        $hint = geoguru_diagnostic_reachability_remediation_hint($classification);
        if ($hint !== '') {
            $msg = ($msg !== '' ? $msg . ' ' : '') . $hint;
        }
    }

    if ($msg === '') {
        $msg = $pass ? __('Probe succeeded.', 'lovedbyai-seo-for-llms-and-ai-search') : __('Probe failed.', 'lovedbyai-seo-for-llms-and-ai-search');
    }

    return array(
        'pass' => $pass,
        'message' => trim($msg . ' ' . $detail),
    );
}

/**
 * Capability checks for the current user.
 *
 * @return array
 */
function geoguru_diagnostic_check_capabilities() {
    $checks = array();
    $user = wp_get_current_user();

    $capabilities_to_check = array(
        'manage_options' => __('Full admin access (manage_options)', 'lovedbyai-seo-for-llms-and-ai-search'),
        'edit_posts' => __('Edit posts', 'lovedbyai-seo-for-llms-and-ai-search'),
        'publish_posts' => __('Publish posts', 'lovedbyai-seo-for-llms-and-ai-search'),
        'install_plugins' => __('Install plugins', 'lovedbyai-seo-for-llms-and-ai-search'),
        'activate_plugins' => __('Activate plugins', 'lovedbyai-seo-for-llms-and-ai-search'),
    );

    foreach ($capabilities_to_check as $cap => $label) {
        $checks[$cap] = array(
            'label' => $label,
            'pass' => current_user_can($cap),
        );
    }

    return array(
        'user_id' => $user->ID,
        'user_login' => $user->user_login,
        'checks' => $checks,
    );
}

/**
 * Options table read/write probe.
 *
 * @return array
 */
function geoguru_diagnostic_check_options() {
    $test_value = 'geoguru_diag_opt_' . time();
    $results = array();

    $write_result = update_option(GEOGURU_DIAGNOSTIC_OPTION_TEST_KEY, $test_value);
    $results['write'] = array(
        'label' => __('Write option (update_option)', 'lovedbyai-seo-for-llms-and-ai-search'),
        'pass' => $write_result !== false,
        'message' => $write_result !== false ? __('Success', 'lovedbyai-seo-for-llms-and-ai-search') : __('Failed', 'lovedbyai-seo-for-llms-and-ai-search'),
    );

    $read_value = get_option(GEOGURU_DIAGNOSTIC_OPTION_TEST_KEY, 'NOT_FOUND');
    $read_ok = $read_value === $test_value;
    $results['read'] = array(
        'label' => __('Read option (get_option)', 'lovedbyai-seo-for-llms-and-ai-search'),
        'pass' => $read_ok,
        'message' => $read_ok
            ? __('Success — value matches', 'lovedbyai-seo-for-llms-and-ai-search')
            : sprintf(
                /* translators: %s: value read from database */
                __('Failed — got: %s', 'lovedbyai-seo-for-llms-and-ai-search'),
                esc_html((string) $read_value)
            ),
    );

    delete_option(GEOGURU_DIAGNOSTIC_OPTION_TEST_KEY);

    return $results;
}

/**
 * GeoGuru iframe REST token create + verify (same flow as REST permission_callback).
 *
 * @return array
 */
function geoguru_diagnostic_check_geoguru_token_flow() {
    $results = array();

    if (!function_exists('geoguru_create_secure_nonce') || !function_exists('geoguru_verify_secure_token')) {
        return array(
            'available' => array(
                'label' => __('token API', 'lovedbyai-seo-for-llms-and-ai-search'),
                'pass' => false,
                'message' => __('Token helpers are missing. This should not happen in the main plugin.', 'lovedbyai-seo-for-llms-and-ai-search'),
            ),
        );
    }

    $results['available'] = array(
        'label' => __('token API', 'lovedbyai-seo-for-llms-and-ai-search'),
        'pass' => true,
        'message' => __('Token functions loaded.', 'lovedbyai-seo-for-llms-and-ai-search'),
    );

    $token = geoguru_create_secure_nonce('geoguru_rest_nonce');
    if (!$token) {
        $results['create'] = array(
            'label' => __('Create token', 'lovedbyai-seo-for-llms-and-ai-search'),
            'pass' => false,
            'message' => __('Failed to create token. Check logs.', 'lovedbyai-seo-for-llms-and-ai-search'),
        );
        return $results;
    }

    $results['create'] = array(
        'label' => __('Create token', 'lovedbyai-seo-for-llms-and-ai-search'),
        'pass' => true,
        'message' => sprintf(
            /* translators: %d: token length */
            __('Token created (%d chars)', 'lovedbyai-seo-for-llms-and-ai-search'),
            strlen($token)
        ),
    );

    $user_id = geoguru_verify_secure_token($token, 'geoguru_rest_nonce');
    $verify_ok = ($user_id !== false && $user_id > 0);

    $results['verify'] = array(
        'label' => __('Verify token', 'lovedbyai-seo-for-llms-and-ai-search'),
        'pass' => $verify_ok,
        'message' => $verify_ok
            ? sprintf(
                /* translators: %d: WordPress user ID */
                __('Verified — user_id %d', 'lovedbyai-seo-for-llms-and-ai-search'),
                $user_id
            )
            : __('Token not found or invalid. Options may not persist (object cache, DB issues, etc.).', 'lovedbyai-seo-for-llms-and-ai-search'),
    );

    return $results;
}

/**
 * Transients read/write probe.
 *
 * @return array
 */
function geoguru_diagnostic_check_transients() {
    $test_value = 'geoguru_diag_tr_' . time();
    $results = array();

    $write_result = set_transient(GEOGURU_DIAGNOSTIC_TRANSIENT_TEST_KEY, $test_value, MINUTE_IN_SECONDS);
    $results['write'] = array(
        'label' => __('Write transient (set_transient)', 'lovedbyai-seo-for-llms-and-ai-search'),
        'pass' => $write_result !== false,
        'message' => $write_result !== false ? __('Success', 'lovedbyai-seo-for-llms-and-ai-search') : __('Failed', 'lovedbyai-seo-for-llms-and-ai-search'),
    );

    $read_value = get_transient(GEOGURU_DIAGNOSTIC_TRANSIENT_TEST_KEY);
    $read_ok = $read_value === $test_value;
    $results['read'] = array(
        'label' => __('Read transient (get_transient)', 'lovedbyai-seo-for-llms-and-ai-search'),
        'pass' => $read_ok,
        'message' => $read_ok
            ? __('Success — value matches', 'lovedbyai-seo-for-llms-and-ai-search')
            : sprintf(
                /* translators: %s: value read from transient */
                __('Failed — got: %s', 'lovedbyai-seo-for-llms-and-ai-search'),
                esc_html((string) $read_value)
            ),
    );

    delete_transient(GEOGURU_DIAGNOSTIC_TRANSIENT_TEST_KEY);

    return $results;
}

/**
 * Origins to try for CORS preflight probe (must match geoguru_set_cors_headers allowlist + optional ALLOWED_ORIGINS when WP_DEBUG).
 *
 * @return array
 */
function geoguru_diagnostic_get_cors_probe_origins() {
    $origins = array(
        'https://app.geoguru.ai',
        'https://portal.geoguru.ai',
        'https://lovedby.ai',
        'https://app.lovedby.ai',
    );
    if (defined('WP_DEBUG') && WP_DEBUG && class_exists('GeoGuru_ConfigService')) {
        $config = GeoGuru_ConfigService::get_instance();
        $raw = $config->get('ALLOWED_ORIGINS', '');
        if (!empty($raw)) {
            foreach (array_map('trim', explode(',', $raw)) as $o) {
                if ($o !== '') {
                    $sanitized = esc_url_raw($o);
                    if (filter_var($sanitized, FILTER_VALIDATE_URL)) {
                        $origins[] = $sanitized;
                    }
                }
            }
        }
    }
    return array_values(array_unique($origins));
}

/**
 * Resolve the portal's same-origin proxy URL (`/api/wp-proxy`) from the
 * `geoguru_portal_url` option. Empty string when the option is missing or
 * not a valid URL — the caller must treat that as "skipped".
 *
 * @return string
 */
function geoguru_diagnostic_get_portal_proxy_url() {
    $portal_url = get_option('geoguru_portal_url');
    if (empty($portal_url) || !filter_var($portal_url, FILTER_VALIDATE_URL)) {
        return '';
    }
    return rtrim(esc_url_raw($portal_url), '/') . '/api/wp-proxy';
}

/**
 * Exercise the portal's `/api/wp-proxy` rescue path in bootstrap mode
 * (WP-REST URL + nonce, no Supabase session — the same shape the portal
 * uses for pre-signup install traffic). A green result here means the
 * portal's Cloudflare Pages Function can reach this site server-to-server
 * and the WP plugin accepts its token, which is exactly what has to be
 * true for a browser on a CORS-broken network to fall through to the
 * proxy and save settings anyway.
 *
 * Note on scope: this test does WP-server -> portal -> WP-server. A
 * browser's path is browser -> portal -> WP-server; the browser hop is
 * same-origin and can't be tested from here. If the WP server's egress
 * firewall blocks `app.lovedby.ai` but users' ISPs don't, this probe
 * will fail while real-world rescue still works — we call that out in
 * the failure message rather than pretending it's a universal gate.
 *
 * Called only after the session-token create/verify checks have passed,
 * because we need a live, valid `geoguru_rest_nonce` token to forward.
 *
 * Result shape mirrors the other section-5 checks:
 *   { pass: bool, skipped: bool, message: string }.
 *
 * @param string $token Session token from geoguru_create_secure_nonce.
 * @return array
 */
function geoguru_diagnostic_check_portal_proxy_rescue_path($token) {
    $proxy_url = geoguru_diagnostic_get_portal_proxy_url();
    if ($proxy_url === '') {
        return array(
            'skipped' => true,
            'pass' => true,
            'message' => __('Skipped — geoguru_portal_url option is empty or invalid. The portal proxy rescue path can not be tested.', 'lovedbyai-seo-for-llms-and-ai-search'),
        );
    }
    if (empty($token)) {
        return array(
            'skipped' => true,
            'pass' => true,
            'message' => __('Skipped — session token create/verify must pass first; the probe needs a live token to forward.', 'lovedbyai-seo-for-llms-and-ai-search'),
        );
    }

    // Bootstrap mode on the portal enforces `https://<host>/wp-json/geoguru/v1/`
    // and rejects private / non-https URLs. That's intentional — the proxy
    // can not be used for http or internal sites in production either — so
    // we skip cleanly rather than emitting a red FAIL the customer can't
    // act on.
    $rest_base = rest_url('geoguru/v1/');
    $rest_scheme = wp_parse_url($rest_base, PHP_URL_SCHEME);
    if ($rest_scheme !== 'https') {
        return array(
            'skipped' => true,
            'pass' => true,
            'message' => sprintf(
                /* translators: %s: REST base URL the site is serving */
                __('Skipped — the portal proxy requires an HTTPS site URL. rest_url() returned "%s". In production the rescue path will only be available after the site is served over HTTPS.', 'lovedbyai-seo-for-llms-and-ai-search'),
                esc_url_raw($rest_base)
            ),
        );
    }

    $payload = wp_json_encode(array(
        'endpoint' => '/settings',
        'method' => 'GET',
        'wpToken' => $token,
        'wpRestUrl' => $rest_base,
    ));
    if ($payload === false) {
        return array(
            'skipped' => false,
            'pass' => false,
            'message' => __('Could not JSON-encode proxy probe payload.', 'lovedbyai-seo-for-llms-and-ai-search'),
        );
    }

    $response = wp_remote_post(
        $proxy_url,
        array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => $payload,
            'reject_unsafe_urls' => false,
        )
    );

    if (is_wp_error($response)) {
        return array(
            'skipped' => false,
            'pass' => false,
            'message' => sprintf(
                /* translators: 1: proxy URL, 2: WP_Error message */
                __('Could not reach the portal proxy at %1$s from this server: %2$s. This probe runs WP → portal → WP; browsers use a different network path, so a failure here does not categorically prove the rescue path is broken for end users — but it does mean this server-side test can not verify it.', 'lovedbyai-seo-for-llms-and-ai-search'),
                esc_url($proxy_url),
                esc_html($response->get_error_message())
            ),
        );
    }

    $http = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    $classification = (is_array($data) && isset($data['classification'])) ? (string) $data['classification'] : '';
    $upstream_status = (is_array($data) && isset($data['upstreamStatus'])) ? (int) $data['upstreamStatus'] : 0;
    $reason = (is_array($data) && isset($data['reason'])) ? (string) $data['reason'] : '';

    if ($http === 200 && $classification === 'ok' && $upstream_status === 200) {
        return array(
            'skipped' => false,
            'pass' => true,
            'message' => sprintf(
                /* translators: %s: proxy URL */
                __('OK — portal proxy at %s reached this site and returned a 200 /settings response. Settings save will work for users whose browsers cannot complete the direct CORS request.', 'lovedbyai-seo-for-llms-and-ai-search'),
                esc_url($proxy_url)
            ),
        );
    }

    // Translate the classification taxonomy into an actionable message.
    // Each branch explains both what happened and what the admin can do
    // about it — this row is the customer's one source of truth for
    // "is the rescue path actually available for my site?"
    if ($classification === 'remote_block') {
        return array(
            'skipped' => false,
            'pass' => false,
            'message' => sprintf(
                /* translators: 1: machine reason, 2: upstream HTTP status */
                __('Portal reached this site but the response was classified as blocked (reason: %1$s, upstream HTTP %2$d). A WAF, CDN, or security plugin is blocking Cloudflare egress to this site — the rescue path cannot help browsers until that source is allowlisted.', 'lovedbyai-seo-for-llms-and-ai-search'),
                esc_html($reason !== '' ? $reason : '—'),
                $upstream_status
            ),
        );
    }
    if ($classification === 'tls') {
        return array(
            'skipped' => false,
            'pass' => false,
            'message' => sprintf(
                /* translators: %s: machine reason */
                __('Portal reached this site but TLS verification failed (reason: %s). The rescue path cannot be used until the TLS chain is fixed.', 'lovedbyai-seo-for-llms-and-ai-search'),
                esc_html($reason !== '' ? $reason : 'tls_error')
            ),
        );
    }
    if ($classification === 'unreachable') {
        return array(
            'skipped' => false,
            'pass' => false,
            'message' => sprintf(
                /* translators: 1: machine reason, 2: upstream HTTP status */
                __('Portal could not reach this site at all (reason: %1$s, upstream HTTP %2$d). Confirm public DNS and that the firewall does not drop Cloudflare egress.', 'lovedbyai-seo-for-llms-and-ai-search'),
                esc_html($reason !== '' ? $reason : 'network'),
                $upstream_status
            ),
        );
    }
    if ($classification === 'auth') {
        return array(
            'skipped' => false,
            'pass' => false,
            'message' => sprintf(
                /* translators: 1: upstream HTTP status, 2: machine reason */
                __('Portal reached this site but the plugin rejected the session token (upstream HTTP %1$d, reason: %2$s). The session-token create/verify checks above should pass first.', 'lovedbyai-seo-for-llms-and-ai-search'),
                $upstream_status,
                esc_html($reason !== '' ? $reason : '—')
            ),
        );
    }
    if ($classification === 'wp_error') {
        return array(
            'skipped' => false,
            'pass' => false,
            'message' => sprintf(
                /* translators: 1: proxy HTTP status, 2: machine reason, 3: upstream HTTP status */
                __('Portal proxy returned wp_error (proxy HTTP %1$d, reason: %2$s, upstream HTTP %3$d). Usually indicates a malformed payload or an ineligible wpRestUrl shape.', 'lovedbyai-seo-for-llms-and-ai-search'),
                $http,
                esc_html($reason !== '' ? $reason : '—'),
                $upstream_status
            ),
        );
    }
    return array(
        'skipped' => false,
        'pass' => false,
        'message' => sprintf(
            /* translators: 1: proxy HTTP status, 2: classification, 3: upstream HTTP status, 4: reason */
            __('Unexpected proxy response (proxy HTTP %1$d, classification: %2$s, upstream HTTP %3$d, reason: %4$s).', 'lovedbyai-seo-for-llms-and-ai-search'),
            $http,
            esc_html($classification !== '' ? $classification : '—'),
            $upstream_status,
            esc_html($reason !== '' ? $reason : '—')
        ),
    );
}

/**
 * Server-to-self OPTIONS request to GeoGuru REST (simulates browser CORS preflight).
 * Detects 405 Method Not Allowed and other blocks before WordPress can answer.
 *
 * @return array { pass: bool, message: string, code: int, origin_tested: string }
 */
function geoguru_diagnostic_check_options_http_preflight() {
    $url = rest_url('geoguru/v1/settings');
    $origins = geoguru_diagnostic_get_cors_probe_origins();
    $last_code = 0;
    $last_error = '';
    $saw_405 = false;

    foreach ($origins as $origin) {
        $response = wp_remote_request(
            $url,
            geoguru_diagnostic_same_site_http_probe_args(
                array(
                    'method' => 'OPTIONS',
                    'headers' => array(
                        'Origin' => $origin,
                        'Access-Control-Request-Method' => 'POST',
                        'Access-Control-Request-Headers' => 'content-type, x-geoguru-token',
                    ),
                )
            )
        );

        if (is_wp_error($response)) {
            $last_error = $response->get_error_message();
            continue;
        }

        $last_code = (int) wp_remote_retrieve_response_code($response);
        if ($last_code === 405) {
            $saw_405 = true;
        }
        if ($last_code === 200 || $last_code === 204) {
            $cors_check = geoguru_diagnostic_validate_preflight_cors_headers($response, $origin);
            if (!$cors_check['pass']) {
                return array(
                    'pass' => false,
                    'message' => $cors_check['message'],
                    'code' => $last_code,
                    'origin_tested' => $origin,
                );
            }
            return array(
                'pass' => true,
                'message' => sprintf(
                    /* translators: 1: Origin header used, 2: HTTP status code */
                    __('OPTIONS preflight OK — Origin %1$s, HTTP %2$d. CORS headers validated (Allow-Origin echoes request, credentials, POST/OPTIONS in Allow-Methods).', 'lovedbyai-seo-for-llms-and-ai-search'),
                    $origin,
                    $last_code
                ),
                'code' => $last_code,
                'origin_tested' => $origin,
            );
        }
    }

    if ($last_error !== '') {
        return array(
            'pass' => false,
            'message' => sprintf(
                /* translators: %s: WordPress HTTP error message */
                __('OPTIONS probe request error: %s', 'lovedbyai-seo-for-llms-and-ai-search'),
                $last_error
            ),
            'code' => 0,
            'origin_tested' => '',
        );
    }

    if ($saw_405) {
        return array(
            'pass' => false,
            'message' => __('OPTIONS returned 405 Method Not Allowed. Nginx, a WAF, or a security plugin may be blocking preflight. Allow OPTIONS for /wp-json/geoguru/v1/*.', 'lovedbyai-seo-for-llms-and-ai-search'),
            'code' => 405,
            'origin_tested' => '',
        );
    }

    return array(
        'pass' => false,
        'message' => sprintf(
            /* translators: %d: HTTP status code */
            __('OPTIONS preflight failed — last HTTP status %d. If the portal is embedded cross-origin, preflight must return 200/204 with CORS headers.', 'lovedbyai-seo-for-llms-and-ai-search'),
            $last_code
        ),
        'code' => $last_code,
        'origin_tested' => '',
    );
}

/**
 * Render Environment Diagnosis admin page.
 */
function geoguru_render_environment_diagnosis_page() {
    if (function_exists('geoguru_prune_expired_iframe_tokens')) {
        geoguru_prune_expired_iframe_tokens();
    }

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'lovedbyai-seo-for-llms-and-ai-search'));
    }

    add_filter('http_request_args', 'geoguru_diagnostic_http_request_args_disable_sslverify', 99999, 2);

    $cors_get_with_origin = array(
        'pass' => true,
        'skipped' => true,
        'message' => __('Not run.', 'lovedbyai-seo-for-llms-and-ai-search'),
    );
    $rest_global_namespace_check = array(
        'pass' => false,
        'message' => __('Not run.', 'lovedbyai-seo-for-llms-and-ai-search'),
    );
    $rest_public_route_check = array(
        'pass' => false,
        'message' => __('Not run.', 'lovedbyai-seo-for-llms-and-ai-search'),
    );
    $rest_token_query_check = array(
        'skipped' => true,
        'pass' => true,
        'message' => __('Not run.', 'lovedbyai-seo-for-llms-and-ai-search'),
    );
    $rest_token_header_check = array(
        'skipped' => true,
        'pass' => true,
        'message' => __('Not run.', 'lovedbyai-seo-for-llms-and-ai-search'),
    );
    $portal_proxy_check = array(
        'skipped' => true,
        'pass' => true,
        'message' => __('Not run.', 'lovedbyai-seo-for-llms-and-ai-search'),
    );
    $reachability_page_discovery = array(
        'pass' => true,
        'message' => __('Not run.', 'lovedbyai-seo-for-llms-and-ai-search'),
    );
    $reachability_optimizer = $reachability_page_discovery;
    $reachability_indexnow  = $reachability_page_discovery;

    try {
        $cap_results = geoguru_diagnostic_check_capabilities();
        $option_results = geoguru_diagnostic_check_options();
        $transient_results = geoguru_diagnostic_check_transients();
        $geoguru_results = geoguru_diagnostic_check_geoguru_token_flow();
        $options_http_preflight = geoguru_diagnostic_check_options_http_preflight();

        $all_pass = true;
        foreach (array_merge($option_results, $transient_results) as $r) {
            if (!$r['pass']) {
                $all_pass = false;
                break;
            }
        }
        if (function_exists('geoguru_create_secure_nonce')) {
            foreach ($geoguru_results as $r) {
                if (!$r['pass']) {
                    $all_pass = false;
                    break;
                }
            }
        }

        // Note: CORS failures do NOT flip $all_pass here — we defer that
        // until after the portal proxy rescue check has run so a working
        // rescue path can mitigate the failure per the user-visible gate
        // below.

        $rest_global_namespace_check = geoguru_diagnostic_check_rest_namespace_not_globally_locked();
        $rest_public_route_check = geoguru_diagnostic_check_rest_public_llm_event_not_globally_locked();
        if (!$rest_global_namespace_check['pass'] || !$rest_public_route_check['pass']) {
            $all_pass = false;
        }

        $rest_test_token = null;
        $rest_settings_url = '';
        if (function_exists('geoguru_create_secure_nonce') && isset($geoguru_results['verify']) && $geoguru_results['verify']['pass']) {
            $rest_test_token = geoguru_create_secure_nonce('geoguru_rest_nonce');
            if ($rest_test_token) {
                $rest_settings_url = rest_url('geoguru/v1/settings') . '?token=' . rawurlencode($rest_test_token);
                $rest_token_query_check = geoguru_diagnostic_check_rest_settings_with_token($rest_test_token, 'query');
                $rest_token_header_check = geoguru_diagnostic_check_rest_settings_with_token($rest_test_token, 'header');
                $rest_token_query_check['skipped'] = false;
                $rest_token_header_check['skipped'] = false;
                if (!$rest_token_query_check['pass'] || !$rest_token_header_check['pass']) {
                    $all_pass = false;
                }
            } else {
                $rest_token_query_check = array(
                    'skipped' => false,
                    'pass' => false,
                    'message' => __('Could not create a session token for REST probes.', 'lovedbyai-seo-for-llms-and-ai-search'),
                );
                $rest_token_header_check = $rest_token_query_check;
                $all_pass = false;
            }
        } else {
            $rest_token_query_check = array(
                'skipped' => true,
                'pass' => true,
                'message' => __('Skipped — session token create/verify checks must pass first.', 'lovedbyai-seo-for-llms-and-ai-search'),
            );
            $rest_token_header_check = $rest_token_query_check;
        }

        if ($options_http_preflight['pass'] && !empty($options_http_preflight['origin_tested'])) {
            if ($rest_test_token) {
                $cors_get_with_origin = geoguru_diagnostic_check_rest_get_cors_headers(
                    rest_url('geoguru/v1/settings') . '?token=' . rawurlencode($rest_test_token),
                    $options_http_preflight['origin_tested']
                );
            } else {
                $cors_get_with_origin = array(
                    'pass' => true,
                    'skipped' => true,
                    'message' => __('Skipped — session token checks must pass to probe an authenticated GET with Origin.', 'lovedbyai-seo-for-llms-and-ai-search'),
                );
            }
        } else {
            $cors_get_with_origin = array(
                'pass' => true,
                'skipped' => true,
                'message' => __('Skipped — OPTIONS preflight must succeed first.', 'lovedbyai-seo-for-llms-and-ai-search'),
            );
        }

        // Portal proxy rescue path — establishes whether a CORS failure on
        // either of the rows above is mitigated by the same-origin proxy.
        // Runs only if we have a live session token; otherwise skipped.
        if ($rest_test_token) {
            $portal_proxy_check = geoguru_diagnostic_check_portal_proxy_rescue_path($rest_test_token);
        } else {
            $portal_proxy_check = array(
                'skipped' => true,
                'pass' => true,
                'message' => __('Skipped — session token checks must pass first.', 'lovedbyai-seo-for-llms-and-ai-search'),
            );
        }

        // Mitigation gate: the overall "All critical checks passed" banner
        // treats CORS failures as OK when the portal proxy rescue path is
        // definitively working (classification: ok, upstream 200). That
        // matches the user-visible reality — settings save works in the
        // portal even if the raw CORS headers are wrong — without lying
        // about the CORS rows themselves, which still render with their
        // raw result plus an explanatory "rescued by proxy" note.
        $cors_options_failed = !$options_http_preflight['pass'];
        $cors_get_failed = (empty($cors_get_with_origin['skipped']) && empty($cors_get_with_origin['pass']));
        $cors_any_failed = $cors_options_failed || $cors_get_failed;
        $proxy_rescue_ok = empty($portal_proxy_check['skipped']) && !empty($portal_proxy_check['pass']);
        $cors_mitigated_by_proxy = $cors_any_failed && $proxy_rescue_ok;

        if ($cors_any_failed && !$cors_mitigated_by_proxy) {
            $all_pass = false;
        }
        // The proxy-rescue row never flips $all_pass on its own. When CORS
        // is fine, a failing rescue row is purely informational (the
        // customer is working today via direct; rescue would only help if
        // CORS broke later). Only the mitigation interaction above is
        // gate-relevant.

        $reachability_page_discovery = geoguru_diagnostic_check_backend_service_site_reachability(
            __('Page discovery service', 'lovedbyai-seo-for-llms-and-ai-search'),
            'geoguru_discovery_service_url',
            '/api/v1/discovery/websites/%s/reachability'
        );
        $reachability_optimizer = geoguru_diagnostic_check_backend_service_site_reachability(
            __('Webpage content optimizer', 'lovedbyai-seo-for-llms-and-ai-search'),
            'geoguru_optimizer_service_url',
            '/api/v1/optimizer/websites/%s/reachability'
        );
        $reachability_indexnow = geoguru_diagnostic_check_backend_service_site_reachability(
            __('IndexNow service', 'lovedbyai-seo-for-llms-and-ai-search'),
            'geoguru_indexnow_service_url',
            '/api/v1/indexnow/websites/%s/reachability'
        );
        if (!$reachability_page_discovery['pass'] || !$reachability_optimizer['pass'] || !$reachability_indexnow['pass']) {
            $all_pass = false;
        }
    } finally {
        remove_filter('http_request_args', 'geoguru_diagnostic_http_request_args_disable_sslverify', 99999);
    }

    $diagnostic_page_url = admin_url('admin.php?page=' . GEOGURU_DIAGNOSTIC_PAGE_SLUG);
    $display_name = function_exists('geoguru_get_display_name') ? geoguru_get_display_name() : 'LovedByAI';
    ?>
    <div class="wrap">
        <h1><?php echo esc_html($display_name . ' — ' . __('Environment diagnosis', 'lovedbyai-seo-for-llms-and-ai-search')); ?></h1>
        <p class="description">
            <?php esc_html_e('Verifies permissions, database options, transients, REST token flow, server-to-self REST GET, CORS (with portal proxy rescue fallback), WordPress-wide REST login rules, whether LovedByAI backend services can reach this site\'s public reachability endpoint (WAF / connectivity), and that the public tracking endpoint stays reachable. Run any time after changing hosting, cache, or security plugins.', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
        </p>

        <p>
            <a href="<?php echo esc_url($diagnostic_page_url); ?>" class="button button-primary">
                <?php esc_html_e('Run diagnosis again', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
            </a>
        </p>

        <div class="environment-diagnosis-summary <?php echo $all_pass ? 'status-pass' : 'status-fail'; ?>" style="padding: 12px; margin: 16px 0; border-left: 4px solid <?php echo $all_pass ? '#46b450' : '#dc3232'; ?>; background: #fff;">
            <strong>
                <?php
                echo $all_pass
                    ? esc_html__('All critical checks passed.', 'lovedbyai-seo-for-llms-and-ai-search')
                    : esc_html__('Some checks failed. See details below.', 'lovedbyai-seo-for-llms-and-ai-search');
                ?>
            </strong>
        </div>

        <h2><?php esc_html_e('1. User & capabilities', 'lovedbyai-seo-for-llms-and-ai-search'); ?></h2>
        <p>
            <?php
            printf(
                /* translators: 1: WordPress user ID, 2: WordPress login name */
                esc_html__('User ID: %1$d | Login: %2$s', 'lovedbyai-seo-for-llms-and-ai-search'),
                absint($cap_results['user_id']),
                esc_html($cap_results['user_login'])
            );
            ?>
        </p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Capability', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Status', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cap_results['checks'] as $check) : ?>
                <tr>
                    <td><?php echo esc_html($check['label']); ?></td>
                    <td>
                        <span style="color: <?php echo $check['pass'] ? '#46b450' : '#dc3232'; ?>;">
                            <?php echo $check['pass'] ? esc_html__('Yes', 'lovedbyai-seo-for-llms-and-ai-search') : esc_html__('No', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2><?php esc_html_e('2. Options (get_option / update_option)', 'lovedbyai-seo-for-llms-and-ai-search'); ?></h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Test', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Status', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Message', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($option_results as $result) : ?>
                <tr>
                    <td><?php echo esc_html($result['label']); ?></td>
                    <td>
                        <span style="color: <?php echo $result['pass'] ? '#46b450' : '#dc3232'; ?>;">
                            <?php echo $result['pass'] ? esc_html__('OK', 'lovedbyai-seo-for-llms-and-ai-search') : esc_html__('FAIL', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($result['message']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2><?php esc_html_e('3. Transients (get_transient / set_transient)', 'lovedbyai-seo-for-llms-and-ai-search'); ?></h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Test', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Status', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Message', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transient_results as $result) : ?>
                <tr>
                    <td><?php echo esc_html($result['label']); ?></td>
                    <td>
                        <span style="color: <?php echo $result['pass'] ? '#46b450' : '#dc3232'; ?>;">
                            <?php echo $result['pass'] ? esc_html__('OK', 'lovedbyai-seo-for-llms-and-ai-search') : esc_html__('FAIL', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($result['message']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2><?php esc_html_e('4. token flow (REST API auth)', 'lovedbyai-seo-for-llms-and-ai-search'); ?></h2>
        <p class="description">
            <?php esc_html_e('Same create-and-verify flow used by REST endpoints. If generic options pass but this fails, iframe tokens may not persist (object cache, DB, or permissions).', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
        </p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Test', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Status', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Message', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($geoguru_results as $result) : ?>
                <tr>
                    <td><?php echo esc_html($result['label']); ?></td>
                    <td>
                        <span style="color: <?php echo $result['pass'] ? '#46b450' : '#dc3232'; ?>;">
                            <?php echo $result['pass'] ? esc_html__('OK', 'lovedbyai-seo-for-llms-and-ai-search') : esc_html__('FAIL', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($result['message']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($rest_test_token) : ?>
        <h3 style="margin-top: 1em;"><?php esc_html_e('REST /settings with session token (guest-style)', 'lovedbyai-seo-for-llms-and-ai-search'); ?></h3>
        <p class="description">
            <?php esc_html_e('These requests do not send WordPress login cookies (same idea as a cross-origin iframe). A 401 or 403 here means the token is rejected on the wire even though it was just created — distinct from “anonymous / no token” checks in section 6.', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
        </p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Test', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Status', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Message', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php esc_html_e('GET with ?token= (portal GET style)', 'lovedbyai-seo-for-llms-and-ai-search'); ?></td>
                    <td>
                        <span style="color: <?php echo !empty($rest_token_query_check['pass']) ? '#46b450' : '#dc3232'; ?>;">
                            <?php echo !empty($rest_token_query_check['pass']) ? esc_html__('OK', 'lovedbyai-seo-for-llms-and-ai-search') : esc_html__('FAIL', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($rest_token_query_check['message']); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('GET with plugin\'s token header only (POST-style token)', 'lovedbyai-seo-for-llms-and-ai-search'); ?></td>
                    <td>
                        <span style="color: <?php echo !empty($rest_token_header_check['pass']) ? '#46b450' : '#dc3232'; ?>;">
                            <?php echo !empty($rest_token_header_check['pass']) ? esc_html__('OK', 'lovedbyai-seo-for-llms-and-ai-search') : esc_html__('FAIL', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($rest_token_header_check['message']); ?></td>
                </tr>
            </tbody>
        </table>
        <p class="description">
            <?php esc_html_e('Manual REST test: open this link in the same browser (you must be logged in as an admin).', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
        </p>
        <p>
            <a href="<?php echo esc_url($rest_settings_url); ?>" target="_blank" rel="noopener noreferrer" class="button">
                <?php esc_html_e('Test REST /settings endpoint', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
            </a>
        </p>
        <?php else : ?>
        <p class="description" style="margin-top: 12px;">
            <strong><?php esc_html_e('REST /settings with session token:', 'lovedbyai-seo-for-llms-and-ai-search'); ?></strong>
            <?php echo ' ' . esc_html($rest_token_query_check['message']); ?>
        </p>
        <?php endif; ?>

        <h2><?php esc_html_e('5. CORS and portal proxy rescue path', 'lovedbyai-seo-for-llms-and-ai-search'); ?></h2>
        <p class="description">
            <?php esc_html_e('Checks that allowed Origins receive correct Access-Control-* headers on OPTIONS preflight and on an authenticated GET (cross-origin admin). Missing or wrong values often mean another plugin, theme, or reverse proxy is overriding or stripping headers after this plugin sets them. When CORS is broken, the portal falls back to a same-origin proxy that forwards settings calls server-to-server — the last row verifies the proxy can reach this site.', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
        </p>
        <?php
        // Status rendering for the two CORS rows respects the mitigation
        // gate: when the portal proxy rescue path is verifiably working we
        // flip a raw FAIL to an OK here because settings save works for
        // users via the rescue path, which is the outcome the customer
        // actually cares about. The message column stays honest and shows
        // the raw detail plus a "rescued by proxy" prefix so an admin
        // reading this for the first time understands both that CORS
        // headers are misconfigured AND that the system compensates.
        $preflight_raw_ok = !empty($options_http_preflight['pass']);
        $preflight_mitigated = !$preflight_raw_ok && $cors_mitigated_by_proxy;
        if ($preflight_raw_ok) {
            $preflight_status_label = __('OK', 'lovedbyai-seo-for-llms-and-ai-search');
            $preflight_color = '#46b450';
            $preflight_message = $options_http_preflight['message'];
        } elseif ($preflight_mitigated) {
            $preflight_status_label = __('OK', 'lovedbyai-seo-for-llms-and-ai-search');
            $preflight_color = '#46b450';
            $preflight_message = sprintf(
                /* translators: %s: raw check message */
                __('Rescued by portal proxy — raw CORS preflight failed but the same-origin proxy fallback covers this: %s', 'lovedbyai-seo-for-llms-and-ai-search'),
                $options_http_preflight['message']
            );
        } else {
            $preflight_status_label = __('FAIL', 'lovedbyai-seo-for-llms-and-ai-search');
            $preflight_color = '#dc3232';
            $preflight_message = $options_http_preflight['message'];
        }

        if (!empty($cors_get_with_origin['skipped'])) {
            $cg_status_label = __('Skipped', 'lovedbyai-seo-for-llms-and-ai-search');
            $cg_color = '#996800';
            $cg_message = $cors_get_with_origin['message'];
        } elseif (!empty($cors_get_with_origin['pass'])) {
            $cg_status_label = __('OK', 'lovedbyai-seo-for-llms-and-ai-search');
            $cg_color = '#46b450';
            $cg_message = $cors_get_with_origin['message'];
        } elseif ($cors_mitigated_by_proxy) {
            $cg_status_label = __('OK', 'lovedbyai-seo-for-llms-and-ai-search');
            $cg_color = '#46b450';
            $cg_message = sprintf(
                /* translators: %s: raw check message */
                __('Rescued by portal proxy — raw CORS GET check failed but the same-origin proxy fallback covers this: %s', 'lovedbyai-seo-for-llms-and-ai-search'),
                $cors_get_with_origin['message']
            );
        } else {
            $cg_status_label = __('FAIL', 'lovedbyai-seo-for-llms-and-ai-search');
            $cg_color = '#dc3232';
            $cg_message = $cors_get_with_origin['message'];
        }

        if (!empty($portal_proxy_check['skipped'])) {
            $pp_status_label = __('Skipped', 'lovedbyai-seo-for-llms-and-ai-search');
            $pp_color = '#996800';
        } elseif (!empty($portal_proxy_check['pass'])) {
            $pp_status_label = __('OK', 'lovedbyai-seo-for-llms-and-ai-search');
            $pp_color = '#46b450';
        } else {
            $pp_status_label = __('FAIL', 'lovedbyai-seo-for-llms-and-ai-search');
            $pp_color = '#dc3232';
        }
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Test', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Status', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Message', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php esc_html_e('OPTIONS preflight (Allow-Origin, credentials, methods)', 'lovedbyai-seo-for-llms-and-ai-search'); ?></td>
                    <td>
                        <span style="color: <?php echo esc_attr($preflight_color); ?>;">
                            <?php echo esc_html($preflight_status_label); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($preflight_message); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('GET /settings with Origin (non-preflight CORS)', 'lovedbyai-seo-for-llms-and-ai-search'); ?></td>
                    <td>
                        <span style="color: <?php echo esc_attr($cg_color); ?>;">
                            <?php echo esc_html($cg_status_label); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($cg_message); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Portal proxy rescue path (bootstrap mode)', 'lovedbyai-seo-for-llms-and-ai-search'); ?></td>
                    <td>
                        <span style="color: <?php echo esc_attr($pp_color); ?>;">
                            <?php echo esc_html($pp_status_label); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($portal_proxy_check['message']); ?></td>
                </tr>
            </tbody>
        </table>

        <h2><?php esc_html_e('6. REST API — site-wide auth (not token)', 'lovedbyai-seo-for-llms-and-ai-search'); ?></h2>
        <p class="description">
            <?php esc_html_e('/settings and most plugins routes still require a valid session token — that is normal. Here we only detect WordPress-wide rules that block anonymous REST before those routes run (e.g. rest_api_authentication_required). The public llm-source-event endpoint must stay reachable without logging in.', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
        </p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Test', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Status', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Message', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php esc_html_e('Anonymous GET /geoguru/v1/settings (no token)', 'lovedbyai-seo-for-llms-and-ai-search'); ?></td>
                    <td>
                        <span style="color: <?php echo $rest_global_namespace_check['pass'] ? '#46b450' : '#dc3232'; ?>;">
                            <?php echo $rest_global_namespace_check['pass'] ? esc_html__('OK', 'lovedbyai-seo-for-llms-and-ai-search') : esc_html__('FAIL', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($rest_global_namespace_check['message']); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Anonymous POST /geoguru/v1/llm-source-event (public route)', 'lovedbyai-seo-for-llms-and-ai-search'); ?></td>
                    <td>
                        <span style="color: <?php echo $rest_public_route_check['pass'] ? '#46b450' : '#dc3232'; ?>;">
                            <?php echo $rest_public_route_check['pass'] ? esc_html__('OK', 'lovedbyai-seo-for-llms-and-ai-search') : esc_html__('FAIL', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($rest_public_route_check['message']); ?></td>
                </tr>
            </tbody>
        </table>

        <h2><?php esc_html_e('7. Backend services → WordPress reachability', 'lovedbyai-seo-for-llms-and-ai-search'); ?></h2>
        <p class="description">
            <?php esc_html_e('Each row asks a LovedByAI cloud service to fetch this site\'s public GET /wp-json/geoguru/v1/reachability from the internet (same path our integrations use). If a row fails, a WAF, CDN, firewall, or security plugin may be blocking outbound traffic from that service to your WordPress REST API.', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
        </p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Service', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Status', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                    <th><?php esc_html_e('Message', 'lovedbyai-seo-for-llms-and-ai-search'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php esc_html_e('Page discovery service', 'lovedbyai-seo-for-llms-and-ai-search'); ?></td>
                    <td>
                        <span style="color: <?php echo $reachability_page_discovery['pass'] ? '#46b450' : '#dc3232'; ?>;">
                            <?php echo $reachability_page_discovery['pass'] ? esc_html__('OK', 'lovedbyai-seo-for-llms-and-ai-search') : esc_html__('FAIL', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($reachability_page_discovery['message']); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Webpage content optimizer', 'lovedbyai-seo-for-llms-and-ai-search'); ?></td>
                    <td>
                        <span style="color: <?php echo $reachability_optimizer['pass'] ? '#46b450' : '#dc3232'; ?>;">
                            <?php echo $reachability_optimizer['pass'] ? esc_html__('OK', 'lovedbyai-seo-for-llms-and-ai-search') : esc_html__('FAIL', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($reachability_optimizer['message']); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('IndexNow service', 'lovedbyai-seo-for-llms-and-ai-search'); ?></td>
                    <td>
                        <span style="color: <?php echo $reachability_indexnow['pass'] ? '#46b450' : '#dc3232'; ?>;">
                            <?php echo $reachability_indexnow['pass'] ? esc_html__('OK', 'lovedbyai-seo-for-llms-and-ai-search') : esc_html__('FAIL', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($reachability_indexnow['message']); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
}
