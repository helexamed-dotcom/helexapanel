/* HeleXa Med — viewer shell.
   Bridges the sandboxed lesson frame to the server. Nothing here is a security
   control and nothing here decides how long the student studied: the browser
   only reports whether its tab is visible, and the server does the arithmetic
   against its own clock. */
(function () {
    'use strict';

    var script    = document.currentScript;
    var contentId = script.getAttribute('data-content');
    var interval  = parseInt(script.getAttribute('data-heartbeat'), 10) || 25;
    var tracking  = script.getAttribute('data-tracking') === '1';
    var csrf      = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var frame     = document.getElementById('lesson');
    var timerEl   = document.getElementById('study-timer');

    var serverSeconds = parseInt(script.getAttribute('data-studied'), 10) || 0;
    var displayed     = serverSeconds;
    var frameVisible  = true;
    var frameFocused  = true;
    var frameScroll   = 0;

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body || {})
        });
    }

    function paint(seconds) {
        if (!timerEl) { return; }
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        var text = [h, m, s].map(function (n) { return String(n).padStart(2, '0'); }).join(':');
        timerEl.textContent = text.replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
    }

    /* ------------------------------------------------------ loading overlay
       A multi-megabyte lesson takes a moment on mobile data. Showing the raw
       empty frame while it arrives reads as a broken page. */
    var overlay = document.getElementById('viewer-loading');

    function revealFrame() {
        if (frame) { frame.classList.add('is-ready'); }
        if (overlay && overlay.parentNode) {
            overlay.classList.add('is-hidden');
            window.setTimeout(function () {
                if (overlay.parentNode) { overlay.remove(); }
            }, 350);
        }
    }

    if (frame) {
        frame.addEventListener('load', revealFrame);
        // If the load event never fires (blocked, cached oddly, older browser),
        // show the frame anyway rather than leaving a spinner forever.
        window.setTimeout(revealFrame, 20000);
    } else {
        revealFrame();
    }

    /* ---------------------------------------------- messages from the frame */
    window.addEventListener('message', function (event) {
        // The frame runs on an opaque origin, so its origin string is "null";
        // matching the window object itself is the reliable check.
        if (!frame || event.source !== frame.contentWindow) { return; }

        var data = event.data;
        if (!data || data.source !== 'helexa-viewer') { return; }

        if (data.type === 'state' && data.contentId === contentId && data.state) {
            post('/content/' + encodeURIComponent(contentId) + '/state', { state: data.state })
                .catch(function () { /* retried on the next change */ });
            return;
        }

        if (data.type && data.type.indexOf('highlight-') === 0) {
            handleHighlight(data);
            return;
        }

        if (typeof data.visible === 'boolean') { frameVisible = data.visible; }
        if (typeof data.scroll === 'number' && isFinite(data.scroll)) { frameScroll = data.scroll; }
    });

    /* ------------------------------------------------------- status buttons */
    document.querySelectorAll('.status-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            var status = button.getAttribute('data-status');

            post('/content/' + encodeURIComponent(contentId) + '/status', { status: status })
                .then(function (r) { return r.json(); })
                .then(function (result) {
                    if (result && result.ok) { markStatus(button); }
                })
                .catch(function () {
                    // Offline: remember the choice and let the sync engine
                    // deliver it when the connection comes back.
                    if (window.HeleXa && window.HeleXa.queueStatus) {
                        window.HeleXa.queueStatus(contentId, status);
                        markStatus(button);
                    }
                });
        });
    });

    function markStatus(button) {
        document.querySelectorAll('.status-btn').forEach(function (b) { b.classList.remove('is-on'); });
        button.classList.add('is-on');
    }

    /* ----------------------------------------------------------- highlights
       The frame owns the DOM work; this side owns the network. When a request
       cannot go out, the change is queued so it reaches the server later
       instead of existing only on this screen. */
    var highlights = [];
    var frameReady = false;

    function base() {
        return '/content/' + encodeURIComponent(contentId) + '/highlights';
    }

    function handleHighlight(data) {
        var payload = data.payload || {};

        if (data.type === 'highlight-unanchored') {
            var note = document.getElementById('hl-note');
            if (note && payload.count) {
                note.textContent = faDigits(String(payload.count)) +
                    ' هایلایت روی متن فعلی پیدا نشد. احتمالاً این جزوه بعد از ثبت آن‌ها به‌روزرسانی شده است.';
            }
            return;
        }

        if (data.type === 'highlight-create') {
            highlights.push({
                uuid: payload.uuid, kind: payload.kind, color: payload.color, quote: payload.quote
            });
            paintList();
            post(base(), payload)
                .then(function (r) { if (!r.ok) { throw new Error('SAVE_FAILED'); } })
                .catch(function () { queue('create', payload); });
            return;
        }

        if (data.type === 'highlight-delete') {
            highlights = highlights.filter(function (h) { return h.uuid !== payload.uuid; });
            paintList();
            post(base() + '/' + encodeURIComponent(payload.uuid) + '/delete', {})
                .then(function (r) { if (!r.ok) { throw new Error('DELETE_FAILED'); } })
                .catch(function () { queue('delete', { uuid: payload.uuid }); });
            return;
        }

        if (data.type === 'highlight-recolor') {
            highlights.forEach(function (h) {
                if (h.uuid === payload.uuid) { h.color = payload.color; }
            });
            paintList();
            post(base() + '/' + encodeURIComponent(payload.uuid) + '/color', { color: payload.color })
                .then(function (r) { if (!r.ok) { throw new Error('COLOR_FAILED'); } })
                .catch(function () { queue('recolor', payload); });
        }
    }

    function queue(action, payload) {
        if (window.HeleXa && window.HeleXa.queueHighlight) {
            window.HeleXa.queueHighlight(contentId, action, payload);
        }
    }

    function faDigits(value) {
        return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
    }

    function paintList() {
        var counter = document.getElementById('hl-count');
        if (counter) { counter.textContent = faDigits(String(highlights.length)); }

        var list = document.getElementById('hl-list');
        if (!list) { return; }

        list.innerHTML = '';
        if (!highlights.length) {
            var empty = document.createElement('div');
            empty.className = 'empty';
            empty.textContent = 'هنوز چیزی هایلایت نکرده‌اید.';
            list.appendChild(empty);
            return;
        }

        highlights.slice().reverse().forEach(function (item) {
            var row = document.createElement('button');
            row.type = 'button';
            row.className = 'hl-row';
            row.setAttribute('data-color', item.color || 'yellow');
            row.textContent = item.kind === 'area'
                ? 'کادر روی تصویر'
                : (item.quote || '').slice(0, 120) || 'بدون متن';
            row.addEventListener('click', function () {
                if (!frame || !frame.contentWindow) { return; }
                frame.contentWindow.postMessage(
                    { source: 'helexa-shell', type: 'scroll-to', uuid: item.uuid }, '*'
                );
            });
            list.appendChild(row);
        });
    }

    function loadHighlights() {
        fetch(base(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.ok) { return; }
                highlights = (data.highlights || []).map(function (h) {
                    return { uuid: h.uuid, kind: h.kind, color: h.color, quote: h.quote };
                });
                paintList();
            })
            .catch(function () { /* offline: the seeded copy in the frame still shows */ });
    }

    function wireHighlightChrome() {
        var areaButton  = document.getElementById('btn-area-mode');
        var listButton  = document.getElementById('btn-highlights');
        var drawer      = document.getElementById('hl-drawer');
        var closeButton = document.getElementById('btn-hl-close');

        if (areaButton) {
            areaButton.addEventListener('click', function () {
                var on = areaButton.classList.toggle('is-on');
                areaButton.textContent = on ? 'پایان هایلایت تصویر' : 'هایلایت تصویر';
                if (frame && frame.contentWindow) {
                    frame.contentWindow.postMessage(
                        { source: 'helexa-shell', type: 'area-mode', on: on }, '*'
                    );
                }
            });
        }

        if (listButton && drawer) {
            listButton.addEventListener('click', function () { drawer.hidden = !drawer.hidden; });
        }
        if (closeButton && drawer) {
            closeButton.addEventListener('click', function () { drawer.hidden = true; });
        }

        if (listButton) { loadHighlights(); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireHighlightChrome);
    } else {
        wireHighlightChrome();
    }

    /* ------------------------------------------------------------ heartbeat */
    function isActive() {
        return document.visibilityState === 'visible' && frameVisible;
    }

    /* Time that could not reach the server is neither discarded nor credited
       locally. It is accumulated and queued as an event; the server decides
       how much of it counts when the queue is drained. */
    var offlinePending = 0;
    var offlineSince   = 0;

    function flushOfflineStudy() {
        if (!offlinePending || !window.HeleXa || !window.HeleXa.queueStudy) { return; }

        var seconds   = offlinePending;
        var endedAt   = Date.now();
        var startedAt = offlineSince || (endedAt - seconds * 1000);

        offlinePending = 0;
        offlineSince   = 0;

        window.HeleXa.queueStudy(contentId, startedAt, endedAt, seconds);
    }

    function beat() {
        if (!tracking) { return; }

        post('/content/' + encodeURIComponent(contentId) + '/beat', {
            visible: isActive(),
            focused: document.hasFocus() && frameFocused,
            scroll: frameScroll
        })
            .then(function (r) {
                if (!r.ok) { throw new Error('BEAT_FAILED'); }
                return r.json();
            })
            .then(function (result) {
                flushOfflineStudy();
                if (result && result.ok && typeof result.total === 'number') {
                    // The server total is authoritative; the local display is
                    // corrected to it on every beat.
                    serverSeconds = result.total;
                    displayed     = serverSeconds;
                    paint(displayed);
                }
            })
            .catch(function () {
                if (!isActive()) { return; }
                if (!offlineSince) { offlineSince = Date.now() - interval * 1000; }
                offlinePending += interval;
                // Flushed in small batches, so a closed tab loses very little.
                if (offlinePending >= interval * 4) { flushOfflineStudy(); }
            });
    }

    paint(displayed);

    if (tracking) {
        beat();                              // opens the study session
        setInterval(beat, interval * 1000);

        // Between beats the display ticks locally so the timer does not look
        // frozen. It is overwritten by the server value on the next beat.
        setInterval(function () {
            if (isActive()) { paint(++displayed); }
        }, 1000);

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') { beat(); }
        });

        window.addEventListener('pagehide', function () {
            flushOfflineStudy();

            var url  = '/content/' + encodeURIComponent(contentId) + '/end';
            var body = new Blob([JSON.stringify({ _token: csrf })], { type: 'application/json' });

            if (navigator.sendBeacon) {
                navigator.sendBeacon(url, body);
            } else {
                post(url, {});
            }
        });
    }
})();
