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

    /* -------------------------------------------------------- user menu
       One dropdown replaces what used to be four separate topbar controls
       (theme toggle, password gear, avatar link, logout button). Click to
       open, click outside or Escape to close, and it never fights with the
       mobile drawer or the sidebar rail for the same keypress. */
    var userMenu    = document.querySelector('[data-user-menu]');
    var userTrigger = document.querySelector('[data-user-menu-trigger]');
    var userPanel   = document.querySelector('[data-user-panel]');

    function closeUserMenu() {
        if (!userPanel || userPanel.hidden) { return; }
        userPanel.hidden = true;
        if (userTrigger) { userTrigger.setAttribute('aria-expanded', 'false'); }
    }

    function openUserMenu() {
        if (!userPanel) { return; }
        userPanel.hidden = false;
        if (userTrigger) { userTrigger.setAttribute('aria-expanded', 'true'); }
    }

    if (userTrigger && userPanel) {
        userTrigger.addEventListener('click', function (event) {
            event.stopPropagation();
            if (userPanel.hidden) { openUserMenu(); } else { closeUserMenu(); }
        });

        // A click anywhere inside the panel (e.g. the theme toggle) must not
        // bubble up and immediately close the very menu it is part of.
        userPanel.addEventListener('click', function (event) { event.stopPropagation(); });

        document.addEventListener('click', function (event) {
            if (userMenu && !userMenu.contains(event.target)) { closeUserMenu(); }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { closeUserMenu(); }
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

    /* ------------------------------------------------ mobile drawer */
    var toggle  = document.querySelector('[data-menu-toggle]');
    var sidebar = document.querySelector('[data-sidebar]');
    var scrim   = document.querySelector('[data-scrim]');

    function closeMenu() {
        if (sidebar) { sidebar.classList.remove('is-open'); }
        if (scrim)   { scrim.classList.remove('is-open'); }
        if (toggle)  { toggle.setAttribute('aria-expanded', 'false'); }
    }

    if (toggle && sidebar && scrim) {
        toggle.addEventListener('click', function () {
            var open = sidebar.classList.toggle('is-open');
            scrim.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        scrim.addEventListener('click', closeMenu);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeMenu(); }
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
