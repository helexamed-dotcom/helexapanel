/* HeleXa — pen and notes in the lesson viewer.
 *
 * The drawing itself happens inside the lesson frame (it owns the page); this
 * side owns the toolbar, the network and the note panel. Loaded after
 * viewer.js, which exposes window.HlxViewer.
 */
(function () {
    'use strict';

    var V = window.HlxViewer;
    var script = document.currentScript;
    if (!V || !script) { return; }

    var contentId = V.contentId;
    var inkOn = script.getAttribute('data-ink') === '1';
    var notesOn = script.getAttribute('data-notes') === '1';
    var pdfjs = script.getAttribute('data-pdfjs') === '1';
    var PENDING_KEY = 'helexa_ink_pending_' + contentId;

    function load(key) { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; } }
    function save(key, value) {
        try {
            if (value === null) { localStorage.removeItem(key); } else { localStorage.setItem(key, JSON.stringify(value)); }
        } catch (e) { /* storage full or private mode */ }
    }
    function shell(message) {
        message.source = 'helexa-shell';
        V.toFrame(message);
    }
    function uuid() {
        if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
        var b = new Uint8Array(16);
        crypto.getRandomValues(b);
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        var x = Array.prototype.map.call(b, function (n) { return ('0' + n.toString(16)).slice(-2); }).join('');
        return x.slice(0, 8) + '-' + x.slice(8, 12) + '-' + x.slice(12, 16) + '-' + x.slice(16, 20) + '-' + x.slice(20);
    }
    function json(method, url, body) {
        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json', 'Accept': 'application/json',
                'X-CSRF-Token': V.csrf, 'X-Requested-With': 'XMLHttpRequest'
            },
            body: body === undefined ? undefined : JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                if (!r.ok || data.ok === false) {
                    var err = new Error(data.message || 'REQUEST_FAILED');
                    err.status = r.status;
                    throw err;
                }
                return data;
            });
        });
    }

    /* ================================================================ pen */

    var pen = Object.assign({ mode: 'pen', color: '#2563eb', size: 3, finger: true, fingerChosen: false }, load('helexa_ink') || {});

    function sendPen() {
        shell({ type: 'ink-options', options: { mode: pen.mode, color: pen.color, size: pen.size, finger: !!pen.finger } });
    }

    function paintPen() {
        document.querySelectorAll('[data-ink-mode]').forEach(function (b) {
            var on = b.getAttribute('data-ink-mode') === pen.mode;
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        document.querySelectorAll('[data-ink-color]').forEach(function (b) {
            b.classList.toggle('is-on', b.getAttribute('data-ink-color') === pen.color);
        });
        document.querySelectorAll('[data-ink-size]').forEach(function (b) {
            b.classList.toggle('is-on', +b.getAttribute('data-ink-size') === +pen.size);
        });
        document.querySelectorAll('[data-ink-finger]').forEach(function (b) {
            b.classList.toggle('is-on', !!pen.finger);
            b.setAttribute('aria-pressed', pen.finger ? 'true' : 'false');
        });
        var dot = document.querySelector('[data-draw-dot]');
        if (dot) { dot.style.background = pen.mode === 'eraser' ? 'transparent' : pen.color; }
    }

    function setPen(patch) {
        Object.keys(patch).forEach(function (k) { pen[k] = patch[k]; });
        save('helexa_ink', pen);
        paintPen();
        sendPen();
    }

    var saving = false, queued = null;

    function saveInk(strokes) {
        if (saving) { queued = strokes; return; }
        saving = true;
        json('POST', '/content/' + encodeURIComponent(contentId) + '/ink', { strokes: strokes })
            .then(function () {
                save(PENDING_KEY, null);
            })
            .catch(function (err) {
                if (err.status === 413) {
                    V.toast(err.message || 'حجم نوشته‌ها زیاد است.');
                    return;
                }
                // Kept on this device and sent again when the connection returns.
                save(PENDING_KEY, { at: Date.now(), strokes: strokes });
                V.toast('نوشته‌ها روی همین دستگاه نگه داشته شد و بعداً ذخیره می‌شود.');
            })
            .then(function () {
                saving = false;
                if (queued) { var next = queued; queued = null; saveInk(next); }
            });
    }

    function retryPending() {
        var pending = load(PENDING_KEY);
        if (pending && pending.strokes) {
            shell({ type: 'ink-load', strokes: pending.strokes });
            saveInk(pending.strokes);
        }
    }

    if (inkOn) {
        document.querySelectorAll('[data-ink-mode]').forEach(function (b) {
            b.addEventListener('click', function () { setPen({ mode: b.getAttribute('data-ink-mode') }); });
        });
        document.querySelectorAll('[data-ink-color]').forEach(function (b) {
            b.addEventListener('click', function () {
                setPen({ color: b.getAttribute('data-ink-color'), mode: pen.mode === 'eraser' ? 'pen' : pen.mode });
            });
        });
        document.querySelectorAll('[data-ink-size]').forEach(function (b) {
            b.addEventListener('click', function () { setPen({ size: +b.getAttribute('data-ink-size') }); });
        });
        document.querySelectorAll('[data-ink-finger]').forEach(function (b) {
            b.addEventListener('click', function () {
                setPen({ finger: !pen.finger, fingerChosen: true });
                V.toast(pen.finger ? 'انگشت هم می‌نویسد.' : 'با انگشت صفحه جابه‌جا می‌شود؛ با قلم بنویسید.');
            });
        });
        document.querySelectorAll('[data-ink-clear]').forEach(function (b) {
            b.addEventListener('click', function () {
                if (window.confirm('همه نوشته‌های قلم روی این جزوه پاک شود؟ (با واگرد برمی‌گردد)')) {
                    if (V.tool() !== 'draw') { V.setTool('draw'); }
                    shell({ type: 'ink-clear' });
                }
                V.closeMenu();
            });
        });

        V.on('ink-save', function (payload) { saveInk(payload.strokes || []); });
        V.on('pen-detected', function () {
            if (!pen.fingerChosen && pen.finger) {
                setPen({ finger: false });
                V.toast('قلم شناسایی شد ✍️ حالا با انگشت صفحه را جابه‌جا کنید.');
            }
        });
        V.onFrameLoad(function () { sendPen(); retryPending(); });
        window.addEventListener('online', retryPending);
        paintPen();
    }

    /* ============================================================== notes */

    if (!notesOn) { return; }

    var sheet = document.querySelector('[data-note-sheet]');
    var host = document.querySelector('[data-note-host]');
    var scrim = document.querySelector('[data-note-scrim]');
    var editor = null;
    var lastColor = load('helexa_note_color') || 'yellow';

    function closeNote() {
        if (editor) { editor.destroy(); editor = null; }
        if (sheet) { sheet.hidden = true; }
        if (scrim) { scrim.hidden = true; }
        document.body.classList.remove('note-open');
    }

    function openNote(id, fresh) {
        if (!sheet || !host || !window.HlxNotes) { window.open('/student/notes/' + encodeURIComponent(id), '_blank'); return; }
        if (editor && editor.uuid === id) { return; }
        closeNote();
        sheet.hidden = false;
        if (scrim) { scrim.hidden = false; }
        document.body.classList.add('note-open');
        editor = new window.HlxNotes.Editor(host, {
            uuid: id,
            compact: true,
            pdfjs: pdfjs,
            scroller: host,
            focusTitle: !!fresh,
            onClose: closeNote,
            onMeta: function (meta) {
                shell({ type: 'note-update', uuid: meta.uuid, title: meta.title, color: meta.color });
                if (meta.color) { lastColor = meta.color; save('helexa_note_color', meta.color); }
            },
            onDeleted: function (gone) {
                shell({ type: 'note-remove', uuid: gone });
                closeNote();
                V.toast('یادداشت حذف شد.');
            }
        });
    }

    document.querySelectorAll('[data-note-add]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (V.tool() === 'draw') { V.setTool('off'); }
            shell({ type: 'note-add', uuid: uuid(), color: lastColor });
            V.closeMenu();
        });
    });

    V.on('note-created', function (p) {
        json('POST', '/student/notes', {
            uuid: p.uuid, content_uuid: contentId, x: p.x, y: p.y, w: p.w, color: p.color
        }).then(function (data) {
            if (data.uuid !== p.uuid) {
                shell({ type: 'note-remove', uuid: p.uuid });
                return;
            }
            V.toast('یادداشت اضافه شد؛ دکمه‌اش را هر جا خواستید بکشید.');
            openNote(p.uuid, true);
        }).catch(function (err) {
            shell({ type: 'note-remove', uuid: p.uuid });
            V.toast(err.message && err.message !== 'REQUEST_FAILED' ? err.message : 'یادداشت ساخته نشد؛ اتصال را بررسی کنید.');
        });
    });

    V.on('note-open', function (p) { openNote(p.uuid, false); });

    V.on('note-move', function (p) {
        json('POST', '/student/notes/' + encodeURIComponent(p.uuid), { x: p.x, y: p.y, w: p.w })
            .catch(function () { V.toast('جای یادداشت ذخیره نشد.'); });
    });

    if (scrim) { scrim.addEventListener('click', closeNote); }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && editor) { closeNote(); }
    });
    window.addEventListener('pagehide', function () { if (editor) { editor.flush(true); } });
})();
