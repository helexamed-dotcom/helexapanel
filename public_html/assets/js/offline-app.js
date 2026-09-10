/* =====================================================================
   HeleXa Med — the offline shell application.

   Reads everything from IndexedDB, so it works with no server at all.
   When a connection is available it re-verifies every stored lesson against
   the server, which is how a revoked enrolment or a new content version
   reaches a device that has been away.
   ===================================================================== */
(function () {
    'use strict';

    var DB = window.HeleXaDB;
    var shell = document.querySelector('.offline-shell');
    var HEARTBEAT = Math.max(10, parseInt(shell.getAttribute('data-heartbeat'), 10) || 25);

    var els = {
        pill: document.getElementById('conn-pill'),
        library: document.getElementById('library'),
        empty: document.getElementById('empty-library'),
        used: document.getElementById('storage-used'),
        quota: document.getElementById('storage-quota'),
        syncCard: document.getElementById('sync-card'),
        syncStatus: document.getElementById('sync-status'),
        viewLibrary: document.getElementById('view-library'),
        viewReader: document.getElementById('view-reader'),
        frame: document.getElementById('reader-frame'),
        readerTitle: document.getElementById('reader-title'),
        readerTimer: document.getElementById('reader-timer'),
        readerLoading: document.getElementById('reader-loading'),
        heading: document.getElementById('offline-heading'),
        subtitle: document.getElementById('offline-subtitle')
    };

    var CONN = {
        checking:     ['conn-checking', 'در حال بررسی…'],
        online:       ['conn-online',   'آنلاین'],
        syncing:      ['conn-syncing',  'در حال همگام‌سازی'],
        reconnecting: ['conn-syncing',  'در حال اتصال مجدد'],
        offline:      ['conn-offline',  'آفلاین']
    };

    function fa(value) {
        return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
    }

    function humanBytes(bytes) {
        if (!bytes) { return fa('0') + ' کیلوبایت'; }
        var mb = bytes / 1048576;
        return fa(mb >= 1 ? mb.toFixed(1) + ' مگابایت' : Math.round(bytes / 1024) + ' کیلوبایت');
    }

    function daysLeft(iso) {
        if (!iso) { return null; }
        return Math.ceil((new Date(iso).getTime() - Date.now()) / 86400000);
    }

    /* ------------------------------------------------------- library */

    function renderLibrary() {
        var scope = window.HeleXa.scope();
        if (!scope) {
            els.library.innerHTML = '';
            els.empty.hidden = false;
            els.empty.querySelector('.empty').innerHTML =
                'برای دیدن محتوای آفلاین، یک بار به‌صورت آنلاین وارد حساب خود شوید.';
            return Promise.resolve();
        }

        return Promise.all([DB.listContents(scope), DB.listProgress(scope)]).then(function (data) {
            var contents = data[0];
            var progress = {};
            data[1].forEach(function (row) { progress[row.content_uuid] = row; });

            els.empty.hidden = contents.length > 0;
            els.library.innerHTML = '';

            var totalBytes = contents.reduce(function (sum, row) { return sum + (row.byte_size || 0); }, 0);
            els.used.textContent = humanBytes(totalBytes);

            var byCourse = {};
            contents.forEach(function (row) {
                byCourse[row.course_uuid] = byCourse[row.course_uuid] || { title: row.course_title, items: [], bytes: 0 };
                byCourse[row.course_uuid].items.push(row);
                byCourse[row.course_uuid].bytes += row.byte_size || 0;
            });

            Object.keys(byCourse).forEach(function (courseUuid) {
                els.library.appendChild(courseCard(courseUuid, byCourse[courseUuid], progress));
            });
        });
    }

    function courseCard(courseUuid, course, progress) {
        var card = document.createElement('div');
        card.className = 'card';

        var head = document.createElement('div');
        head.className = 'card-head';
        head.innerHTML =
            '<div><h3 class="card-title" style="margin:0;">' + escapeHtml(course.title) + '</h3>' +
            '<div class="leaf-meta">' + fa(course.items.length) + ' جزوه · ' + humanBytes(course.bytes) + '</div></div>';

        var remove = document.createElement('button');
        remove.className = 'btn btn-danger btn-sm';
        remove.type = 'button';
        remove.textContent = 'حذف این دوره از آفلاین';
        remove.addEventListener('click', function () {
            if (!window.confirm('همه جزوه‌های آفلاین این دوره از این دستگاه حذف شود؟')) { return; }
            var scope = window.HeleXa.scope();
            Promise.all(course.items.map(function (item) {
                return DB.removeContent(scope, item.content_uuid);
            })).then(refreshAll);
        });
        head.appendChild(remove);
        card.appendChild(head);

        var list = document.createElement('div');
        list.className = 'tree';

        course.items.forEach(function (item) {
            list.appendChild(lessonRow(item, progress[item.content_uuid]));
        });

        card.appendChild(list);
        return card;
    }

    function lessonRow(item, progressRow) {
        var row = document.createElement('div');
        row.className = 'tree-leaf';

        var left = document.createElement('div');
        left.style.cssText = 'min-width:0; flex:1;';

        var days = daysLeft(item.lease_expires_at);
        var leaseText = days === null ? '' :
            (days > 0 ? 'اعتبار آفلاین: ' + fa(days) + ' روز' : 'اعتبار آفلاین تمام شده');

        var studied = progressRow && progressRow.seconds
            ? ' · ' + fa(Math.round(progressRow.seconds / 60)) + ' دقیقه مطالعه'
            : '';

        left.innerHTML =
            '<div class="leaf-title">' + escapeHtml(item.title) + '</div>' +
            '<div class="leaf-meta">' + humanBytes(item.byte_size) + ' · ' + leaseText + studied + '</div>';

        var open = document.createElement('button');
        open.className = 'btn btn-sm ' + (days !== null && days <= 0 ? 'btn-ghost' : 'btn-primary');
        open.type = 'button';
        open.textContent = days !== null && days <= 0 ? 'نیازمند اتصال' : 'مطالعه';
        open.disabled = days !== null && days <= 0;
        open.addEventListener('click', function () { openReader(item); });

        var drop = document.createElement('button');
        drop.className = 'btn btn-ghost btn-sm';
        drop.type = 'button';
        drop.textContent = 'حذف';
        drop.addEventListener('click', function () {
            if (!window.confirm('این جزوه از حافظه دستگاه حذف شود؟')) { return; }
            DB.removeContent(window.HeleXa.scope(), item.content_uuid).then(refreshAll);
        });

        var actions = document.createElement('div');
        actions.className = 'row-actions';
        actions.appendChild(open);
        actions.appendChild(drop);

        row.appendChild(left);
        row.appendChild(actions);
        return row;
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    /* -------------------------------------------------------- reader */

    var reader = { uuid: null, startedAt: 0, seconds: 0, ticker: null, flusher: null };

    /* -------------------------------------------------------- highlights
       The stored lesson already contains whatever highlights existed at
       download time (the server seeds them into the page when it is fetched).
       Anything made while reading offline is queued here, exactly like an
       offline study event, and reconciled by the sync engine once a
       connection returns. There is no local list UI for these; the frame
       itself paints the marks, this side only persists the change. */
    var hlCounts = {};

    function paintHlCount(uuid) {
        var el = document.getElementById('reader-hl-count');
        if (!el) { return; }
        var count = hlCounts[uuid] || 0;
        el.textContent = count > 0 ? '✏️ ' + fa(String(count)) : '';
    }

    window.addEventListener('message', function (event) {
        if (!reader.uuid || event.source !== els.frame.contentWindow) { return; }
        var data = event.data || {};
        if (!data.type || data.type.indexOf('highlight-') !== 0) { return; }

        var scope = window.HeleXa.scope();
        if (!scope) { return; }

        if (data.type === 'highlight-create') {
            hlCounts[reader.uuid] = (hlCounts[reader.uuid] || 0) + 1;
            paintHlCount(reader.uuid);
            window.HeleXa.queueHighlight(reader.uuid, 'create', data.payload);
        } else if (data.type === 'highlight-delete') {
            hlCounts[reader.uuid] = Math.max(0, (hlCounts[reader.uuid] || 1) - 1);
            paintHlCount(reader.uuid);
            window.HeleXa.queueHighlight(reader.uuid, 'delete', data.payload);
        } else if (data.type === 'highlight-recolor') {
            window.HeleXa.queueHighlight(reader.uuid, 'recolor', data.payload);
        }

        if (window.HeleXa.isOnline()) { window.HeleXa.sync().then(refreshSyncCard); }
    });

    function openReader(item) {
        reader.uuid = item.content_uuid;
        reader.startedAt = Date.now();
        reader.seconds = 0;

        els.readerTitle.textContent = item.title;
        els.viewLibrary.hidden = true;
        els.viewReader.hidden = false;
        els.readerLoading.hidden = false;
        els.heading.textContent = item.title;
        els.subtitle.textContent = item.course_title || '';

        DB.getProgress(window.HeleXa.scope(), item.content_uuid).then(function (row) {
            paintTimer(row && row.seconds ? row.seconds : 0);
        });

        // Same URL as the online viewer. Online it streams from the server;
        // offline the service worker answers from the stored copy.
        paintHlCount(item.content_uuid);
        els.frame.src = '/content/' + encodeURIComponent(item.content_uuid) + '/stream';
        els.frame.addEventListener('load', function () { els.readerLoading.hidden = true; }, { once: true });

        reader.ticker = window.setInterval(function () {
            if (document.visibilityState !== 'visible') { return; }
            reader.seconds++;
            DB.getProgress(window.HeleXa.scope(), reader.uuid).then(function (row) {
                paintTimer((row && row.seconds ? row.seconds : 0) + reader.seconds);
            });
        }, 1000);

        // Flush in chunks so a crash or a closed tab loses at most one chunk.
        reader.flusher = window.setInterval(flushStudy, HEARTBEAT * 1000);
    }

    function paintTimer(totalSeconds) {
        var h = Math.floor(totalSeconds / 3600);
        var m = Math.floor((totalSeconds % 3600) / 60);
        var s = totalSeconds % 60;
        els.readerTimer.textContent = fa([h, m, s].map(function (n) {
            return String(n).padStart(2, '0');
        }).join(':'));
    }

    function flushStudy() {
        if (!reader.uuid || reader.seconds <= 0) { return Promise.resolve(); }

        var seconds = reader.seconds;
        var endedAt = Date.now();
        var startedAt = endedAt - seconds * 1000;
        reader.seconds = 0;

        var scope = window.HeleXa.scope();

        return DB.getProgress(scope, reader.uuid).then(function (row) {
            return DB.saveProgress(scope, reader.uuid, {
                seconds: (row && row.seconds ? row.seconds : 0) + seconds,
                last_read_at: new Date().toISOString()
            });
        }).then(function () {
            // Queued, never trusted: the server decides how much of this
            // counts when the queue is eventually drained.
            return window.HeleXa.queueStudy(reader.uuid, startedAt, endedAt, seconds);
        }).then(function () {
            return window.HeleXa.isOnline() ? window.HeleXa.sync() : null;
        }).then(refreshSyncCard);
    }

    function closeReader() {
        window.clearInterval(reader.ticker);
        window.clearInterval(reader.flusher);
        return flushStudy().then(function () {
            reader.uuid = null;
            els.frame.src = 'about:blank';
            els.viewReader.hidden = true;
            els.viewLibrary.hidden = false;
            els.heading.textContent = 'مطالعه آفلاین';
            els.subtitle.textContent = 'محتوای ذخیره‌شده روی این دستگاه';
            return refreshAll();
        });
    }

    document.getElementById('btn-reader-back').addEventListener('click', closeReader);

    document.querySelectorAll('.status-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!reader.uuid) { return; }
            var status = button.getAttribute('data-status');

            document.querySelectorAll('.status-btn').forEach(function (b) { b.classList.remove('is-on'); });
            button.classList.add('is-on');

            DB.saveProgress(window.HeleXa.scope(), reader.uuid, { status: status })
                .then(function () { return window.HeleXa.queueStatus(reader.uuid, status); })
                .then(function () { return window.HeleXa.isOnline() ? window.HeleXa.sync() : null; })
                .then(refreshSyncCard);
        });
    });

    window.addEventListener('pagehide', function () { flushStudy(); });

    /* ------------------------------------------------------- storage */

    function refreshStorage() {
        if (!navigator.storage || !navigator.storage.estimate) {
            els.quota.textContent = '';
            return Promise.resolve();
        }
        return navigator.storage.estimate().then(function (estimate) {
            if (!estimate || !estimate.quota) { els.quota.textContent = ''; return; }
            els.quota.textContent = 'سهمیه در دسترس مرورگر: ' + humanBytes(estimate.quota) +
                ' · استفاده‌شده: ' + humanBytes(estimate.usage || 0);
        }).catch(function () { els.quota.textContent = ''; });
    }

    function refreshSyncCard() {
        return window.HeleXa.queueSummary().then(function (summary) {
            var hasWork = summary.pending > 0 || summary.failed > 0;
            els.syncCard.hidden = !hasWork;
            if (!hasWork) { return; }

            var parts = [];
            if (summary.pending) { parts.push(fa(summary.pending) + ' مورد در انتظار ارسال'); }
            if (summary.failed) { parts.push(fa(summary.failed) + ' مورد ناموفق'); }
            els.syncStatus.textContent = parts.join(' · ') +
                (window.HeleXa.isOnline() ? '' : ' — پس از برقراری اتصال ارسال می‌شود.');
        });
    }

    /* -------------------------------------------------- verification */

    /**
     * Ask the server whether each stored lesson is still ours to read.
     * Revoked items are deleted from the device; changed versions are flagged.
     */
    function verifyAll() {
        var scope = window.HeleXa.scope();
        if (!scope || !window.HeleXa.isOnline()) { return Promise.resolve(); }

        return DB.listContents(scope).then(function (rows) {
            if (!rows.length) { return null; }

            return fetch('/api/offline/verify', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.HeleXa.csrf() || '',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ contents: rows.map(function (r) { return r.content_uuid; }) })
            }).then(function (response) {
                if (!response.ok) { return null; }
                return response.json();
            }).then(function (data) {
                if (!data || !data.ok) { return null; }

                return Promise.all(rows.map(function (row) {
                    var verdict = data.contents[row.content_uuid];
                    if (!verdict || verdict.status === 'revoked') {
                        return DB.removeContent(scope, row.content_uuid);
                    }
                    return DB.markContent(scope, row.content_uuid, {
                        lease_expires_at: verdict.lease.expires_at,
                        status: verdict.version && verdict.version !== row.version ? 'stale' : 'ready'
                    });
                }));
            });
        }).catch(function () { return null; });
    }

    /* ------------------------------------------------------------ go */

    function refreshAll() {
        return Promise.all([renderLibrary(), refreshStorage(), refreshSyncCard()]);
    }

    document.getElementById('btn-refresh-all').addEventListener('click', function () {
        window.HeleXa.probe(true)
            .then(verifyAll)
            .then(refreshAll)
            .then(function () { window.alert('بررسی به‌روزرسانی انجام شد.'); });
    });

    document.getElementById('btn-purge-all').addEventListener('click', function () {
        if (!window.confirm('همه محتوای آفلاین از این دستگاه حذف شود؟ محتوای روی سرور دست‌نخورده می‌ماند.')) {
            return;
        }
        var scope = window.HeleXa.scope();
        DB.listContents(scope).then(function (rows) {
            return Promise.all(rows.map(function (row) { return DB.removeContent(scope, row.content_uuid); }));
        }).then(refreshAll);
    });

    document.getElementById('btn-sync-now').addEventListener('click', function () {
        window.HeleXa.probe(true).then(window.HeleXa.sync).then(refreshSyncCard);
    });

    window.HeleXa.onChange(function (state) {
        var label = CONN[state.connection] || CONN.checking;
        els.pill.className = 'conn-pill ' + label[0];
        els.pill.querySelector('.conn-text').textContent = label[1];
        document.body.classList.toggle('is-offline', state.connection === 'offline');
    });

    window.HeleXa.boot()
        .then(verifyAll)
        .then(refreshAll);
})();
