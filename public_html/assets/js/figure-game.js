/* =====================================================================
   HeleXa Med — «بازی با شکل»: three modes on one picture.
     find     «عصب مدین را پیدا کن» — tap the right place
     ask      a spot pulses — pick its name (or answer its own question)
     explore  every spot numbered; tap one to see what it is
   Answers are checked by the server; the page only draws.
   ===================================================================== */
(function () {
    'use strict';

    var app = document.querySelector('[data-game]');
    if (!app) { return; }
    var boot = JSON.parse(app.querySelector('[data-boot]').textContent);
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var fa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };
    var q = function (s) { return app.querySelector(s); };
    var spots = boot.spots || [];
    var stage = q('[data-stage]'), img = q('[data-img]'), layer = q('[data-layer]');
    var prompt = q('[data-prompt]'), panel = q('[data-panel]'), choices = q('[data-choices]'), fb = q('[data-feedback]');
    var mode = 'find', queue = [], idx = 0, score = 0, correct = 0, streak = 0, started = 0, locked = false, missed = [];
    var zoom = 1;

    function shuffle(a) { a = a.slice(); for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = a[i]; a[i] = a[j]; a[j] = t; } return a; }
    function post(url, body) {
        return fetch(url, { method: 'POST', credentials: 'same-origin', body: JSON.stringify(body),
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); });
    }
    function mark(s, cls, label) {
        var d = document.createElement('span');
        d.className = 'fg-mark ' + cls;
        d.style.left = s.x + '%';
        d.style.top = s.y + '%';
        d.style.width = (s.r * 2) + '%';
        if (label) { var t = document.createElement('em'); t.textContent = label; d.appendChild(t); }
        layer.appendChild(d);
        return d;
    }
    function hud() {
        q('[data-n]').textContent = fa(Math.min(idx + 1, queue.length));
        q('[data-total]').textContent = fa(queue.length);
        q('[data-score]').textContent = fa(score);
        q('[data-bar]').style.width = (queue.length ? idx / queue.length * 100 : 0) + '%';
        q('[data-streak-wrap]').hidden = streak < 2;
        q('[data-streak]').textContent = fa(streak);
    }

    /* ------------------------------------------------ rounds */
    function start(m) {
        mode = m;
        app.querySelectorAll('[data-mode]').forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-mode') === m); });
        app.classList.toggle('is-explore', m === 'explore');
        q('[data-end]').hidden = true;
        layer.innerHTML = '';
        panel.hidden = true;
        fb.hidden = true;
        missed = [];
        if (m === 'explore') { explore(); return; }
        queue = shuffle(spots);
        idx = 0; score = 0; correct = 0; streak = 0;
        started = Date.now();
        q('[data-hud]').hidden = false;
        next();
    }
    function next() {
        layer.innerHTML = '';
        fb.hidden = true;
        locked = false;
        if (idx >= queue.length) { end(); return; }
        hud();
        var s = queue[idx];
        prompt.classList.remove('is-pop');
        void prompt.offsetWidth;
        prompt.classList.add('is-pop');
        if (mode === 'find') {
            panel.hidden = true;
            prompt.innerHTML = '';
            prompt.appendChild(document.createTextNode('روی تصویر پیدا کن: '));
            var b = document.createElement('b');
            b.textContent = s.label;
            prompt.appendChild(b);
        } else {
            var target = mark(s, 'is-target');
            target.appendChild(document.createElement('i'));
            prompt.textContent = s.question || 'این ساختار چیست؟';
            buildChoices(s);
            panel.hidden = false;
            scrollTo(s);
        }
    }
    function buildChoices(s) {
        choices.innerHTML = '';
        var list;
        if (s.question && s.options && s.options.length >= 2) {
            list = shuffle(s.options);
        } else {
            var others = shuffle(spots.filter(function (o) { return o.key !== s.key && o.label !== s.label; })).slice(0, 3).map(function (o) { return o.label; });
            list = shuffle([s.label].concat(others));
        }
        list.forEach(function (text) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'fg-choice';
            b.textContent = text;
            b.addEventListener('click', function () { answerAsk(s, text, b); });
            choices.appendChild(b);
        });
    }
    function scrollTo(s) {
        if (zoom === 1) { return; }
        var box = q('[data-scroll]');
        box.scrollTo({ left: s.x / 100 * stage.offsetWidth - box.clientWidth / 2, top: s.y / 100 * stage.offsetHeight - box.clientHeight / 2, behavior: 'smooth' });
    }

    /* ------------------------------------------------ answering */
    stage.addEventListener('click', function (e) {
        if (mode !== 'find' || locked || idx >= queue.length) { return; }
        var r = img.getBoundingClientRect();
        var x = (e.clientX - r.left) / r.width * 100, y = (e.clientY - r.top) / r.height * 100;
        var s = queue[idx];
        locked = true;
        var tap = mark({ x: x, y: y, r: 1.2 }, 'is-tap');
        post('/student/figures/' + boot.uuid + '/answer', { mode: 'find', key: s.key, x: x, y: y }).then(function (res) {
            tap.remove();
            if (!res.ok) { locked = false; return; }
            mark(s, res.correct ? 'is-right' : 'is-answer', res.correct ? '' : s.label);
            if (!res.correct) { mark({ x: x, y: y, r: 1.4 }, 'is-wrong'); }
            feedback(s, res);
        }).catch(function () { locked = false; tap.remove(); });
    });
    function answerAsk(s, text, btn) {
        if (locked) { return; }
        locked = true;
        post('/student/figures/' + boot.uuid + '/answer', { mode: 'ask', key: s.key, choice: text }).then(function (res) {
            if (!res.ok) { locked = false; return; }
            choices.querySelectorAll('.fg-choice').forEach(function (b) {
                b.disabled = true;
                if (b.textContent === res.right) { b.classList.add('is-right'); }
            });
            if (!res.correct) { btn.classList.add('is-wrong'); }
            var t = layer.querySelector('.is-target');
            if (t) { t.classList.add(res.correct ? 'is-right' : 'is-answer'); }
            feedback(s, res);
        }).catch(function () { locked = false; });
    }
    function feedback(s, res) {
        if (res.correct) {
            correct++; streak++;
            score += 10 + Math.min(streak - 1, 5) * 2;
            stage.classList.remove('is-shake');
            burst();
        } else {
            streak = 0;
            missed.push({ spot: s, lesson: res.lesson });
            stage.classList.remove('is-shake'); void stage.offsetWidth; stage.classList.add('is-shake');
        }
        hud();
        q('[data-fb-title]').textContent = res.correct ? (streak >= 3 ? 'عالی! ' + fa(streak) + ' تا پشت سر هم 🔥' : 'درسته! ✓') : 'نه — پاسخ: ' + res.right;
        fb.classList.toggle('is-good', !!res.correct);
        var text = [res.hint, res.explanation].filter(Boolean).join('\n');
        q('[data-fb-text]').textContent = text;
        q('[data-fb-text]').hidden = !text;
        var l = q('[data-fb-lesson]');
        l.hidden = !res.lesson;
        if (res.lesson) { l.href = '/student/lessons/' + res.lesson.uuid; q('[data-fb-lesson-title]').textContent = res.lesson.title; }
        panel.hidden = false;
        fb.hidden = false;
        if (mode === 'find') { choices.innerHTML = ''; }
        q('[data-next]').focus({ preventScroll: true });
        if (window.matchMedia('(max-width: 800px)').matches) { fb.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }
    q('[data-next]').addEventListener('click', function () { idx++; next(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !fb.hidden && document.activeElement !== q('[data-next]')) { idx++; next(); }
    });

    function burst() {
        var t = layer.querySelector('.is-right');
        if (!t) { return; }
        for (var i = 0; i < 10; i++) {
            var p = document.createElement('i');
            p.className = 'fg-spark';
            p.style.setProperty('--a', (i * 36) + 'deg');
            t.appendChild(p);
        }
    }

    /* ------------------------------------------------ the end */
    function end() {
        hud();
        q('[data-bar]').style.width = '100%';
        var pct = queue.length ? Math.round(correct / queue.length * 100) : 0;
        var secs = Math.round((Date.now() - started) / 1000);
        q('[data-end-pct]').textContent = '٪' + fa(pct);
        q('[data-ring]').style.setProperty('--p', pct);
        q('[data-end-title]').textContent = pct >= 90 ? 'استاد این شکلی! 🏆' : pct >= 70 ? 'خیلی خوب! 👏' : pct >= 40 ? 'بد نبود — یک دور دیگر؟' : 'تمرین بیشتر لازم است 💪';
        q('[data-end-text]').textContent = fa(correct) + ' از ' + fa(queue.length) + ' درست · ' + fa(Math.floor(secs / 60)) + ':' + fa(String(secs % 60).padStart(2, '0')) + ' · ⚡ ' + fa(score);
        var box = q('[data-missed]');
        box.innerHTML = '';
        if (missed.length) {
            var h = document.createElement('b');
            h.textContent = 'این‌ها را دوباره ببین:';
            box.appendChild(h);
            missed.forEach(function (m) {
                var a = document.createElement(m.lesson ? 'a' : 'span');
                a.className = 'fg-miss';
                a.textContent = m.spot.label + (m.lesson ? ' — ' + m.lesson.title + ' ←' : '');
                if (m.lesson) { a.href = '/student/lessons/' + m.lesson.uuid; }
                box.appendChild(a);
            });
        }
        panel.hidden = true;
        q('[data-end]').hidden = false;
        post('/student/figures/' + boot.uuid + '/finish', { mode: mode, total: queue.length, correct: correct, seconds: secs });
    }
    q('[data-again]').addEventListener('click', function () { start(mode); });
    q('[data-other]').addEventListener('click', function () { start(mode === 'find' ? 'ask' : 'find'); });

    /* ------------------------------------------------ explore */
    function explore() {
        q('[data-hud]').hidden = true;
        prompt.textContent = 'روی هر نقطه بزن تا ببینی چیست.';
        spots.forEach(function (s, i) {
            var d = mark(s, 'is-explore');
            var n = document.createElement('b');
            n.textContent = fa(i + 1);
            d.appendChild(n);
            d.addEventListener('click', function (e) {
                e.stopPropagation();
                layer.querySelectorAll('.is-open').forEach(function (x) { x.classList.remove('is-open'); });
                d.classList.add('is-open');
                q('[data-fb-title]').textContent = fa(i + 1) + '. ' + s.label;
                q('[data-fb-text]').textContent = s.explain || '';
                q('[data-fb-text]').hidden = !s.explain;
                var l = q('[data-fb-lesson]');
                l.hidden = !s.lesson;
                if (s.lesson) { l.href = '/student/lessons/' + s.lesson.uuid; q('[data-fb-lesson-title]').textContent = s.lesson.title; }
                choices.innerHTML = '';
                fb.classList.add('is-good');
                panel.hidden = false;
                fb.hidden = false;
                q('[data-next]').hidden = true;
            });
        });
    }
    app.querySelectorAll('[data-mode]').forEach(function (b) {
        b.addEventListener('click', function () { q('[data-next]').hidden = false; start(b.getAttribute('data-mode')); });
    });

    /* ------------------------------------------------ zoom */
    q('[data-zoom]').addEventListener('click', function () {
        zoom = zoom === 1 ? 2 : zoom === 2 ? 3 : 1;
        stage.style.width = (zoom * 100) + '%';
        q('[data-zoom-label]').textContent = fa(zoom) + '×';
        app.classList.toggle('is-zoomed', zoom > 1);
        if (idx < queue.length && mode === 'ask') { scrollTo(queue[idx]); }
    });

    if (spots.length < 2) {
        prompt.textContent = 'این شکل هنوز نقطه کافی ندارد.';
        q('[data-hud]').hidden = true;
        return;
    }
    start('find');
})();
