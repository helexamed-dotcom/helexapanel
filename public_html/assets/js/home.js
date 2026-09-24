/* =====================================================================
   HeleXa Med — the student's home: the countdowns and the reading list.
   ===================================================================== */
(function () {
    'use strict';

    var DIGITS = '۰۱۲۳۴۵۶۷۸۹';
    var fa = function (s) { return String(s).replace(/[0-9]/g, function (d) { return DIGITS[+d]; }); };
    var pad = function (n) { return n < 10 ? '0' + n : String(n); };
    var token = function () { return (document.querySelector('meta[name="csrf-token"]') || {}).content || ''; };

    /* ------------------------------------------------- the countdowns
       Counted against the server's clock (the offset is measured once at
       load), so a phone whose clock is wrong still shows the right time. */
    var box = document.querySelector('[data-countdowns]');
    if (box) {
        var offset = (Number(box.getAttribute('data-server-ms')) || Date.now()) - Date.now();
        var cards = Array.prototype.map.call(box.querySelectorAll('[data-cd]'), function (el) {
            // The admin's moment is Tehran time.
            var at = Date.parse(el.getAttribute('data-at') + '+03:30');
            var units = {};
            ['d', 'h', 'm', 's'].forEach(function (u) {
                var f = el.querySelector('[data-flip="' + u + '"]');
                units[u] = {
                    el: f, value: null, timer: null,
                    top: f.querySelector('.flip-static.flip-top b'), bottom: f.querySelector('.flip-static.flip-bottom b'),
                    front: f.querySelector('.leaf-front b'), back: f.querySelector('.leaf-back b')
                };
            });
            return { el: el, at: at, units: units, done: el.querySelector('[data-cd-done]') };
        });

        var set = function (card, v) {
            if (card.value === null) {
                card.top.textContent = card.bottom.textContent = card.front.textContent = card.back.textContent = v;
                card.value = v;
                return;
            }
            if (card.value === v) { return; }
            var old = card.value;
            card.value = v;
            card.top.textContent = v;
            card.bottom.textContent = old;
            card.front.textContent = old;
            card.back.textContent = v;
            card.el.classList.remove('is-turning');
            void card.el.offsetWidth;
            card.el.classList.add('is-turning');
            window.clearTimeout(card.timer);
            card.timer = window.setTimeout(function () {
                card.bottom.textContent = v;
                card.front.textContent = v;
                card.el.classList.remove('is-turning');
            }, 620);
        };

        var tick = function () {
            var now = Date.now() + offset;
            cards.forEach(function (c) {
                if (c.el.hidden) { return; }
                var left = Math.max(0, Math.floor((c.at - now) / 1000));
                var d = Math.floor(left / 86400), h = Math.floor(left % 86400 / 3600), m = Math.floor(left % 3600 / 60), s = left % 60;
                set(c.units.d, fa(d < 100 ? pad(d) : d));
                set(c.units.h, fa(pad(h)));
                set(c.units.m, fa(pad(m)));
                set(c.units.s, fa(pad(s)));
                if (c.done) { c.done.hidden = left > 0; }
            });
        };

        var dots = box.querySelectorAll('[data-cd-dot]');
        var shown = 0, rotate = null;
        var show = function (i) {
            shown = i;
            cards.forEach(function (c, j) { c.el.hidden = j !== i; if (j === i) { Object.keys(c.units).forEach(function (u) { c.units[u].value = null; }); } });
            dots.forEach(function (d, j) { d.setAttribute('aria-selected', j === i ? 'true' : 'false'); });
            tick();
        };
        dots.forEach(function (d, i) { d.addEventListener('click', function () { window.clearInterval(rotate); show(i); }); });
        if (cards.length > 1) { rotate = window.setInterval(function () { show((shown + 1) % cards.length); }, 9000); }

        tick();
        window.setTimeout(function () { tick(); window.setInterval(tick, 1000); }, 1000 - ((Date.now() + offset) % 1000));
    }

    /* ----------------------------------------------- the reading list
       Adding, ticking and removing happen in place; with JavaScript off
       the same forms post and come back here. */
    var list = document.querySelector('[data-study-list]');
    var form = document.querySelector('[data-study-form]');

    function send(f) {
        return fetch(f.action, {
            method: 'POST', body: new FormData(f), credentials: 'same-origin',
            headers: { 'X-CSRF-Token': token(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); });
    }
    function toast(m, t) { if (window.HlxUI) { window.HlxUI.toast(m, t); } }
    function leave(item) {
        item.classList.add('is-leaving');
        window.setTimeout(function () { item.remove(); }, 380);
    }

    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (f.matches('[data-study-toggle]')) {
            e.preventDefault();
            var item = f.closest('[data-study-item]');
            f.querySelector('.study-check').classList.add('is-checking');
            send(f).then(function (res) {
                if (res.ok) { window.setTimeout(function () { leave(item); }, 250); toast('آفرین! یکی کمتر 💪', 'ok'); }
            }).catch(function () { f.submit(); });
        } else if (f.matches('[data-study-delete]')) {
            e.preventDefault();
            var row = f.closest('[data-study-item]');
            send(f).then(function (res) { if (res.ok) { leave(row); } }).catch(function () { f.submit(); });
        }
    });

    if (form && list) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var title = form.querySelector('[name="title"]');
            if (!title.value.trim()) { title.focus(); return; }
            send(form).then(function (res) {
                if (!res.ok) { toast(res.message || 'ذخیره نشد.', 'bad'); return; }
                // The server decides the order and the id; reload just this card.
                return fetch('/student', { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var fresh = doc.querySelector('[data-study-list]');
                    if (fresh) {
                        var before = {};
                        list.querySelectorAll('[data-study-item]').forEach(function (li) { before[li.querySelector('form').action] = true; });
                        list.replaceWith(fresh);
                        list = fresh;
                        list.querySelectorAll('[data-study-item]').forEach(function (li) {
                            if (!before[li.querySelector('form').action]) { li.classList.add('is-new'); }
                        });
                        var empty = document.querySelector('.home-empty-line');
                        if (empty) { empty.remove(); }
                    }
                    form.reset();
                    title.focus();
                });
            }).catch(function () { form.submit(); });
        });
    }
})();
