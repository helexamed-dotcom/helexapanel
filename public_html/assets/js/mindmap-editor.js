/* =====================================================================
   HeleXa Med — the admin's mind map editor: the toolbar, the inspector
   for the selected topic, saving (Ctrl+S, and quietly after changes),
   topic images and JSON / outline in and out. The canvas is mindmap.js.
   ===================================================================== */
(function () {
    'use strict';

    var root = document.querySelector('[data-editor]');
    if (!root || !window.HxMindmap) { return; }
    var boot = JSON.parse(root.querySelector('[data-boot]').textContent);
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var fa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };
    function toast(m, t) { if (window.HlxUI) { window.HlxUI.toast(m, t); } }
    var q = function (s) { return root.querySelector(s); };
    var stateEl = q('[data-state]');
    var dirty = false, saving = false, timer = null;
    var lessons = boot.lessons || {};
    var byTitle = {};
    Object.keys(lessons).forEach(function (u) { byTitle[lessons[u].title] = u; });

    var map = window.HxMindmap.mount(q('[data-canvas]'), {
        data: boot.root, theme: boot.theme, layout: boot.layout, editable: true, lessons: lessons,
        onChange: function () { markDirty(); },
        onSelect: function (node) { inspect(node); },
        onZoom: function (k) { q('[data-zoom]').textContent = fa(Math.round(k * 100)) + '٪'; }
    });
    map.focus();

    /* ------------------------------------------------ saving */
    function markDirty() {
        dirty = true;
        stateEl.textContent = 'تغییرات ذخیره نشده';
        stateEl.className = 'me-state is-dirty';
        window.clearTimeout(timer);
        timer = window.setTimeout(function () { save(true); }, 4000);
    }
    function meta(name) {
        var el = root.querySelector('[data-meta="' + name + '"]:checked') || root.querySelector('[data-meta="' + name + '"]:not([type="radio"])');
        return el ? el.value : '';
    }
    var tone = (q('[data-tones] .is-on') || {}).getAttribute ? q('[data-tones] .is-on').getAttribute('data-tone') : 'violet';
    function save(quiet) {
        if (saving) { return; }
        saving = true;
        stateEl.textContent = 'در حال ذخیره…';
        var body = {
            title: meta('title'), summary: meta('summary'), status: meta('status'), theme: meta('theme'), layout: meta('layout'),
            subject_id: +meta('subject_id') || 0, package_id: +meta('package_id') || 0, tone: tone, root: map.getData()
        };
        var tagBox = root.querySelector('[data-meta-tags]');
        if (tagBox) {
            body.tags = Array.prototype.map.call(tagBox.querySelectorAll('input[name="tags[]"]:checked'), function (i) { return +i.value; });
            var typed = tagBox.querySelector('input[name="new_tags"]');
            body.new_tags = typed ? typed.value : '';
            if (typed && typed.value) { typed.setAttribute('data-sent', '1'); }
        }
        fetch(root.getAttribute('data-save'), {
            method: 'POST', credentials: 'same-origin', body: JSON.stringify(body),
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (res) {
            saving = false;
            if (!res.ok) { stateEl.textContent = 'ذخیره نشد'; toast(res.message || 'ذخیره نشد.', 'bad'); return; }
            dirty = false;
            stateEl.textContent = 'ذخیره شد · ' + fa(res.count) + ' موضوع';
            stateEl.className = 'me-state is-ok';
            if (!quiet) { toast('نقشه ذخیره شد ✓', 'ok'); }
        }).catch(function () { saving = false; stateEl.textContent = 'اتصال برقرار نشد'; });
    }
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); save(false); }
    });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    root.querySelectorAll('[data-meta]').forEach(function (el) {
        el.addEventListener('change', function () {
            var name = el.getAttribute('data-meta');
            if (name === 'theme') { map.setTheme(el.value); }
            if (name === 'layout') { map.setLayout(el.value); }
            markDirty();
        });
    });
    var tagsEl = root.querySelector('[data-meta-tags]');
    if (tagsEl) { tagsEl.addEventListener('change', function (e) { if (!e.target.matches('[data-tp-filter]')) { markDirty(); } }); }
    root.querySelectorAll('[data-tone]').forEach(function (b) {
        b.addEventListener('click', function () {
            root.querySelectorAll('[data-tone]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
            tone = b.getAttribute('data-tone');
            markDirty();
        });
    });

    /* ------------------------------------------------ toolbar */
    var acts = {
        child: function () { map.addChild(); }, sibling: function () { map.addSibling(); }, remove: function () { map.remove(); },
        undo: map.undo, redo: map.redo, expand: map.expandAll, collapse: map.collapseAll, fit: map.fit,
        zin: map.zoomIn, zout: map.zoomOut, save: function () { save(false); },
        io: function () { q('[data-io]').hidden = false; }
    };
    root.querySelectorAll('[data-act]').forEach(function (b) {
        b.addEventListener('mousedown', function (e) { e.preventDefault(); });
        b.addEventListener('click', function () { acts[b.getAttribute('data-act')](); if (b.getAttribute('data-act') !== 'io') { map.focus(); } });
    });

    /* ------------------------------------------------ inspector */
    var current = null;
    var none = q('[data-none]'), some = q('[data-some]');
    var textIn = q('[data-f="text"]'), noteIn = q('[data-f="note"]');
    var img = q('[data-img]'), imgRemove = q('[data-img-remove]');
    function inspect(node) {
        current = node;
        none.hidden = !!node;
        some.hidden = !node;
        if (!node) { return; }
        textIn.value = node.text || '';
        noteIn.value = node.note || '';
        root.querySelectorAll('[data-color]').forEach(function (b) { b.classList.toggle('is-on', (node.color || '') === b.getAttribute('data-color')); });
        root.querySelectorAll('[data-shape]').forEach(function (b) { b.classList.toggle('is-on', (node.shape || '') === b.getAttribute('data-shape')); });
        root.querySelectorAll('[data-marker]').forEach(function (b) { b.classList.toggle('is-on', (node.marker || '') === b.getAttribute('data-marker')); });
        img.hidden = !node.image;
        imgRemove.hidden = !node.image;
        if (node.image) { img.src = '/media/mindmaps/' + node.image; }
        var l = node.lesson && lessons[node.lesson];
        q('[data-lesson-now]').hidden = !l;
        q('[data-lesson-title]').textContent = l ? l.title + (l.status !== 'published' ? ' (پیش‌نویس)' : '') : '';
        q('[data-lesson-pick]').value = '';
    }
    function patch(p) { if (current) { var id = current.id; map.update(id, p); current = map.node(id); inspect(current); } }
    var textTimer = null;
    textIn.addEventListener('input', function () {
        window.clearTimeout(textTimer);
        textTimer = window.setTimeout(function () { if (textIn.value.trim()) { patch({ text: textIn.value.trim() }); } }, 350);
    });
    noteIn.addEventListener('change', function () { patch({ note: noteIn.value.trim() }); });
    root.querySelectorAll('[data-color]').forEach(function (b) { b.addEventListener('click', function () { patch({ color: b.getAttribute('data-color') }); }); });
    root.querySelectorAll('[data-shape]').forEach(function (b) { b.addEventListener('click', function () { patch({ shape: b.getAttribute('data-shape') }); }); });
    root.querySelectorAll('[data-marker]').forEach(function (b) { b.addEventListener('click', function () { patch({ marker: b.getAttribute('data-marker') }); }); });
    q('[data-lesson-pick]').addEventListener('change', function (e) {
        var u = byTitle[e.target.value];
        if (u) { patch({ lesson: u }); } else if (e.target.value) { toast('این درسنامه پیدا نشد؛ از فهرست انتخاب کنید.', 'bad'); }
    });
    q('[data-lesson-clear]').addEventListener('click', function () { patch({ lesson: null }); });
    imgRemove.addEventListener('click', function () { patch({ image: null }); });

    function upload(fd) {
        fd.append('_token', token);
        stateEl.textContent = 'در حال بارگذاری تصویر…';
        return fetch(root.getAttribute('data-upload'), { method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); });
    }
    q('[data-img-input]').addEventListener('change', function (e) {
        var f = e.target.files && e.target.files[0];
        if (!f || !current) { return; }
        var fd = new FormData();
        fd.append('image', f);
        upload(fd).then(function (res) {
            if (!res.ok) { toast(res.message || 'بارگذاری نشد.', 'bad'); return; }
            patch({ image: res.name });
        });
        e.target.value = '';
    });
    // paste an image while a topic is selected
    document.addEventListener('paste', function (e) {
        if (!current || document.activeElement && /INPUT|TEXTAREA/.test(document.activeElement.tagName)) { return; }
        var items = (e.clipboardData && e.clipboardData.items) || [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
                e.preventDefault();
                var fd = new FormData();
                fd.append('image', items[i].getAsFile(), 'pasted.png');
                upload(fd).then(function (res) { if (res.ok) { patch({ image: res.name }); } else { toast(res.message, 'bad'); } });
                return;
            }
        }
    });

    /* ------------------------------------------------ JSON / outline */
    var io = q('[data-io]');
    q('[data-io-close]').addEventListener('click', function () { io.hidden = true; map.focus(); });
    io.addEventListener('click', function (e) { if (e.target === io) { io.hidden = true; } });
    root.querySelectorAll('[data-io-tab]').forEach(function (b) {
        b.addEventListener('click', function () {
            root.querySelectorAll('[data-io-tab]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
            root.querySelectorAll('[data-io-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-io-pane') !== b.getAttribute('data-io-tab'); });
        });
    });
    q('[data-copy-json]').addEventListener('click', function () {
        var doc = { format: 'helexa.mindmap', version: 1, title: meta('title'), theme: meta('theme'), layout: meta('layout'), root: map.getData() };
        navigator.clipboard.writeText(JSON.stringify(doc, null, 2)).then(function () { toast('JSON کپی شد ✓', 'ok'); });
    });
    function outline(text) {
        var lines = text.replace(/\t/g, '    ').split(/\r?\n/).filter(function (l) { return l.trim(); });
        if (!lines.length) { return null; }
        var items = lines.map(function (l) {
            var indent = l.length - l.replace(/^ +/, '').length, body = l.trim(), h = body.match(/^(#{1,6})\s+/);
            if (h) { body = body.slice(h[0].length); }
            body = body.replace(/^([-*+•·]|\d+[.)])\s+/, '');
            return { level: h ? (h[1].length - 1) * 100 : 1000 + indent, text: body };
        });
        var top = { text: items[0].text, children: [] }, stack = [{ level: -1, node: top }];
        items.slice(1).forEach(function (it) {
            var n = { text: it.text, children: [] };
            while (stack.length > 1 && stack[stack.length - 1].level >= it.level) { stack.pop(); }
            stack[stack.length - 1].node.children.push(n);
            stack.push({ level: it.level, node: n });
        });
        return top;
    }
    function applyText(text) {
        var data = null;
        try {
            var j = JSON.parse(text);
            data = j.root || (j.text !== undefined ? j : null);
            if (j.theme) { var t = root.querySelector('[data-meta="theme"]'); t.value = j.theme; map.setTheme(j.theme); }
            if (j.layout) { var l = root.querySelector('[data-meta="layout"]'); l.value = j.layout; map.setLayout(j.layout); }
        } catch (x) { data = outline(text); }
        if (!data) { toast('متن قابل تبدیل به نقشه نبود.', 'bad'); return; }
        map.setData(data);
        io.hidden = true;
        map.fit();
        toast('نقشه جایگزین شد: ' + fa(map.count()) + ' موضوع', 'ok');
    }
    q('[data-io-apply]').addEventListener('click', function () { applyText(q('[data-io-text]').value); });
    q('[data-io-file]').addEventListener('change', function (e) {
        var f = e.target.files && e.target.files[0];
        if (!f) { return; }
        f.text().then(applyText);
        e.target.value = '';
    });
})();
