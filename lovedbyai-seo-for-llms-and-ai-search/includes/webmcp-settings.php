<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('GEOGURU_WEBMCP_DISABLED_TOOLS_OPTION')) {
    define('GEOGURU_WEBMCP_DISABLED_TOOLS_OPTION', 'geoguru_webmcp_disabled_tools');
}

if (!function_exists('geoguru_webmcp_sanitize_tool_names')) {
    function geoguru_webmcp_sanitize_tool_names($value) {
        if (!is_array($value)) {
            return array();
        }
        $names = array();
        foreach ($value as $name) {
            if (count($names) >= 50) {
                break;
            }
            if (is_string($name) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names;
    }
}

if (!function_exists('geoguru_webmcp_disabled_tools')) {
    function geoguru_webmcp_disabled_tools() {
        return geoguru_webmcp_sanitize_tool_names(get_option(GEOGURU_WEBMCP_DISABLED_TOOLS_OPTION, array()));
    }
}
