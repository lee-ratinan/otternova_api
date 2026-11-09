/*!
 * Otternova visitor tracker, for the website (www) and the marketplace (mart).
 * Sends page views, time on page and custom events to POST /v1/track of the API, in batches.
 *
 * On every page, before </body>:
 *   www:  <script src="{LINK_API}/js/tracker.js" data-site="www" defer></script>
 *   mart: <script src="{LINK_API}/js/tracker.js" data-site="mart" data-business="{business_slug}" defer></script>
 *         (leave data-business out on the marketplace's own pages: home, search, terms, privacy)
 * data-endpoint="https://..." sends somewhere else than /v1/track next to this script.
 *
 * Custom events, such as the shopping funnel of mart:
 *   OtternovaTracker.track('view_item',        { artifact: 'Ab3xK9...', value: 120, currency: 'THB' });
 *   OtternovaTracker.track('add_to_cart',      { artifact: 'Ab3xK9...', variant: '...', quantity: 1, value: 120, currency: 'THB' });
 *   OtternovaTracker.track('checkout_start',   { items: 3, value: 360, currency: 'THB' });
 *   OtternovaTracker.track('checkout_confirm', { order: 'Zq81...', items: 3, value: 360, currency: 'THB' });
 * Names are lower-case letters, digits and _; props are up to 10 texts, numbers or true/false.
 * Calls made before this script has loaded are kept if the page sets, early on:
 *   <script>window.OtternovaTracker = window.OtternovaTracker || { q: [] };
 *     OtternovaTracker.track = OtternovaTracker.track || function () { OtternovaTracker.q.push(arguments); };</script>
 */
(function (window, document) {
    'use strict';

    var script = document.currentScript;
    var existing = window.OtternovaTracker;
    if (!script || (existing && existing.loaded)) {
        return;
    }

    var SITE = script.getAttribute('data-site');
    if (SITE !== 'www' && SITE !== 'mart') {
        return;
    }
    var BUSINESS = SITE === 'mart' ? (script.getAttribute('data-business') || '') : '';
    var ENDPOINT = script.getAttribute('data-endpoint') || new URL('../v1/track', script.src).href;

    var SESSION_IDLE_MS = 30 * 60 * 1000;   // a new session after this long without an event
    var VISITOR_KEY = 'otn_vid';
    var SESSION_KEY = 'otn_sid' + (BUSINESS ? ':' + BUSINESS : ''); // one session per business on mart
    var FLUSH_EVERY_MS = 15000;              // send what is queued this often while the page is open
    var FLUSH_AT = 10;                       // ... or as soon as this many events are queued
    var PER_REQUEST = 20;                    // events in one request, to stay well under the API's size limit
    var UTM = ['source', 'medium', 'campaign', 'term', 'content'];

    var memory = {};
    var queue = [];
    var visibleMs = 0;
    var visibleSince = null;
    var maxScroll = 0;
    var reportedScroll = 0;
    var pagePath = location.pathname;

    // --- ids and storage --------------------------------------------------------------------------------------

    function newId() {
        var alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz-_';
        var bytes = new Uint8Array(16);
        var id = '';
        (window.crypto || window.msCrypto).getRandomValues(bytes);
        for (var i = 0; i < bytes.length; i++) {
            id += alphabet[bytes[i] & 63];
        }
        return id;
    }

    function load(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (e) {
            return memory[key] || null; // storage blocked: the ids last as long as the page
        }
    }

    function save(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (e) {
            memory[key] = value;
        }
    }

    function visitorId() {
        var id = load(VISITOR_KEY);
        if (!id || !/^[0-9A-Za-z_-]{16}$/.test(id)) {
            id = newId();
            save(VISITOR_KEY, id);
        }
        return id;
    }

    /** The current session's id, renewed after SESSION_IDLE_MS without an event; each call counts as activity */
    function sessionId() {
        var now = Date.now();
        var stored = null;
        try {
            stored = JSON.parse(load(SESSION_KEY) || 'null');
        } catch (e) {
            stored = null;
        }
        var id = stored && /^[0-9A-Za-z_-]{16}$/.test(stored.id) && now - stored.last < SESSION_IDLE_MS
            ? stored.id
            : newId();
        save(SESSION_KEY, JSON.stringify({ id: id, last: now }));
        return id;
    }

    // --- events -----------------------------------------------------------------------------------------------

    function enqueue(event) {
        event.at = Date.now();
        event.session = sessionId(); // an event belongs to the session it happened in, even if sent later
        queue.push(event);
        if (queue.length >= FLUSH_AT) {
            flush();
        }
    }

    function pageview() {
        pagePath = location.pathname;
        var params = new URLSearchParams(location.search);
        var utm = {};
        var hasUtm = false;
        UTM.forEach(function (key) {
            var value = params.get('utm_' + key);
            if (value) {
                utm[key] = value;
                hasUtm = true;
            }
            params.delete('utm_' + key);
        });
        var query = params.toString();
        var referrer = '';
        try {
            if (document.referrer && new URL(document.referrer).host !== location.host) {
                referrer = document.referrer; // only from elsewhere: moving between our own pages is no source
            }
        } catch (e) {
            referrer = '';
        }

        enqueue({
            type: 'pageview',
            path: pagePath,
            title: document.title || undefined,
            query: query || undefined,
            referrer: referrer || undefined,
            utm: hasUtm ? utm : undefined
        });
    }

    /** Queue the time the page was visible since the last report, and how far it was scrolled */
    function engage() {
        if (visibleSince !== null) {
            visibleMs += Date.now() - visibleSince;
            visibleSince = document.visibilityState === 'visible' ? Date.now() : null;
        }
        // visibilitychange and pagehide both fire when leaving: report only what is new
        if (visibleMs < 1000 && maxScroll <= reportedScroll) {
            return;
        }
        enqueue({ type: 'engage', path: pagePath, visible_ms: Math.round(visibleMs), scroll_pct: maxScroll });
        visibleMs = 0;
        reportedScroll = maxScroll;
    }

    function track(name, props) {
        if (typeof name !== 'string' || !/^[a-z][a-z0-9_]{0,63}$/.test(name)) {
            return;
        }
        enqueue({ type: 'event', path: location.pathname, name: name, props: props && typeof props === 'object' ? props : undefined });
    }

    // --- sending ----------------------------------------------------------------------------------------------

    function send(body) {
        var json = JSON.stringify(body);
        // text/plain is a "simple" request, so the browser sends it cross-origin without a CORS preflight
        if (navigator.sendBeacon && navigator.sendBeacon(ENDPOINT, new Blob([json], { type: 'text/plain;charset=UTF-8' }))) {
            return;
        }
        try {
            fetch(ENDPOINT, {
                method: 'POST',
                body: json,
                headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
                mode: 'no-cors',
                credentials: 'omit',
                keepalive: true
            });
        } catch (e) {
            // nothing more to do: tracking must never break the page
        }
    }

    function flush() {
        if (queue.length === 0) {
            return;
        }
        var now = Date.now();
        var events = queue;
        queue = [];

        // one request per session (a session can roll over while events wait) and per PER_REQUEST events
        var bySession = {};
        events.forEach(function (event) {
            (bySession[event.session] = bySession[event.session] || []).push(event);
        });
        Object.keys(bySession).forEach(function (session) {
            var list = bySession[session];
            for (var i = 0; i < list.length; i += PER_REQUEST) {
                send({
                    site: SITE,
                    business_slug: BUSINESS || undefined,
                    visitor_id: visitorId(),
                    session_id: session,
                    language: navigator.language || undefined,
                    timezone: timezone(),
                    screen: window.screen ? [window.screen.width, window.screen.height] : undefined,
                    events: list.slice(i, i + PER_REQUEST).map(function (event) {
                        var out = {};
                        Object.keys(event).forEach(function (key) {
                            if (key !== 'at' && key !== 'session' && event[key] !== undefined) {
                                out[key] = event[key];
                            }
                        });
                        out.ago_ms = Math.max(0, now - event.at);
                        return out;
                    })
                });
            }
        });
    }

    function timezone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || undefined;
        } catch (e) {
            return undefined;
        }
    }

    // --- page lifecycle ---------------------------------------------------------------------------------------

    function onScroll() {
        var doc = document.documentElement;
        var height = Math.max(doc.scrollHeight, document.body ? document.body.scrollHeight : 0);
        var seen = (window.scrollY || doc.scrollTop) + window.innerHeight;
        var pct = height > 0 ? Math.min(100, Math.round(seen / height * 100)) : 100;
        if (pct > maxScroll) {
            maxScroll = pct;
        }
    }

    function onHidden() {
        engage();
        flush();
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            visibleSince = Date.now();
        } else {
            onHidden();
        }
    });
    window.addEventListener('pagehide', onHidden);
    window.addEventListener('scroll', onScroll, { passive: true });
    window.setInterval(flush, FLUSH_EVERY_MS);

    visibleSince = document.visibilityState === 'visible' ? Date.now() : null;
    onScroll();
    pageview();

    var tracker = {
        loaded: true,
        track: track,
        /** For pages that change their address without loading a new page */
        pageview: function () {
            engage();
            maxScroll = 0;
            reportedScroll = 0;
            pageview();
        },
        flush: flush
    };
    window.OtternovaTracker = tracker;

    // calls the page made before this script loaded
    if (existing && existing.q && existing.q.length) {
        existing.q.forEach(function (args) {
            track(args[0], args[1]);
        });
    }
})(window, document);
