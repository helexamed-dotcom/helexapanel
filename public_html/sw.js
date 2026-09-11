/* =====================================================================
   HeleXa Med — service worker.

   Guiding rule: a cached response must never become an authorisation.
   Anything that depends on who is asking is network-only. The only HTML
   this worker will ever serve from cache is /offline, which by design
   contains no user data at all.
   ===================================================================== */
'use strict';

importScripts('/assets/js/helexa-db.js');

/* Bump this whenever a precached asset changes. The shell and static caches
   are stale-while-revalidate, so without a bump a returning student gets one
   more load of the previous CSS and JS — which after a redesign means the new
   markup rendered against the old stylesheet. A new version name makes the
   install step fetch the current files and activate drops the old caches. */
var VERSION = 'v1.3.0';
var CACHES = {
    shell:  'helexa-shell-'  + VERSION,
    static: 'helexa-static-' + VERSION,
    fonts:  'helexa-fonts-'  + VERSION,
    icons:  'helexa-icons-'  + VERSION
};

var SHELL_URLS = [
    '/offline',
    '/assets/css/app.css',
    '/assets/css/pwa.css',
    '/assets/js/helexa-db.js',
    '/assets/js/helexa-core.js',
    '/assets/js/offline-app.js',
    '/assets/js/pwa.js',
    '/assets/js/app.js',
    '/assets/js/viewer.js',
    '/assets/js/balin.js',
    '/assets/js/offline-manager.js',
    '/manifest.webmanifest'
];

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

/* Never cached, never served from cache, under any circumstances. */
var NEVER_CACHE = [
    /^\/admin(\/|$)/,          // the whole admin tree
    /^\/login$/,
    /^\/logout$/,
    /^\/install\.php$/,
    /^\/account(\/|$)/,        // profile, password, avatars
    /^\/api\/session\//,       // "am I still signed in" must never be stale
    /^\/api\/sync\//,
    /^\/api\/offline\//        // authorisation checks are always live
];

function isNeverCached(pathname) {
    return NEVER_CACHE.some(function (pattern) { return pattern.test(pathname); });
}

/* ------------------------------------------------------------- install */

/**
 * A redirect is not the document that was asked for. Without this check a
 * signed-out request for /offline follows the redirect, returns the login page
 * with status 200, and the login page gets cached under the /offline key —
 * which would then be shown to an offline user as their library.
 */
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
    event.waitUntil(
        Promise.all([
            precache(CACHES.shell, SHELL_URLS),
            precache(CACHES.fonts, FONT_URLS),
            precache(CACHES.icons, ICON_URLS)
        ])
        // Waiting is not skipped here: an update never swaps the worker out
        // from under an open lesson unless the page asks for it.
    );
});

/* ------------------------------------------------------------ activate */

self.addEventListener('activate', function (event) {
    var keep = Object.keys(CACHES).map(function (k) { return CACHES[k]; });

    event.waitUntil(
        caches.keys().then(function (names) {
            return Promise.all(names.map(function (name) {
                // Only our own old versions are removed. Downloaded lessons
                // live in IndexedDB and survive every deployment.
                if (name.indexOf('helexa-') === 0 && keep.indexOf(name) === -1) {
                    return caches.delete(name);
                }
                return null;
            }));
        }).then(function () { return self.clients.claim(); })
    );
});

/* ------------------------------------------------------------- message */

self.addEventListener('message', function (event) {
    var data = event.data || {};

    if (data.type === 'SKIP_WAITING') {
        self.skipWaiting();
        return;
    }

    if (data.type === 'PURGE_CACHES') {
        // Called on logout and on account switch. IndexedDB is cleared by the
        // page, which owns the account scope; here we drop the HTTP caches.
        event.waitUntil(caches.keys().then(function (names) {
            return Promise.all(names.map(function (name) {
                return name.indexOf('helexa-') === 0 ? caches.delete(name) : null;
            }));
        }));
    }
});

/* --------------------------------------------------------------- fetch */

self.addEventListener('fetch', function (event) {
    var request = event.request;

    // Anything that changes state goes straight to the network. A queued POST
    // would be a way to replay a mutation later without the user knowing.
    if (request.method !== 'GET') { return; }

    var url = new URL(request.url);
    if (url.origin !== self.location.origin) { return; }
    if (isNeverCached(url.pathname)) { return; }

    // A stored lesson, when the network cannot deliver it.
    var streamMatch = url.pathname.match(/^\/content\/([0-9a-f-]{36})\/stream$/i);
    if (streamMatch) {
        event.respondWith(streamWithOfflineFallback(request, streamMatch[1]));
        return;
    }

    if (url.pathname.indexOf('/assets/fonts/') === 0) {
        event.respondWith(cacheFirst(request, CACHES.fonts));
        return;
    }
    if (url.pathname.indexOf('/assets/icons/') === 0) {
        event.respondWith(cacheFirst(request, CACHES.icons));
        return;
    }
    if (url.pathname.indexOf('/assets/') === 0 || url.pathname === '/manifest.webmanifest') {
        event.respondWith(staleWhileRevalidate(request, CACHES.static));
        return;
    }

    // The offline shell itself: served from cache first so it opens instantly
    // and works with no connection at all.
    if (url.pathname === '/offline') {
        event.respondWith(
            staleWhileRevalidate(request, CACHES.shell).then(function (response) {
                // Signed out, or the server sent us elsewhere: fall back to the
                // last known-good shell rather than showing a redirected page.
                if (isUsableFor(request, response)) { return response; }
                return caches.match('/offline', { cacheName: CACHES.shell })
                    .then(function (cached) { return cached || response; });
            })
        );
        return;
    }

    // Every other page is a normal, private, server-rendered page. It is
    // fetched from the network and never stored; if the network is gone the
    // user lands on the offline shell instead of a browser error page.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(function () {
                return caches.match('/offline', { cacheName: CACHES.shell })
                    .then(function (cached) { return cached || offlineFallbackResponse(); });
            })
        );
    }
});

/* ---------------------------------------------------------- strategies */

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
                if (isUsableFor(request, response)) { cache.put(request, response.clone()); }
                return response;
            }).catch(function () { return cached; });
            return cached || network;
        });
    });
}

/**
 * Lesson bytes.
 *
 * Online, this is a plain pass-through: the server re-runs authentication,
 * enrolment and content checks exactly as before, and the response is not
 * stored by the worker. Offline, we fall back to the copy the user explicitly
 * downloaded — and only if its lease is still valid.
 */
function streamWithOfflineFallback(request, contentUuid) {
    return fetch(request).catch(function () {
        return serveStoredLesson(contentUuid);
    }).then(function (response) {
        if (response && (response.ok || response.status === 304)) { return response; }
        // A 401/403/404 from the server is authoritative: do not paper over a
        // revoked entitlement with a local copy.
        return response;
    });
}

function serveStoredLesson(contentUuid) {
    return self.HeleXaDB.getMeta('scope').then(function (scope) {
        if (!scope) { return offlineFallbackResponse(); }

        return self.HeleXaDB.getContent(scope, contentUuid).then(function (record) {
            if (!record || record.status !== 'ready' || !self.HeleXaDB.leaseValid(record)) {
                return offlineLessonUnavailable(record);
            }
            return self.HeleXaDB.getContentHtml(scope, contentUuid).then(function (html) {
                if (!html) { return offlineLessonUnavailable(record); }

                return new Response(html, {
                    status: 200,
                    headers: {
                        'Content-Type': 'text/html; charset=UTF-8',
                        'Cache-Control': 'no-store',
                        'X-Content-Type-Options': 'nosniff',
                        'X-HeleXa-Source': 'offline-store',
                        // The same policy the server sends, so a stored lesson
                        // is no less contained than a streamed one.
                        'Content-Security-Policy': [
                            "default-src 'none'",
                            "script-src 'unsafe-inline' 'unsafe-eval'",
                            "style-src 'unsafe-inline'",
                            'img-src data: blob:',
                            'media-src data: blob:',
                            'font-src data:',
                            "connect-src 'none'",
                            "frame-src 'none'",
                            "object-src 'none'",
                            "base-uri 'none'",
                            "form-action 'none'",
                            "frame-ancestors 'self'"
                        ].join('; ')
                    }
                });
            });
        });
    }).catch(function () { return offlineFallbackResponse(); });
}

function page(title, message, status) {
    return new Response(
        '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">' +
        '<meta name="viewport" content="width=device-width, initial-scale=1">' +
        '<title>' + title + '</title>' +
        '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f7fb;' +
        'font-family:"IRANSansWeb",Tahoma,system-ui,sans-serif;color:#101a2c;padding:24px}' +
        '.b{background:#fff;border:1px solid #e6ebf3;border-radius:20px;padding:30px;max-width:400px;text-align:center}' +
        'h1{font-size:17px;margin:0 0 10px}p{color:#48566d;font-size:14px;line-height:2;margin:0 0 18px}' +
        'a{display:inline-block;background:#2563eb;color:#fff;padding:11px 20px;border-radius:12px;text-decoration:none}' +
        '</style></head><body><div class="b"><h1>' + title + '</h1><p>' + message + '</p>' +
        '<a href="/offline">محتوای آفلاین من</a></div></body></html>',
        { status: status || 200, headers: { 'Content-Type': 'text/html; charset=UTF-8', 'Cache-Control': 'no-store' } }
    );
}

function offlineFallbackResponse() {
    return page('اتصال اینترنت برقرار نیست',
        'در حالت آفلاین فقط محتوایی که قبلاً ذخیره کرده‌اید در دسترس است.', 200);
}

function offlineLessonUnavailable(record) {
    if (record && !self.HeleXaDB.leaseValid(record)) {
        return page('اعتبار نسخه آفلاین تمام شده است',
            'برای ادامه مطالعه این جزوه، یک بار به اینترنت وصل شوید تا دسترسی شما دوباره بررسی شود.', 200);
    }
    return page('این محتوا آفلاین ذخیره نشده است',
        'برای مطالعه آفلاین، وقتی آنلاین هستید داخل جزوه دکمه «ذخیره برای مطالعه آفلاین» را بزنید.', 200);
}
