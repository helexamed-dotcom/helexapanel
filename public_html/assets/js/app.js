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

    /* ------------------------------------------------- topbar dropdowns
       The profile menu and the notification bell behave identically: click to
       open, click outside or Escape to close, and only one open at a time.

       On a phone each becomes a bottom sheet, and that needs one piece of
       care. The topbar carries a backdrop-filter, and a filtered element is
       the containing block for any position:fixed inside it — so a sheet left
       in the header would measure "bottom: 12px" against a 66px bar and land
       back at the top of the screen, covering the very header it came from.
       Opening therefore moves the panel to <body> first, and closing puts it
       back where the markup had it. */
    var SHEET_WIDTH = 620;
    var openDrop    = null;

    function isSheet() {
        return window.matchMedia('(max-width: ' + SHEET_WIDTH + 'px)').matches;
    }

    function makeDropdown(rootSelector, triggerSelector, panelSelector) {
        var root    = document.querySelector(rootSelector);
        var trigger = document.querySelector(triggerSelector);
        var panel   = document.querySelector(panelSelector);

        if (!root || !trigger || !panel) { return null; }

        // Remembered so the panel can go home again; querying for it later
        // would find the wrong place once it has been moved once.
        var home = panel.parentNode;
        var drop = {};

        drop.close = function () {
            if (panel.hidden) { return; }
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');

            if (panel.parentNode !== home) { home.appendChild(panel); }
            panel.classList.remove('is-sheet');
            document.documentElement.classList.remove('sheet-open');
            if (openDrop === drop) { openDrop = null; }
        };

        drop.open = function () {
            if (openDrop && openDrop !== drop) { openDrop.close(); }

            if (isSheet()) {
                document.body.appendChild(panel);
                panel.classList.add('is-sheet');
                document.documentElement.classList.add('sheet-open');
            }

            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            openDrop = drop;
        };

        drop.contains = function (node) {
            return root.contains(node) || panel.contains(node);
        };

        trigger.addEventListener('click', function (event) {
            event.stopPropagation();
            if (panel.hidden) { drop.open(); } else { drop.close(); }
        });

        // A click inside the panel (the theme toggle, say) must not bubble out
        // and immediately close the menu it belongs to.
        panel.addEventListener('click', function (event) { event.stopPropagation(); });

        return drop;
    }

    var drops = [
        makeDropdown('[data-user-menu]', '[data-user-menu-trigger]', '[data-user-panel]'),
        makeDropdown('[data-bell-menu]', '[data-bell-trigger]', '[data-bell-panel]')
    ].filter(Boolean);

    if (drops.length) {
        document.addEventListener('click', function (event) {
            drops.forEach(function (drop) {
                if (!drop.contains(event.target)) { drop.close(); }
            });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { drops.forEach(function (d) { d.close(); }); }
        });
        // A sheet is sized to the viewport it opened in; rotating the phone or
        // crossing the breakpoint would leave it anchored to nothing.
        window.addEventListener('resize', function () {
            if (openDrop) { openDrop.close(); }
        });
    }

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
