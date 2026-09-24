/* =====================================================================
   HeleXa Med — reading a درسنامه.

   The student's own layer over the admin's text: highlights in six colours,
   text in four, underline, bold, and a note on any of them. Each mark is
   stored as {block id, start, end} inside one paragraph, so an edit to one
   paragraph never shifts the marks in the others. The page re-renders the
   marks from the untouched original on every change, which keeps the
   arithmetic simple and the DOM clean.
   ===================================================================== */
(function () {
    'use strict';

    var root = document.querySelector('[data-lesson-reader]');
    if (!root) { return; }

    var doc    = root.querySelector('[data-lr-doc]');
    var pop    = root.querySelector('[data-lr-pop]');
    var uuid   = root.getAttribute('data-uuid');
    var url    = '/student/lessons/' + uuid + '/state';
    var token  = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var PHONE  = window.matchMedia('(max-width: 760px)');
    var fa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };
    var toast = function (m, t) { if (window.HlxUI) { window.HlxUI.toast(m, t); } };

    // Fixed layers live on <body>, never under an animated ancestor.
    var barBox = root.querySelector('.lr-progress');
    if (barBox) { document.body.appendChild(barBox); }
    document.body.appendChild(pop);

    var marks = [];
    try { marks = JSON.parse(root.querySelector('[data-lr-initial]').textContent || '[]') || []; } catch (e) { marks = []; }

    // Anchors for the table of contents.
    doc.querySelectorAll('h2[data-b], h3[data-b]').forEach(function (h) { h.id = 'b-' + h.getAttribute('data-b'); });
    var original = doc.cloneNode(true);

    /* ------------------------------------------------------- rendering */
    function textNodes(el) {
        var out = [], w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null);
        while (w.nextNode()) { out.push(w.currentNode); }
        return out;
    }

    function wrap(block, s, e, mark) {
        var pos = 0;
        textNodes(block).forEach(function (node) {
            var len = node.nodeValue.length, from = Math.max(s, pos), to = Math.min(e, pos + len);
            if (from < to) {
                var mid = node;
                if (from > pos) { mid = node.splitText(from - pos); }
                if (to < pos + len) { mid.splitText(to - from); }
                var el = document.createElement(mark.k === 'hl' ? 'mark' : 'span');
                el.className = mark.k === 'hl' ? 'u-hl hl-' + mark.c
                    : mark.k === 'tc' ? 'u-tc tc-' + mark.c
                    : mark.k === 'ul' ? 'u-ul' : 'u-bold';
                if (mark.n) { el.classList.add('has-note'); el.title = mark.n; }
                el.setAttribute('data-h', mark.id);
                mid.parentNode.insertBefore(el, mid);
                el.appendChild(mid);
            }
            pos += len;
        });
    }

    function render() {
        var fresh = original.cloneNode(true);
        doc.innerHTML = fresh.innerHTML;
        // Colours first, then highlights, so a highlight paints over a colour.
        var order = { tc: 0, bold: 1, ul: 2, hl: 3 };
        marks.slice().sort(function (a, b) { return order[a.k] - order[b.k]; }).forEach(function (m) {
            var block = doc.querySelector('[data-b="' + m.b + '"]');
            if (block) { wrap(block, m.s, m.e, m); }
        });
        var c = root.querySelector('[data-lr-count]');
        if (c) { c.textContent = fa(marks.length); }
    }
    render();

    /* ------------------------------------------------------- selection */
    function blockOf(node) {
        while (node && node !== doc) {
            if (node.nodeType === 1 && node.hasAttribute('data-b')) { return node; }
            node = node.parentNode;
        }
        return null;
    }

    function offsetIn(block, node, offset) {
        var pos = 0, nodes = textNodes(block);
        if (node.nodeType === 1) {
            // A boundary between elements: count the text before that child.
            var before = document.createRange();
            before.setStart(block, 0);
            before.setEnd(node, offset);
            return before.toString().length;
        }
        for (var i = 0; i < nodes.length; i++) {
            if (nodes[i] === node) { return pos + offset; }
            pos += nodes[i].nodeValue.length;
        }
        return pos;
    }

    /** The current selection as one {b, s, e, q} per block it touches. */
    function pieces() {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount || sel.isCollapsed) { return []; }
        var range = sel.getRangeAt(0);
        if (!doc.contains(range.commonAncestorContainer)) { return []; }
        var out = [];
        var blocks = Array.prototype.filter.call(doc.querySelectorAll('[data-b]'), function (b) {
            return range.intersectsNode(b) && !b.querySelector('[data-b]');
        });
        blocks.forEach(function (b) {
            var s = b.contains(range.startContainer) ? offsetIn(b, range.startContainer, range.startOffset) : 0;
            var e = b.contains(range.endContainer) ? offsetIn(b, range.endContainer, range.endOffset) : b.textContent.length;
            if (e > s) { out.push({ b: b.getAttribute('data-b'), s: s, e: e, q: b.textContent.slice(s, e).slice(0, 300) }); }
        });
        return out;
    }

    var current = [];
    function showPop() {
        current = pieces();
        if (!current.length) { hidePop(); return; }
        pop.hidden = false;
        if (PHONE.matches) {
            pop.classList.add('is-dock');
            pop.style.top = pop.style.left = '';
            return;
        }
        pop.classList.remove('is-dock');
        var r = window.getSelection().getRangeAt(0).getBoundingClientRect();
        var w = pop.offsetWidth || 280;
        var left = Math.min(window.innerWidth - w - 10, Math.max(10, r.left + r.width / 2 - w / 2));
        var top = r.top - pop.offsetHeight - 12;
        if (top < 70) { top = r.bottom + 12; }
        pop.style.left = left + 'px';
        pop.style.top = top + 'px';
    }
    function hidePop() { pop.hidden = true; }

    var selTimer = null;
    document.addEventListener('selectionchange', function () {
        window.clearTimeout(selTimer);
        selTimer = window.setTimeout(showPop, PHONE.matches ? 350 : 120);
    });
    pop.addEventListener('mousedown', function (e) { e.preventDefault(); });

    function uid() { return Math.random().toString(36).slice(2, 10); }

    function add(kind, color, note) {
        if (!current.length) { return; }
        current.forEach(function (p) {
            // A new highlight replaces any overlapping one of the same kind.
            marks = marks.filter(function (m) { return !(m.b === p.b && m.k === kind && m.s < p.e && p.s < m.e); });
            marks.push({ id: uid(), b: p.b, s: p.s, e: p.e, k: kind, c: color || 'yellow', q: p.q, n: note || '' });
        });
        finish();
    }

    function erase() {
        current.forEach(function (p) {
            marks = marks.filter(function (m) { return !(m.b === p.b && m.s < p.e && p.s < m.e); });
        });
        finish();
    }

    function finish() {
        window.getSelection().removeAllRanges();
        hidePop();
        render();
        save({ highlights: marks });
    }

    pop.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b) { return; }
        if (b.hasAttribute('data-lr-hl')) { add('hl', b.getAttribute('data-lr-hl')); }
        else if (b.hasAttribute('data-lr-tc')) { add('tc', b.getAttribute('data-lr-tc')); }
        else if (b.hasAttribute('data-lr-kind')) { add(b.getAttribute('data-lr-kind'), 'yellow'); }
        else if (b.hasAttribute('data-lr-erase')) { erase(); }
        else if (b.hasAttribute('data-lr-note')) {
            var keep = current.slice();
            var n = window.prompt('یادداشت روی این قسمت:');
            if (n) { current = keep; add('hl', 'yellow', n.slice(0, 500)); }
        }
    });

    // Tapping a mark with a note shows it.
    doc.addEventListener('click', function (e) {
        var m = e.target.closest('[data-h].has-note');
        if (m && window.getSelection().isCollapsed) { toast('📝 ' + m.title); }
    });

    /* --------------------------------------------------------- saving */
    var saveTimer = null, pending = {};
    function save(fields) {
        Object.keys(fields).forEach(function (k) { pending[k] = fields[k]; });
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(flush, 700);
    }
    function flush(keepalive) {
        if (!Object.keys(pending).length) { return Promise.resolve(); }
        var body = JSON.stringify(pending);
        pending = {};
        return fetch(url, {
            method: 'POST', credentials: 'same-origin', keepalive: !!keepalive && body.length < 60000, body: body,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).catch(function () { toast('ذخیره نشد؛ اتصال را بررسی کن.', 'bad'); });
    }
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { flush(true); } });

    /* ----------------------------------------------- reading progress */
    var bar = document.querySelector('[data-lr-bar]');
    var best = +root.getAttribute('data-progress') || 0, lastSent = best;
    function onScroll() {
        var r = doc.getBoundingClientRect();
        var seen = Math.max(0, Math.min(1, (window.innerHeight - r.top) / Math.max(1, r.height)));
        var pct = Math.round(seen * 100);
        if (bar) { bar.style.width = pct + '%'; }
        if (pct > best) { best = pct; }
        if (best - lastSent >= 10) { lastSent = best; save({ progress: best }); }
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    // The table of contents follows the reader.
    var links = root.querySelectorAll('[data-lr-toc]');
    if (links.length && 'IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (!en.isIntersecting) { return; }
                var id = en.target.getAttribute('data-b');
                links.forEach(function (a) { a.classList.toggle('is-on', a.getAttribute('data-lr-toc') === id); });
            });
        }, { rootMargin: '-80px 0px -70% 0px' });
        doc.querySelectorAll('h2[data-b], h3[data-b]').forEach(function (h) { io.observe(h); });
    }

    /* ---------------------------------------------- reading comforts */
    var scale = 1;
    try { scale = +localStorage.getItem('lr-scale') || 1; } catch (e) {}
    function applyScale() { root.style.setProperty('--lr-scale', String(scale)); }
    applyScale();
    root.querySelectorAll('[data-lr-size]').forEach(function (b) {
        b.addEventListener('click', function () {
            scale = Math.max(0.85, Math.min(1.45, Math.round((scale + 0.1 * +b.getAttribute('data-lr-size')) * 100) / 100));
            applyScale();
            try { localStorage.setItem('lr-scale', String(scale)); } catch (e) {}
        });
    });
    var themeBtn = root.querySelector('[data-lr-theme]');
    try { if (localStorage.getItem('lr-paper') === '1') { root.classList.add('is-paper'); } } catch (e) {}
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var on = root.classList.toggle('is-paper');
            try { localStorage.setItem('lr-paper', on ? '1' : '0'); } catch (e) {}
        });
    }

    var bm = root.querySelector('[data-lr-bookmark]');
    if (bm) {
        bm.addEventListener('click', function () {
            var on = bm.getAttribute('aria-pressed') !== 'true';
            bm.setAttribute('aria-pressed', on ? 'true' : 'false');
            save({ bookmarked: on ? 1 : 0 });
            toast(on ? 'به نشان‌شده‌ها اضافه شد 🔖' : 'از نشان‌شده‌ها برداشته شد');
        });
    }

    var readBtn = root.querySelector('[data-lr-read]');
    if (readBtn) {
        readBtn.addEventListener('click', function () {
            readBtn.disabled = true;
            pending.read = 1;
            flush().then(function (res) {
                readBtn.classList.add('is-done');
                readBtn.querySelector('span').textContent = 'این درسنامه را خوانده‌ای';
                var xp = res && res.xp && res.xp.xp;
                toast(xp ? 'آفرین! +' + fa(xp) + ' امتیاز 🎉' : 'آفرین! 🎉', 'ok');
                if (!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) { confetti(readBtn); }
            });
        });
    }

    function confetti(from) {
        var r = from.getBoundingClientRect(), colors = ['#f59e0b', '#ef4444', '#10b981', '#3b82f6', '#a855f7', '#ec4899'];
        for (var i = 0; i < 36; i++) {
            var p = document.createElement('i');
            p.className = 'lr-confetti';
            p.style.left = (r.left + r.width / 2) + 'px';
            p.style.top = (r.top + r.height / 2) + 'px';
            p.style.background = colors[i % colors.length];
            p.style.setProperty('--dx', (Math.random() * 360 - 180) + 'px');
            p.style.setProperty('--dy', (-Math.random() * 260 - 80) + 'px');
            p.style.setProperty('--r', (Math.random() * 720 - 360) + 'deg');
            document.body.appendChild(p);
            window.setTimeout(p.remove.bind(p), 1400);
        }
    }

    /* ---------------------------------------------- my highlights list */
    var mine = root.querySelector('[data-lr-mine]');
    if (mine && window.HlxUI) {
        mine.addEventListener('click', function () {
            var el = window.HlxUI.el;
            var box = el('div', 'lr-mine');
            if (!marks.length) { box.appendChild(el('p', 'hx-muted', 'هنوز چیزی هایلایت نکرده‌ای. یک جمله را انتخاب کن.')); }
            var close = null;
            marks.forEach(function (m) {
                var row = el('button', 'lr-mine-row k-' + m.k + ' c-' + m.c);
                row.type = 'button';
                row.appendChild(el('span', 'lr-mine-q', m.q || '…'));
                if (m.n) { row.appendChild(el('small', null, '📝 ' + m.n)); }
                row.addEventListener('click', function () {
                    var t = doc.querySelector('[data-h="' + m.id + '"]');
                    if (close) { close(); }
                    if (t) {
                        t.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        t.classList.add('is-flash');
                        window.setTimeout(function () { t.classList.remove('is-flash'); }, 1600);
                    }
                });
                box.appendChild(row);
            });
            close = window.HlxUI.modal({ title: 'هایلایت‌ها و یادداشت‌های من', body: box, wide: true,
                actions: marks.length ? [{ label: 'پاک کردن همه', onClick: function (c) {
                    if (window.confirm('همه هایلایت‌ها و یادداشت‌های این درسنامه پاک شود؟')) { marks = []; render(); save({ highlights: marks }); c(); }
                } }, { label: 'بستن', primary: true }] : [{ label: 'بستن', primary: true }] });
        });
    }
})();
