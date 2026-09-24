/* =====================================================================
   HeleXa Med — IndexedDB layer.

   Loaded both in pages (<script>) and inside the service worker
   (importScripts), so it must not touch `window` or the DOM.

   Everything personal is keyed by an opaque per-account scope handed out by
   /api/session/state. That is what keeps user A's queued study time
   unreadable to user B on a shared device: a different account gets
   a different scope, and the boot sequence wipes the database outright when
   the scope changes.
   ===================================================================== */
(function (root) {
    'use strict';

    var DB_NAME = 'helexa';
    var DB_VERSION = 1;

    var STORES = {
        meta: 'meta',                   // app state: scope, csrf, timestamps
        courses: 'courses',             // offline-navigable course tree
        contents: 'contents',           // lesson metadata + lease
        blobs: 'content_blobs',         // lesson HTML, kept apart from metadata
        progress: 'progress',           // per-lesson status and reading position
        queue: 'sync_queue',            // events waiting to reach the server
        cacheMeta: 'cache_meta'         // bookkeeping for storage reporting
    };

    var dbPromise = null;

    function open() {
        if (dbPromise) { return dbPromise; }

        dbPromise = new Promise(function (resolve, reject) {
            var request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = function (event) {
                var db = request.result;
                var from = event.oldVersion;

                // Migrations are additive and ordered, so a device that has
                // been away for several releases upgrades through each step
                // instead of being wiped.
                if (from < 1) {
                    db.createObjectStore(STORES.meta, { keyPath: 'key' });

                    var courses = db.createObjectStore(STORES.courses, { keyPath: 'id' });
                    courses.createIndex('scope', 'scope', { unique: false });

                    var contents = db.createObjectStore(STORES.contents, { keyPath: 'id' });
                    contents.createIndex('scope', 'scope', { unique: false });
                    contents.createIndex('course', ['scope', 'course_uuid'], { unique: false });
                    contents.createIndex('status', ['scope', 'status'], { unique: false });

                    db.createObjectStore(STORES.blobs, { keyPath: 'id' });

                    var progress = db.createObjectStore(STORES.progress, { keyPath: 'id' });
                    progress.createIndex('scope', 'scope', { unique: false });

                    var queue = db.createObjectStore(STORES.queue, { keyPath: 'event_id' });
                    queue.createIndex('state', ['scope', 'state'], { unique: false });
                    queue.createIndex('scope', 'scope', { unique: false });

                    db.createObjectStore(STORES.cacheMeta, { keyPath: 'key' });
                }
                // Future versions append `if (from < 2) { ... }` here.
            };

            request.onsuccess = function () { resolve(request.result); };
            request.onerror = function () { reject(request.error); };
            request.onblocked = function () { reject(new Error('IDB_BLOCKED')); };
        });

        return dbPromise;
    }

    function tx(store, mode, run) {
        return open().then(function (db) {
            return new Promise(function (resolve, reject) {
                var transaction = db.transaction(store, mode);
                var result;
                transaction.oncomplete = function () { resolve(result); };
                transaction.onerror = function () { reject(transaction.error); };
                transaction.onabort = function () { reject(transaction.error); };
                try {
                    result = run(transaction.objectStore(store), function (value) { result = value; });
                } catch (error) {
                    reject(error);
                }
            });
        });
    }

    function request(req) {
        return new Promise(function (resolve, reject) {
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
    }

    function getAll(store, indexName, query) {
        return open().then(function (db) {
            return new Promise(function (resolve, reject) {
                var source = db.transaction(store, 'readonly').objectStore(store);
                if (indexName) { source = source.index(indexName); }
                var req = source.getAll(query);
                req.onsuccess = function () { resolve(req.result || []); };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function put(store, value) {
        return open().then(function (db) {
            return new Promise(function (resolve, reject) {
                var transaction = db.transaction(store, 'readwrite');
                transaction.objectStore(store).put(value);
                transaction.oncomplete = function () { resolve(value); };
                transaction.onerror = function () { reject(transaction.error); };
            });
        });
    }

    function get(store, key) {
        return open().then(function (db) {
            return request(db.transaction(store, 'readonly').objectStore(store).get(key));
        });
    }

    function remove(store, key) {
        return open().then(function (db) {
            return new Promise(function (resolve, reject) {
                var transaction = db.transaction(store, 'readwrite');
                transaction.objectStore(store).delete(key);
                transaction.oncomplete = function () { resolve(true); };
                transaction.onerror = function () { reject(transaction.error); };
            });
        });
    }

    function clearAll() {
        return open().then(function (db) {
            var names = Object.keys(STORES).map(function (k) { return STORES[k]; });
            return new Promise(function (resolve, reject) {
                var transaction = db.transaction(names, 'readwrite');
                names.forEach(function (name) { transaction.objectStore(name).clear(); });
                transaction.oncomplete = function () { resolve(true); };
                transaction.onerror = function () { reject(transaction.error); };
            });
        });
    }

    /* ----------------------------------------------------------- meta */

    function setMeta(key, value) { return put(STORES.meta, { key: key, value: value, updated_at: Date.now() }); }
    function getMeta(key) {
        return get(STORES.meta, key).then(function (row) { return row ? row.value : null; });
    }

    function key(scope, uuid) { return scope + ':' + uuid; }

    /**
     * Downloaded lessons were retired with the offline library. Devices that
     * saved some before the update still hold them, so the boot sequence calls
     * this once to wipe every store except the sync queue and the meta row.
     */
    function purgeLegacyContent() {
        var names = [STORES.courses, STORES.contents, STORES.blobs, STORES.progress, STORES.cacheMeta];
        return open().then(function (db) {
            return new Promise(function (resolve) {
                var transaction = db.transaction(names, 'readwrite');
                names.forEach(function (name) { transaction.objectStore(name).clear(); });
                transaction.oncomplete = function () { resolve(true); };
                transaction.onerror = function () { resolve(false); };
            });
        });
    }

    /* ---------------------------------------------------- sync queue */

    function uuidV4() {
        if (root.crypto && root.crypto.randomUUID) { return root.crypto.randomUUID(); }
        var bytes = new Uint8Array(16);
        root.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        var hex = Array.prototype.map.call(bytes, function (b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }

    function enqueue(scope, type, payload) {
        var event = {
            event_id: uuidV4(),
            scope: scope,
            type: type,
            payload: payload,
            state: 'pending',
            attempts: 0,
            next_attempt_at: 0,
            created_at: new Date().toISOString()
        };
        return put(STORES.queue, event).then(function () { return event; });
    }

    function pendingEvents(scope) {
        return getAll(STORES.queue, 'scope', scope).then(function (rows) {
            var now = Date.now();
            return rows.filter(function (row) {
                return row.state !== 'synced' && (row.next_attempt_at || 0) <= now;
            });
        });
    }

    function allEvents(scope) { return getAll(STORES.queue, 'scope', scope); }

    function updateEvent(eventId, patch) {
        return get(STORES.queue, eventId).then(function (row) {
            if (!row) { return null; }
            Object.keys(patch).forEach(function (k) { row[k] = patch[k]; });
            return put(STORES.queue, row);
        });
    }

    function dropEvent(eventId) { return remove(STORES.queue, eventId); }

    root.HeleXaDB = {
        STORES: STORES,
        version: DB_VERSION,
        open: open,
        clearAll: clearAll,
        setMeta: setMeta,
        getMeta: getMeta,
        purgeLegacyContent: purgeLegacyContent,
        enqueue: enqueue,
        pendingEvents: pendingEvents,
        allEvents: allEvents,
        updateEvent: updateEvent,
        dropEvent: dropEvent,
        uuidV4: uuidV4,
        key: key
    };
})(typeof self !== 'undefined' ? self : this);
