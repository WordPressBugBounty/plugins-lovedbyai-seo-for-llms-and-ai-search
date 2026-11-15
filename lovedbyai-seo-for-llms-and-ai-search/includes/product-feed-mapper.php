<?php

// Turns one product, already read out of the store into a plain array, into one row of the product
// feed that ChatGPT reads (OpenAI's product file schema). Nothing here touches WordPress or
// WooCommerce, so the rules can be tested without either.
//
// The feed is public and is read on white-label sites too: every value comes from the store itself.
// No plugin or vendor name may ever be written into a row.

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('geoguru_product_feed_text')) {
    /** HTML to plain text on one line, cut to $max characters. */
    function geoguru_product_feed_text($html, $max) {
        if (!is_string($html) || $html === '') {
            return '';
        }
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = preg_replace('/\[\/?[a-zA-Z][^\]]*\]/', ' ', (string) $text); // shortcodes
        $text = preg_replace('/<[^>]+>/', ' ', (string) $text);
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $max ? rtrim(mb_substr($text, 0, $max, 'UTF-8')) : $text;
        }
        return strlen($text) > $max ? rtrim(substr($text, 0, $max)) : $text;
    }
}

if (!function_exists('geoguru_product_feed_money')) {
    /** "79.99 USD", or '' when the amount is not a positive number or the currency is not a code. */
    function geoguru_product_feed_money($amount, $currency, $decimals) {
        if (!is_numeric($amount) || (float) $amount <= 0) {
            return '';
        }
        $currency = strtoupper(trim((string) $currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return '';
        }
        $decimals = max(0, min(4, (int) $decimals));
        return number_format((float) $amount, $decimals, '.', '') . ' ' . $currency;
    }
}

if (!function_exists('geoguru_product_feed_gtin')) {
    /** The GTIN when it is 8, 12, 13 or 14 digits with a valid check digit, else ''. */
    function geoguru_product_feed_gtin($value) {
        $digits = preg_replace('/[\s-]/', '', (string) $value);
        if (!preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', (string) $digits)) {
            return '';
        }
        $sum = 0;
        $body = strrev(substr($digits, 0, -1));
        for ($i = 0; $i < strlen($body); $i++) {
            $sum += (int) $body[$i] * ($i % 2 === 0 ? 3 : 1);
        }
        return ((10 - ($sum % 10)) % 10) === (int) substr($digits, -1) ? $digits : '';
    }
}

if (!function_exists('geoguru_product_feed_url')) {
    /** The URL when it is an absolute http(s) address, else ''. */
    function geoguru_product_feed_url($value) {
        $value = is_string($value) ? trim($value) : '';
        return preg_match('#^https?://[^\s/]+#i', $value) ? $value : '';
    }
}

if (!function_exists('geoguru_product_feed_decimal')) {
    /** A positive number as a plain decimal string ("1.5"), else ''. */
    function geoguru_product_feed_decimal($value) {
        if (!is_numeric($value) || (float) $value <= 0) {
            return '';
        }
        $out = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        return $out === '' ? '' : $out;
    }
}

if (!function_exists('geoguru_product_feed_row')) {
    /**
     * @param array $p     One product or variation: id, parent_id, title, description, url, image,
     *                     images, regular_price, sale_price, stock_status, brand, gtin, category,
     *                     weight, length, width, height, review_count, rating, variant.
     * @param array $store seller_name, seller_url, currency, decimals, weight_unit, dimension_unit.
     * @return array array('row' => array|null, 'skip' => reason or '')
     */
    function geoguru_product_feed_row(array $p, array $store) {
        $skip = function ($reason) {
            return array('row' => null, 'skip' => $reason);
        };

        $id = isset($p['id']) ? (string) $p['id'] : '';
        $title = geoguru_product_feed_text(isset($p['title']) ? $p['title'] : '', 150);
        $url = geoguru_product_feed_url(isset($p['url']) ? $p['url'] : '');
        if ($id === '' || $title === '' || $url === '') {
            return $skip('incomplete');
        }
        $image = geoguru_product_feed_url(isset($p['image']) ? $p['image'] : '');
        if ($image === '') {
            return $skip('no_image');
        }
        $seller = geoguru_product_feed_text(isset($store['seller_name']) ? $store['seller_name'] : '', 150);
        if ($seller === '') {
            return $skip('no_seller');
        }

        $currency = isset($store['currency']) ? $store['currency'] : '';
        $decimals = isset($store['decimals']) ? $store['decimals'] : 2;
        $regular = isset($p['regular_price']) ? $p['regular_price'] : '';
        $sale = isset($p['sale_price']) ? $p['sale_price'] : '';
        $price = geoguru_product_feed_money($regular, $currency, $decimals);
        if ($price === '') {
            // Some stores fill only the selling price; it is then the price, with no sale to show.
            $price = geoguru_product_feed_money($sale, $currency, $decimals);
            $sale = '';
        }
        if ($price === '') {
            return $skip('no_price');
        }

        $status = isset($p['stock_status']) ? (string) $p['stock_status'] : '';
        $availability_map = array(
            'instock' => 'in_stock',
            'outofstock' => 'out_of_stock',
            'onbackorder' => 'backorder',
        );
        $availability = isset($availability_map[$status]) ? $availability_map[$status] : 'unknown';

        $description = geoguru_product_feed_text(isset($p['description']) ? $p['description'] : '', 5000);
        $brand = geoguru_product_feed_text(isset($p['brand']) ? $p['brand'] : '', 150);

        $row = array(
            'item_id' => $id,
            'title' => $title,
            // Both are required on every row. A product with no description of its own is described
            // by its title, and one with no brand is sold under the store's own name.
            'description' => $description !== '' ? $description : $title,
            'url' => $url,
            'brand' => $brand !== '' ? $brand : $seller,
            'seller_name' => $seller,
            'image_url' => $image,
            'availability' => $availability,
            'price' => $price,
        );

        $sale_money = geoguru_product_feed_money($sale, $currency, $decimals);
        if ($sale_money !== '' && (float) $sale < (float) $regular) {
            $row['sale_price'] = $sale_money;
        }

        $seller_url = geoguru_product_feed_url(isset($store['seller_url']) ? $store['seller_url'] : '');
        if ($seller_url !== '') {
            $row['seller_url'] = $seller_url;
        }

        $parent = isset($p['parent_id']) ? (string) $p['parent_id'] : '';
        if ($parent !== '' && $parent !== '0' && $parent !== $id) {
            $row['group_id'] = $parent;
            $row['listing_has_variations'] = true;
            $dict = array();
            if (isset($p['variant']) && is_array($p['variant'])) {
                foreach ($p['variant'] as $name => $value) {
                    $name = geoguru_product_feed_text((string) $name, 100);
                    $value = geoguru_product_feed_text((string) $value, 100);
                    if ($name !== '' && $value !== '') {
                        $dict[$name] = $value;
                    }
                }
            }
            // An object even when empty: json_encode would write an empty array as [].
            $row['variant_dict'] = empty($dict) ? new stdClass() : $dict;
        }

        $gtin = geoguru_product_feed_gtin(isset($p['gtin']) ? $p['gtin'] : '');
        if ($gtin !== '') {
            $row['gtin'] = $gtin;
        }

        $category = geoguru_product_feed_text(isset($p['category']) ? $p['category'] : '', 500);
        if ($category !== '') {
            $row['product_category'] = $category;
        }

        $extra = array();
        if (isset($p['images']) && is_array($p['images'])) {
            foreach ($p['images'] as $extra_url) {
                $extra_url = geoguru_product_feed_url($extra_url);
                if ($extra_url !== '' && $extra_url !== $image && !in_array($extra_url, $extra, true)) {
                    $extra[] = $extra_url;
                }
                if (count($extra) >= 10) {
                    break;
                }
            }
        }
        if (!empty($extra)) {
            $row['additional_image_urls'] = $extra;
        }

        $weight_units = array('g' => 'g', 'kg' => 'kg', 'oz' => 'oz', 'lbs' => 'lb', 'lb' => 'lb');
        $weight_unit = isset($store['weight_unit']) ? (string) $store['weight_unit'] : '';
        $weight = geoguru_product_feed_decimal(isset($p['weight']) ? $p['weight'] : '');
        if ($weight !== '' && isset($weight_units[$weight_unit])) {
            $row['weight'] = $weight;
            $row['item_weight_unit'] = $weight_units[$weight_unit];
        }

        $dimension_unit = isset($store['dimension_unit']) ? (string) $store['dimension_unit'] : '';
        if (in_array($dimension_unit, array('in', 'cm', 'ft', 'm', 'mm'), true)) {
            $dimensions = array();
            foreach (array('length', 'width', 'height') as $axis) {
                $size = geoguru_product_feed_decimal(isset($p[$axis]) ? $p[$axis] : '');
                if ($size !== '') {
                    $dimensions[$axis] = $size;
                }
            }
            if (count($dimensions) >= 2) {
                $dimensions['unit'] = $dimension_unit;
                $row['dimensions'] = $dimensions;
            }
        }

        $reviews = isset($p['review_count']) ? (int) $p['review_count'] : 0;
        $rating = isset($p['rating']) && is_numeric($p['rating']) ? (float) $p['rating'] : 0.0;
        if ($reviews > 0 && $rating > 0 && $rating <= 5) {
            $row['review_count'] = $reviews;
            $row['star_rating'] = number_format($rating, 2, '.', '');
        }

        return array('row' => $row, 'skip' => '');
    }
}
