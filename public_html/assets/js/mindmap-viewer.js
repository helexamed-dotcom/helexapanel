/* =====================================================================
   HeleXa Med — the student's mind map viewer: the canvas from
   mindmap.js read-only, a card for the topic you tap (its note, image
   and درسنامه), search, and «مرور کردم».
   ===================================================================== */
(function () {
    'use strict';

    var app = document.querySelector('[data-viewer-app]');
    if (!app || !window.HxMindmap) { return; }
    var boot = JSON.parse(app.querySelector('[data-boot]').textContent);
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var fa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };
    var q = function (s) { return app.querySelector(s); };
    var lessons = boot.lessons || {};
    var card = q('[data-card]');

    var map = window.HxMindmap.mount(q('[data-canvas]'), {
        data: boot.root, theme: boot.theme, layout: boot.layout, editable: false, lessons: lessons,
        onSelect: show,
        onZoom: function (k) { q('[data-zoom]').textContent = fa(Math.round(k * 100)) + '٪'; }
    });
    map.focus();

    function show(node) {
        if (!node || !(node.note || node.image || (node.lesson && lessons[node.lesson]))) { card.hidden = true; return; }
        q('[data-card-title]').textContent = (node.marker ? node.marker + ' ' : '') + node.text;
        var img = q('[data-card-img]');
        img.hidden = !node.image;
        if (node.image) { img.src = '/media/mindmaps/' + node.image; }
        var note = q('[data-card-note]');
        note.hidden = !node.note;
        note.textContent = node.note || '';
        var l = node.lesson && lessons[node.lesson];
        var link = q('[data-card-lesson]');
        link.hidden = !l;
        if (l) { link.href = '/student/lessons/' + node.lesson; q('[data-card-lesson-title]').textContent = l.title; }
        card.hidden = false;
        card.style.animation = 'none';
        void card.offsetWidth;
        card.style.animation = '';
    }
    q('[data-card-close]').addEventListener('click', function () { card.hidden = true; map.select(null, true); });
    q('[data-card-img]').addEventListener('click', function () { window.open(this.src, '_blank'); });

    var acts = {
        expand: map.expandAll, collapse: map.collapseAll, zin: map.zoomIn, zout: map.zoomOut, fit: map.fit,
        done: function () {
            var btn = q('[data-act="done"]');
            if (btn.classList.contains('is-done')) { return; }
            var fd = new FormData();
            fd.append('_token', token);
            fetch(app.getAttribute('data-done-url'), { method: 'POST', body: fd, credentials: 'same-origin',
                headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); }).then(function (res) {
                    if (!res.ok) { return; }
                    btn.classList.add('is-done');
                    q('[data-done-text]').textContent = 'مرور شد';
                    if (window.HlxUI) { window.HlxUI.toast(res.xp ? '+' + fa(res.xp.xp) + ' امتیاز ⚡' : 'ثبت شد ✓', 'ok'); }
                });
        }
    };
    app.querySelectorAll('[data-act]').forEach(function (b) {
        b.addEventListener('click', function () { acts[b.getAttribute('data-act')](); });
    });

    var search = q('[data-search]'), t = null;
    search.addEventListener('input', function () {
        window.clearTimeout(t);
        t = window.setTimeout(function () { map.search(search.value); }, 250);
    });
    search.addEventListener('keydown', function (e) { if (e.key === 'Escape') { search.value = ''; map.search(''); } });
})();
