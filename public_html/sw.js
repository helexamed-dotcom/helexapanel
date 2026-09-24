/* =====================================================================
   HeleXa Med — service worker.

   Guiding rule: a cached response must never become an authorisation.
   Anything that depends on who is asking is network-only. This worker only
   keeps the static files (styles, scripts, fonts, icons) so pages open fast
   and the app can be installed; no page and no lesson is ever stored.

   The offline library that used to live here was retired. Activating this
   version deletes every cache the old worker created, which is where the
   /offline shell and its assets were kept.
   ===================================================================== */
'use strict';

/* Bump this whenever a precached asset changes. */
var VERSION = 'v2.0.0';
var CACHES = {
    static: 'helexa-static-' + VERSION,
    fonts:  'helexa-fonts-'  + VERSION,
    icons:  'helexa-icons-'  + VERSION
};

var FONT_URLS = [
    '/assets/fonts/IRANSansWeb_FaNum.woff2',
    '/assets/fonts/IRANSansWeb_FaNum_Medium.woff2',
    '/assets/fonts/IRANSansWeb_FaNum_Bold.woff2',
    '/assets/css/lesson-fonts.css'
];

var ICON_URLS = [
    '/assets/icons/icon-192.png',
    '/assets/icons/icon-512.png',
    '/assets/icons/maskable-192.png',
    '/assets/icons/maskable-512.png',
    '/assets/icons/apple-touch-icon.png',
    '/assets/icons/favicon-32.png'
];

function isUsableFor(request, response) {
    if (!response || !response.ok || response.type === 'opaqueredirect') { return false; }
    if (response.redirected) { return false; }
    try {
        return new URL(response.url).pathname === new URL(request.url).pathname;
    } catch (e) {
        return true;
    }
}

/** Precaches tolerantly: one missing file must not abort the whole install. */
function precache(cacheName, urls) {
    return caches.open(cacheName).then(function (cache) {
        return Promise.all(urls.map(function (url) {
            var request = new Request(url, { credentials: 'same-origin' });
            return fetch(request).then(function (response) {
                if (isUsableFor(request, response)) { return cache.put(request, response); }
                return null;
            }).catch(function () { return null; });
        }));
    });
}

self.addEventListener('install', function (event) {
    event.waitUntil(Promise.all([
        precache(CACHES.fonts, FONT_URLS),
        precache(CACHES.icons, ICON_URLS)
    ]));
});

self.addEventListener('activate', function (event) {
    var keep = Object.keys(CACHES).map(function (k) { return CACHES[k]; });
    event.waitUntil(
        caches.keys().then(function (names) {
            return Promise.all(names.map(function (name) {
                if (name.indexOf('helexa-') === 0 && keep.indexOf(name) === -1) {
                    return caches.delete(name);
                }
                return null;
            }));
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('message', function (event) {
    var data = event.data || {};
    if (data.type === 'SKIP_WAITING') {
        self.skipWaiting();
        return;
    }
    if (data.type === 'PURGE_CACHES') {
        event.waitUntil(caches.keys().then(function (names) {
            return Promise.all(names.map(function (name) {
                return name.indexOf('helexa-') === 0 ? caches.delete(name) : null;
            }));
        }));
    }
});

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (request.method !== 'GET') { return; }

    var url = new URL(request.url);
    if (url.origin !== self.location.origin) { return; }

    if (url.pathname.indexOf('/assets/fonts/') === 0) {
        event.respondWith(cacheFirst(request, CACHES.fonts));
        return;
    }
    if (url.pathname.indexOf('/assets/icons/') === 0) {
        event.respondWith(cacheFirst(request, CACHES.icons));
        return;
    }
    // Uploaded images under /assets/images are the site's own branding and
    // are fine to keep; everything else under /assets is a stamped file.
    if (url.pathname.indexOf('/assets/') === 0 || url.pathname === '/manifest.webmanifest') {
        event.respondWith(staleWhileRevalidate(request, CACHES.static));
    }
    // Pages, API calls and lessons are left to the browser: always network.
});

function cacheFirst(request, cacheName) {
    return caches.open(cacheName).then(function (cache) {
        return cache.match(request).then(function (cached) {
            if (cached) { return cached; }
            return fetch(request).then(function (response) {
                if (isUsableFor(request, response)) { cache.put(request, response.clone()); }
                return response;
            });
        });
    });
}

function staleWhileRevalidate(request, cacheName) {
    return caches.open(cacheName).then(function (cache) {
        return cache.match(request).then(function (cached) {
            var network = fetch(request).then(function (response) {
                if (isUsableFor(request, response)) {
                    cache.put(request, response.clone());
                    dropSupersededVersions(cache, request);
                }
                return response;
            }).catch(function () {
                return cached || cache.match(request, { ignoreSearch: true });
            });
            return cached || network;
        });
    });
}

/**
 * Assets arrive stamped with the file's modification time, so every edit is
 * a new URL. Once a new stamp is stored, the older stamps of the same file go.
 */
function dropSupersededVersions(cache, request) {
    var kept = new URL(request.url);
    cache.keys().then(function (keys) {
        keys.forEach(function (key) {
            var url = new URL(key.url);
            if (url.pathname === kept.pathname && url.search !== kept.search) {
                cache.delete(key);
            }
        });
    });
}
