<?php

// Answers through the store's own Store API, so its visibility and price rules apply.

if (!defined('ABSPATH')) {
    exit;
}

class GeoGuru_UcpEndpoints
{
    const SEARCH_CAPABILITY = 'dev.ucp.shopping.catalog.search';
    const LOOKUP_CAPABILITY = 'dev.ucp.shopping.catalog.lookup';
    const DEFAULT_LIMIT = 10;
    const MAX_LIMIT = 25;
    const MAX_LOOKUP_IDS = 50;
    const MAX_QUERY_CHARS = 256;
    const MAX_CATEGORIES = 20;
    const MAX_OPTIONS = 20;
    const MAX_PAGE = 10000;
    const RATE_LIMIT = 60;
    const RATE_WINDOW = 60;
    const IDS_PER_UNIT = 25;
    const WORD_HITS = 50;
    const BLOCKED_TTL = 600;

    private static $instance = null;
    private $work = 0;
    private $negotiated = null;

    private function __construct()
    {
    }

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register_routes()
    {
        foreach (['search', 'lookup', 'product'] as $name) {
            register_rest_route(GeoGuru_UcpService::REST_NAMESPACE, "/catalog/$name", [
                'methods' => 'POST',
                'callback' => [$this, $name],
                'permission_callback' => '__return_true',
            ]);
        }
        add_filter('rest_pre_serve_request', [$this, 'send_cors_headers'], 20, 3);
    }

    public function send_cors_headers($served, $result, $request)
    {
        if (strpos((string) $request->get_route(), '/' . GeoGuru_UcpService::REST_NAMESPACE . '/') === 0) {
            header_remove('Access-Control-Allow-Credentials');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, UCP-Agent, Request-Id');
        }
        return $served;
    }

    public function search($request)
    {
        return $this->handle($request, 'run_search', self::SEARCH_CAPABILITY);
    }

    public function lookup($request)
    {
        return $this->handle($request, 'run_lookup', self::LOOKUP_CAPABILITY);
    }

    public function product($request)
    {
        return $this->handle($request, 'run_product', self::LOOKUP_CAPABILITY);
    }

    private function store_products($params)
    {
        $request = new WP_REST_Request('GET', '/wc/store/v1/products');
        $request->set_query_params($params);
        $response = rest_do_request($request);
        $status = (int) $response->get_status();
        if (in_array($status, [401, 403, 404], true)) {
            set_transient(GeoGuru_UcpService::STORE_API_BLOCKED, 1, self::BLOCKED_TTL);
        }
        // In-process answers keep the Store API's nested objects, so they are read back as arrays.
        $json = wp_json_encode($response->get_data());
        $data = is_string($json) ? json_decode($json, true) : null;
        if ($status !== 200 || !is_array($data)) {
            throw new RuntimeException('store_api_failed');
        }
        $total = count($data);
        foreach ($response->get_headers() as $name => $value) {
            if (strtolower((string) $name) === 'x-wp-total') {
                $total = (int) $value;
            }
        }
        return ['items' => array_values($data), 'total' => $total];
    }

    private function handle($request, $method, $capability)
    {
        $wait = $this->rate_limit_wait();
        if ($wait > 0) {
            return $this->respond($request, ['code' => 'rate_limited', 'content' => 'Too many requests.'], 429, ['Retry-After' => (string) $wait]);
        }
        if (GeoGuru_UcpService::get_instance()->reason() !== null) {
            return $this->respond($request, ['code' => 'not_found', 'content' => 'No catalog is published here.'], 404);
        }
        $agent = GeoGuru_UcpAgent::negotiate((string) $request->get_header('ucp_agent'), $capability, $this->caller());
        $this->work = $agent['fetched'] ? 1 : 0;
        if (!$agent['ok']) {
            $response = $this->negotiation_failed($request, $agent, $capability);
            $this->charge_work();
            return $response;
        }
        $this->negotiated = $agent['capabilities'];
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }
        try {
            $response = $this->$method($request, $params);
        } catch (InvalidArgumentException $e) {
            $response = $this->respond($request, ['code' => 'invalid_request', 'content' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            if (class_exists('GeoGuru_Logger')) {
                GeoGuru_Logger::get_instance()->error('UCP catalog request failed', ['route' => (string) $request->get_route(), 'error' => $e->getMessage()]);
            }
            $response = $this->respond($request, ['code' => 'internal_error', 'content' => 'The catalog could not be read.'], 500);
        }
        $this->charge_work();
        return $response;
    }

    // A capability mismatch is a UCP answer; the other negotiation failures are transport errors.
    private function negotiation_failed($request, $agent, $capability)
    {
        if ($agent['status'] === 200) {
            $ucp = geoguru_ucp_envelope($capability, []);
            $ucp['status'] = 'error';
            return $this->respond($request, [
                'ucp' => $ucp,
                'messages' => [['type' => 'error', 'code' => $agent['code'], 'content' => $agent['content'], 'severity' => 'unrecoverable']],
                'continue_url' => home_url('/'),
            ]);
        }
        $headers = $agent['retry_after'] !== null ? ['Retry-After' => (string) $agent['retry_after']] : [];
        return $this->respond($request, ['code' => $agent['code'], 'content' => $agent['content'], 'continue_url' => home_url('/')], $agent['status'], $headers);
    }

    // Agent profile fetches, variation fetches, word searches and lookup batches cost units beyond the request itself.
    private function charge_work()
    {
        if ($this->work < 1) {
            return;
        }
        $key = $this->rate_key();
        $state = get_transient($key);
        if (is_array($state) && isset($state['start'], $state['count'])) {
            $state['count'] = (int) $state['count'] + $this->work;
            set_transient($key, $state, max(1, (int) $state['start'] + self::RATE_WINDOW - time()));
        }
    }

    private function respond($request, $body, $status = 200, $headers = [])
    {
        $response = new WP_REST_Response($body, $status);
        $response->header('Access-Control-Allow-Origin', '*');
        $id = geoguru_ucp_request_id($request->get_header('request_id'));
        if ($id !== '') {
            $response->header('Request-Id', $id);
        }
        foreach ($headers as $name => $value) {
            $response->header($name, $value);
        }
        return $response;
    }

    // Seconds to wait when the caller is over the limit, else 0. Behind a proxy every caller shares one bucket.
    private function rate_limit_wait()
    {
        $key = $this->rate_key();
        $now = time();
        $state = get_transient($key);
        if (!is_array($state) || !isset($state['start'], $state['count']) || $now - (int) $state['start'] >= self::RATE_WINDOW) {
            $state = ['start' => $now, 'count' => 0];
        }
        $remaining = max(1, (int) $state['start'] + self::RATE_WINDOW - $now);
        if ((int) $state['count'] >= self::RATE_LIMIT) {
            return $remaining;
        }
        $state['count'] = (int) $state['count'] + 1;
        set_transient($key, $state, $remaining);
        return 0;
    }

    private function rate_key()
    {
        return 'geoguru_ucp_rl_' . md5($this->caller());
    }

    private function caller()
    {
        return geoguru_ucp_rate_bucket(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')));
    }

    private function fail($message)
    {
        throw new InvalidArgumentException($message); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent in a JSON body, not HTML
    }

    // A non-negative whole number given as an int or a digit string, else null.
    private function whole_or_null($value)
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && strlen($value) <= 12) {
            return (int) $value;
        }
        return null;
    }

    private function whole_number($value, $label)
    {
        $whole = $this->whole_or_null($value);
        if ($whole === null) {
            $this->fail("$label must be a non-negative integer.");
        }
        return $whole;
    }

    private function run_search($request, $params)
    {
        $query = $params['query'] ?? null;
        $messages = [];
        $categories = $this->category_filter($params);
        $range = $this->price_filter($params, $messages);
        // With no query, a filter makes the request a browse, even a price filter skipped for its currency.
        $price_given = !empty($params['filters']['price']);
        $browse = $query === null && ($categories !== null || $range !== null || $price_given);
        if (!$browse && (!is_string($query) || trim($query) === '')) {
            $this->fail('query must be a non-empty string, or a filter must apply.');
        }
        if (!$browse && preg_match('//u', $query) !== 1) {
            $this->fail('query must be valid UTF-8.');
        }
        if (!$browse && (strlen($query) > self::MAX_QUERY_CHARS * 4 || preg_match_all('/./su', $query) > self::MAX_QUERY_CHARS)) {
            $this->fail('query must be at most ' . self::MAX_QUERY_CHARS . ' characters.');
        }
        $pagination = $params['pagination'] ?? [];
        if (!is_array($pagination)) {
            $this->fail('pagination must be an object.');
        }
        $limit = self::DEFAULT_LIMIT;
        if (isset($pagination['limit'])) {
            $whole = $this->whole_or_null($pagination['limit']) ?? 0;
            $limit = $whole >= 1 ? min($whole, self::MAX_LIMIT) : self::DEFAULT_LIMIT;
        }
        $page = 1;
        if (array_key_exists('cursor', $pagination)) {
            $page = geoguru_ucp_decode_cursor($pagination['cursor']);
            if ($page === null || $page > self::MAX_PAGE) {
                $this->fail('cursor is not valid.');
            }
        }

        $base = ['catalog_visibility' => $browse ? 'catalog' : 'search'];
        $empty = false;
        if ($categories !== null) {
            $ids = $this->category_ids($categories);
            if ($ids) {
                $base['category'] = implode(',', $ids);
            } else {
                $empty = true;
            }
        }
        if ($range !== null) {
            $currency = $this->store_currency();
            $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : geoguru_ucp_iso_exponent($currency);
            foreach (['min' => 'min_price', 'max' => 'max_price'] as $field => $param) {
                if (isset($range[$field])) {
                    $base[$param] = geoguru_ucp_store_amount($range[$field], $currency, $decimals, $field === 'min');
                }
            }
        }

        $items = [];
        $total = 0;
        if (!$empty) {
            $args = array_merge($base, ['per_page' => $limit, 'page' => $page]);
            if (!$browse) {
                $args['search'] = $query;
            }
            $found = $this->store_products($args);
            $items = $found['items'];
            $total = $found['total'];
            // A browse has no query, so it has no words to fall back on.
            $words = $total === 0 ? geoguru_ucp_search_words($query) : [];
            if (count($words) >= 2) {
                list($items, $total) = $this->search_by_words($base, $words, $page, $limit);
            }
        }

        $has_next = $page * $limit < $total;
        $pagination_out = [];
        if ($has_next) {
            $pagination_out['cursor'] = geoguru_ucp_encode_cursor($page + 1);
        }
        $pagination_out['has_next_page'] = $has_next;
        $pagination_out['total_count'] = $total;
        $products = [];
        foreach ($this->map_items($items, false) as $product) {
            $product = geoguru_ucp_within_price($product, $range);
            if ($product !== null) {
                $products[] = $product;
            }
        }
        $body = [
            'ucp' => geoguru_ucp_envelope(self::SEARCH_CAPABILITY, $this->negotiated),
            'products' => $products,
            'pagination' => $pagination_out,
        ];
        if ($messages) {
            $body['messages'] = $messages;
        }
        return $this->respond($request, $body);
    }

    private function store_currency()
    {
        return function_exists('get_woocommerce_currency') ? strtoupper((string) get_woocommerce_currency()) : '';
    }

    // Prices are not converted, so a filter in another currency is skipped and a message says so.
    private function price_filter($params, &$messages)
    {
        $filters = $params['filters'] ?? [];
        if (!is_array($filters)) {
            $this->fail('filters must be an object.');
        }
        if (!isset($filters['price'])) {
            return null;
        }
        if (!is_array($filters['price'])) {
            $this->fail('filters.price must be an object.');
        }
        $range = [];
        foreach (['min', 'max'] as $field) {
            if (isset($filters['price'][$field])) {
                $range[$field] = $this->whole_number($filters['price'][$field], "filters.price.$field");
            }
        }
        $context = isset($params['context']) && is_array($params['context']) ? $params['context'] : [];
        $wanted = isset($context['currency']) && is_string($context['currency']) ? strtoupper(trim($context['currency'])) : '';
        $store = $this->store_currency();
        if ($range && $wanted !== '' && $store !== '' && $wanted !== $store) {
            $messages[] = [
                'type' => 'info',
                'code' => 'price_filter_ignored',
                'path' => '$.filters.price',
                'content' => "The price filter was not applied: this store prices in $store and does not convert currencies.",
            ];
            return null;
        }
        return $range ?: null;
    }

    // filters.categories as a list of strings, or null when absent or empty.
    private function category_filter($params)
    {
        $filters = $params['filters'] ?? [];
        if (!is_array($filters)) {
            $this->fail('filters must be an object.');
        }
        $categories = $filters['categories'] ?? [];
        if (!is_array($categories) || count(array_filter($categories, 'is_string')) !== count($categories)) {
            $this->fail('filters.categories must be an array of strings.');
        }
        if (count($categories) > self::MAX_CATEGORIES) {
            $this->fail('filters.categories can hold at most ' . self::MAX_CATEGORIES . ' entries.');
        }
        return $categories ? array_values($categories) : null;
    }

    // Term ids of the named categories and their subcategories, so search and lookup match alike.
    private function category_ids($categories)
    {
        $ids = [];
        foreach ($categories as $value) {
            $term = get_term_by('slug', $value, 'product_cat');
            if (!$term) {
                $term = get_term_by('name', $value, 'product_cat');
            }
            if (!$term) {
                continue;
            }
            $children = get_term_children((int) $term->term_id, 'product_cat');
            foreach (array_merge([(int) $term->term_id], is_array($children) ? $children : []) as $id) {
                $ids[(int) $id] = (int) $id;
            }
        }
        return array_values($ids);
    }

    // Takes at most 50 hits per word, which is plenty for a small store's catalog. Each word costs a unit.
    private function search_by_words($base, $words, $page, $limit)
    {
        $by_id = [];
        $hits = [];
        foreach ($words as $word) {
            $this->work++;
            $found = $this->store_products(array_merge($base, ['search' => $word, 'per_page' => self::WORD_HITS, 'page' => 1]));
            $ids = [];
            foreach ($found['items'] as $item) {
                $id = (int) $item['id'];
                $ids[] = $id;
                if (!isset($by_id[$id])) {
                    $by_id[$id] = $item;
                }
            }
            $hits[] = $ids;
        }
        $titles = [];
        foreach ($by_id as $id => $item) {
            $titles[$id] = isset($item['name']) ? $item['name'] : '';
        }
        $order = geoguru_ucp_rank_word_hits($hits, $titles, $words);
        $items = [];
        foreach (array_slice($order, ($page - 1) * $limit, $limit) as $id) {
            $items[] = $by_id[$id];
        }
        return [$items, count($order)];
    }

    private function run_lookup($request, $params)
    {
        $ids = $params['ids'] ?? null;
        if (!is_array($ids) || !$ids) {
            $this->fail('ids must be an array of 1 to ' . self::MAX_LOOKUP_IDS . ' strings.');
        }
        if (count($ids) > self::MAX_LOOKUP_IDS) {
            return $this->respond($request, ['code' => 'request_too_large', 'content' => 'ids can hold at most ' . self::MAX_LOOKUP_IDS . ' identifiers.'], 400);
        }
        foreach ($ids as $id) {
            if (!is_string($id)) {
                $this->fail('ids must be an array of strings.');
            }
        }
        $ids = array_values($ids);
        $messages = [];
        $categories = $this->category_filter($params);
        $range = $this->price_filter($params, $messages);
        // Each id costs a visibility check and up to three items, so a batch is charged by its size.
        $this->work += intdiv(count($ids) + self::IDS_PER_UNIT - 1, self::IDS_PER_UNIT);
        $found = $this->resolve($ids, false, true, $categories, $range);
        $body = ['ucp' => geoguru_ucp_envelope(self::LOOKUP_CAPABILITY, $this->negotiated), 'products' => $found['products']];
        foreach ($found['missing'] as $index) {
            $messages[] = [
                'type' => 'info',
                'code' => 'not_found',
                'path' => "\$.ids[$index]",
                'content' => $ids[$index],
            ];
        }
        if ($messages) {
            $body['messages'] = $messages;
        }
        return $this->respond($request, $body);
    }

    private function run_product($request, $params)
    {
        $id = $params['id'] ?? null;
        if (!is_string($id) || $id === '') {
            $this->fail('id must be a non-empty string.');
        }
        $selected = $this->selections($params['selected'] ?? []);
        $preferences = $params['preferences'] ?? [];
        if (!is_array($preferences) || count($preferences) > self::MAX_OPTIONS || count(array_filter($preferences, 'is_string')) !== count($preferences)) {
            $this->fail('preferences must be an array of at most ' . self::MAX_OPTIONS . ' option names.');
        }
        $messages = [];
        $categories = $this->category_filter($params);
        $range = $this->price_filter($params, $messages);
        $found = $this->resolve([$id], true, false, $categories);
        if (!$found['products'] && !$found['excluded']) {
            return $this->product_error($request, "Product not found: $id", 'unrecoverable');
        }
        $product = $found['products'] ? $found['products'][0] : null;
        // A variant id decides the selection; the request's own selections then do not count.
        $variant_id = $product && ctype_digit($id) && (string) (int) $id !== $product['id'] ? (string) (int) $id : null;
        if ($variant_id !== null && !in_array($variant_id, array_column($product['variants'], 'id'), true)) {
            return $this->product_error($request, "Product not found: $id", 'unrecoverable');
        }
        $product = $product ? geoguru_ucp_within_price(geoguru_ucp_select_variants($product, $variant_id, $selected, $preferences, $range), $range) : null;
        if ($product === null) {
            return $this->product_error($request, "Product $id does not match the filters.", 'recoverable');
        }
        $body = ['ucp' => geoguru_ucp_envelope(self::LOOKUP_CAPABILITY, $this->negotiated), 'product' => $product];
        if ($messages) {
            $body['messages'] = $messages;
        }
        return $this->respond($request, $body);
    }

    private function product_error($request, $content, $severity)
    {
        $ucp = geoguru_ucp_envelope(self::LOOKUP_CAPABILITY, $this->negotiated);
        $ucp['status'] = 'error';
        return $this->respond($request, [
            'ucp' => $ucp,
            'messages' => [
                [
                    'type' => 'error',
                    'code' => 'not_found',
                    'path' => '$.id',
                    'content' => $content,
                    'severity' => $severity,
                ]
            ]
        ]);
    }

    private function selections($selected)
    {
        if (!is_array($selected) || count($selected) > self::MAX_OPTIONS) {
            $this->fail('selected must be an array of at most ' . self::MAX_OPTIONS . ' options.');
        }
        $clean = [];
        $names = [];
        foreach ($selected as $selection) {
            if (!is_array($selection) || !isset($selection['name'], $selection['label']) || !is_string($selection['name']) || !is_string($selection['label']) || in_array($selection['name'], $names, true)) {
                $this->fail('selected must hold objects with a string label and a name used once.');
            }
            $names[] = $selection['name'];
            $clean[] = ['name' => $selection['name'], 'label' => $selection['label']];
        }
        return $clean;
    }

    private function is_hidden($id)
    {
        $product = function_exists('wc_get_product') ? wc_get_product($id) : false;
        return $product && $product->get_catalog_visibility() === 'hidden';
    }

    // Ids resolve to their parent products in request order; `missing` holds positions, `excluded` a filtered-out product.
    private function resolve($ids, $full, $with_inputs, $categories = null, $range = null)
    {
        $numeric = [];
        foreach ($ids as $id) {
            if (ctype_digit($id) && strlen($id) <= 12 && (int) $id > 0 && !$this->is_hidden((int) $id)) {
                $numeric[$id] = (int) $id;
            }
        }
        $products = [];
        $variations = [];
        if ($numeric) {
            $include = array_values($numeric);
            foreach ($this->store_products(['include' => $include, 'per_page' => count($include)])['items'] as $item) {
                $products[(int) $item['id']] = $item;
            }
            foreach ($this->store_products(['type' => 'variation', 'include' => $include, 'per_page' => count($include)])['items'] as $item) {
                $variations[(int) $item['id']] = $item;
            }
            $parents = [];
            foreach ($variations as $item) {
                $parent = (int) $item['parent'];
                if (!isset($products[$parent]) && !$this->is_hidden($parent)) {
                    $parents[$parent] = $parent;
                }
            }
            if ($parents) {
                foreach ($this->store_products(['include' => array_values($parents), 'per_page' => count($parents)])['items'] as $item) {
                    $products[(int) $item['id']] = $item;
                }
            }
        }

        $groups = [];
        $missing = [];
        $seen = [];
        foreach ($ids as $index => $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $n = $numeric[$id] ?? 0;
            if (isset($products[$n])) {
                $parent = $n;
                $kind = 'product';
            } elseif (isset($variations[$n]) && isset($products[(int) $variations[$n]['parent']]) && !$this->is_hidden((int) $variations[$n]['parent'])) {
                $parent = (int) $variations[$n]['parent'];
                $kind = 'variant';
            } else {
                $missing[] = $index;
                continue;
            }
            if (!isset($groups[$parent])) {
                $groups[$parent] = ['index' => [], 'product' => [], 'variant' => []];
            }
            $groups[$parent]['index'][$index] = $id;
            if ($kind === 'product') {
                $groups[$parent]['product'][] = $id;
            } else {
                $groups[$parent]['variant'][$n][] = $id;
            }
        }

        $mapped = [];
        $excluded = false;
        $category_ids = $categories === null ? null : $this->category_ids($categories);
        $kept = [];
        foreach ($groups as $parent => $group) {
            if ($category_ids !== null && !geoguru_ucp_in_category_ids($products[$parent], $category_ids)) {
                $excluded = true;
                continue;
            }
            $kept[$parent] = $products[$parent];
        }
        $by_parent = $this->variations_of($kept);
        foreach (array_intersect_key($groups, $kept) as $parent => $group) {
            // Variations already fetched by id join the parent's first 100, so a late one is not lost.
            $extra = [];
            foreach (array_keys($group['variant']) as $variant_id) {
                $extra[] = $variations[$variant_id];
            }
            $product = $this->map_items([$products[$parent]], $full, $extra, $by_parent);
            if (!$product) {
                $missing = array_merge($missing, array_keys($group['index']));
                continue;
            }
            $product = $product[0];
            if ($with_inputs) {
                // Ids are matched before the price filter, so an id it excludes is left out, not missing.
                $answered = [];
                foreach ($this->add_inputs($product, $group)['variants'] as $variant) {
                    foreach ($variant['inputs'] as $input) {
                        $answered[] = $input['id'];
                    }
                }
                $missing = array_merge($missing, array_keys(array_diff($group['index'], $answered)));
                $product = geoguru_ucp_within_price($product, $range);
                $product = $product === null ? null : $this->add_inputs($product, $group);
                if ($product === null || !$product['variants']) {
                    $excluded = true;
                    continue;
                }
            }
            $mapped[] = $product;
        }
        sort($missing);
        return ['products' => $mapped, 'missing' => $missing, 'excluded' => $excluded];
    }

    // A product id resolves to its featured variant, a variant id to itself. The featured one goes first.
    private function add_inputs($product, $group)
    {
        $featured = $group['product'] ? geoguru_ucp_featured_index($product['variants']) : -1;
        $variants = [];
        foreach ($product['variants'] as $index => $variant) {
            $inputs = [];
            if ($index === $featured) {
                foreach ($group['product'] as $id) {
                    $inputs[] = ['id' => $id, 'match' => 'featured'];
                }
            }
            $asked = $group['variant'][(int) $variant['id']] ?? [];
            foreach ($asked as $id) {
                $inputs[] = ['id' => $id, 'match' => 'exact'];
            }
            if ($inputs) {
                $variant['inputs'] = $inputs;
                if ($index === $featured) {
                    array_unshift($variants, $variant);
                } else {
                    $variants[] = $variant;
                }
            }
        }
        $product['variants'] = $variants;
        return $product;
    }

    // Barcodes are read only when $full is set, since each costs a product load.
    private function map_items($items, $full, $extra = [], $by_parent = null)
    {
        if ($by_parent === null) {
            $by_parent = $this->variations_of($items);
        }
        $mapped = [];
        foreach ($items as $item) {
            $variations = [];
            if (isset($item['type']) && $item['type'] === 'variable') {
                $variations = $by_parent[(int) $item['id']] ?? [];
                $have = [];
                foreach ($variations as $row) {
                    $have[(int) $row['id']] = true;
                }
                foreach ($extra as $row) {
                    if (!isset($have[(int) $row['id']]) && (int) $row['parent'] === (int) $item['id']) {
                        $variations[] = $row;
                    }
                }
            }
            $ids = [];
            foreach ($variations ?: [$item] as $variant) {
                $ids[] = (int) $variant['id'];
            }
            $product = geoguru_ucp_map_product($item, $variations, $full, $full ? $this->barcodes($ids) : []);
            if ($product !== null) {
                $mapped[] = $product;
            }
        }
        return $mapped;
    }

    // The variable products' variations, at most 100 each, in shared queries of 100 that cost a unit each.
    // Pages stop at one per product, the old cost; a product they did not reach gets its own query.
    private function variations_of($items)
    {
        $parents = [];
        foreach ($items as $item) {
            if (isset($item['type']) && $item['type'] === 'variable') {
                $parents[(int) $item['id']] = (int) $item['id'];
            }
        }
        $by_parent = [];
        $complete = false;
        for ($page = 1; $parents && !$complete && $page <= count($parents); $page++) {
            $this->work++;
            $found = $this->store_products(['type' => 'variation', 'parent' => array_values($parents), 'per_page' => 100, 'page' => $page]);
            foreach ($found['items'] as $row) {
                $parent = (int) $row['parent'];
                if (count($by_parent[$parent] ?? []) < 100) {
                    $by_parent[$parent][] = $row;
                }
            }
            $complete = count($found['items']) < 100 || $page * 100 >= $found['total'];
        }
        foreach ($complete ? [] : $parents as $parent) {
            if (!isset($by_parent[$parent])) {
                $this->work++;
                $by_parent[$parent] = $this->store_products(['type' => 'variation', 'parent' => [$parent], 'per_page' => 100])['items'];
            }
        }
        return $by_parent;
    }

    private function barcodes($ids)
    {
        $barcodes = [];
        if (!function_exists('wc_get_product')) {
            return $barcodes;
        }
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if ($product && method_exists($product, 'get_global_unique_id')) {
                $gtin = (string) $product->get_global_unique_id();
                if ($gtin !== '') {
                    $barcodes[$id] = $gtin;
                }
            }
        }
        return $barcodes;
    }
}
