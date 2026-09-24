/* =====================================================================
   HeleXa Med — the «بازی با شکل» editor: click the picture to add a
   hotspot, drag it into place, size it, name it, and optionally give it
   its own question with choices. Positions are percent of the picture.
   ===================================================================== */
(function () {
    'use strict';

    var root = document.querySelector('[data-fe]');
    if (!root) { return; }
    var boot = JSON.parse(root.querySelector('[data-boot]').textContent);
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var fa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };
    function toast(m, t) { if (window.HlxUI) { window.HlxUI.toast(m, t); } }
    var q = function (s) { return root.querySelector(s); };
    var stage = q('[data-stage]'), img = q('[data-img]'), layer = q('[data-spots]'), list = q('[data-list]');
    var stateEl = q('[data-state]');
    var spots = boot.spots || [];
    var selected = null, dirty = false, timer = null, saving = false;
    var tone = (q('[data-tones] .is-on') || { getAttribute: function () { return 'amber'; } }).getAttribute('data-tone');
    function uid() { return Math.random().toString(36).slice(2, 11); }
    function find(key) { for (var i = 0; i < spots.length; i++) { if (spots[i].key === key) { return spots[i]; } } return null; }

    /* ------------------------------------------------ drawing */
    function render() {
        layer.innerHTML = '';
        spots.forEach(function (s, i) {
            var d = document.createElement('button');
            d.type = 'button';
            d.className = 'fe-spot' + (s.key === selected ? ' is-on' : '');
            d.style.left = s.x + '%';
            d.style.top = s.y + '%';
            d.style.width = (s.r * 2) + '%';
            d.setAttribute('data-key', s.key);
            d.innerHTML = '<i>' + fa(i + 1) + '</i>';
            d.title = s.label;
            layer.appendChild(d);
        });
        list.innerHTML = '';
        spots.forEach(function (s, i) {
            var li = document.createElement('li');
            li.className = s.key === selected ? 'is-on' : '';
            li.setAttribute('data-key', s.key);
            li.innerHTML = '<span class="fe-n"></span><span class="fe-l"></span>' + (s.question ? '<em>❓</em>' : '') + (s.lesson_id || s.tag_id ? '<em>📘</em>' : '');
            li.querySelector('.fe-n').textContent = fa(i + 1);
            li.querySelector('.fe-l').textContent = s.label || '(بی‌نام)';
            list.appendChild(li);
        });
        q('[data-count]').textContent = fa(spots.length);
    }
    list.addEventListener('click', function (e) { var li = e.target.closest('li'); if (li) { select(li.getAttribute('data-key')); } });

    /* ------------------------------------------------ inspector */
    var none = q('[data-none]'), some = q('[data-some]');
    var fields = {};
    root.querySelectorAll('[data-f]').forEach(function (el) { fields[el.getAttribute('data-f')] = el; });
    function select(key) {
        selected = key && find(key) ? key : null;
        render();
        var s = selected && find(selected);
        none.hidden = !!s;
        some.hidden = !s;
        if (!s) { return; }
        fields.label.value = s.label || '';
        fields.r.value = s.r;
        q('[data-r-out]').textContent = fa(Math.round(s.r * 10) / 10) + '٪';
        fields.question.value = s.question || '';
        fields.hint.value = s.hint || '';
        fields.explanation.value = s.explanation || '';
        fields.tag_id.value = String(s.tag_id || 0);
        fields.lesson_id.value = String(s.lesson_id || 0);
        drawOptions(s);
    }
    Object.keys(fields).forEach(function (k) {
        fields[k].addEventListener('input', function () {
            var s = find(selected);
            if (!s) { return; }
            var v = fields[k].value;
            s[k] = k === 'r' ? parseFloat(v) : (k === 'tag_id' || k === 'lesson_id' ? parseInt(v, 10) || 0 : v);
            if (k === 'r') { q('[data-r-out]').textContent = fa(Math.round(s.r * 10) / 10) + '٪'; }
            if (k === 'label' || k === 'r' || k === 'question') { render(); }
            if (k === 'question') { q('[data-options-wrap]').classList.toggle('is-needed', !!v.trim()); }
            markDirty();
        });
    });

    var optBox = q('[data-options]');
    function drawOptions(s) {
        s.options = s.options || [];
        optBox.innerHTML = '';
        s.options.forEach(function (o, i) {
            var row = document.createElement('div');
            row.className = 'fe-opt';
            row.innerHTML = '<input type="radio" name="fe-correct"><input class="input" maxlength="200"><button type="button" class="ad-icon-btn is-danger" title="حذف">×</button>';
            var r = row.querySelector('[type="radio"]'), t = row.querySelector('.input');
            r.checked = !!o.correct;
            t.value = o.text || '';
            r.addEventListener('change', function () { s.options.forEach(function (x, j) { x.correct = j === i; }); markDirty(); });
            t.addEventListener('input', function () { o.text = t.value; markDirty(); });
            row.querySelector('button').addEventListener('click', function () { s.options.splice(i, 1); drawOptions(s); markDirty(); });
            optBox.appendChild(row);
        });
        q('[data-options-wrap]').classList.toggle('is-needed', !!(s.question || '').trim());
    }
    q('[data-add-option]').addEventListener('click', function () {
        var s = find(selected);
        if (!s || s.options.length >= 6) { return; }
        s.options.push({ text: '', correct: s.options.length === 0 });
        drawOptions(s);
        var inputs = optBox.querySelectorAll('.input');
        inputs[inputs.length - 1].focus();
    });

    function removeSelected() {
        if (!selected) { return; }
        spots = spots.filter(function (s) { return s.key !== selected; });
        select(null);
        markDirty();
    }

    /* ------------------------------------------------ placing */
    function pct(e) {
        var r = img.getBoundingClientRect();
        return { x: Math.max(0, Math.min(100, (e.clientX - r.left) / r.width * 100)), y: Math.max(0, Math.min(100, (e.clientY - r.top) / r.height * 100)) };
    }
    var drag = null;
    stage.addEventListener('pointerdown', function (e) {
        var spot = e.target.closest('.fe-spot');
        if (spot) {
            e.preventDefault();
            select(spot.getAttribute('data-key'));
            drag = { key: spot.getAttribute('data-key'), moved: false };
            stage.setPointerCapture(e.pointerId);
            return;
        }
        if (e.target === img || e.target === layer) {
            var p = pct(e);
            var s = { key: uid(), label: '', x: Math.round(p.x * 1000) / 1000, y: Math.round(p.y * 1000) / 1000, r: spots.length ? spots[spots.length - 1].r : 3.5,
                      question: '', options: [], hint: '', explanation: '', tag_id: 0, lesson_id: 0 };
            spots.push(s);
            select(s.key);
            markDirty();
            window.setTimeout(function () { fields.label.focus(); }, 30);
        }
    });
    stage.addEventListener('pointermove', function (e) {
        if (!drag) { return; }
        var s = find(drag.key), p = pct(e);
        s.x = Math.round(p.x * 1000) / 1000;
        s.y = Math.round(p.y * 1000) / 1000;
        drag.moved = true;
        var el = layer.querySelector('[data-key="' + drag.key + '"]');
        if (el) { el.style.left = s.x + '%'; el.style.top = s.y + '%'; }
    });
    stage.addEventListener('pointerup', function () { if (drag && drag.moved) { markDirty(); } drag = null; });
    document.addEventListener('keydown', function (e) {
        var inField = document.activeElement && /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName);
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); save(false); return; }
        if (inField) { return; }
        if ((e.key === 'Delete' || e.key === 'Backspace') && selected) { e.preventDefault(); removeSelected(); }
        if (e.key.indexOf('Arrow') === 0 && selected) {
            e.preventDefault();
            var s = find(selected), step = e.shiftKey ? 1 : 0.2;
            if (e.key === 'ArrowLeft') { s.x = Math.max(0, s.x - step); }
            if (e.key === 'ArrowRight') { s.x = Math.min(100, s.x + step); }
            if (e.key === 'ArrowUp') { s.y = Math.max(0, s.y - step); }
            if (e.key === 'ArrowDown') { s.y = Math.min(100, s.y + step); }
            render(); markDirty();
        }
    });

    /* ------------------------------------------------ the picture */
    function uploadImage(file) {
        var fd = new FormData();
        fd.append('image', file);
        fd.append('_token', token);
        stateEl.textContent = 'در حال بارگذاری تصویر…';
        fetch(root.getAttribute('data-image-url'), { method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); }).then(function (res) {
                if (!res.ok) { toast(res.message || 'بارگذاری نشد.', 'bad'); stateEl.textContent = ''; return; }
                img.src = res.url;
                stage.hidden = false;
                var first = q('[data-first-image]');
                if (first) { first.remove(); }
                stateEl.textContent = 'تصویر ذخیره شد';
                toast('تصویر ذخیره شد ✓', 'ok');
            });
    }
    root.querySelectorAll('[data-image-input]').forEach(function (inp) {
        inp.addEventListener('change', function () { if (inp.files && inp.files[0]) { uploadImage(inp.files[0]); } inp.value = ''; });
    });
    var drop = q('[data-first-image]');
    if (drop) {
        ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
        drop.addEventListener('dragleave', function () { drop.classList.remove('is-over'); });
        drop.addEventListener('drop', function (e) { e.preventDefault(); if (e.dataTransfer.files[0]) { uploadImage(e.dataTransfer.files[0]); } });
    }

    /* ------------------------------------------------ saving */
    function markDirty() {
        dirty = true;
        stateEl.textContent = 'تغییرات ذخیره نشده';
        stateEl.className = 'me-state is-dirty';
        window.clearTimeout(timer);
        timer = window.setTimeout(function () { save(true); }, 5000);
    }
    function meta(name) {
        var el = root.querySelector('[data-meta="' + name + '"]:checked') || root.querySelector('[data-meta="' + name + '"]:not([type="radio"])');
        return el ? el.value : '';
    }
    function save(quiet) {
        if (saving) { return; }
        saving = true;
        var body = { title: meta('title'), summary: meta('summary'), status: meta('status'), subject_id: +meta('subject_id') || 0,
                     package_id: +meta('package_id') || 0, tone: tone, spots: spots };
        fetch(root.getAttribute('data-save'), {
            method: 'POST', credentials: 'same-origin', body: JSON.stringify(body),
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (res) {
            saving = false;
            if (!res.ok) { stateEl.textContent = res.message || 'ذخیره نشد'; stateEl.className = 'me-state is-dirty'; if (!quiet) { toast(res.message || 'ذخیره نشد.', 'bad'); } return; }
            dirty = false;
            stateEl.textContent = 'ذخیره شد · ' + fa(res.count) + ' نقطه';
            stateEl.className = 'me-state is-ok';
            if (!quiet) { toast('ذخیره شد ✓', 'ok'); }
        }).catch(function () { saving = false; stateEl.textContent = 'اتصال برقرار نشد'; });
    }
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    root.querySelectorAll('[data-meta]').forEach(function (el) { el.addEventListener('change', markDirty); });
    root.querySelectorAll('[data-tone]').forEach(function (b) {
        b.addEventListener('click', function () {
            root.querySelectorAll('[data-tone]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
            tone = b.getAttribute('data-tone');
            markDirty();
        });
    });
    var acts = { save: function () { save(false); }, remove: removeSelected, io: function () { q('[data-io]').hidden = false; } };
    root.querySelectorAll('[data-act]').forEach(function (b) { b.addEventListener('click', function () { acts[b.getAttribute('data-act')](); }); });

    /* ------------------------------------------------ JSON */
    var io = q('[data-io]');
    q('[data-io-close]').addEventListener('click', function () { io.hidden = true; });
    q('[data-io-apply]').addEventListener('click', function () {
        var doc;
        try { doc = JSON.parse(q('[data-io-text]').value); } catch (x) { toast('JSON نامعتبر است.', 'bad'); return; }
        var list2 = Array.isArray(doc) ? doc : doc.spots;
        if (!Array.isArray(list2)) { toast('کلید spots پیدا نشد.', 'bad'); return; }
        spots = list2.filter(function (s) { return s && s.label; }).map(function (s) {
            return { key: s.key || uid(), label: String(s.label), x: +s.x || 50, y: +s.y || 50, r: +s.r || 3.5, question: s.question || '',
                     options: Array.isArray(s.options) ? s.options.map(function (o) { return typeof o === 'string' ? { text: o, correct: false } : o; }) : [],
                     hint: s.hint || '', explanation: s.explanation || '', tag_id: +s.tag_id || 0, lesson_id: +s.lesson_id || 0 };
        });
        io.hidden = true;
        select(null);
        markDirty();
        toast(fa(spots.length) + ' نقطه وارد شد', 'ok');
    });

    render();
})();
