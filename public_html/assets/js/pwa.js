/* =====================================================================
   HeleXa Med — PWA shell behaviour on panel pages:
   worker registration, update prompt, install experience, status pill.
   ===================================================================== */
(function () {
    'use strict';

    /**
     * Connection state as signal strength.
     *
     * Only a class and a label change; the glyph itself is in the page markup
     * so it is on screen from the first paint rather than popping in once this
     * script runs. The label is for screen readers — sighted users get the
     * icon, which says the same thing without a word of Persian to translate.
     */
    var STATES = {
        checking:     ['is-checking', 'در حال بررسی اتصال'],
        online:       ['is-online',   'آنلاین'],
        syncing:      ['is-weak',     'در حال همگام‌سازی'],
        reconnecting: ['is-weak',     'اتصال ضعیف'],
        offline:      ['is-offline',  'آفلاین']
    };

    function mountPill() {
        var host = document.querySelector('[data-conn-slot]');
        if (!host) { return; }

        var label = host.querySelector('.conn-label');

        window.HeleXa.onChange(function (state) {
            var next = STATES[state.connection] || STATES.checking;

            host.className = 'conn ' + next[0];
            host.setAttribute('title', next[1]);
            if (label) { label.textContent = next[1]; }

            document.body.classList.toggle('is-offline', state.connection === 'offline');
        });
    }

    /* ------------------------------------------------ worker lifecycle */

    function registerWorker() {
        if (!('serviceWorker' in navigator)) { return; }

        navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(function (registration) {
            registration.addEventListener('updatefound', function () {
                var incoming = registration.installing;
                if (!incoming) { return; }

                incoming.addEventListener('statechange', function () {
                    // A new worker is ready but an old one is still driving the
                    // page. Nothing is swapped until the user agrees, so an
                    // open lesson is never reloaded out from under them.
                    if (incoming.state === 'installed' && navigator.serviceWorker.controller) {
                        showUpdateBar(registration);
                    }
                });
            });
        }).catch(function () { /* unsupported or blocked; the site still works */ });

        var reloading = false;
        navigator.serviceWorker.addEventListener('controllerchange', function () {
            if (reloading) { return; }
            reloading = true;
            window.location.reload();
        });
    }

    function showUpdateBar(registration) {
        if (document.getElementById('pwa-update')) { return; }

        var bar = document.createElement('div');
        bar.id = 'pwa-update';
        bar.className = 'pwa-banner';
        bar.innerHTML =
            '<span>نسخه جدید برنامه آماده است.</span>' +
            '<button class="btn btn-primary btn-sm" type="button">بارگذاری مجدد</button>';

        bar.querySelector('button').addEventListener('click', function () {
            if (registration.waiting) {
                registration.waiting.postMessage({ type: 'SKIP_WAITING' });
            }
        });

        document.body.appendChild(bar);
    }

    /* -------------------------------------------------------- install */

    var deferredPrompt = null;

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    function dismissedRecently() {
        try {
            var until = Number(window.localStorage.getItem('helexa_install_dismissed_until') || 0);
            return until > Date.now();
        } catch (e) { return false; }
    }

    function rememberDismissal(days) {
        try {
            window.localStorage.setItem('helexa_install_dismissed_until',
                String(Date.now() + days * 86400000));
        } catch (e) { /* private mode; the prompt simply reappears next visit */ }
    }

    function showInstallCard(html, onInstall) {
        if (document.getElementById('pwa-install') || isStandalone() || dismissedRecently()) { return; }

        var card = document.createElement('div');
        card.id = 'pwa-install';
        card.className = 'pwa-install';
        card.innerHTML =
            '<div class="pwa-install-icon"><img src="/assets/icons/icon-192.png" alt="" width="40" height="40"></div>' +
            '<div class="pwa-install-body">' + html + '</div>' +
            '<div class="pwa-install-actions"></div>';

        var actions = card.querySelector('.pwa-install-actions');

        if (onInstall) {
            var install = document.createElement('button');
            install.className = 'btn btn-primary btn-sm';
            install.type = 'button';
            install.textContent = 'نصب برنامه';
            install.addEventListener('click', function () {
                onInstall();
                card.remove();
            });
            actions.appendChild(install);
        }

        var later = document.createElement('button');
        later.className = 'btn btn-ghost btn-sm';
        later.type = 'button';
        later.textContent = 'بعداً';
        later.addEventListener('click', function () {
            // Respect a "no": a fortnight of silence, not a nag on every page.
            rememberDismissal(14);
            card.remove();
        });
        actions.appendChild(later);

        document.body.appendChild(card);
    }

    function wireInstall() {
        window.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault();
            deferredPrompt = event;

            showInstallCard(
                '<strong>HeleXa Med را نصب کنید</strong>' +
                '<span>برای دسترسی سریع‌تر و مطالعه آفلاین، برنامه را روی دستگاهتان نصب کنید.</span>',
                function () {
                    deferredPrompt.prompt();
                    deferredPrompt.userChoice.then(function (choice) {
                        if (choice.outcome !== 'accepted') { rememberDismissal(14); }
                        deferredPrompt = null;
                    });
                }
            );
        });

        window.addEventListener('appinstalled', function () { rememberDismissal(3650); });

        // iOS gives no install event at all, so the only honest option is to
        // show the actual steps instead of a button that cannot work.
        var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        if (isIOS && !isStandalone()) {
            window.setTimeout(function () {
                showInstallCard(
                    '<strong>HeleXa را به صفحه اصلی اضافه کنید</strong>' +
                    '<span>در Safari دکمه اشتراک‌گذاری <b>⬆︎</b> را بزنید، سپس ' +
                    '«Add to Home Screen» را انتخاب کنید.</span>',
                    null
                );
            }, 4000);
        }
    }

    /* ----------------------------------------------- logout hygiene */

    /**
     * Signing out must not leave the previous student's lessons, progress and
     * queued events sitting on the device for the next person who logs in.
     */
    function wireLogout() {
        document.querySelectorAll('form[action="/logout"]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (form.dataset.purged === '1') { return; }
                event.preventDefault();
                form.dataset.purged = '1';

                var done = function () { form.submit(); };
                window.setTimeout(done, 1500); // never block sign-out on storage

                window.HeleXa.purgeEverything().then(done).catch(done);
            });
        });
    }

    /* ------------------------------------------------------------ go */

    function start() {
        registerWorker();
        wireInstall();
        wireLogout();
        mountPill();
        window.HeleXa.boot();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
