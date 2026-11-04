<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Requests no longer carry an API key, so the stored one is unused.
function geoguru_migrate_to_2_5_3($from_version, $logger) {
    delete_option('geoguru_supabase_anon_key');
}
