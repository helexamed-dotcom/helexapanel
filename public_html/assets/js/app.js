/* HeleXa Med — panel scripts.
   UX only. Nothing here is a security control: every rule enforced in this
   file is enforced again on the server, which is the copy that counts. */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="csrf-token"]');
    window.HELEXA_CSRF = meta ? meta.getAttribute('content') : '';

    window.helexaFetch = function (url, options) {
        options = options || {};
        options.headers = Object.assign({
            'X-CSRF-Token': window.HELEXA_CSRF,
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/json'
        }, options.headers || {});
        options.credentials = 'same-origin';
        return fetch(url, options);
    };

    /* --------------------------------------------------- theme switch
       The initial value is applied by a tiny inline script in <head>, before
       first paint. This only handles the toggle and remembering the choice. */
    var themeMeta = document.getElementById('theme-color-meta');

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        if (themeMeta) {
            themeMeta.setAttribute('content', theme === 'dark' ? '#0e131a' : '#ffffff');
        }
    }

    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try { localStorage.setItem('helexa_theme', next); } catch (e) {}
            // Remember it on the account as well, so other devices follow.
            var root = document.documentElement;
            root.setAttribute('data-mode', next);
            var token = document.querySelector('meta[name="csrf-token"]');
            if (window.fetch && token) {
                var body = new URLSearchParams();
                body.set('mode', next);
                fetch('/account/settings/mode', {
                    method: 'POST', credentials: 'same-origin', body: body,
                    headers: { 'X-CSRF-Token': token.getAttribute('content') }
                }).catch(function () { /* offline: the local choice still applies */ });
            }
        });
    });

    // Follow the operating system until the user expresses a preference.
    if (window.matchMedia) {
        var scheme = window.matchMedia('(prefers-color-scheme: dark)');
        var onSchemeChange = function (event) {
            var chosen = null;
            try { chosen = localStorage.getItem('helexa_theme'); } catch (e) {}
            if (!chosen) { applyTheme(event.matches ? 'dark' : 'light'); }
        };
        if (scheme.addEventListener) { scheme.addEventListener('change', onSchemeChange); }
    }

    /* ------------------------------------------------ sidebar rail */
    document.querySelectorAll('[data-sidebar-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
            try {
                localStorage.setItem('helexa_sidebar', collapsed ? 'collapsed' : 'expanded');
            } catch (e) {}
        });
    });

    /* ------------------------------------------------ mobile drawer
       The drawer is a modal on small screens, so it behaves like one: the page
       behind it stops scrolling, Tab stays inside it, Escape closes it, and
       focus returns to the button that opened it. */
    var toggle  = document.querySelector('[data-menu-toggle]');
    var sidebar = document.querySelector('[data-sidebar]');
    var scrim   = document.querySelector('[data-scrim]');
    var closers = document.querySelectorAll('[data-menu-close]');

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

    function drawerIsModal() {
        return window.matchMedia('(max-width: 900px)').matches;
    }

    function isMenuOpen() {
        return !!sidebar && sidebar.classList.contains('is-open');
    }

    function closeMenu() {
        if (!isMenuOpen()) { return; }
        sidebar.classList.remove('is-open');
        if (scrim) { scrim.classList.remove('is-open'); }
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
            if (drawerIsModal()) { toggle.focus(); }
        }
        document.documentElement.classList.remove('drawer-open');
    }

    function openMenu() {
        if (!sidebar || isMenuOpen()) { return; }
        sidebar.classList.add('is-open');
        if (scrim) { scrim.classList.add('is-open'); }
        if (toggle) { toggle.setAttribute('aria-expanded', 'true'); }
        document.documentElement.classList.add('drawer-open');

        var first = sidebar.querySelector('.nav-item.is-active') || sidebar.querySelector(FOCUSABLE);
        if (first && first.focus) { first.focus({ preventScroll: true }); }
    }

    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            if (isMenuOpen()) { closeMenu(); } else { openMenu(); }
        });

        if (scrim) { scrim.addEventListener('click', closeMenu); }
        closers.forEach(function (button) { button.addEventListener('click', closeMenu); });

        // Following a link inside the drawer navigates away; leaving the open
        // state behind would flash the drawer again on the next page.
        sidebar.addEventListener('click', function (event) {
            if (event.target.closest && event.target.closest('a[href]')) { closeMenu(); }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { closeMenu(); return; }
            if (event.key !== 'Tab' || !isMenuOpen() || !drawerIsModal()) { return; }

            var items = Array.prototype.filter.call(
                sidebar.querySelectorAll(FOCUSABLE),
                function (el) { return el.offsetParent !== null; }
            );
            if (!items.length) { return; }

            var first = items[0];
            var last  = items[items.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        // Growing past the breakpoint turns the drawer back into a static
        // column; the locked body scroll has to be released with it.
        window.addEventListener('resize', function () {
            if (!drawerIsModal()) { closeMenu(); }
        });
    }

    /* ------------------------------- selects that submit their own form
       Declared with data-auto-submit instead of an inline onchange, because
       the panel CSP allows no inline event handlers. */
    document.querySelectorAll('[data-auto-submit]').forEach(function (control) {
        control.addEventListener('change', function () {
            if (control.form) { control.form.submit(); }
        });
    });

    /* --------------------------------------- confirm and lock on submit
       Locking the button also prevents the double-submit that creates two
       students or sends a message twice, which is a correctness win, not
       just a cosmetic one. */
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var question = form.getAttribute('data-confirm');
            if (question && !window.confirm(question)) {
                event.preventDefault();
                return;
            }

            var button = form.querySelector('button[type="submit"], button:not([type])');
            if (button && !button.classList.contains('is-busy')) {
                // Let the browser send the form first, then disable.
                window.setTimeout(function () { button.classList.add('is-busy'); }, 0);

                // If the page is still here after a while, the request failed
                // or was blocked; give the button back rather than stranding it.
                window.setTimeout(function () { button.classList.remove('is-busy'); }, 12000);
            }
        });
    });

    /* -------------------------------------------- dismissible messages */
    document.querySelectorAll('.alert').forEach(function (alert) {
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'alert-close';
        close.setAttribute('aria-label', 'بستن');
        close.textContent = '×';

        function dismiss() {
            alert.classList.add('is-leaving');
            window.setTimeout(function () { alert.remove(); }, 250);
        }

        close.addEventListener('click', dismiss);
        alert.appendChild(close);

        // Success notices fade on their own; errors stay until dismissed,
        // and anything holding a one-time password is never auto-hidden.
        if (alert.classList.contains('alert-success') && !alert.querySelector('.mono')) {
            window.setTimeout(dismiss, 7000);
        }
    });

    /* --------------------------------- academic cascade on the student form
       University -> major -> terms -> group. Options that do not belong to the
       level above are hidden, and any that were already selected are cleared,
       so the form cannot submit a combination the server would silently drop. */
    var universitySelect = document.querySelector('[data-chain="university"]');
    var majorSelect      = document.querySelector('[data-chain="major"]');
    var groupSelect      = document.querySelector('[data-chain="group"]');
    var termBox          = document.getElementById('term-choices');

    function filterOptions(select, attribute, allowed) {
        if (!select) { return; }
        Array.prototype.forEach.call(select.options, function (option) {
            if (!option.value) { return; }
            var value = option.getAttribute(attribute) || '';
            var show  = allowed === null || value === String(allowed);
            option.hidden = !show;
            if (!show && option.selected) { select.value = ''; }
        });
    }

    function syncTerms() {
        if (!termBox) { return; }
        var majorId = majorSelect ? majorSelect.value : '';
        var checked = [];

        termBox.querySelectorAll('.perm-item').forEach(function (item) {
            var itemMajor = item.getAttribute('data-major') || '';
            // A term with no major is general and always offered.
            var show = itemMajor === '' || (majorId !== '' && itemMajor === majorId);
            item.hidden = !show;

            var input = item.querySelector('input');
            if (!show && input.checked) { input.checked = false; }
            if (input.checked) { checked.push(input.value); }
        });

        filterGroups(checked);
    }

    function filterGroups(termIds) {
        if (!groupSelect) { return; }
        Array.prototype.forEach.call(groupSelect.options, function (option) {
            if (!option.value) { return; }
            var show = termIds.indexOf(option.getAttribute('data-term')) !== -1;
            option.hidden = !show;
            if (!show && option.selected) { groupSelect.value = ''; }
        });
    }

    if (universitySelect || majorSelect || termBox) {
        if (universitySelect) {
            universitySelect.addEventListener('change', function () {
                filterOptions(majorSelect, 'data-university', universitySelect.value || null);
                syncTerms();
            });
        }
        if (majorSelect) { majorSelect.addEventListener('change', syncTerms); }
        if (termBox) {
            termBox.addEventListener('change', function () {
                var checked = [];
                termBox.querySelectorAll('input:checked').forEach(function (input) { checked.push(input.value); });
                filterGroups(checked);
            });
        }

        // Initial pass, so an edit form opens showing only valid options.
        if (universitySelect && universitySelect.value) {
            filterOptions(majorSelect, 'data-university', universitySelect.value);
        }
        syncTerms();
    }

    /* ------------------------------------------ wide-table scroll hint */
    function markScrollable() {
        document.querySelectorAll('.table-wrap').forEach(function (wrap) {
            var overflowing = wrap.scrollWidth > wrap.clientWidth + 4;
            wrap.classList.toggle('is-scrollable', overflowing && wrap.scrollLeft > -(wrap.scrollWidth - wrap.clientWidth - 4));
        });
    }
    markScrollable();
    window.addEventListener('resize', markScrollable);
    document.querySelectorAll('.table-wrap').forEach(function (wrap) {
        wrap.addEventListener('scroll', markScrollable, { passive: true });
    });
})();

/* ------------------------------------------------ subject auto-fill
   On the schedule builder and exam forms: picking a subject from the list
   fills the free-text title for convenience, without ever making that link
   mandatory — clearing the subject leaves the typed title untouched. */
document.querySelectorAll('[data-title-source]').forEach(function (select) {
    var titleInput = document.getElementById('item-title') || select.closest('form').querySelector('input[name="title"]');
    if (!titleInput) { return; }

    select.addEventListener('change', function () {
        var option = select.options[select.selectedIndex];
        var title  = option ? option.getAttribute('data-title') : '';
        if (title && (!titleInput.value || titleInput.dataset.autofilled === '1')) {
            titleInput.value = title;
            titleInput.dataset.autofilled = '1';
        }
    });

    titleInput.addEventListener('input', function () {
        if (titleInput.dataset.autofilled === '1' && titleInput.value !== (select.options[select.selectedIndex] || {}).getAttribute?.('data-title')) {
            titleInput.dataset.autofilled = '0';
        }
    });
});

/* ----------------------------------------------- notification audience
   Shows only the field the selected audience actually needs, so the admin
   is never staring at six mostly-irrelevant selects at once. Purely a
   convenience: the server validates and drops anything the audience does
   not use, regardless of what the form happened to submit. */
(function () {
    var select = document.getElementById('notif-audience');
    if (!select) { return; }

    var fields = document.querySelectorAll('[data-audience-field]');

    function sync() {
        fields.forEach(function (field) {
            field.hidden = field.getAttribute('data-audience-field') !== select.value;
        });
    }

    select.addEventListener('change', sync);
    sync();
})();

/* =====================================================================
   List / grid switch — remembers the choice per page in this browser.
   ===================================================================== */
(function () {
    'use strict';
    document.querySelectorAll('[data-view-switch]').forEach(function (group) {
        var name   = group.getAttribute('data-view-switch');
        var target = document.querySelector('[data-view-target="' + name + '"]');
        if (!target) { return; }
        var key = 'helexa_view_' + name;

        function apply(view) {
            target.setAttribute('data-view', view);
            group.querySelectorAll('[data-view]').forEach(function (b) {
                var on = b.getAttribute('data-view') === view;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }

        try {
            var saved = localStorage.getItem(key);
            if (saved === 'list' || saved === 'grid') { apply(saved); }
        } catch (e) { /* private mode */ }

        group.querySelectorAll('[data-view]').forEach(function (b) {
            b.addEventListener('click', function () {
                var view = b.getAttribute('data-view');
                apply(view);
                try { localStorage.setItem(key, view); } catch (e) { /* ignore */ }
            });
        });
    });
})();

/* =====================================================================
   Flip clock
   Iran time, whatever the device's own zone, corrected by the offset
   between the server's clock and this device's at page load — a phone
   whose clock is a few minutes off still shows the right time.
   ===================================================================== */
(function () {
    'use strict';
    var clock = document.querySelector('[data-flipclock]');
    if (!clock) { return; }

    var serverMs = Number(clock.getAttribute('data-server-ms')) || Date.now();
    var offset   = serverMs - Date.now();
    var zone     = clock.getAttribute('data-zone') || 'Asia/Tehran';
    var DIGITS   = '۰۱۲۳۴۵۶۷۸۹';
    var fa = function (s) { return String(s).replace(/[0-9]/g, function (d) { return DIGITS[Number(d)]; }); };

    var fmt;
    try {
        fmt = new Intl.DateTimeFormat('en-GB', { timeZone: zone, hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
    } catch (e) {
        fmt = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
    }

    function parts() {
        var out = {};
        fmt.formatToParts(new Date(Date.now() + offset)).forEach(function (p) { out[p.type] = p.value; });
        return { h: out.hour, m: out.minute, s: out.second };
    }

    var cards = {};
    ['h', 'm', 's'].forEach(function (u) {
        var el = clock.querySelector('[data-flip="' + u + '"]');
        if (!el) { return; }
        cards[u] = {
            el: el,
            top: el.querySelector('.flip-static.flip-top b'),
            bottom: el.querySelector('.flip-static.flip-bottom b'),
            leafFront: el.querySelector('.leaf-front b'),
            leafBack: el.querySelector('.leaf-back b'),
            value: null,
            timer: null
        };
    });

    function set(card, value) {
        var v = fa(value);
        if (card.value === null) {
            card.top.textContent = card.bottom.textContent = card.leafFront.textContent = card.leafBack.textContent = v;
            card.value = v;
            return;
        }
        if (card.value === v) { return; }

        var old = card.value;
        card.value = v;

        // Behind the leaf: new top, old bottom. On the leaf: old top turning
        // down to reveal the new bottom on its back.
        card.top.textContent = v;
        card.bottom.textContent = old;
        card.leafFront.textContent = old;
        card.leafBack.textContent = v;

        card.el.classList.remove('is-turning');
        void card.el.offsetWidth;           // restart the animation
        card.el.classList.add('is-turning');

        window.clearTimeout(card.timer);
        card.timer = window.setTimeout(function () {
            card.bottom.textContent = v;
            card.leafFront.textContent = v;
            card.el.classList.remove('is-turning');
        }, 620);
    }

    function tick() {
        var t = parts();
        Object.keys(cards).forEach(function (u) { set(cards[u], t[u]); });
    }

    tick();
    // Align to the next whole second, then tick every second.
    window.setTimeout(function () {
        tick();
        window.setInterval(tick, 1000);
    }, 1000 - ((Date.now() + offset) % 1000));
})();

/* =====================================================================
   Content library form: show only the fields the chosen kind uses and
   preview a new cover exactly as the 4:3 card will crop it.
   ===================================================================== */
(function () {
    'use strict';
    var form = document.querySelector('[data-lib-form]');
    if (!form) { return; }

    function sync() {
        var checked = form.querySelector('[data-lib-kind]:checked');
        var kind = checked ? checked.value : '';
        form.querySelectorAll('[data-lib-for]').forEach(function (box) {
            var on = box.getAttribute('data-lib-for').split(' ').indexOf(kind) !== -1;
            box.hidden = !on;
        });
        var file = form.querySelector('input[name="file"]');
        if (file) {
            file.accept = kind === 'video' ? 'video/mp4,video/webm'
                : kind === 'image' ? 'image/jpeg,image/png,image/webp,image/gif'
                : '.pdf,.docx,.pptx,.xlsx,.zip,.mp3';
        }
    }
    form.querySelectorAll('[data-lib-kind]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();

    var cover = form.querySelector('[data-lib-cover]');
    var box = form.querySelector('[data-lib-cover-preview]');
    var lastUrl = null;
    if (cover && box) {
        cover.addEventListener('change', function () {
            var f = cover.files && cover.files[0];
            if (!f || !/^image\//.test(f.type)) { return; }
            if (lastUrl) { URL.revokeObjectURL(lastUrl); }
            lastUrl = URL.createObjectURL(f);
            var img = document.createElement('img');
            img.alt = '';
            img.src = lastUrl;
            box.textContent = '';
            box.appendChild(img);
        });
    }
})();

/* Copy-to-clipboard buttons: data-copy-target="#selector" */
(function () {
    'use strict';
    document.addEventListener('click', function (event) {
        var btn = event.target.closest ? event.target.closest('[data-copy-target]') : null;
        if (!btn) { return; }
        var source = document.querySelector(btn.getAttribute('data-copy-target'));
        if (!source) { return; }
        var text = 'value' in source ? source.value : source.textContent;
        var done = function () {
            var old = btn.textContent;
            btn.textContent = 'کپی شد ✓';
            window.setTimeout(function () { btn.textContent = old; }, 1600);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { source.select && source.select(); });
        } else if (source.select) {
            source.select();
            try { document.execCommand('copy'); done(); } catch (e) { /* user can copy manually */ }
        }
    });
})();

/* Appearance settings: preview the accent and mode before saving. */
(function () {
    'use strict';
    var form = document.querySelector('[data-prefs-form]');
    if (!form) { return; }
    var root = document.documentElement;
    form.addEventListener('change', function (event) {
        var el = event.target;
        if (el.hasAttribute('data-accent-pick')) {
            root.setAttribute('data-accent', el.value);
        } else if (el.hasAttribute('data-mode-pick')) {
            var dark = el.value === 'dark' ||
                (el.value === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            root.setAttribute('data-theme', dark ? 'dark' : 'light');
        }
    });
    form.addEventListener('submit', function () {
        var mode = form.querySelector('[data-mode-pick]:checked');
        try {
            if (!mode || mode.value === 'system') { localStorage.removeItem('helexa_theme'); }
            else { localStorage.setItem('helexa_theme', mode.value); }
        } catch (e) {}
    });
})();
/* Choosing classes: live count and same-time warnings. */
(function () {
    'use strict';
    var form = document.querySelector('[data-pick-form]');
    if (!form) { return; }
    var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="units[]"]'));
    var count = form.querySelector('[data-pick-count]');
    var fa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };

    function slots(box) {
        return (box.getAttribute('data-slots') || '').split(';').filter(Boolean).map(function (s) {
            var p = s.split('-');
            return { day: +p[0], from: +p[1], to: +p[2] };
        });
    }

    function sync() {
        var picked = boxes.filter(function (b) { return b.checked; });
        boxes.forEach(function (b) {
            var clash = false;
            if (b.checked) {
                var mine = slots(b);
                picked.forEach(function (o) {
                    if (o === b) { return; }
                    slots(o).forEach(function (t) {
                        mine.forEach(function (s) {
                            if (s.day === t.day && s.from < t.to && t.from < s.to) { clash = true; }
                        });
                    });
                });
            }
            var label = b.closest('.pick-opt');
            if (label) {
                label.classList.toggle('is-clash', clash);
                var note = label.querySelector('.pick-clash');
                if (note) { note.hidden = !clash; }
            }
        });
        if (count) {
            var lessons = {};
            picked.forEach(function (b) { lessons[b.getAttribute('data-title')] = true; });
            count.textContent = picked.length ? fa(Object.keys(lessons).length) + ' درس انتخاب شده' : 'هیچ درسی انتخاب نشده — برنامه گروه خودتان نمایش داده می‌شود';
        }
    }

    boxes.forEach(function (b) { b.addEventListener('change', sync); });
    var reset = form.querySelector('[data-pick-reset]');
    if (reset) {
        reset.addEventListener('click', function (e) {
            if (!window.confirm('انتخاب‌ها پاک شود و برنامه گروه خودتان نمایش داده شود؟')) { e.preventDefault(); }
        });
    }
    sync();
})();

/* -----------------------------------------------------------------------
   Tick or clear a whole checklist.

   Declared with data-check-all="#section" rather than an inline handler,
   which the panel's content security policy does not allow.
   ----------------------------------------------------------------------- */
(function () {
    'use strict';

    function apply(button, checked) {
        var target = document.querySelector(button.getAttribute(checked ? 'data-check-all' : 'data-check-none'));
        if (!target) { return; }
        target.querySelectorAll('input[type="checkbox"]:not([disabled])').forEach(function (box) {
            if (box.checked !== checked) {
                box.checked = checked;
                box.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    document.addEventListener('click', function (event) {
        var all = event.target.closest ? event.target.closest('[data-check-all]') : null;
        if (all) { event.preventDefault(); apply(all, true); return; }

        var none = event.target.closest ? event.target.closest('[data-check-none]') : null;
        if (none) { event.preventDefault(); apply(none, false); }
    });
})();

/* -----------------------------------------------------------------------
   HlxUI — the site's pop-ups: a modal sheet and a toast.

   Built from DOM nodes, never from HTML strings, except where a caller
   passes markup it produced itself from escaped server output (the
   درسنامه). Esc, the backdrop and the close button all dismiss a modal, and
   focus returns to whatever opened it.
   ----------------------------------------------------------------------- */
(function () {
    'use strict';
    if (window.HlxUI) { return; }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    var toastBox = null;
    function toast(message, tone) {
        if (!toastBox) {
            toastBox = el('div', 'hlx-toasts');
            toastBox.setAttribute('role', 'status');
            toastBox.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastBox);
        }
        var item = el('div', 'hlx-toast' + (tone ? ' is-' + tone : ''), message);
        toastBox.appendChild(item);
        window.setTimeout(function () { item.classList.add('is-leaving'); }, 3200);
        window.setTimeout(function () { if (item.parentNode) { item.parentNode.removeChild(item); } }, 3700);
    }

    /**
     * opts: { title, body (Node), wide, actions: [{label, primary, onClick(close)}] }
     * returns close()
     */
    function modal(opts) {
        var opener = document.activeElement;
        var back   = el('div', 'hlx-modal-back');
        var box    = el('div', 'hlx-modal' + (opts.wide ? ' is-wide' : ''));
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');

        var head  = el('div', 'hlx-modal-head');
        var title = el('h3', 'hlx-modal-title', opts.title || '');
        var x     = el('button', 'hlx-modal-x', '×');
        x.type = 'button';
        x.setAttribute('aria-label', 'بستن');
        head.appendChild(title);
        head.appendChild(x);

        var body = el('div', 'hlx-modal-body');
        if (opts.body) { body.appendChild(opts.body); }

        box.appendChild(head);
        box.appendChild(body);

        if (opts.actions && opts.actions.length) {
            var foot = el('div', 'hlx-modal-foot');
            opts.actions.forEach(function (a) {
                var b = el('button', 'btn ' + (a.primary ? 'btn-primary' : 'btn-ghost'), a.label);
                b.type = 'button';
                b.addEventListener('click', function () { if (a.onClick) { a.onClick(close, b); } else { close(); } });
                foot.appendChild(b);
            });
            box.appendChild(foot);
        }

        back.appendChild(box);
        document.body.appendChild(back);
        document.documentElement.classList.add('hlx-modal-open');
        window.requestAnimationFrame(function () { back.classList.add('is-in'); });

        function onKey(e) { if (e.key === 'Escape') { close(); } }
        function close() {
            document.removeEventListener('keydown', onKey);
            back.classList.remove('is-in');
            document.documentElement.classList.remove('hlx-modal-open');
            window.setTimeout(function () { if (back.parentNode) { back.parentNode.removeChild(back); } }, 220);
            if (opener && opener.focus) { try { opener.focus(); } catch (e) { /* gone */ } }
        }
        x.addEventListener('click', close);
        back.addEventListener('click', function (e) { if (e.target === back) { close(); } });
        document.addEventListener('keydown', onKey);
        window.setTimeout(function () {
            var first = box.querySelector('textarea, select, input, button.btn-primary') || x;
            first.focus();
        }, 60);

        return close;
    }

    window.HlxUI = { modal: modal, toast: toast, el: el };
})();

/* -----------------------------------------------------------------------
   Tree cascade for filters: درس → زیردرس → عنوان.

   <div data-tree-cascade>
     <select data-level="1">…</select>
     <select data-level="2"><option data-parent="…">…</select>
     <select data-level="3"><option data-parent="…">…</select>
     <input type="hidden" data-tree-value name="subject_id">   (optional)
   </div>

   Picking a level shows only the children of what was picked in the level
   above; picking a deeper one first fills in its parents. The hidden input,
   when present, always carries the deepest choice.
   ----------------------------------------------------------------------- */
(function () {
    'use strict';

    function init(box) {
        var selects = Array.prototype.slice.call(box.querySelectorAll('select[data-level]'))
            .sort(function (a, b) { return (+a.getAttribute('data-level')) - (+b.getAttribute('data-level')); });
        var hidden = box.querySelector('[data-tree-value]');
        if (selects.length < 2) { return; }

        function narrow(i) {
            var select = selects[i];
            // The nearest level above that has a choice: its direct parent
            // (data-parent), or the درس two levels up (data-root) when the
            // زیردرس in between was left on «همه».
            var pv = '', attr = 'data-parent';
            if (selects[i - 1].value) {
                pv = selects[i - 1].value;
            } else if (i >= 2 && selects[i - 2].value) {
                pv = selects[i - 2].value;
                attr = 'data-root';
            }
            var any = false;
            Array.prototype.forEach.call(select.options, function (opt) {
                if (!opt.value) { return; }
                var show = !pv ? true : (pv === 'unfiled' ? false : opt.getAttribute(attr) === pv);
                // Safari ignores hidden on <option>; disabled keeps it unpickable.
                opt.hidden = !show;
                opt.disabled = !show;
                if (show) { any = true; }
                if (!show && opt.selected) { select.value = ''; }
            });
            select.closest('.field') && select.closest('.field').classList.toggle('is-empty-level', !any && !!pv);
        }

        function sync() {
            for (var i = 1; i < selects.length; i++) { narrow(i); }
            if (hidden) {
                var v = '';
                selects.forEach(function (s) { if (s.value) { v = s.value; } });
                hidden.value = v;
            }
        }

        function parentOf(select) {
            var opt = select.value ? select.options[select.selectedIndex] : null;
            return opt ? opt.getAttribute('data-parent') || '' : '';
        }

        selects.forEach(function (select, i) {
            select.addEventListener('change', function () {
                // A deeper pick fills in the levels above it.
                for (var j = i; j > 0; j--) {
                    var p = parentOf(selects[j]);
                    if (p) { selects[j - 1].value = p; }
                }
                // A new pick above clears the picks below.
                for (var k = i + 1; k < selects.length; k++) {
                    var opt = selects[k].value ? selects[k].options[selects[k].selectedIndex] : null;
                    if (opt && selects[k - 1].value && opt.getAttribute('data-parent') !== selects[k - 1].value) {
                        selects[k].value = '';
                    }
                }
                sync();
            });
        });
        sync();
    }

    document.querySelectorAll('[data-tree-cascade]').forEach(init);
})();

/* -----------------------------------------------------------------------
   «📌 باید بخونم» and the analysis' «📌» buttons post in place, without
   leaving the page; with JavaScript off they are ordinary forms.
   ----------------------------------------------------------------------- */
(function () {
    'use strict';
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches('[data-study-add]')) { return; }
        e.preventDefault();
        var button = form.querySelector('button');
        if (button) { button.disabled = true; }
        var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        fetch(form.action, {
            method: 'POST', body: new FormData(form), credentials: 'same-origin',
            headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.ok) {
                    if (button) { button.classList.add('is-done'); button.textContent = '✓ در درس‌های من'; }
                    if (window.HlxUI) { window.HlxUI.toast(res.message || '📌 اضافه شد.', 'ok'); }
                } else {
                    if (button) { button.disabled = false; }
                    if (window.HlxUI) { window.HlxUI.toast((res && res.message) || 'ذخیره نشد.', 'bad'); }
                }
            })
            .catch(function () {
                if (button) { button.disabled = false; }
                form.submit();
            });
    });

    // «کپی همه» for a freshly made batch of activation codes.
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-copy-codes]') : null;
        if (!b) { return; }
        var box = document.querySelector('[data-codes]');
        if (!box) { return; }
        var done = function () { if (window.HlxUI) { window.HlxUI.toast('کدها کپی شد ✓', 'ok'); } };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(box.value).then(done, function () { box.select(); document.execCommand('copy'); done(); });
        } else {
            box.select(); document.execCommand('copy'); done();
        }
    });
})();

/* -----------------------------------------------------------------------
   Small form helpers: the shared tag picker's filter, an input that saves
   its form when it loses focus, and a textarea that grows with its text.
   ----------------------------------------------------------------------- */
(function () {
    'use strict';
    document.querySelectorAll('[data-tag-picker]').forEach(function (box) {
        var filter = box.querySelector('[data-tp-filter]');
        if (!filter) { return; }
        filter.addEventListener('input', function () {
            var q = filter.value.trim().toLowerCase();
            box.querySelectorAll('.tp-tag').forEach(function (t) {
                t.hidden = q !== '' && (t.getAttribute('data-title') || '').indexOf(q) === -1 && !t.querySelector('input').checked;
            });
        });
        filter.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
    });

    document.querySelectorAll('input[data-autosave-blur]').forEach(function (input) {
        var start = input.value;
        input.addEventListener('change', function () {
            if (input.value.trim() !== '' && input.value !== start) { input.form.submit(); }
        });
    });

    document.querySelectorAll('textarea[data-grow]').forEach(function (ta) {
        var fit = function () { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; };
        ta.addEventListener('input', fit);
        fit();
    });
})();

/* The bot texts form in the admin panel: a live Telegram-like preview. */
(function () {
    'use strict';
    var form = document.querySelector('[data-tg-texts]');
    if (!form) { return; }
    var box = form.querySelector('.tg-preview');
    var mode = 'welcome';
    var input = function (k) { var el = form.querySelector('[data-tg-in="' + k + '"]'); return el ? el.value : ''; };
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
    function render() {
        var html = esc(input(mode)).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>')
            .split('{name}').join('سارا').split('{site}').join(esc(form.getAttribute('data-site') || ''));
        form.querySelector('[data-tg-out]').innerHTML = html;
        var site = form.querySelector('[data-tg-out-site]');
        site.textContent = input('btnSite');
        site.className = 'is-link';
        site.title = input('url') || form.getAttribute('data-login') || '';
        form.querySelector('[data-tg-out-reset]').textContent = input('btnReset');
        box.classList.toggle('is-member', mode === 'member');
    }
    form.addEventListener('input', render);
    form.querySelectorAll('[data-tg-show]').forEach(function (b) {
        b.addEventListener('click', function () {
            mode = b.getAttribute('data-tg-show');
            form.querySelectorAll('[data-tg-show]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
            render();
        });
    });
    form.querySelectorAll('textarea[data-tg-in]').forEach(function (t) {
        t.addEventListener('focus', function () {
            var want = t.getAttribute('data-tg-in');
            var btn = form.querySelector('[data-tg-show="' + want + '"]');
            if (btn && mode !== want) { btn.click(); }
        });
    });
    render();
})();

/* نقشه دسترسی: a column's «همه / هیچ» button. */
(function () {
    'use strict';
    var form = document.querySelector('[data-am]');
    if (!form) { return; }
    form.addEventListener('click', function (e) {
        var b = e.target.closest('[data-am-toggle]');
        if (!b) { return; }
        var boxes = form.querySelectorAll('input[data-am-col="' + b.getAttribute('data-am-toggle') + '"]:not(:disabled)');
        var allOn = Array.prototype.every.call(boxes, function (x) { return x.checked; });
        boxes.forEach(function (x) { x.checked = !allOn; });
    });
})();
