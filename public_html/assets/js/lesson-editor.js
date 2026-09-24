/* =====================================================================
   HeleXa Med — the درسنامه editor.

   A contenteditable page with a Word-like toolbar. It only ever produces
   what the server's RichText allow-list keeps (colours, highlights,
   alignment, sizes, lists, tables, callouts, images), and the server cleans
   it again on save — this file is convenience, not a control.
   ===================================================================== */
(function () {
    'use strict';

    var form = document.querySelector('[data-lesson-form]');
    if (!form) { return; }

    var ed      = form.querySelector('[data-le-editor]');
    var out     = form.querySelector('[data-le-output]');
    var source  = form.querySelector('[data-le-source]');
    var bar     = form.querySelector('[data-le-toolbar]');
    var count   = form.querySelector('[data-le-count]');
    var draftEl = form.querySelector('[data-le-draft]');
    var upload  = form.getAttribute('data-upload');
    var draftKey = form.getAttribute('data-draft-key');
    var token   = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var fa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };
    var saved = null;   // the selection, kept while a palette has focus
    var dirty = false;

    try { document.execCommand('styleWithCSS', false, true); } catch (e) {}
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}
    if (!ed.innerHTML.trim()) { ed.innerHTML = '<p><br></p>'; }

    /* ------------------------------------------------- selection keeping */
    function keep() {
        var s = window.getSelection();
        if (s && s.rangeCount && ed.contains(s.anchorNode)) { saved = s.getRangeAt(0).cloneRange(); }
    }
    function restore() {
        ed.focus();
        if (!saved) { return; }
        var s = window.getSelection();
        s.removeAllRanges();
        s.addRange(saved);
    }
    ed.addEventListener('keyup', keep);
    ed.addEventListener('mouseup', keep);
    ed.addEventListener('input', function () { keep(); changed(); });
    document.addEventListener('selectionchange', function () {
        if (document.activeElement === ed) { keep(); reflect(); }
    });

    function exec(cmd, arg) {
        restore();
        document.execCommand(cmd, false, arg === undefined ? null : arg);
        keep();
        changed();
    }

    /* ------------------------------------------------------- toolbar */
    bar.addEventListener('mousedown', function (e) {
        // Buttons must not steal the selection from the page.
        if (e.target.closest('button') && !e.target.closest('select')) { e.preventDefault(); }
    });

    bar.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b) { return; }
        if (b.hasAttribute('data-cmd')) {
            exec(b.getAttribute('data-cmd'), b.getAttribute('data-arg') || undefined);
        } else if (b.hasAttribute('data-open')) {
            var p = bar.querySelector('[data-palette="' + b.getAttribute('data-open') + '"]');
            var was = !p.hidden;
            closePalettes();
            p.hidden = was;
        } else if (b.hasAttribute('data-fore')) {
            exec('foreColor', b.getAttribute('data-fore'));
            bar.querySelector('[data-color-now]').style.background = b.getAttribute('data-fore');
            closePalettes();
        } else if (b.hasAttribute('data-hilite')) {
            var c = b.getAttribute('data-hilite');
            restore();
            if (!document.execCommand('hiliteColor', false, c)) { document.execCommand('backColor', false, c); }
            keep(); changed();
            if (c !== 'transparent') { bar.querySelector('[data-hl-now]').style.background = c; }
            closePalettes();
        } else if (b.hasAttribute('data-callout')) {
            callout(b.getAttribute('data-callout'));
            closePalettes();
        } else if (b.hasAttribute('data-table')) {
            table();
        } else if (b.hasAttribute('data-link')) {
            var url = window.prompt('نشانی پیوند (https://… یا /student/…):', 'https://');
            if (url && /^(https?:\/\/|\/student\/|mailto:)/.test(url)) { exec('createLink', url); }
        } else if (b.hasAttribute('data-source')) {
            toggleSource(b);
        } else if (b.hasAttribute('data-focus')) {
            form.classList.toggle('is-focus');
            b.classList.toggle('is-on');
        }
    });

    function closePalettes() { bar.querySelectorAll('[data-palette]').forEach(function (p) { p.hidden = true; }); }
    document.addEventListener('click', function (e) { if (!e.target.closest('.le-pick')) { closePalettes(); } });

    bar.querySelector('[data-block]').addEventListener('change', function (e) {
        exec('formatBlock', e.target.value === 'p' ? 'P' : e.target.value.toUpperCase());
    });

    bar.querySelector('[data-size]').addEventListener('change', function (e) {
        var size = e.target.value;
        e.target.value = '';
        if (!size) { return; }
        restore();
        var s = window.getSelection();
        if (!s.rangeCount || s.isCollapsed) { return; }
        var range = s.getRangeAt(0);
        var span = document.createElement('span');
        span.style.fontSize = size;
        span.appendChild(range.extractContents());
        range.insertNode(span);
        s.removeAllRanges();
        var r = document.createRange();
        r.selectNodeContents(span);
        s.addRange(r);
        keep(); changed();
    });

    /** Keeps the block <select> in step with where the caret is. */
    function reflect() {
        var s = window.getSelection();
        if (!s.rangeCount) { return; }
        var n = s.anchorNode;
        while (n && n !== ed && !(n.nodeType === 1 && /^(P|H2|H3|H4|BLOCKQUOTE)$/.test(n.tagName))) { n = n.parentNode; }
        var sel = bar.querySelector('[data-block]');
        sel.value = n && n !== ed ? n.tagName.toLowerCase() : 'p';
        bar.querySelectorAll('[data-cmd="bold"],[data-cmd="italic"],[data-cmd="underline"]').forEach(function (btn) {
            try { btn.classList.toggle('is-on', document.queryCommandState(btn.getAttribute('data-cmd'))); } catch (x) {}
        });
    }

    function insertHtml(html) {
        restore();
        document.execCommand('insertHTML', false, html);
        keep(); changed();
    }

    function callout(tone) {
        var s = window.getSelection();
        var text = s && s.rangeCount && !s.isCollapsed ? s.toString() : '';
        var esc = document.createElement('div');
        esc.textContent = text || 'متن نکته را بنویسید…';
        insertHtml('<div class="callout callout-' + tone + '"><p>' + esc.innerHTML + '</p></div><p><br></p>');
    }

    function table() {
        var spec = window.prompt('ابعاد جدول (سطر×ستون)، مثلاً 3x3:', '3x3');
        var m = spec && spec.replace(/[×*]/g, 'x').match(/^\s*(\d{1,2})\s*x\s*(\d{1,2})\s*$/);
        if (!m) { return; }
        var rows = Math.min(30, +m[1]), cols = Math.min(10, +m[2]), html = '<table><tbody>';
        for (var r = 0; r < rows; r++) {
            html += '<tr>';
            for (var c = 0; c < cols; c++) { html += r === 0 ? '<th>عنوان</th>' : '<td><br></td>'; }
            html += '</tr>';
        }
        insertHtml(html + '</tbody></table><p><br></p>');
    }

    /* ------------------------------------------------------- images */
    function sendImage(fd) {
        fd.append('_token', token);
        if (draftEl) { draftEl.textContent = 'در حال بارگذاری تصویر…'; }
        return fetch(upload, { method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (draftEl) { draftEl.textContent = ''; }
                if (!res.ok) { window.alert(res.message || 'بارگذاری نشد.'); return; }
                insertHtml('<figure><img src="' + res.url + '" alt=""><figcaption>توضیح تصویر</figcaption></figure><p><br></p>');
            })
            .catch(function () { window.alert('بارگذاری تصویر ناموفق بود.'); });
    }
    var imgInput = bar.querySelector('[data-image]');
    imgInput.addEventListener('change', function () {
        if (!imgInput.files.length) { return; }
        var fd = new FormData();
        fd.append('image', imgInput.files[0]);
        sendImage(fd);
        imgInput.value = '';
    });

    ed.addEventListener('paste', function (e) {
        var items = (e.clipboardData && e.clipboardData.items) || [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
                e.preventDefault();
                keep();
                var fd = new FormData();
                fd.append('image', items[i].getAsFile(), 'pasted.png');
                sendImage(fd);
                return;
            }
        }
        // Word and Google Docs paste their own HTML; it is cleaned on save.
        // Pasted data: images inside that HTML cannot be kept, so they go.
        var html = e.clipboardData && e.clipboardData.getData('text/html');
        if (html && /<img[^>]+src=["']?(data:|file:)/i.test(html)) {
            e.preventDefault();
            insertHtml(html.replace(/<img[^>]*>/gi, ''));
        }
    });

    ed.addEventListener('drop', function (e) {
        var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f && /^image\//.test(f.type)) {
            e.preventDefault();
            var fd = new FormData();
            fd.append('image', f);
            sendImage(fd);
        }
    });

    /* ---------------------------------------------- source, counting */
    function toggleSource(btn) {
        var on = source.hidden;
        if (on) {
            source.value = ed.innerHTML;
            source.hidden = false;
            ed.hidden = true;
        } else {
            ed.innerHTML = source.value;
            source.hidden = true;
            ed.hidden = false;
            changed();
        }
        btn.classList.toggle('is-on', on);
    }

    function changed() {
        dirty = true;
        var words = (ed.innerText || '').trim().split(/\s+/).filter(Boolean).length;
        if (count) { count.textContent = fa(words) + ' کلمه · حدود ' + fa(Math.max(1, Math.ceil(words / 180))) + ' دقیقه مطالعه'; }
        scheduleDraft();
    }

    /* A local draft, so a closed tab or an expired session loses nothing. */
    var draftTimer = null;
    function scheduleDraft() {
        window.clearTimeout(draftTimer);
        draftTimer = window.setTimeout(function () {
            try {
                localStorage.setItem(draftKey, JSON.stringify({ at: Date.now(), html: ed.innerHTML, title: form.title.value }));
                if (draftEl) { draftEl.textContent = 'پیش‌نویس محلی ذخیره شد'; }
            } catch (e) {}
        }, 1500);
    }
    try {
        var d = JSON.parse(localStorage.getItem(draftKey) || 'null');
        if (d && d.html && d.html !== ed.innerHTML && Date.now() - d.at < 7 * 86400000
            && window.confirm('یک نسخه ذخیره‌نشده از این درسنامه در این مرورگر هست. بازیابی شود؟')) {
            ed.innerHTML = d.html;
            if (d.title) { form.title.value = d.title; }
        }
    } catch (e) {}

    form.addEventListener('submit', function () {
        if (!source.hidden) { ed.innerHTML = source.value; }
        out.value = ed.innerHTML;
        dirty = false;
        try { localStorage.removeItem(draftKey); } catch (e) {}
    });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    ed.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); form.requestSubmit ? form.requestSubmit(form.querySelector('[name="stay"]')) : form.submit(); }
    });
    changed();
    dirty = false;
})();
