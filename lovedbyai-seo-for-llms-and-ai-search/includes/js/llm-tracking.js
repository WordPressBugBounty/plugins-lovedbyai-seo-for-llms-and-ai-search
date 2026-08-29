/**
 * LLM source event tracking beacon. Reports a page visit that arrived from an
 * LLM (utm_source or referer). Config comes from window.geoguru_llm_tracking,
 * injected before this script. Minified at build time to llm-tracking.min.js.
 */
(function () {
    var cfg = window.geoguru_llm_tracking;
    if (!cfg || !cfg.proxyUrl) {
        return;
    }

    var data = {
        url: location.href,
        referer: document.referrer || '',
        utm_source: (new URLSearchParams(window.location.search)).get('utm_source') || '',
        user_agent: navigator.userAgent || ''
    };
    var payload = JSON.stringify(data);

    // Statuses that mean the request never reached the backend: the route is
    // blocked or missing. Anything else (a validation 400, a 502 from a forward
    // that may already have inserted the row) is an answer from our own side, and
    // retrying it would risk recording the visit twice.
    function shouldTryDirect(status) {
        return status === 401 || status === 403 || status === 404;
    }

    // Fallback for a site whose own endpoint cannot be reached at all.
    function direct() {
        if (!cfg.fallbackUrl || !cfg.siteId) {
            return;
        }
        var body = JSON.stringify({
            site_id: cfg.siteId,
            url: data.url,
            referer: data.referer,
            utm_source: data.utm_source,
            user_agent: data.user_agent
        });
        fetch(cfg.fallbackUrl, {
            method: 'POST',
            body: body,
            keepalive: true,
            headers: { 'Content-Type': 'application/json' }
        }).catch(function () {});
    }

    if (typeof fetch === 'function' && typeof Request !== 'undefined') {
        fetch(cfg.proxyUrl, {
            method: 'POST',
            body: payload,
            keepalive: true,
            headers: { 'Content-Type': 'application/json' }
        }).then(function (res) {
            if (!res.ok && shouldTryDirect(res.status)) {
                direct();
            }
        }).catch(direct);
    } else if (typeof navigator.sendBeacon === 'function') {
        // sendBeacon cannot report failures, so no fallback from here.
        var blob = new Blob([payload], { type: 'application/json' });
        navigator.sendBeacon(cfg.proxyUrl, blob);
    }
})();
