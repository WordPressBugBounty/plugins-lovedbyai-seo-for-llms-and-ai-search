/**
 * Relay between the embedded dashboard iframe and this site's REST API. The
 * iframe asks over postMessage; the fetch from here is same-origin. Config
 * comes from window.geoguruPortalBridge, injected before this script.
 * Minified at build time to portal-bridge.min.js.
 */
(function () {
    var cfg = window.geoguruPortalBridge;
    if (!cfg || typeof window.fetch !== 'function' || typeof window.addEventListener !== 'function') {
        return;
    }

    // Distinct markers per direction so a reply can never be read as a request.
    var FROM_FRAME = 'geoguru-portal';
    var FROM_PAGE = 'geoguru-bridge';

    // If the admin area and the REST API sit on different origins, the fetch
    // from here would be cross-origin too and gain nothing — report it so the
    // frame uses another route.
    var sameOriginRest = cfg.restOrigin === window.location.origin;

    function frameWindow() {
        var el = document.getElementById(cfg.iframeId);
        return el ? el.contentWindow : null;
    }

    // ─── Iframe height ───────────────────────────────────────────────────────────
    //
    // The frame is sized to the space actually left below it, not to the viewport.
    // A plain `height: 100vh` is a whole viewport tall while starting below the admin
    // bar, so its last 32px sit past the fold — which is where the dashboard's account
    // row lives — and the admin page grows a second scrollbar around the frame's own.
    //
    // Measured rather than `calc(100vh - 32px)`: the bar is 46px on narrow screens, and
    // admin notices push the frame down by however tall they happen to be. Anything that
    // changes what sits above it is already accounted for by reading its own position.
    var MIN_FRAME_HEIGHT = 320;

    function sizeFrame() {
        var el = document.getElementById(cfg.iframeId);
        if (!el) {
            return;
        }
        var available = window.innerHeight - el.getBoundingClientRect().top;
        el.style.height = Math.max(MIN_FRAME_HEIGHT, Math.round(available)) + 'px';
    }

    function watchFrameHeight() {
        sizeFrame();
        window.addEventListener('resize', sizeFrame);

        // Notices are dismissed, and other plugins inject their own after load. Both change
        // the frame's offset without a resize event, so watch the container it sits in.
        var body = document.getElementById('wpbody-content');
        if (body && typeof window.ResizeObserver === 'function') {
            new window.ResizeObserver(sizeFrame).observe(body);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', watchFrameHeight);
    } else {
        watchFrameHeight();
    }

    function reply(target, payload) {
        payload.source = FROM_PAGE;
        // Always an exact origin, never '*': the reply can carry site settings.
        target.postMessage(payload, cfg.portalOrigin);
    }

    function routeAllowed(endpoint, method) {
        if (!Object.prototype.hasOwnProperty.call(cfg.allowed, endpoint)) {
            return false;
        }
        var methods = cfg.allowed[endpoint];
        for (var i = 0; i < methods.length; i++) {
            if (methods[i] === method) {
                return true;
            }
        }
        return false;
    }

    function serve(target, msg) {
        var endpoint = typeof msg.endpoint === 'string' ? msg.endpoint : '';
        var method = typeof msg.method === 'string' ? msg.method.toUpperCase() : '';
        var token = typeof msg.token === 'string' ? msg.token : '';

        if (!routeAllowed(endpoint, method)) {
            reply(target, { type: 'response', id: msg.id, ok: false, status: 0, reason: 'endpoint_not_allowed' });
            return;
        }

        // endpoint matched a literal key above, so it cannot leave our namespace.
        var url = cfg.restBase.replace(/\/$/, '') + endpoint;
        var options = { method: method, credentials: 'same-origin', headers: {} };

        if (method === 'GET') {
            // Same token channel the frame uses when it calls directly.
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'token=' + encodeURIComponent(token) +
                '&_ts=' + (new Date()).getTime();
        } else {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-GeoGuru-Token'] = token;
            options.body = typeof msg.body === 'string' ? msg.body : JSON.stringify(msg.body || {});
        }

        var status = 0;
        var contentType = '';
        var redirected = false;
        fetch(url, options).then(function (res) {
            status = res.status;
            contentType = res.headers.get('Content-Type') || '';
            // A redirect turns a POST into a GET and drops the body, so the 200 that
            // comes back would look like a save that never happened. Report it as a
            // transport failure and let the frame use another route.
            redirected = res.redirected === true;
            return res.text();
        }).then(function (text) {
            if (redirected) {
                reply(target, { type: 'response', id: msg.id, ok: false, status: 0, reason: 'redirected' });
                return;
            }
            reply(target, {
                type: 'response',
                id: msg.id,
                ok: status >= 200 && status < 300,
                status: status,
                contentType: contentType,
                body: text
            });
        }).catch(function (err) {
            reply(target, {
                type: 'response',
                id: msg.id,
                ok: false,
                status: 0,
                reason: 'fetch_failed',
                message: err && err.message ? err.message : ''
            });
        });
    }

    window.addEventListener('message', function (event) {
        if (event.origin !== cfg.portalOrigin) {
            return;
        }
        // Origin alone would trust any window on that origin; require our frame.
        var expected = frameWindow();
        if (!expected || event.source !== expected) {
            return;
        }

        var msg = event.data;
        if (!msg || typeof msg !== 'object' || msg.source !== FROM_FRAME || typeof msg.id !== 'string') {
            return;
        }

        if (msg.type === 'hello') {
            reply(event.source, {
                type: 'hello-ack',
                id: msg.id,
                pluginVersion: cfg.pluginVersion,
                sameOriginRest: sameOriginRest
            });
            return;
        }

        if (msg.type === 'request') {
            if (!sameOriginRest) {
                reply(event.source, { type: 'response', id: msg.id, ok: false, status: 0, reason: 'rest_not_same_origin' });
                return;
            }
            serve(event.source, msg);
        }
    }, false);
})();
