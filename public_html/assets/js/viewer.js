/* HeleXa Med — viewer shell.
   Bridges the sandboxed lesson frame to the server. Nothing here is a security
   control and nothing here decides how long the student studied: the browser
   only reports whether its tab is visible, and the server does the arithmetic
   against its own clock.

   The toolbar lives on this side and the DOM work happens inside the frame, so
   every tool change crosses as a postMessage. */
(function () {
    'use strict';

    var script    = document.currentScript;
    var contentId = script.getAttribute('data-content');
    var interval  = parseInt(script.getAttribute('data-heartbeat'), 10) || 25;
    var tracking  = script.getAttribute('data-tracking') === '1';
    var hlEnabled = script.getAttribute('data-highlight') === '1';
    var csrf      = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var frame     = document.getElementById('lesson');
    var timerEl   = document.getElementById('study-timer');

    var serverSeconds = parseInt(script.getAttribute('data-studied'), 10) || 0;
    var displayed     = serverSeconds;
    var frameVisible  = true;
    var frameFocused  = true;
    var frameScroll   = 0;

    var highlights = [];
    var tool       = 'off';
    var penColor   = 'yellow';

    // Pen and notes (viewer-ink.js) listen to frame messages through here.
    var inkEnabled   = script.getAttribute('data-ink') === '1';
    var notesEnabled = script.getAttribute('data-notes') === '1';
    var listeners    = {};
    var loadHooks    = [];
    var frameLoaded  = false;

    try {
        var savedColor = localStorage.getItem('helexa_hl_color');
        if (savedColor) { penColor = savedColor; }
    } catch (e) { /* private mode: the default colour applies */ }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body || {})
        });
    }

    function faDigits(value) {
        return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
    }

    function paint(seconds) {
        if (!timerEl) { return; }
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        var text = [h, m, s].map(function (n) { return String(n).padStart(2, '0'); }).join(':');
        timerEl.textContent = faDigits(text);
    }

    /* ------------------------------------------------------------- toast */
    var toastEl = document.querySelector('[data-toast]');
    var toastTimer = null;

    function toast(message) {
        if (!toastEl) { return; }
        toastEl.textContent = message;
        toastEl.hidden = false;
        if (toastTimer) { clearTimeout(toastTimer); }
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, 3200);
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

    function toFrame(message) {
        if (frame && frame.contentWindow) {
            frame.contentWindow.postMessage(message, '*');
        }
    }

    if (frame) {
        frame.addEventListener('load', function () {
            revealFrame();
            frameLoaded = true;
            // The frame boots in reading mode; hand it back whatever the
            // toolbar is currently set to.
            toFrame({ source: 'helexa-shell', type: 'color', color: penColor });
            toFrame({ source: 'helexa-shell', type: 'tool', tool: tool });
            loadHooks.forEach(function (fn) { try { fn(); } catch (e) { /* one hook must not stop the rest */ } });
        });
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

        if (data.type && listeners[data.type]) {
            listeners[data.type].forEach(function (fn) { fn(data.payload || {}, data); });
            return;
        }

        if (data.type && data.type.indexOf('highlight-') === 0) {
            handleHighlight(data);
            return;
        }

        // The frame tells us when there is a passage waiting to be marked, so
        // the pen can present itself as "highlight this" rather than as a mode.
        if (data.type === 'selection') {
            setSelectionReady(!!data.has);
            return;
        }

        if (data.type === 'applied') {
            if (data.applied) { toast(data.tool === 'eraser' ? 'هایلایت برداشته شد.' : 'هایلایت شد.'); }
            setSelectionReady(false);
            return;
        }

        if (typeof data.visible === 'boolean') { frameVisible = data.visible; }
        if (typeof data.scroll === 'number' && isFinite(data.scroll)) { frameScroll = data.scroll; }
    });

    /* ------------------------------------------------------- status buttons */
    document.querySelectorAll('[data-status]').forEach(function (button) {
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
        document.querySelectorAll('[data-status]').forEach(function (b) { b.classList.remove('is-on'); });
        button.classList.add('is-on');
        closeMenu();
    }

    /* ----------------------------------------------------------- highlights
       The frame owns the DOM work; this side owns the network. When a request
       cannot go out, the change is queued so it reaches the server later
       instead of existing only on this screen. */
    function base() {
        return '/content/' + encodeURIComponent(contentId) + '/highlights';
    }

    function handleHighlight(data) {
        var payload = data.payload || {};

        if (data.type === 'highlight-state') {
            setHistory(!!payload.canUndo, !!payload.canRedo);
            return;
        }

        if (data.type === 'highlight-unanchored') {
            var note = document.getElementById('hl-note');
            if (note && payload.count) {
                note.textContent = faDigits(String(payload.count)) +
                    ' هایلایت روی متن فعلی پیدا نشد. احتمالاً این جزوه بعد از ثبت آن‌ها به‌روزرسانی شده است.';
            }
            return;
        }

        if (data.type === 'highlight-create') {
            highlights = highlights.filter(function (h) { return h.uuid !== payload.uuid; });
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
        } else {
            toast('این تغییر ذخیره نشد. اتصال اینترنت را بررسی کنید.');
        }
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
            var row = document.createElement('div');
            row.className = 'hl-row';
            row.setAttribute('data-color', item.color || 'yellow');

            var jump = document.createElement('button');
            jump.type = 'button';
            jump.className = 'hl-row-text';
            jump.textContent = (item.quote || '').slice(0, 160) || 'بدون متن';
            jump.addEventListener('click', function () {
                toFrame({ source: 'helexa-shell', type: 'scroll-to', uuid: item.uuid });
                if (window.matchMedia('(max-width: 720px)').matches) { closeDrawer(); }
            });

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'hl-row-del';
            remove.setAttribute('aria-label', 'حذف این هایلایت');
            remove.title = 'حذف این هایلایت';
            remove.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"' +
                ' stroke-width="1.9" stroke-linecap="round"><path d="m6.6 6.6 10.8 10.8M17.4 6.6 6.6 17.4"/></svg>';
            remove.addEventListener('click', function () {
                toFrame({ source: 'helexa-shell', type: 'erase', uuid: item.uuid });
            });

            row.appendChild(jump);
            row.appendChild(remove);
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

    /* ------------------------------------------------------------- toolbar */

    var selectionReady = false;

    /**
     * Marks the toolbar as holding a passage that is waiting to be acted on.
     * The pen and the eraser both light up, because either is a sensible
     * thing to do to a selection, and a short hint says what a press will do.
     */
    function setSelectionReady(ready) {
        if (selectionReady === ready) { return; }
        selectionReady = ready;

        document.body.classList.toggle('has-selection', ready);
        document.querySelectorAll('[data-tool]').forEach(function (button) {
            button.classList.toggle('is-ready', ready);
        });

        var hint = document.querySelector('[data-selection-hint]');
        if (hint) { hint.hidden = !ready; }
    }

    function setTool(next) {
        tool = next;
        document.querySelectorAll('[data-tool]').forEach(function (button) {
            var on = button.getAttribute('data-tool') === tool;
            button.classList.toggle('is-on', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });

        var palette = document.querySelector('[data-palette]');
        if (palette) { palette.hidden = tool !== 'pen'; }
        var inkPalette = document.querySelector('[data-ink-palette]');
        if (inkPalette) { inkPalette.hidden = tool !== 'draw'; }
        document.body.classList.toggle('is-drawing', tool === 'draw');

        document.body.classList.toggle('is-erasing', tool === 'eraser');
        toFrame({ source: 'helexa-shell', type: 'tool', tool: tool });
    }

    function setColor(color) {
        penColor = color;
        try { localStorage.setItem('helexa_hl_color', color); } catch (e) {}

        document.querySelectorAll('.swatch').forEach(function (swatch) {
            swatch.classList.toggle('is-on', swatch.getAttribute('data-color') === color);
        });
        var dot = document.querySelector('[data-tool-dot]');
        if (dot) { dot.setAttribute('data-color', color); }

        toFrame({ source: 'helexa-shell', type: 'color', color: color });
    }

    function setHistory(canUndo, canRedo) {
        var undoButton = document.querySelector('[data-undo]');
        var redoButton = document.querySelector('[data-redo]');
        if (undoButton) { undoButton.disabled = !canUndo; }
        if (redoButton) { redoButton.disabled = !canRedo; }
    }

    /* --------------------------------------------------------- overflow menu */
    var menuWrap    = document.querySelector('[data-vmenu]');
    var menuTrigger = document.querySelector('[data-vmenu-trigger]');
    var menuPanel   = document.querySelector('[data-vmenu-panel]');

    function closeMenu() {
        if (!menuPanel || menuPanel.hidden) { return; }
        menuPanel.hidden = true;
        if (menuTrigger) { menuTrigger.setAttribute('aria-expanded', 'false'); }
    }

    function toggleMenu() {
        if (!menuPanel) { return; }
        var open = menuPanel.hidden;
        menuPanel.hidden = !open;
        if (menuTrigger) { menuTrigger.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }

    /* ------------------------------------------------------ highlight drawer */
    function closeDrawer() {
        var drawer = document.getElementById('hl-drawer');
        if (drawer) { drawer.hidden = true; }
    }

    function wireChrome() {
        if (menuTrigger && menuPanel) {
            menuTrigger.addEventListener('click', function (event) {
                event.stopPropagation();
                toggleMenu();
            });
            menuPanel.addEventListener('click', function (event) { event.stopPropagation(); });
            document.addEventListener('click', function (event) {
                if (menuWrap && !menuWrap.contains(event.target)) { closeMenu(); }
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') { return; }
            closeMenu();
            closeDrawer();
        });

        if (!hlEnabled && !inkEnabled && !notesEnabled) { return; }

        document.querySelectorAll('[data-tool]').forEach(function (button) {
            button.addEventListener('click', function () {
                var wanted = button.getAttribute('data-tool');

                // With a passage already selected, the button marks it. That
                // is the order the gesture happens in on a phone: select with
                // the handles, then reach for the tool. The frame answers with
                // "applied", and only if it had nothing does this fall through
                // to arming the tool.
                if (selectionReady && (wanted === 'pen' || wanted === 'eraser')) {
                    toFrame({ source: 'helexa-shell', type: 'apply', tool: wanted });
                    return;
                }

                // Pressing the active tool again returns to plain reading, so
                // text can be selected without leaving a mark behind.
                setTool(tool === wanted ? 'off' : wanted);
            });
        });

        document.querySelectorAll('.swatch').forEach(function (swatch) {
            swatch.addEventListener('click', function () {
                setColor(swatch.getAttribute('data-color'));
                if (tool !== 'pen') { setTool('pen'); }
            });
        });

        var undoButton = document.querySelector('[data-undo]');
        var redoButton = document.querySelector('[data-redo]');
        if (undoButton) {
            undoButton.addEventListener('click', function () {
                toFrame({ source: 'helexa-shell', type: 'undo' });
            });
        }
        if (redoButton) {
            redoButton.addEventListener('click', function () {
                toFrame({ source: 'helexa-shell', type: 'redo' });
            });
        }

        var listButton  = document.getElementById('btn-highlights');
        var drawer      = document.getElementById('hl-drawer');
        var closeButton = document.getElementById('btn-hl-close');

        if (listButton && drawer) {
            listButton.addEventListener('click', function () {
                drawer.hidden = !drawer.hidden;
                closeMenu();
            });
        }
        if (closeButton) { closeButton.addEventListener('click', closeDrawer); }

        setColor(penColor);
        setHistory(false, false);
        if (hlEnabled) { loadHighlights(); }
    }

    window.HlxViewer = {
        contentId: contentId,
        csrf: csrf,
        toFrame: toFrame,
        toast: toast,
        closeMenu: function () { closeMenu(); },
        tool: function () { return tool; },
        setTool: function (next) { setTool(next); },
        on: function (type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
        onFrameLoad: function (fn) { if (frameLoaded) { fn(); } else { loadHooks.push(fn); } }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireChrome);
    } else {
        wireChrome();
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
