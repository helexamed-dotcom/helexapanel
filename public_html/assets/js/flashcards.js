/**
 * فلش‌کارت — study session, import panel, card list filter.
 *
 * The schedule is decided on the server; this file shows a card, flips it,
 * sends the rating and moves on. A card rated «دوباره» is shown again later in
 * the same session, which is how a forgotten card gets learned before the
 * student leaves.
 */
(function () {
    'use strict';

    var meta  = document.querySelector('meta[name="csrf-token"]');
    var TOKEN = meta ? meta.getAttribute('content') : '';

    function toFa(value) {
        return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[Number(d)]; });
    }

    function post(url, fields) {
        var body = new FormData();
        body.append('_token', TOKEN);
        Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
        return fetch(url, {
            method: 'POST', body: body, credentials: 'same-origin',
            headers: { 'X-CSRF-Token': TOKEN, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok || !data.ok) { throw new Error((data && data.message) || 'request failed'); }
                return data;
            });
        });
    }

    /* =================================================================
       Delete buttons that live inside an edit form
       ================================================================= */
    document.querySelectorAll('[data-fc-confirm]').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            if (!window.confirm(btn.getAttribute('data-fc-confirm'))) {
                event.preventDefault();
            } else {
                // The edit form's text fields are "required"; a delete must not
                // be blocked because one of them was cleared.
                btn.form.noValidate = true;
            }
        });
    });

    /* =================================================================
       Card list filter
       ================================================================= */
    var filter = document.querySelector('[data-fc-filter]');
    if (filter) {
        var items = document.querySelectorAll('[data-fc-item]');
        filter.addEventListener('input', function () {
            var q = filter.value.trim().toLowerCase();
            items.forEach(function (item) {
                item.hidden = q !== '' && item.textContent.toLowerCase().indexOf(q) === -1;
            });
        });
    }

    /* =================================================================
       Import panel: file / paste tabs, drag-and-drop, row count
       ================================================================= */
    document.querySelectorAll('[data-fc-import]').forEach(function (form) {
        var tabs  = form.querySelectorAll('[data-fc-tab]');
        var panes = form.querySelectorAll('[data-fc-pane]');
        var file  = form.querySelector('input[type="file"]');
        var text  = form.querySelector('textarea[name="text"]');
        var drop  = form.querySelector('[data-fc-drop]');
        var name  = form.querySelector('[data-fc-filename]');
        var count = form.querySelector('[data-fc-paste-count]');

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var which = tab.getAttribute('data-fc-tab');
                tabs.forEach(function (t) {
                    var on = t === tab;
                    t.classList.toggle('is-active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                panes.forEach(function (p) { p.hidden = p.getAttribute('data-fc-pane') !== which; });
                // Only one source travels, so the server never has to guess.
                if (which === 'file' && text) { text.value = ''; if (count) { count.textContent = ''; } }
                if (which === 'paste' && file) { file.value = ''; }
            });
        });

        if (file && name) {
            file.addEventListener('change', function () {
                if (file.files && file.files[0]) {
                    name.textContent = '✓ ' + file.files[0].name + ' — ' + toFa(Math.ceil(file.files[0].size / 1024)) + ' کیلوبایت';
                }
            });
        }

        if (drop && file) {
            drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('is-over'); });
            drop.addEventListener('dragleave', function () { drop.classList.remove('is-over'); });
            drop.addEventListener('drop', function (e) {
                e.preventDefault();
                drop.classList.remove('is-over');
                if (e.dataTransfer && e.dataTransfer.files.length) {
                    file.files = e.dataTransfer.files;
                    file.dispatchEvent(new Event('change'));
                }
            });
        }

        if (text && count) {
            text.addEventListener('input', function () {
                var lines = text.value.split(/\r?\n/).filter(function (l) { return l.trim() !== ''; });
                var withTab = lines.filter(function (l) { return l.indexOf('\t') !== -1 || l.indexOf(',') !== -1 || l.indexOf(';') !== -1; });
                count.textContent = lines.length
                    ? toFa(lines.length) + ' ردیف' + (withTab.length < lines.length ? ' — ' + toFa(lines.length - withTab.length) + ' ردیف ستون دوم ندارد' : '')
                    : '';
            });
        }
    });

    /* =================================================================
       Study session
       ================================================================= */
    var root = document.querySelector('[data-fc-study]');
    var data = root && root.querySelector('[data-fc-queue]');
    if (!root || !data) { return; }

    var queue;
    try { queue = JSON.parse(data.textContent || '[]'); } catch (e) { queue = []; }
    if (!queue.length) { return; }

    var run      = root.querySelector('[data-fc-run]');
    var summary  = root.querySelector('[data-fc-summary]');
    var flip     = root.querySelector('[data-fc-flip]');
    var front    = root.querySelector('[data-fc-front]');
    var back     = root.querySelector('[data-fc-back]');
    var hint     = root.querySelector('[data-fc-hint]');
    var hintBtn  = root.querySelector('[data-fc-show-hint]');
    var tap      = root.querySelector('[data-fc-tap]');
    var badge    = root.querySelector('[data-fc-new]');
    var reveal   = root.querySelector('[data-fc-reveal]');
    var rates    = root.querySelector('[data-fc-rates]');
    var star     = root.querySelector('[data-fc-star]');
    var counter  = root.querySelector('[data-fc-counter]');
    var bar      = root.querySelector('[data-fc-bar]');
    var timerEl  = root.querySelector('[data-fc-timer]');

    var total    = queue.length;          // distinct cards in this session
    var done     = 0;                     // distinct cards finished (not «دوباره»)
    var stats    = { reviews: 0, good: 0, again: 0 };
    var current  = null;
    var flipped  = false;
    var busy     = false;
    var started  = Date.now();

    function clock(ms) {
        var s = Math.floor(ms / 1000);
        var m = Math.floor(s / 60);
        return toFa((m < 10 ? '0' : '') + m + ':' + (s % 60 < 10 ? '0' : '') + (s % 60));
    }
    var ticker = window.setInterval(function () { timerEl.textContent = clock(Date.now() - started); }, 1000);

    function paintProgress() {
        counter.textContent = toFa(Math.min(done + 1, total)) + ' / ' + toFa(total);
        bar.style.width = Math.round(done * 100 / total) + '%';
    }

    function show(card) {
        current = card;
        flipped = false;
        flip.classList.remove('is-flipped');
        front.textContent = card.front;
        // The back is filled after the flip starts, so a quick glance at the
        // DOM during the animation does not show it early on slow devices.
        back.textContent = '';
        hint.hidden = true;
        hint.textContent = card.hint ? '💡 ' + card.hint : '';
        hintBtn.hidden = !card.hint;
        tap.hidden = false;
        badge.hidden = !card.isNew;
        star.classList.toggle('is-on', !!card.starred);
        star.textContent = card.starred ? '★' : '☆';
        star.setAttribute('aria-pressed', card.starred ? 'true' : 'false');
        reveal.hidden = false;
        rates.hidden = true;
        Object.keys(card.previews || {}).forEach(function (r) {
            var el = rates.querySelector('[data-fc-preview="' + r + '"]');
            if (el) { el.textContent = toFa(card.previews[r]); }
        });
        paintProgress();
        reveal.focus({ preventScroll: true });
    }

    function turn() {
        if (!current) { return; }
        flipped = !flipped;
        if (flipped) { back.textContent = current.back; }
        flip.classList.toggle('is-flipped', flipped);
        tap.hidden = true;
        if (flipped) {
            reveal.hidden = true;
            rates.hidden = false;
        }
    }

    function finish() {
        window.clearInterval(ticker);
        run.hidden = true;
        summary.hidden = false;
        summary.querySelector('[data-fc-sum="total"]').textContent = toFa(stats.reviews);
        summary.querySelector('[data-fc-sum="good"]').textContent  = toFa(stats.good);
        summary.querySelector('[data-fc-sum="again"]').textContent = toFa(stats.again);
        summary.querySelector('[data-fc-sum="time"]').textContent  = clock(Date.now() - started);
        var restart = summary.querySelector('[data-fc-restart]');
        restart.href = window.location.pathname + window.location.search;
        bar.style.width = '100%';
    }

    function next() {
        if (!queue.length) { finish(); return; }
        show(queue.shift());
    }

    function rate(rating) {
        if (busy || !current || !flipped) { return; }
        busy = true;
        rates.querySelectorAll('button').forEach(function (b) { b.disabled = true; });

        var card = current;
        post('/student/flashcards/rate/' + encodeURIComponent(card.id), { rating: rating })
            .then(function () {
                stats.reviews++;
                if (rating === 1) {
                    stats.again++;
                    // Seen again before the session ends: a few cards later,
                    // not immediately, so it is recall and not short-term echo.
                    card.isNew = false;
                    // After «دوباره» the run restarts, so the buttons now mean
                    // what Scheduler gives a card with no successful reviews.
                    card.previews = { 1: '10 دقیقه', 2: '1 روز', 3: '1 روز', 4: '4 روز' };
                    queue.splice(Math.min(queue.length, 3), 0, card);
                } else {
                    stats.good++;
                    done++;
                }
                next();
            })
            .catch(function (err) {
                window.alert(err.message && err.message !== 'request failed' ? err.message : 'ثبت نشد. اتصال را بررسی کن و دوباره بزن.');
            })
            .then(function () {
                busy = false;
                rates.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
            });
    }

    flip.addEventListener('click', turn);
    reveal.addEventListener('click', turn);
    hintBtn.addEventListener('click', function () { hint.hidden = false; });
    rates.querySelectorAll('[data-fc-rate]').forEach(function (b) {
        b.addEventListener('click', function () { rate(Number(b.getAttribute('data-fc-rate'))); });
    });

    star.addEventListener('click', function () {
        if (!current) { return; }
        var card = current;
        post('/student/flashcards/star/' + encodeURIComponent(card.id), {})
            .then(function (res) {
                card.starred = !!res.starred;
                if (card === current) {
                    star.classList.toggle('is-on', card.starred);
                    star.textContent = card.starred ? '★' : '☆';
                    star.setAttribute('aria-pressed', card.starred ? 'true' : 'false');
                }
            })
            .catch(function () { window.alert('ستاره ذخیره نشد.'); });
    });

    document.addEventListener('keydown', function (e) {
        var tag = (e.target && e.target.tagName) || '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.ctrlKey || e.metaKey || e.altKey) { return; }
        if (run.hidden) { return; }

        var key = e.key;
        // Persian keyboards send Persian digits; accept both.
        var digit = '۱۲۳۴'.indexOf(key) !== -1 ? '۱۲۳۴'.indexOf(key) + 1 : Number(key);

        if (key === ' ' || key === 'Enter') {
            if (e.target && e.target.closest && e.target.closest('[data-fc-rates]')) { return; }
            e.preventDefault();
            if (!flipped) { turn(); }
        } else if (digit >= 1 && digit <= 4 && flipped) {
            e.preventDefault();
            rate(digit);
        } else if (key === 's' || key === 'S' || key === 'س') {
            star.click();
        } else if ((key === 'h' || key === 'H' || key === 'ا') && current && current.hint) {
            hint.hidden = false;
        }
    });

    next();
})();
