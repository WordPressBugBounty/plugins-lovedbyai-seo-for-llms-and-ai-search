<?php
/**
 * Whether WordPress itself serves this request to the public.
 *
 * Nothing optimized -- the CDN copy, the ?llm_view=1 mirror, the overlay -- may be served for a
 * page WordPress does not serve. Otherwise a trashed or unpublished post, or the old URL of a
 * renamed page, goes on being served optimized (on the mirror, with a forced 200) long after it was
 * removed. The overlay payload stays in post meta, so a restored post is served optimized again at
 * once.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * True for a 404, or when the queried object is a post whose status WordPress does not serve to the
 * public (draft, pending, private, future, trash: a logged-in preview) or whose password the visitor
 * has not entered. A custom status registered public (bbPress `closed`) is served, so it is not
 * guarded: the test is is_post_status_viewable(), not `=== 'publish'`.
 *
 * Only meaningful once the main query has run: template_redirect, template_include, shutdown.
 * The queried object is tested as a WP_Post rather than through is_singular(), so the posts page
 * -- a real page behind the blog index, which the overlay covers -- is guarded too.
 *
 * @return bool
 */
function geoguru_is_unservable_request() {
    if (is_404()) {
        return true;
    }
    $queried = get_queried_object();
    if (!($queried instanceof WP_Post)) {
        return false;
    }
    if (!is_post_status_viewable(get_post_status($queried))) {
        return true;
    }
    return (bool) post_password_required($queried);
}
