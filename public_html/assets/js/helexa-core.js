/* =====================================================================
   HeleXa Med — shared PWA core: identity, connectivity, sync.
   Loaded by every panel page and by the offline shell.
   ===================================================================== */
(function (root) {
    'use strict';

    var DB = root.HeleXaDB;

    var state = {
        scope: null,
        user: null,
        csrf: null,
        offline: { enabled: false, lease_days: 14, max_contents: 60 },
        connection: 'checking',   // checking | online | offline | reconnecting | syncing
        lastProbe: 0,
        booted: false
    };

    var listeners = [];
    var probeTimer = null;
    var bootPromise = null;

    function emit() {
        listeners.forEach(function (fn) {
            try { fn(state); } catch (e) { /* a broken listener must not stop the rest */ }
        });
    }

    function setConnection(next) {
        if (state.connection === next) { return; }
        state.connection = next;
        emit();
    }

    function csrfFromPage() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : null;
    }

    /* ------------------------------------------------------- identity */

    /**
     * Establishes who is signed in and, crucially, whether the account on this
     * device has changed. A different account means everything the previous
     * one downloaded is wiped before the new session can touch the database.
     */
    function bootstrap() {
        state.csrf = csrfFromPage();

        return DB.getMeta('scope').then(function (storedScope) {
            state.scope = storedScope;

            return fetch('/api/session/state', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (response.status === 401 || response.status === 302) { return null; }
                if (!response.ok) { throw new Error('SESSION_STATE_FAILED'); }
                return response.json();
            }).then(function (data) {
                if (!data || !data.ok) {
                    // Signed out, or the request never reached the server.
                    setConnection(navigator.onLine ? 'reconnecting' : 'offline');
                    return state;
                }

                setConnection('online');
                state.csrf = data.csrf || state.csrf;
                state.user = data.user;
                state.offline = data.offline || state.offline;

                if (storedScope && storedScope !== data.user.scope) {
                    // Account switch on a shared device.
                    return purgeEverything().then(function () {
                        return DB.setMeta('scope', data.user.scope);
                    }).then(function () {
                        state.scope = data.user.scope;
                        return state;
                    });
                }

                state.scope = data.user.scope;
                return DB.setMeta('scope', data.user.scope)
                    .then(function () { return DB.setMeta('user_name', data.user.name); })
                    .then(function () { return state; });
            }).catch(function () {
                setConnection(navigator.onLine ? 'reconnecting' : 'offline');
                return state;
            });
        });
    }

    function purgeEverything() {
        var jobs = [DB.clearAll()];
        if (root.navigator && navigator.serviceWorker && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({ type: 'PURGE_CACHES' });
        }
        return Promise.all(jobs);
    }

    /* --------------------------------------------------- connectivity */

    /**
     * navigator.onLine only reports whether a network interface exists. On a
     * captive portal or a dead uplink it happily says "online", so the real
     * test is whether our own backend answers.
     */
    function probe(force) {
        var now = Date.now();
        if (!force && now - state.lastProbe < 8000) { return Promise.resolve(state.connection); }
        state.lastProbe = now;

        if (!navigator.onLine) {
            setConnection('offline');
            return Promise.resolve('offline');
        }

        setConnection(state.connection === 'online' ? 'online' : 'reconnecting');

        return fetch('/api/session/state', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) { throw new Error('UNREACHABLE'); }
            return response.json();
        }).then(function (data) {
            if (data && data.ok) {
                state.csrf = data.csrf || state.csrf;
                state.user = data.user || state.user;
                setConnection('online');
                return 'online';
            }
            setConnection('offline');
            return 'offline';
        }).catch(function () {
            setConnection('offline');
            return 'offline';
        });
    }

    function watchConnection() {
        window.addEventListener('online', function () { probe(true).then(sync); });
        window.addEventListener('offline', function () { setConnection('offline'); });

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') { probe(false).then(sync); }
        });

        // While offline, keep checking quietly so the pill turns green as soon
        // as the connection actually comes back.
        probeTimer = window.setInterval(function () {
            if (state.connection !== 'online') { probe(true).then(sync); }
        }, 30000);
    }

    /* --------------------------------------------------------- queue */

    function queueStudy(contentUuid, startedAt, endedAt, seconds) {
        if (!state.scope || !(seconds > 0)) { return Promise.resolve(null); }
        return DB.enqueue(state.scope, 'study', {
            content_uuid: contentUuid,
            started_at: new Date(startedAt).toISOString(),
            ended_at: new Date(endedAt).toISOString(),
            duration: Math.round(seconds)
        });
    }

    function queueHighlight(contentUuid, action, payload) {
        if (!state.scope) { return Promise.resolve(null); }
        return DB.enqueue(state.scope, 'highlight', {
            content_uuid: contentUuid,
            action: action,
            highlight: payload
        });
    }

    function queueStatus(contentUuid, status) {
        if (!state.scope) { return Promise.resolve(null); }
        return DB.enqueue(state.scope, 'status', { content_uuid: contentUuid, status: status });
    }

    /* ---------------------------------------------------- sync engine */

    var syncing = false;

    /**
     * Drains the local queue.
     *
     * Each event carries the id it was created with, so retrying a batch that
     * actually succeeded is harmless: the server recognises the id and reports
     * "duplicate" instead of crediting the time twice. Failures stay in the
     * queue with an increasing backoff rather than being dropped.
     */
    function sync() {
        if (syncing || !state.scope || state.connection !== 'online') {
            return Promise.resolve({ skipped: true });
        }

        syncing = true;
        setConnection('syncing');

        return DB.pendingEvents(state.scope).then(function (events) {
            if (!events.length) { return { sent: 0 }; }

            var byType = { study: [], status: [], highlight: [] };
            events.forEach(function (event) {
                if (byType[event.type]) { byType[event.type].push(event); }
            });

            return Promise.all([
                sendBatch('/api/sync/study', byType.study),
                sendBatch('/api/sync/status', byType.status),
                sendBatch('/api/sync/highlights', byType.highlight)
            ]).then(function (counts) {
                return { sent: counts[0] + counts[1] + counts[2] };
            });
        }).then(function (result) {
            syncing = false;
            setConnection('online');
            emit();
            return result;
        }).catch(function (error) {
            syncing = false;
            setConnection(navigator.onLine ? 'reconnecting' : 'offline');
            return { error: String(error) };
        });
    }

    function sendBatch(url, events) {
        if (!events.length) { return Promise.resolve(0); }

        var slice = events.slice(0, 50);
        var payload = {
            events: slice.map(function (event) {
                return Object.assign({ event_id: event.event_id }, event.payload);
            })
        };

        return Promise.all(slice.map(function (event) {
            return DB.updateEvent(event.event_id, { state: 'syncing', attempts: (event.attempts || 0) + 1 });
        })).then(function () {
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': state.csrf || '',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            });
        }).then(function (response) {
            if (response.status === 401 || response.status === 419) {
                // Session gone or token stale: keep the data, try again after
                // the next successful bootstrap.
                return retryLater(slice, 60000).then(function () { return 0; });
            }
            if (response.status === 429) {
                return retryLater(slice, 300000).then(function () { return 0; });
            }
            if (!response.ok) {
                return retryLater(slice).then(function () { return 0; });
            }
            return response.json().then(function (data) {
                var handled = 0;
                (data.results || []).forEach(function (result) {
                    handled++;
                    if (result.outcome === 'accepted' || result.outcome === 'duplicate') {
                        DB.dropEvent(result.event_id);
                    } else {
                        // Permanently invalid: keep a record, stop retrying.
                        DB.updateEvent(result.event_id, { state: 'failed', reason: result.reason || 'REJECTED' });
                    }
                });
                return handled;
            });
        }).catch(function () {
            return retryLater(slice).then(function () { return 0; });
        });
    }

    function retryLater(events, fixedDelay) {
        return Promise.all(events.map(function (event) {
            var attempts = (event.attempts || 0) + 1;
            // Exponential backoff, capped, so a long outage does not turn into
            // a request every few seconds.
            var delay = fixedDelay || Math.min(15 * 60 * 1000, Math.pow(2, attempts) * 1000);
            return DB.updateEvent(event.event_id, {
                state: 'pending',
                next_attempt_at: Date.now() + delay
            });
        }));
    }

    function queueSummary() {
        if (!state.scope) { return Promise.resolve({ pending: 0, failed: 0 }); }
        return DB.allEvents(state.scope).then(function (rows) {
            return {
                pending: rows.filter(function (r) { return r.state === 'pending' || r.state === 'syncing'; }).length,
                failed: rows.filter(function (r) { return r.state === 'failed'; }).length
            };
        });
    }

    /* ----------------------------------------------------------- API */

    root.HeleXa = {
        state: state,
        onChange: function (fn) { listeners.push(fn); fn(state); },
        boot: function () {
            // Every caller shares one boot promise. Returning early for the
            // second caller would let it run before the account scope is known.
            if (bootPromise) { return bootPromise; }
            state.booted = true;
            watchConnection();
            bootPromise = bootstrap().then(function (result) {
                sync();
                return result;
            });
            return bootPromise;
        },
        probe: probe,
        sync: sync,
        queueStudy: queueStudy,
        queueStatus: queueStatus,
        queueHighlight: queueHighlight,
        queueSummary: queueSummary,
        purgeEverything: purgeEverything,
        scope: function () { return state.scope; },
        csrf: function () { return state.csrf; },
        isOnline: function () { return state.connection === 'online' || state.connection === 'syncing'; }
    };
})(window);
