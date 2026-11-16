<?php

// The protocol requires every catalog request to be negotiated against the calling agent's own profile.

defined('ABSPATH') || exit;

if (!function_exists('geoguru_ucp_resolve_host')) {
    function geoguru_ucp_resolve_host($host) {
        return GeoGuru_UcpAgent::resolve_host($host);
    }
}

class GeoGuru_UcpAgent {
    const CACHE_PREFIX = 'geoguru_ucp_agent_';
    const LAST_PREFIX = 'geoguru_ucp_agent_last_';
    const BUDGET_KEY = 'geoguru_ucp_agent_budget';
    const CALLER_PREFIX = 'geoguru_ucp_agent_caller_';
    const RUNNING_KEY = 'geoguru_ucp_agent_running';
    const RESOLVER_WARNED_KEY = 'geoguru_ucp_agent_resolver_warned';
    const BUDGET = 10;
    const CALLER_BUDGET = 3;
    const BUDGET_WINDOW = 60;
    const MAX_RUNNING = 2;
    const RUNNING_STALE = 30;
    const RUNNING_RETRY = 5;
    const FAILURE_TTL = 60;
    const MIN_TTL = 60;
    const DEFAULT_TTL = 3600;
    const MAX_TTL = 86400;
    const LAST_TTL = 604800;
    const MAX_HEADER = 2048;
    const MAX_BYTES = 131072;
    const VERSION_PATTERN = '/^\d{4}-\d{2}-\d{2}\z/';

    // Special-use ranges (RFC 6890 and later registry entries) that a profile URL must not reach.
    const SPECIAL_IPV4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];
    const SPECIAL_IPV6 = [
        '::/96', '::ffff:0:0/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32',
        '3fff::/20', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    // $caller is the caller's rate-limit bucket, so one caller cannot spend the whole discovery budget.
    public static function negotiate(string $header_value, string $capability, string $caller = ''): array {
        $fetched = false;
        $result = self::evaluate($header_value, $capability, $caller, $fetched);
        $result['fetched'] = $fetched;
        return $result;
    }

    // Both address families are looked up because the fetch may connect over either.
    public static function resolve_host($host) {
        $v4 = function_exists('gethostbynamel') ? gethostbynamel($host) : false;
        $v6 = function_exists('dns_get_record') && defined('DNS_AAAA') ? @dns_get_record($host, DNS_AAAA) : false;
        if (!is_array($v4) && !is_array($v6)) {
            return null;
        }
        $addresses = is_array($v4) ? $v4 : [];
        foreach (is_array($v6) ? $v6 : [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }
        return $addresses;
    }

    private static function evaluate($header_value, $capability, $caller, &$fetched) {
        $url = self::profile_url($header_value);
        if ($url === null) {
            return self::failure(400, 'invalid_profile_url', 'UCP-Agent must carry profile="<https URL>", the agent\'s UCP profile.');
        }
        $hash = md5($url);
        $entry = get_transient(self::CACHE_PREFIX . $hash);
        if (!self::is_entry($entry)) {
            $entry = self::refresh($url, $hash, $caller, $fetched);
        }
        if (isset($entry['error'])) {
            return $entry['error'];
        }
        if ($entry['version'] !== GEOGURU_UCP_VERSION) {
            return self::failure(422, 'version_unsupported', 'The agent profile\'s protocol version is not supported. This business supports version ' . GEOGURU_UCP_VERSION . '.');
        }
        $shared = [];
        foreach ($entry['capabilities'] as $name => $versions) {
            if ($versions) {
                rsort($versions);
                $shared[$name] = [['version' => $versions[0]]];
            }
        }
        if (!isset($shared[$capability])) {
            return self::failure(200, 'capabilities_incompatible', "No compatible capabilities in the intersection: the agent profile does not declare $capability at version " . GEOGURU_UCP_VERSION . '.');
        }
        return ['ok' => true, 'version' => GEOGURU_UCP_VERSION, 'capabilities' => $shared];
    }

    private static function is_entry($entry) {
        return is_array($entry) && (isset($entry['error']) || isset($entry['version'], $entry['capabilities']));
    }

    private static function failure($status, $code, $content, $retry_after = null) {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'content' => $content, 'retry_after' => $retry_after];
    }

    private static function refresh($url, $hash, $caller, &$fetched) {
        $last = get_transient(self::LAST_PREFIX . $hash);
        $last = self::is_entry($last) && !isset($last['error']) ? $last : null;
        $refusal = self::refusal($caller);
        if ($refusal !== null) {
            // A known agent keeps working from its last good profile while new lookups are held back.
            return $last ?? ['error' => $refusal];
        }
        $fetched = true;
        $token = self::start_running();
        try {
            $entry = self::discover($url);
        } finally {
            self::stop_running($token);
        }
        $ttl = $entry['ttl'];
        unset($entry['ttl']);
        if (isset($entry['error']) && $last !== null) {
            // It also keeps that profile while its host fails, cached for the failure's short lifetime.
            $entry = $last;
        } elseif (!isset($entry['error'])) {
            set_transient(self::LAST_PREFIX . $hash, $entry, self::LAST_TTL);
        }
        set_transient(self::CACHE_PREFIX . $hash, $entry, $ttl);
        return $entry;
    }

    // Slow DNS can hold a worker well past the fetch timeout, so few lookups run at once and few start each minute.
    private static function refusal($caller) {
        if (count(self::running()) >= self::MAX_RUNNING) {
            return self::failure(503, 'profile_unreachable', 'Too many agent profiles are being fetched right now. Retry later.', self::RUNNING_RETRY);
        }
        $budgets = [self::BUDGET_KEY => self::BUDGET];
        if ($caller !== '') {
            $budgets[self::CALLER_PREFIX . md5($caller)] = self::CALLER_BUDGET;
        }
        if (!self::spend_budgets($budgets)) {
            return self::failure(503, 'profile_unreachable', 'Too many new agent profiles to fetch right now. Retry later.', self::BUDGET_WINDOW);
        }
        return null;
    }

    private static function running() {
        $now = time();
        $running = get_transient(self::RUNNING_KEY);
        $kept = [];
        foreach (is_array($running) ? $running : [] as $token => $started) {
            if (is_int($started) && $now - $started <= self::RUNNING_STALE) {
                $kept[$token] = $started;
            }
        }
        return $kept;
    }

    private static function start_running() {
        $running = self::running();
        $token = uniqid('', true);
        $running[$token] = time();
        set_transient(self::RUNNING_KEY, $running, self::RUNNING_STALE);
        return $token;
    }

    private static function stop_running($token) {
        $running = self::running();
        unset($running[$token]);
        set_transient(self::RUNNING_KEY, $running, self::RUNNING_STALE);
    }

    // Spends one unit from each budget, or none when any of them is used up.
    private static function spend_budgets($budgets) {
        $now = time();
        $states = [];
        foreach ($budgets as $key => $limit) {
            $state = get_transient($key);
            if (!is_array($state) || !isset($state['start'], $state['count']) || $now - (int) $state['start'] >= self::BUDGET_WINDOW) {
                $state = ['start' => $now, 'count' => 0];
            }
            if ((int) $state['count'] >= $limit) {
                return false;
            }
            $states[$key] = $state;
        }
        foreach ($states as $key => $state) {
            $state['count'] = (int) $state['count'] + 1;
            set_transient($key, $state, max(1, (int) $state['start'] + self::BUDGET_WINDOW - $now));
        }
        return true;
    }

    private static function discover($url) {
        $addresses = self::public_addresses(self::url_host($url));
        if ($addresses === null) {
            return self::failed(400, 'invalid_profile_url', 'The agent profile URL must resolve to public addresses only.');
        }
        // cURL connects to the address checked above; other transports resolve again, leaving a short rebinding window.
        $resolve = self::pinned_resolve($url, $addresses[0]);
        $pin = function ($handle, $args, $request_url) use ($url, $resolve) {
            if ($request_url === $url && defined('CURLOPT_RESOLVE')) {
                curl_setopt($handle, CURLOPT_RESOLVE, $resolve); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress's own handle, through http_api_curl
            }
        };
        if ($resolve) {
            add_action('http_api_curl', $pin, 10, 3);
        }
        try {
            $response = wp_safe_remote_get($url, [
                'timeout' => 3,
                'redirection' => 0,
                'limit_response_size' => self::MAX_BYTES,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } finally {
            if ($resolve) {
                remove_action('http_api_curl', $pin, 10);
            }
        }
        $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status > 299) {
            return self::failed(424, 'profile_unreachable', 'Unable to fetch agent profile.');
        }
        $profile = self::read_profile((string) wp_remote_retrieve_body($response));
        if ($profile === null) {
            return self::failed(422, 'profile_malformed', 'The agent profile is not a valid UCP platform profile.');
        }
        $profile['ttl'] = self::ttl(wp_remote_retrieve_header($response, 'cache-control'));
        return $profile;
    }

    private static function failed($status, $code, $content) {
        return ['error' => self::failure($status, $code, $content), 'ttl' => self::FAILURE_TTL];
    }

    // An IP-literal host needs no pinning; IPv6 addresses go in brackets, as cURL expects.
    private static function pinned_resolve($url, $address) {
        if (!preg_match('~^https://([^/?#:\[]+)(?::(\d+))?~', $url, $m) || filter_var($m[1], FILTER_VALIDATE_IP)) {
            return [];
        }
        $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 443;
        $address = strpos($address, ':') !== false ? "[$address]" : $address;
        return ["{$m[1]}:$port:$address"];
    }

    private static function ttl($cache_control) {
        $value = strtolower(is_array($cache_control) ? implode(',', $cache_control) : (string) $cache_control);
        // A profile served as uncacheable breaks the hosting rules; it still gets the minimum lifetime.
        if (preg_match('/(?:^|,)\s*(?:no-store|no-cache|private)\b/', $value)) {
            return self::MIN_TTL;
        }
        if (preg_match('/(?:^|,)\s*max-age\s*=\s*"?(\d+)/', $value, $m)) {
            return max(self::MIN_TTL, min(self::MAX_TTL, (int) $m[1]));
        }
        return self::DEFAULT_TTL;
    }

    // Our side of the intersection is what the store's own profile declares.
    private static function our_capabilities() {
        $ours = [];
        $profile = geoguru_ucp_profile('');
        foreach ($profile['ucp']['capabilities'] as $name => $entries) {
            foreach ($entries as $entry) {
                $ours[$name][] = $entry['version'];
            }
        }
        return $ours;
    }

    // Only the versions both sides share are kept, so the cache never holds the fetched document.
    private static function read_profile($body) {
        $doc = json_decode($body);
        if (!is_object($doc) || !isset($doc->ucp) || !is_object($doc->ucp) || !isset($doc->ucp->version) || !is_string($doc->ucp->version)) {
            return null;
        }
        $ucp = $doc->ucp;
        // Another protocol version is refused as such, whatever shape that version's profile has.
        if ($ucp->version !== GEOGURU_UCP_VERSION) {
            return ['version' => preg_match(self::VERSION_PATTERN, $ucp->version) ? $ucp->version : '', 'capabilities' => []];
        }
        if (!isset($ucp->services) || !is_object($ucp->services) || !isset($ucp->payment_handlers) || !is_object($ucp->payment_handlers)) {
            return null;
        }
        $declared = property_exists($ucp, 'capabilities') ? $ucp->capabilities : new stdClass();
        if (!is_object($declared)) {
            return null;
        }
        $ours = self::our_capabilities();
        $shared = [];
        foreach (get_object_vars($declared) as $name => $entries) {
            if (!is_array($entries)) {
                return null;
            }
            $name = (string) $name;
            foreach ($entries as $entry) {
                if (!is_object($entry) || !isset($entry->version) || !is_string($entry->version) || !preg_match(self::VERSION_PATTERN, $entry->version)) {
                    return null;
                }
                if (isset($ours[$name]) && in_array($entry->version, $ours[$name], true)
                    && self::schema_bound($name, $entry->schema ?? null)
                    && !(isset($shared[$name]) && in_array($entry->version, $shared[$name], true))) {
                    $shared[$name][] = $entry->version;
                }
            }
        }
        return ['version' => $ucp->version, 'capabilities' => $shared];
    }

    // A capability counts only when its schema is served by the owner of its namespace.
    private static function schema_bound($name, $schema) {
        if (!is_string($schema) || !preg_match('~^https://([a-z0-9.\-]+)(?::\d{1,5})?(?:[/?#]|\z)~i', $schema, $m)) {
            return false;
        }
        $host = rtrim(strtolower($m[1]), '.');
        if (strpos($host, '.') === false || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        $authority = implode('.', array_reverse(explode('.', $host)));
        return $name === $authority || strpos($name, "$authority.") === 0;
    }

    private static function profile_url($header_value) {
        if (strlen($header_value) > self::MAX_HEADER) {
            return null;
        }
        $members = self::parse_dictionary($header_value);
        if ($members === null || !isset($members['profile']) || $members['profile'][0] !== 'string') {
            return null;
        }
        // Plain URL characters only, and no userinfo, so every URL parser reads the same host.
        $pattern = '~^https://([a-z0-9.\-_]+|\[[0-9a-f:.]+\])((?::\d{1,5})?(?:[/?#][a-z0-9\-._\~:/?#\[\]@!$&\'()*+,;=%]*)?)\z~i';
        if (!preg_match($pattern, $members['profile'][1], $m)) {
            return null;
        }
        // A fragment never reaches the server, so it must not split the cache.
        $rest = strpos($m[2], '#') === false ? $m[2] : substr($m[2], 0, strpos($m[2], '#'));
        return 'https://' . strtolower($m[1]) . $rest;
    }

    private static function url_host($url) {
        return preg_match('~^https://([^/?#:\[]+|\[[^\]]+\])~', $url, $m) ? $m[1] : '';
    }

    // One special-use address is enough to refuse, since the fetch may connect to any of them.
    private static function public_addresses($host) {
        if ($host === '') {
            return null;
        }
        if ($host[0] === '[') {
            $addresses = filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? [substr($host, 1, -1)] : [];
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $addresses = [$host];
        } else {
            $addresses = geoguru_ucp_resolve_host($host);
            if ($addresses === null) {
                self::warn_resolver_unavailable();
                return null;
            }
        }
        if (!is_array($addresses) || !$addresses) {
            return null;
        }
        foreach ($addresses as $address) {
            if (self::is_special_address((string) $address)) {
                return null;
            }
        }
        return array_values($addresses);
    }

    // Once an hour at most, since every new agent would otherwise repeat it.
    private static function warn_resolver_unavailable() {
        if (get_transient(self::RESOLVER_WARNED_KEY) || !class_exists('GeoGuru_Logger')) {
            return;
        }
        set_transient(self::RESOLVER_WARNED_KEY, 1, 3600);
        GeoGuru_Logger::get_instance()->warning('Agent profile lookups failed: the DNS resolver is unavailable');
    }

    private static function is_special_address($address) {
        if (!filter_var($address, FILTER_VALIDATE_IP)) {
            return true;
        }
        $binary = inet_pton($address);
        if (strlen($binary) === 4) {
            return self::in_ranges($binary, self::SPECIAL_IPV4);
        }
        // NAT64 and 6to4 addresses carry an IPv4 address, which decides.
        if (self::in_range($binary, '64:ff9b::/96')) {
            return self::is_special_address(inet_ntop(substr($binary, 12, 4)));
        }
        if (self::in_range($binary, '2002::/16')) {
            return self::is_special_address(inet_ntop(substr($binary, 2, 4)));
        }
        return self::in_ranges($binary, self::SPECIAL_IPV6);
    }

    private static function in_ranges($binary, $ranges) {
        foreach ($ranges as $range) {
            if (self::in_range($binary, $range)) {
                return true;
            }
        }
        return false;
    }

    private static function in_range($binary, $range) {
        list($prefix, $bits) = explode('/', $range);
        $network = inet_pton($prefix);
        if (strlen($network) !== strlen($binary)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        if (substr($binary, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $rest = (int) $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;
        return (ord($binary[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    // The header is an RFC 8941 dictionary, so other members and parameters must not break it.
    private static function parse_dictionary($input) {
        $members = [];
        $at = strspn($input, ' ');
        $end = strlen(rtrim($input, ' '));
        $input = substr($input, 0, $end);
        while ($at < $end) {
            if (!preg_match('/\G[a-z*][a-z0-9_.*-]*/', $input, $m, 0, $at)) {
                return null;
            }
            $name = $m[0];
            $at += strlen($name);
            $value = ['boolean', true];
            if ($at < $end && $input[$at] === '=') {
                $at++;
                $value = ($at < $end && $input[$at] === '(') ? self::parse_inner_list($input, $at) : self::parse_bare_item($input, $at);
                if ($value === null) {
                    return null;
                }
            }
            if (!self::skip_parameters($input, $at)) {
                return null;
            }
            $members[$name] = $value;
            $at += strspn($input, " \t", $at);
            if ($at >= $end) {
                return $members;
            }
            if ($input[$at] !== ',') {
                return null;
            }
            $at++;
            $at += strspn($input, " \t", $at);
            if ($at >= $end) {
                return null;
            }
        }
        return $members;
    }

    private static function parse_inner_list($input, &$at) {
        $at++;
        $items = [];
        while ($at < strlen($input)) {
            $at += strspn($input, ' ', $at);
            if ($at < strlen($input) && $input[$at] === ')') {
                $at++;
                return ['inner_list', $items];
            }
            $item = self::parse_bare_item($input, $at);
            if ($item === null || !self::skip_parameters($input, $at)) {
                return null;
            }
            $items[] = $item;
            if ($at >= strlen($input) || ($input[$at] !== ' ' && $input[$at] !== ')')) {
                return null;
            }
        }
        return null;
    }

    private static function skip_parameters($input, &$at) {
        while ($at < strlen($input) && $input[$at] === ';') {
            $at++;
            $at += strspn($input, ' ', $at);
            if (!preg_match('/\G[a-z*][a-z0-9_.*-]*/', $input, $m, 0, $at)) {
                return false;
            }
            $at += strlen($m[0]);
            if ($at < strlen($input) && $input[$at] === '=') {
                $at++;
                if (self::parse_bare_item($input, $at) === null) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function parse_bare_item($input, &$at) {
        $patterns = [
            'string' => '/\G"((?:[\x20\x21\x23-\x5b\x5d-\x7e]|\\\\["\\\\])*)"/',
            'number' => '/\G-?(?:\d{1,12}\.\d{1,3}|\d{1,15})(?![\d.])/',
            'token' => '/\G[A-Za-z*][!#$%&\'*+\-.^_`|~0-9A-Za-z:\/]*/',
            'binary' => '/\G:[A-Za-z0-9+\/=]*:/',
            'boolean' => '/\G\?[01]/',
        ];
        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $input, $m, 0, $at)) {
                $at += strlen($m[0]);
                $value = $type === 'string' ? preg_replace('/\\\\(["\\\\])/', '$1', $m[1]) : $m[0];
                return [$type, $value];
            }
        }
        return null;
    }
}
