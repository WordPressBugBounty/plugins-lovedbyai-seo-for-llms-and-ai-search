<?php

// Maps Store API products to UCP catalog products; it calls no WordPress function, so it can be tested alone.

defined('ABSPATH') || exit;

if (!defined('GEOGURU_UCP_VERSION')) {
    define('GEOGURU_UCP_VERSION', '2026-08-25');
}

if (!function_exists('geoguru_ucp_profile')) {
    function geoguru_ucp_profile($endpoint)
    {
        $base = 'https://ucp.dev/' . GEOGURU_UCP_VERSION;
        return [
            'ucp' => [
                'version' => GEOGURU_UCP_VERSION,
                'services' => [
                    'dev.ucp.shopping' => [
                        [
                            'version' => GEOGURU_UCP_VERSION,
                            'spec' => "$base/specification/overview",
                            'transport' => 'rest',
                            'schema' => "$base/services/shopping/rest.openapi.json",
                            'endpoint' => (string) $endpoint,
                        ]
                    ]
                ],
                'capabilities' => [
                    'dev.ucp.shopping.catalog.search' => [
                        [
                            'version' => GEOGURU_UCP_VERSION,
                            'spec' => "$base/specification/shopping/catalog/search",
                            'schema' => "$base/schemas/shopping/catalog_search.json",
                        ]
                    ],
                    'dev.ucp.shopping.catalog.lookup' => [
                        [
                            'version' => GEOGURU_UCP_VERSION,
                            'spec' => "$base/specification/shopping/catalog/lookup",
                            'schema' => "$base/schemas/shopping/catalog_lookup.json",
                        ]
                    ],
                ],
                'payment_handlers' => new stdClass(),
            ]
        ];
    }
}

// A response names only the negotiated capabilities its own operation uses.
if (!function_exists('geoguru_ucp_envelope')) {
    function geoguru_ucp_envelope($capability, $negotiated = null)
    {
        if ($negotiated === null) {
            $capabilities = [$capability => [['version' => GEOGURU_UCP_VERSION]]];
        } else {
            $capabilities = isset($negotiated[$capability]) ? [$capability => $negotiated[$capability]] : new stdClass();
        }
        return ['version' => GEOGURU_UCP_VERSION, 'capabilities' => $capabilities];
    }
}

if (!function_exists('geoguru_ucp_encode_cursor')) {
    function geoguru_ucp_encode_cursor($page)
    {
        return base64_encode(json_encode(['p' => (int) $page]));
    }
}

if (!function_exists('geoguru_ucp_decode_cursor')) {
    function geoguru_ucp_decode_cursor($cursor)
    {
        if (!is_string($cursor) || $cursor === '') {
            return null;
        }
        $json = base64_decode($cursor, true);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data) || !isset($data['p']) || !is_int($data['p']) || $data['p'] < 1) {
            return null;
        }
        return $data['p'];
    }
}

if (!function_exists('geoguru_ucp_search_words')) {
    function geoguru_ucp_search_words($query)
    {
        $query = function_exists('mb_strtolower') ? mb_strtolower((string) $query, 'UTF-8') : strtolower((string) $query);
        $words = [];
        foreach (preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $length = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
            if ($length >= 2 && !in_array($word, $words, true)) {
                $words[] = $word;
            }
        }
        return array_slice($words, 0, 5);
    }
}

if (!function_exists('geoguru_ucp_rank_word_hits')) {
    // Word searches match inside words ('inch' in 'winch'), so titles naming more query words rank first.
    function geoguru_ucp_rank_word_hits($ids_per_word, $titles = [], $words = [])
    {
        $counts = [];
        foreach ($ids_per_word as $ids) {
            foreach (array_unique($ids) as $id) {
                $counts[$id] = isset($counts[$id]) ? $counts[$id] + 1 : 1;
            }
        }
        $named = [];
        foreach (array_keys($counts) as $id) {
            $title = geoguru_ucp_decode_text(isset($titles[$id]) ? $titles[$id] : '');
            $title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
            $named[$id] = 0;
            foreach ($words as $word) {
                $named[$id] += preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u', $title);
            }
        }
        $order = array_keys($counts);
        $position = array_flip($order);
        usort($order, function ($a, $b) use ($counts, $named, $position) {
            if ($named[$a] !== $named[$b]) {
                return $named[$b] - $named[$a];
            }
            if ($counts[$a] !== $counts[$b]) {
                return $counts[$b] - $counts[$a];
            }
            return $position[$a] - $position[$b];
        });
        return $order;
    }
}

if (!function_exists('geoguru_ucp_decode_text')) {
    function geoguru_ucp_decode_text($value)
    {
        return html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('geoguru_ucp_plain_text')) {
    function geoguru_ucp_plain_text($html)
    {
        $text = geoguru_ucp_decode_text(preg_replace('/<[^>]+>/', ' ', (string) $html));
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}

if (!function_exists('geoguru_ucp_iso_exponent')) {
    function geoguru_ucp_iso_exponent(string $currency): int
    {
        $currency = strtoupper($currency);
        if (in_array($currency, ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true)) {
            return 0;
        }
        if (in_array($currency, ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'], true)) {
            return 3;
        }
        return 2;
    }
}

// The Store API counts in the store's display decimals; UCP counts in the currency's ISO minor unit.
if (!function_exists('geoguru_ucp_money')) {
    function geoguru_ucp_money($store_units, $currency, $store_minor_unit)
    {
        $currency = (string) $currency;
        $max = 9007199254740991;
        $amount = max(0, (int) $store_units);
        $shift = geoguru_ucp_iso_exponent($currency) - (int) $store_minor_unit;
        if ($shift > 0) {
            $factor = (int) pow(10, $shift);
            $amount = $amount > intdiv($max, $factor) ? $max : $amount * $factor;
        } elseif ($shift < 0) {
            $divisor = (int) pow(10, -$shift);
            $amount = intdiv($amount, $divisor) + (($amount % $divisor) * 2 >= $divisor ? 1 : 0);
        }
        return ['amount' => min($amount, $max), 'currency' => $currency];
    }
}

// The reverse, for the Store API's price filters. Rounding keeps exactly the prices inside the range.
if (!function_exists('geoguru_ucp_store_amount')) {
    function geoguru_ucp_store_amount($amount, $currency, $store_minor_unit, $round_up)
    {
        $amount = (int) $amount;
        $shift = (int) $store_minor_unit - geoguru_ucp_iso_exponent((string) $currency);
        if ($shift > 0) {
            $factor = (int) pow(10, $shift);
            return (string) (min($amount, intdiv(PHP_INT_MAX, $factor)) * $factor);
        }
        $divisor = (int) pow(10, -$shift);
        $whole = intdiv($amount, $divisor);
        if ($round_up && $whole * $divisor < $amount) {
            $whole++;
        }
        return (string) $whole;
    }
}

if (!function_exists('geoguru_ucp_availability')) {
    function geoguru_ucp_availability($item)
    {
        $backorder = !empty($item['is_on_backorder']);
        $in_stock = !empty($item['is_in_stock']);
        return [
            'available' => !empty($item['is_purchasable']) && ($in_stock || $backorder),
            'status' => $backorder ? 'backorder' : ($in_stock ? 'in_stock' : 'out_of_stock'),
        ];
    }
}

// The Store API shows a missing price as 0, so an item that cannot be bought at 0 has no real price.
if (!function_exists('geoguru_ucp_unpriced')) {
    function geoguru_ucp_unpriced($item)
    {
        $price = (string) ($item['prices']['price'] ?? '');
        return empty($item['is_purchasable']) && ($price === '0' || $price === '');
    }
}

// The header is echoed back, so only plain token characters pass and the length is capped.
if (!function_exists('geoguru_ucp_request_id')) {
    function geoguru_ucp_request_id($value)
    {
        if (!is_string($value)) {
            return '';
        }
        return substr((string) preg_replace('/[^A-Za-z0-9._:-]/', '', $value), 0, 128);
    }
}

// One IPv6 client usually holds a whole /64, so the rate limit counts the /64.
if (!function_exists('geoguru_ucp_rate_bucket')) {
    function geoguru_ucp_rate_bucket($ip)
    {
        $ip = (string) $ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }
        $binary = inet_pton($ip);
        if (substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return inet_ntop(substr($binary, 12));
        }
        return inet_ntop(substr($binary, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}

// A category filter, resolved to term ids, matches any category the product is in.
if (!function_exists('geoguru_ucp_in_category_ids')) {
    function geoguru_ucp_in_category_ids($item, $ids)
    {
        foreach (isset($item['categories']) && is_array($item['categories']) ? $item['categories'] : [] as $category) {
            if (in_array((int) ($category['id'] ?? 0), $ids, true)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('geoguru_ucp_map_variants')) {
    function geoguru_ucp_map_variants($product, $variations, $currency, $minor, $barcodes)
    {
        $title = geoguru_ucp_decode_text($product['name'] ?? '');
        $entries = [];
        foreach (isset($product['variations']) && is_array($product['variations']) ? $product['variations'] : [] as $entry) {
            if (isset($entry['id'])) {
                $entries[(string) $entry['id']] = isset($entry['attributes']) && is_array($entry['attributes']) ? $entry['attributes'] : [];
            }
        }
        // Variations of a global attribute carry the term slug; shoppers see the term name.
        $names = [];
        $terms = [];
        foreach (isset($product['attributes']) && is_array($product['attributes']) ? $product['attributes'] : [] as $attribute) {
            if (!empty($attribute['has_variations'])) {
                $names[] = (string) $attribute['name'];
                foreach (isset($attribute['terms']) && is_array($attribute['terms']) ? $attribute['terms'] : [] as $term) {
                    $terms[(string) $attribute['name']][(string) ($term['slug'] ?? $term['name'])] = (string) $term['name'];
                }
            }
        }

        $variants = [];
        if (isset($product['type']) && $product['type'] === 'variable') {
            foreach ($variations as $variation) {
                if (geoguru_ucp_unpriced($variation)) {
                    continue;
                }
                $id = (string) $variation['id'];
                $values = [];
                foreach ($entries[$id] ?? [] as $attr) {
                    $name = (string) $attr['name'];
                    $raw = (string) $attr['value'];
                    if ($raw !== '') {
                        $values[$name] = geoguru_ucp_decode_text($terms[$name][$raw] ?? $raw);
                    }
                }
                $options = [];
                foreach ($names as $name) {
                    if (isset($values[$name])) {
                        $options[] = ['name' => geoguru_ucp_decode_text($name), 'label' => $values[$name]];
                        unset($values[$name]);
                    }
                }
                foreach ($values as $name => $label) {
                    $options[] = ['name' => geoguru_ucp_decode_text($name), 'label' => $label];
                }
                $labels = [];
                foreach ($options as $option) {
                    $labels[] = $option['label'];
                }
                $summary = geoguru_ucp_plain_text($variation['variation'] ?? '');
                $prices = isset($variation['prices']) && is_array($variation['prices']) ? $variation['prices'] : [];
                $variation_currency = !empty($prices['currency_code']) ? $prices['currency_code'] : $currency;
                $variation_minor = $prices['currency_minor_unit'] ?? $minor;
                $variant = [
                    'id' => $id,
                    'sku' => (string) ($variation['sku'] ?? ''),
                    'title' => $labels ? implode(' / ', $labels) : ($summary !== '' ? $summary : $title),
                    'description' => ['plain' => $summary],
                    'price' => geoguru_ucp_money($prices['price'] ?? 0, $variation_currency, $variation_minor),
                ];
                if (!empty($variation['on_sale']) && isset($prices['regular_price'])) {
                    $variant['list_price'] = geoguru_ucp_money($prices['regular_price'], $variation_currency, $variation_minor);
                }
                $variant['availability'] = geoguru_ucp_availability($variation);
                $variant['options'] = $options;
                $variants[] = $variant;
            }
            usort($variants, function ($a, $b) {
                if ($a['price']['amount'] !== $b['price']['amount']) {
                    return $a['price']['amount'] < $b['price']['amount'] ? -1 : 1;
                }
                return (int) $a['id'] - (int) $b['id'];
            });
        } else {
            if (geoguru_ucp_unpriced($product)) {
                return [];
            }
            $variant = [
                'id' => (string) $product['id'],
                'sku' => (string) ($product['sku'] ?? ''),
                'title' => $title,
                'description' => geoguru_ucp_product_description($product, false),
                'price' => geoguru_ucp_money($product['prices']['price'] ?? 0, $currency, $minor),
            ];
            if (!empty($product['on_sale']) && isset($product['prices']['regular_price'])) {
                $variant['list_price'] = geoguru_ucp_money($product['prices']['regular_price'], $currency, $minor);
            }
            $variant['availability'] = geoguru_ucp_availability($product);
            $variants[] = $variant;
        }

        foreach ($variants as $i => $variant) {
            $gtin = (string) ($barcodes[(int) $variant['id']] ?? '');
            if ($gtin !== '') {
                $variants[$i]['barcodes'] = [['type' => 'GTIN', 'value' => $gtin]];
            }
        }
        return $variants;
    }
}

if (!function_exists('geoguru_ucp_product_description')) {
    function geoguru_ucp_product_description($product, $full)
    {
        $short = (string) ($product['short_description'] ?? '');
        $long = (string) ($product['description'] ?? '');
        $plain = geoguru_ucp_plain_text($short);
        if ($plain === '') {
            $plain = geoguru_ucp_plain_text($long);
        }
        $description = ['plain' => $plain];
        if ($full && $long !== '') {
            $description['html'] = $long;
        }
        return $description;
    }
}

// The Store API passes stored URLs through as they are, which can hold characters a URI may not (a "™"
// in an upload's file name, a space). Each such byte is percent-encoded; existing escapes are kept.
if (!function_exists('geoguru_ucp_uri')) {
    function geoguru_ucp_uri($url)
    {
        return (string) preg_replace_callback('/[^\x21-\x7E]|["<>\\\\^`{|}]/', function ($m) {
            return rawurlencode($m[0]);
        }, (string) $url);
    }
}

if (!function_exists('geoguru_ucp_map_product')) {
    function geoguru_ucp_map_product($product, $variations, $full = false, $barcodes = [])
    {
        if (!empty($product['is_password_protected']) || (isset($product['type']) && $product['type'] === 'grouped')) {
            return null;
        }
        $prices = isset($product['prices']) && is_array($product['prices']) ? $product['prices'] : [];
        $currency = (string) ($prices['currency_code'] ?? '');
        if ($currency === '') {
            return null;
        }
        $minor = (int) ($prices['currency_minor_unit'] ?? geoguru_ucp_iso_exponent($currency));
        $variants = geoguru_ucp_map_variants($product, $variations, $currency, $minor, $barcodes);
        if (!$variants) {
            return null;
        }
        $price = $prices['price'] ?? 0;
        $range = isset($prices['price_range']) && is_array($prices['price_range']) ? $prices['price_range'] : null;

        $mapped = [
            'id' => (string) $product['id'],
            'handle' => (string) ($product['slug'] ?? ''),
            'title' => geoguru_ucp_decode_text($product['name'] ?? ''),
            'description' => geoguru_ucp_product_description($product, $full),
            'url' => geoguru_ucp_uri($product['permalink'] ?? ''),
            'price_range' => [
                'min' => geoguru_ucp_money($range ? $range['min_amount'] : $price, $currency, $minor),
                'max' => geoguru_ucp_money($range ? $range['max_amount'] : $price, $currency, $minor),
            ],
        ];

        $lists = [];
        foreach ($variants as $variant) {
            if (isset($variant['list_price'])) {
                $lists[] = $variant['list_price'];
            }
        }
        if ($lists) {
            $mapped['list_price_range'] = geoguru_ucp_money_range($lists);
        }

        $media = [];
        foreach (isset($product['images']) && is_array($product['images']) ? $product['images'] : [] as $image) {
            $media[] = ['type' => 'image', 'url' => geoguru_ucp_uri($image['src']), 'alt_text' => geoguru_ucp_decode_text($image['alt'] ?? '')];
        }
        if ($media) {
            $mapped['media'] = $media;
        }

        $categories = [];
        foreach (isset($product['categories']) && is_array($product['categories']) ? $product['categories'] : [] as $category) {
            $categories[] = ['value' => geoguru_ucp_decode_text($category['name']), 'taxonomy' => 'merchant'];
        }
        if ($categories) {
            $mapped['categories'] = $categories;
        }

        $options = [];
        foreach (isset($product['attributes']) && is_array($product['attributes']) ? $product['attributes'] : [] as $attribute) {
            if (empty($attribute['has_variations'])) {
                continue;
            }
            $values = [];
            foreach (isset($attribute['terms']) && is_array($attribute['terms']) ? $attribute['terms'] : [] as $term) {
                $values[] = ['label' => geoguru_ucp_decode_text($term['name'])];
            }
            if ($values) {
                $options[] = ['name' => geoguru_ucp_decode_text($attribute['name']), 'values' => $values];
            }
        }
        if ($options) {
            $mapped['options'] = $options;
        }

        if (!empty($product['review_count']) && (int) $product['review_count'] > 0) {
            $mapped['rating'] = ['value' => (float) $product['average_rating'], 'scale_max' => 5, 'count' => (int) $product['review_count']];
        }
        if (!empty($product['brands'][0]['name'])) {
            $mapped['metadata'] = ['brand' => geoguru_ucp_decode_text($product['brands'][0]['name'])];
        }

        $mapped['variants'] = $variants;
        return $mapped;
    }
}

// The cheapest available variant of a price-ordered list, else the cheapest.
if (!function_exists('geoguru_ucp_featured_index')) {
    function geoguru_ucp_featured_index($variants)
    {
        foreach ($variants as $index => $variant) {
            if (!empty($variant['availability']['available'])) {
                return $index;
            }
        }
        return 0;
    }
}

// A variant with no value for an option is sold with any of its values.
if (!function_exists('geoguru_ucp_variant_fits')) {
    function geoguru_ucp_variant_fits($variant, $selections)
    {
        $own = [];
        foreach ($variant['options'] ?? [] as $option) {
            $own[$option['name']] = $option['label'];
        }
        foreach ($selections as $selection) {
            if (isset($own[$selection['name']]) && $own[$selection['name']] !== $selection['label']) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('geoguru_ucp_selection_resolves')) {
    function geoguru_ucp_selection_resolves($variants, $selections)
    {
        foreach ($variants as $variant) {
            if (geoguru_ucp_variant_fits($variant, $selections)) {
                return true;
            }
        }
        return false;
    }
}

// Drops selections until one resolves: unlisted names first (last asked first), then preferences from the end.
if (!function_exists('geoguru_ucp_relax_selection')) {
    function geoguru_ucp_relax_selection($variants, $axes, $selected, $preferences)
    {
        // A choice the product does not offer goes first, so it cannot take valid choices with it.
        $offered = [];
        foreach ($selected as $selection) {
            if (isset($axes[$selection['name']][$selection['label']])) {
                $offered[] = $selection;
            }
        }
        $selected = $offered;
        $rank = [];
        foreach ($selected as $index => $selection) {
            $position = array_search($selection['name'], $preferences, true);
            $rank[$index] = $position === false ? count($preferences) + $index : $position;
        }
        while ($selected && !geoguru_ucp_selection_resolves($variants, $selected)) {
            $drop = null;
            foreach (array_keys($selected) as $index) {
                if ($drop === null || $rank[$index] > $rank[$drop]) {
                    $drop = $index;
                }
            }
            unset($selected[$drop]);
        }
        return array_values($selected);
    }
}

// The featured variant, taken from those inside the price range when there are any.
if (!function_exists('geoguru_ucp_pick_featured')) {
    function geoguru_ucp_pick_featured($variants, $range)
    {
        $inside = geoguru_ucp_within_price(['variants' => $variants], $range);
        $pool = $inside === null ? $variants : $inside['variants'];
        return $pool[geoguru_ucp_featured_index($pool)];
    }
}

// Option selection for get_product: the matching variants, featured first, and availability per option value.
if (!function_exists('geoguru_ucp_select_variants')) {
    function geoguru_ucp_select_variants($product, $variant_id, $selected, $preferences, $range = null)
    {
        $axes = [];
        foreach ($product['options'] ?? [] as $option) {
            foreach ($option['values'] as $value) {
                $axes[$option['name']][$value['label']] = true;
            }
        }
        $variants = $product['variants'];
        $featured = null;
        foreach ($variants as $variant) {
            if ($variant_id !== null && $variant['id'] === (string) $variant_id) {
                $featured = $variant;
            }
        }
        $effective = [];
        if ($featured === null && $selected && $axes) {
            $effective = geoguru_ucp_relax_selection($variants, $axes, array_values($selected), array_values($preferences));
        } else {
            $featured = $featured ?? geoguru_ucp_pick_featured($variants, $range);
            foreach ($featured['options'] ?? [] as $option) {
                if (isset($axes[$option['name']])) {
                    $effective[] = ['name' => $option['name'], 'label' => $option['label']];
                }
            }
        }
        $matching = [];
        foreach ($variants as $variant) {
            if (geoguru_ucp_variant_fits($variant, $effective)) {
                $matching[] = $variant;
            }
        }
        if ($featured === null) {
            $featured = geoguru_ucp_pick_featured($matching, $range);
        }
        $ordered = [$featured];
        foreach ($matching as $variant) {
            if ($variant['id'] !== $featured['id']) {
                $ordered[] = $variant;
            }
        }
        $product['variants'] = $ordered;
        $product = geoguru_ucp_with_ranges($product);
        if (!$axes) {
            return $product;
        }

        foreach ($product['options'] as $o => $option) {
            $others = [];
            foreach ($effective as $selection) {
                if ($selection['name'] !== $option['name']) {
                    $others[] = $selection;
                }
            }
            foreach ($option['values'] as $v => $value) {
                $test = array_merge($others, [['name' => $option['name'], 'label' => $value['label']]]);
                $exists = false;
                $available = false;
                foreach ($variants as $variant) {
                    if (geoguru_ucp_variant_fits($variant, $test)) {
                        $exists = true;
                        $available = $available || !empty($variant['availability']['available']);
                    }
                }
                $product['options'][$o]['values'][$v]['available'] = $available;
                $product['options'][$o]['values'][$v]['exists'] = $exists;
            }
        }
        $product['selected'] = $effective;
        return $product;
    }
}

// Keeps the variants priced inside the range (UCP minor units); null when none is.
if (!function_exists('geoguru_ucp_within_price')) {
    function geoguru_ucp_within_price($product, $range)
    {
        if ($range === null) {
            return $product;
        }
        $kept = [];
        foreach ($product['variants'] as $variant) {
            $amount = $variant['price']['amount'];
            if ((!isset($range['min']) || $amount >= $range['min']) && (!isset($range['max']) || $amount <= $range['max'])) {
                $kept[] = $variant;
            }
        }
        if (!$kept) {
            return null;
        }
        $product['variants'] = $kept;
        return geoguru_ucp_with_ranges($product);
    }
}

// Once variants are narrowed, the product's ranges cover only the variants left.
if (!function_exists('geoguru_ucp_with_ranges')) {
    function geoguru_ucp_with_ranges($product)
    {
        if (!isset($product['price_range']) || empty($product['variants'])) {
            return $product;
        }
        $prices = [];
        $lists = [];
        foreach ($product['variants'] as $variant) {
            $prices[] = $variant['price'];
            if (isset($variant['list_price'])) {
                $lists[] = $variant['list_price'];
            }
        }
        $product['price_range'] = geoguru_ucp_money_range($prices);
        if (isset($product['list_price_range'])) {
            if ($lists) {
                $product['list_price_range'] = geoguru_ucp_money_range($lists);
            } else {
                unset($product['list_price_range']);
            }
        }
        return $product;
    }
}

if (!function_exists('geoguru_ucp_money_range')) {
    function geoguru_ucp_money_range($amounts)
    {
        $min = $amounts[0];
        $max = $amounts[0];
        foreach ($amounts as $money) {
            if ($money['amount'] < $min['amount']) {
                $min = $money;
            }
            if ($money['amount'] > $max['amount']) {
                $max = $money;
            }
        }
        return ['min' => $min, 'max' => $max];
    }
}
