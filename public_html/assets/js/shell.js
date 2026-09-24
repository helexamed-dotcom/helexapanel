/* =====================================================================
   HeleXa Med — the shell: header panels, the launcher, the zoom-open.

   UX only. Every panel's content comes from the server already escaped,
   and every action posts to the same routes the full pages use, which
   re-check everything.
   ===================================================================== */
(function () {
    'use strict';

    var html   = document.documentElement;
    var REDUCE = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var PHONE  = window.matchMedia ? window.matchMedia('(max-width: 620px)') : { matches: false };
    var token  = function () { return (document.querySelector('meta[name="csrf-token"]') || {}).content || ''; };

    function post(url, body) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin', body: body || new FormData(),
            headers: { 'X-CSRF-Token': token(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });
    }

    function toast(msg, tone) { if (window.HlxUI) { window.HlxUI.toast(msg, tone); } }

    /* ------------------------------------------------ header panels */
    var pops = [];
    var openPop = null;
    var scrim = null;

    function makePop(root) {
        var trigger = root.querySelector('[data-pop-trigger]');
        var panel   = root.querySelector('[data-pop-panel]');
        if (!trigger || !panel) { return; }
        var home = panel.parentNode;
        var loaded = !panel.getAttribute('data-src');
        var pop = { root: root, panel: panel };

        function load(query) {
            var src = panel.getAttribute('data-src');
            if (!src) { return Promise.resolve(); }
            var body = panel.querySelector('[data-pop-body]');
            return fetch(src + (query ? (src.indexOf('?') === -1 ? '?' : '&') + query : ''), {
                credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                if (!r.ok) { throw new Error('load'); }
                return r.text();
            }).then(function (markup) {
                // The fragment is server-rendered from escaped templates.
                body.innerHTML = markup;
                loaded = true;
                wire(pop, body);
            }).catch(function () {
                body.innerHTML = '';
                var p = document.createElement('p');
                p.className = 'hub-empty';
                p.textContent = 'بارگذاری نشد. دوباره امتحان کن.';
                body.appendChild(p);
            });
        }
        pop.reload = load;

        pop.open = function () {
            if (openPop && openPop !== pop) { openPop.close(true); }
            if (PHONE.matches) {
                document.body.appendChild(panel);
                panel.classList.add('is-sheet');
                html.classList.add('hx-sheet-open');
                scrim = document.createElement('div');
                scrim.className = 'hx-sheet-scrim';
                scrim.addEventListener('click', function () { pop.close(); });
                document.body.insertBefore(scrim, panel);
                swipeToClose(panel, pop);
            }
            panel.classList.remove('is-leaving');
            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            openPop = pop;
            if (!loaded) { load(); }
        };

        pop.close = function (instant) {
            if (panel.hidden) { return; }
            trigger.setAttribute('aria-expanded', 'false');
            if (openPop === pop) { openPop = null; }
            var finish = function () {
                panel.hidden = true;
                panel.classList.remove('is-leaving', 'is-sheet');
                if (panel.parentNode !== home) { home.appendChild(panel); }
                html.classList.remove('hx-sheet-open');
            };
            var s = scrim;
            scrim = null;
            if (instant || REDUCE) {
                if (s) { s.remove(); }
                finish();
                return;
            }
            panel.classList.add('is-leaving');
            if (s) { s.classList.add('is-leaving'); window.setTimeout(function () { s.remove(); }, 240); }
            window.setTimeout(finish, 220);
        };

        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            if (panel.hidden) { pop.open(); } else { pop.close(); }
        });
        panel.addEventListener('click', function (e) { e.stopPropagation(); });
        pops.push(pop);
    }

    function swipeToClose(panel, pop) {
        var y0 = null;
        panel.ontouchstart = function (e) {
            var body = panel.querySelector('.hx-panel-body');
            y0 = (!body || body.scrollTop <= 0) ? e.touches[0].clientY : null;
        };
        panel.ontouchmove = function (e) {
            if (y0 === null) { return; }
            var dy = e.touches[0].clientY - y0;
            if (dy > 0) { panel.style.transform = 'translateY(' + dy + 'px)'; }
        };
        panel.ontouchend = function (e) {
            if (y0 === null) { return; }
            var dy = e.changedTouches[0].clientY - y0;
            panel.style.transform = '';
            y0 = null;
            if (dy > 90) { pop.close(); }
        };
    }

    document.querySelectorAll('[data-pop]').forEach(makePop);

    document.addEventListener('click', function () { if (openPop) { openPop.close(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && openPop) { openPop.close(); } });
    window.addEventListener('resize', function () { if (openPop && openPop.panel.classList.contains('is-sheet') !== PHONE.matches) { openPop.close(true); } });

    /* Behaviour inside a freshly loaded panel. */
    function wire(pop, body) {
        // tabs (🔔)
        var tabs = body.querySelectorAll('[data-hub-tab]');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var name = tab.getAttribute('data-hub-tab');
                tabs.forEach(function (t) { t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
                body.querySelectorAll('[data-hub-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-hub-pane') !== name; });
                if (name === 'support') { scrollChat(body); }
            });
        });

        // "همه را خواندم"
        body.querySelectorAll('[data-hub-post]').forEach(function (b) {
            b.addEventListener('click', function () {
                b.disabled = true;
                post(b.getAttribute('data-hub-post')).then(function () {
                    body.querySelectorAll('.hub-row.is-unread').forEach(function (r) { r.classList.remove('is-unread'); });
                    var badge = pop.root.querySelector('.hx-badge');
                    if (badge) { badge.remove(); }
                    b.remove();
                });
            });
        });

        // opening a notification marks it read on the way
        body.querySelectorAll('[data-hub-read]').forEach(function (a) {
            a.addEventListener('click', function () {
                if (navigator.sendBeacon) {
                    var fd = new FormData();
                    fd.append('_token', token());
                    navigator.sendBeacon(a.getAttribute('data-hub-read'), fd);
                }
            });
        });

        // support chat
        var chat = body.querySelector('[data-chat-form]');
        if (chat) {
            var input = chat.querySelector('[data-chat-input]');
            var file  = chat.querySelector('[data-chat-file]');
            var clip  = chat.querySelector('.chat-attach');
            var grow = function () { input.style.height = 'auto'; input.style.height = Math.min(120, input.scrollHeight) + 'px'; };
            input.addEventListener('input', grow);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey && !PHONE.matches) { e.preventDefault(); chat.requestSubmit ? chat.requestSubmit() : chat.submit(); }
            });
            if (file && clip) { file.addEventListener('change', function () { clip.classList.toggle('has-file', !!(file.files && file.files.length)); }); }
            chat.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!input.value.trim() && !(file && file.files && file.files.length)) { input.focus(); return; }
                var send = chat.querySelector('.chat-send');
                send.disabled = true;
                post(chat.action, new FormData(chat)).then(function (r) { return r.json().catch(function () { return { ok: r.ok }; }); })
                    .then(function (res) {
                        if (res && res.ok) {
                            pop.reload('tab=support');
                        } else {
                            send.disabled = false;
                            toast((res && res.message) || 'ارسال نشد.', 'bad');
                        }
                    }).catch(function () { send.disabled = false; toast('ارسال نشد. اتصال را بررسی کن.', 'bad'); });
            });
            if (!body.querySelector('[data-hub-pane="support"]').hidden) { scrollChat(body); }
        }

        // activation code (🔑)
        var act = body.querySelector('[data-activate-form]');
        if (act) {
            var msg = act.querySelector('[data-activate-msg]');
            act.addEventListener('submit', function (e) {
                e.preventDefault();
                var btn = act.querySelector('button');
                btn.disabled = true;
                msg.className = 'hub-code-msg';
                msg.textContent = 'در حال بررسی…';
                post(act.action, new FormData(act)).then(function (r) { return r.json(); }).then(function (res) {
                    msg.textContent = res.message || (res.ok ? 'فعال شد.' : 'کد معتبر نیست.');
                    msg.classList.add(res.ok ? 'is-ok' : 'is-bad');
                    btn.disabled = false;
                    if (res.ok) {
                        toast(res.message || 'پکیج فعال شد 🎉', 'ok');
                        window.setTimeout(function () { window.location.reload(); }, 1400);
                    }
                }).catch(function () {
                    btn.disabled = false;
                    msg.textContent = 'ارسال نشد. دوباره امتحان کن.';
                    msg.classList.add('is-bad');
                });
            });
            var codeInput = act.querySelector('input[name="code"]');
            if (codeInput && !PHONE.matches) { window.setTimeout(function () { codeInput.focus(); }, 120); }
        }

        // cart (🛒) quantity and remove buttons post in place
        body.querySelectorAll('[data-cart-post]').forEach(function (b) {
            b.addEventListener('click', function () {
                var fd = new FormData();
                var extra = b.getAttribute('data-cart-body');
                if (extra) { extra.split('&').forEach(function (kv) { var p = kv.split('='); fd.append(p[0], decodeURIComponent(p[1] || '')); }); }
                post(b.getAttribute('data-cart-post'), fd).then(function (r) { return r.json(); }).then(function (res) {
                    updateCartBadge(res && res.count);
                    pop.reload();
                });
            });
        });
    }

    function scrollChat(body) {
        var thread = body.querySelector('[data-chat-thread]');
        if (thread) { window.requestAnimationFrame(function () { thread.scrollTop = thread.scrollHeight; }); }
    }

    function updateCartBadge(count) {
        var badge = document.querySelector('[data-cart-badge]');
        if (!badge || typeof count !== 'number') { return; }
        badge.hidden = count <= 0;
        badge.textContent = String(Math.min(99, count)).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
        badge.style.animation = 'none';
        void badge.offsetWidth;
        badge.style.animation = '';
    }
    window.HxShell = { updateCartBadge: updateCartBadge };

    /* ------------------------------------------------------ launcher */
    (function () {
        var root = document.querySelector('[data-launcher]');
        if (!root) { return; }
        var panel   = root.querySelector('.lx-panel');
        var body    = root.querySelector('.lx-body');
        var search  = root.querySelector('[data-launcher-search]');
        var items   = root.querySelectorAll('[data-lx-item]');
        var groups  = root.querySelectorAll('[data-lx-group]');
        var empty   = root.querySelector('[data-lx-empty]');
        var toggles = document.querySelectorAll('[data-launcher-toggle]');
        var wide    = window.matchMedia ? window.matchMedia('(min-width: 901px)') : { matches: true };
        var opener  = null;
        var timer   = null;

        function isOpen() { return root.classList.contains('is-open'); }
        function expanded(on) { toggles.forEach(function (t) { t.setAttribute('aria-expanded', on ? 'true' : 'false'); }); }

        /* On a phone every icon starts at the round button and flies to its
           place; the offsets are measured, not guessed. */
        function aimFromOrb() {
            if (wide.matches) { return; }
            var orb = document.querySelector('.hx-tabbar .tab-orb');
            if (!orb) { return; }
            var o = orb.getBoundingClientRect();
            var ox = o.left + o.width / 2, oy = o.top + o.height / 2;
            root.querySelectorAll('.lx-item').forEach(function (a) {
                a.style.setProperty('--fx', '0px');
                a.style.setProperty('--fy', '0px');
            });
            // Laid out without the offsets first, so the rects are final places.
            void panel.offsetWidth;
            root.querySelectorAll('.lx-item').forEach(function (a) {
                var r = a.getBoundingClientRect();
                a.style.setProperty('--fx', (ox - (r.left + r.width / 2)) + 'px');
                a.style.setProperty('--fy', (oy - (r.top + r.height / 2)) + 'px');
            });
        }

        function open() {
            if (isOpen()) { return; }
            window.clearTimeout(timer);
            if (openPop) { openPop.close(true); }
            opener = document.activeElement;
            root.hidden = false;
            html.classList.add('lx-open');
            expanded(true);
            if (body) { body.scrollTop = 0; }
            // measure while the panel is at full size but invisible
            panel.style.transition = 'none';
            panel.style.transform = 'none';
            aimFromOrb();
            panel.style.transform = '';
            void panel.offsetWidth;
            panel.style.transition = '';
            root.classList.add('is-open');
            window.setTimeout(function () {
                try { (wide.matches && search ? search : panel).focus({ preventScroll: true }); } catch (e) {}
            }, 80);
        }

        function close(restore) {
            if (!isOpen()) { return; }
            root.classList.remove('is-open');
            expanded(false);
            timer = window.setTimeout(function () {
                root.hidden = true;
                html.classList.remove('lx-open');
                if (search && search.value) { search.value = ''; filter(''); }
            }, REDUCE ? 0 : 420);
            if (restore !== false && opener && opener.focus) { try { opener.focus({ preventScroll: true }); } catch (e) {} }
        }

        function norm(t) {
            return String(t || '').toLowerCase().replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[\s‌]+/g, '');
        }
        function filter(q) {
            q = norm(q);
            var shown = 0;
            items.forEach(function (a) {
                var hit = !q || norm(a.getAttribute('data-search')).indexOf(q) !== -1;
                a.hidden = !hit;
                if (hit) { shown++; }
            });
            groups.forEach(function (g) { g.hidden = !g.querySelector('[data-lx-item]:not([hidden])'); });
            if (empty) { empty.hidden = shown !== 0; }
        }

        toggles.forEach(function (t) {
            t.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (isOpen()) { close(); } else { open(); }
            });
        });
        root.querySelectorAll('[data-launcher-close]').forEach(function (b) { b.addEventListener('click', function () { close(); }); });
        if (search) {
            search.addEventListener('input', function () { filter(search.value); });
            search.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') { return; }
                var first = root.querySelector('[data-lx-item]:not([hidden])');
                if (first) { e.preventDefault(); window.location.href = first.href; }
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) { e.preventDefault(); close(); return; }
            if ((e.ctrlKey || e.metaKey) && !e.altKey && e.code === 'KeyK') { e.preventDefault(); if (isOpen()) { close(); } else { open(); } }
        });
        var y0 = null;
        panel.addEventListener('touchstart', function (e) { y0 = (!body || body.scrollTop <= 0) ? e.touches[0].clientY : null; }, { passive: true });
        panel.addEventListener('touchend', function (e) {
            if (y0 !== null && e.changedTouches[0].clientY - y0 > 90) { close(false); }
            y0 = null;
        });
        window.addEventListener('pageshow', function (e) {
            if (e.persisted && isOpen()) { root.classList.remove('is-open'); root.hidden = true; html.classList.remove('lx-open'); expanded(false); }
        });
    })();

    /* ------------------------------------------- admin menu accordion
       One module open at a time; the search box opens every module with a
       match and hides every page without one. */
    (function () {
        var groups = document.querySelectorAll('[data-adn-group]');
        if (!groups.length) { return; }
        var searching = false;
        groups.forEach(function (g) {
            g.addEventListener('toggle', function () {
                if (!g.open || searching) { return; }
                groups.forEach(function (o) { if (o !== g) { o.open = false; } });
            });
        });
        var search = document.querySelector('[data-adn-search]');
        if (!search) { return; }
        var initial = Array.prototype.map.call(groups, function (g) { return g.open; });
        var norm = function (t) { return String(t || '').toLowerCase().replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[\s\u200c]+/g, ''); };
        search.addEventListener('input', function () {
            var q = norm(search.value);
            searching = q !== '';
            groups.forEach(function (g, i) {
                var hits = 0;
                g.querySelectorAll('[data-adn-item]').forEach(function (a) {
                    var hit = !q || norm(a.getAttribute('data-search')).indexOf(q) !== -1;
                    a.hidden = !hit;
                    if (hit) { hits++; }
                });
                g.hidden = q !== '' && hits === 0;
                g.open = q !== '' ? hits > 0 : initial[i];
            });
        });
        search.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') { return; }
            var first = document.querySelector('[data-adn-item]:not([hidden])');
            var group = first && first.closest('[data-adn-group]');
            if (first && group && !group.hidden) { e.preventDefault(); window.location.href = first.href; }
        });
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && !e.altKey && e.code === 'KeyK') { e.preventDefault(); search.focus(); search.select(); }
        });
    })();

    /* --------------------------------------------------- zoom-open
       Tapping a card marked .hx-zoom grows it to fill the screen, then the
       next page loads underneath — like opening a photo in the gallery. A
       modified click (new tab) or reduced motion skips it. */
    document.addEventListener('click', function (e) {
        if (REDUCE || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        var a = e.target.closest ? e.target.closest('a.hx-zoom[href]') : null;
        if (!a || a.target === '_blank' || a.getAttribute('href').charAt(0) === '#') { return; }
        if (!document.startViewTransition && !('animate' in a)) { return; }
        e.preventDefault();
        var r = a.getBoundingClientRect();
        var ghost = document.createElement('div');
        ghost.className = 'hx-zoom-ghost';
        var clone = a.cloneNode(true);
        clone.style.margin = '0';
        clone.style.width = r.width + 'px';
        clone.style.opacity = '1';
        clone.style.transform = 'none';
        ghost.appendChild(clone);
        ghost.style.top = r.top + 'px';
        ghost.style.left = r.left + 'px';
        ghost.style.width = r.width + 'px';
        ghost.style.height = r.height + 'px';
        document.body.appendChild(ghost);
        void ghost.offsetWidth;
        ghost.style.top = '0px';
        ghost.style.left = '0px';
        ghost.style.width = window.innerWidth + 'px';
        ghost.style.height = window.innerHeight + 'px';
        ghost.style.borderRadius = '0px';
        ghost.classList.add('is-full');
        window.setTimeout(function () { window.location.href = a.href; }, 360);
        window.addEventListener('pageshow', function () { ghost.remove(); }, { once: true });
    });

    /* ------------------------------------------ tab strips on phones */
    // A horizontally scrolling strip starts with its current tab in view.
    document.querySelectorAll('.ptabs .ptab.is-active').forEach(function (t) {
        var strip = t.parentElement;
        strip.scrollLeft += (t.getBoundingClientRect().left + t.offsetWidth / 2) - (strip.getBoundingClientRect().left + strip.clientWidth / 2);
    });

    /* ------------------------------------------------ «بازگشت» */
    // Back through history when we came from one of our own pages (and not
    // from this same page, as after a form was saved); else the parent page
    // the server worked out, which is the link's own address.
    document.querySelectorAll('[data-back]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            var ref = document.referrer;
            if (!ref || window.history.length < 2) { return; }
            try {
                var u = new URL(ref);
                if (u.origin !== window.location.origin || u.pathname === window.location.pathname || /^\/(login|register|auth)/.test(u.pathname)) { return; }
            } catch (x) { return; }
            e.preventDefault();
            window.history.back();
        });
    });
})();
