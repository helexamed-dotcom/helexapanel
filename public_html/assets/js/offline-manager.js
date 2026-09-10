/* =====================================================================
   HeleXa Med — offline content manager.

   Drives the "save for offline" control wherever it appears. The bytes come
   from the same authorised stream endpoint the online viewer uses, so there
   is no second delivery path and no public download URL.
   ===================================================================== */
(function () {
    'use strict';

    var DB = window.HeleXaDB;

    var STATES = {
        idle:       { label: 'ذخیره برای مطالعه آفلاین', cls: 'btn-ghost',   disabled: false },
        working:    { label: 'در حال آماده‌سازی…',        cls: 'btn-ghost',   disabled: true  },
        ready:      { label: '✓ آماده مطالعه آفلاین',    cls: 'btn-ok',      disabled: false },
        update:     { label: 'نسخه جدید موجود است',      cls: 'btn-warn',    disabled: false },
        expired:    { label: 'اعتبار آفلاین تمام شده',   cls: 'btn-warn',    disabled: false },
        disallowed: { label: 'آفلاین در دسترس نیست',     cls: 'btn-ghost',   disabled: true  }
    };

    function render(button, key, detail) {
        var config = STATES[key] || STATES.idle;
        button.className = 'btn btn-sm offline-btn ' + config.cls;
        button.disabled = config.disabled;
        button.dataset.offlineState = key;
        button.textContent = config.label;
        if (detail) { button.title = detail; }
    }

    function progress(button, step) {
        button.textContent = step;
    }

    function humanBytes(bytes) {
        if (!bytes) { return '۰'; }
        var mb = bytes / 1048576;
        var text = mb >= 1 ? mb.toFixed(1) + ' مگابایت' : Math.round(bytes / 1024) + ' کیلوبایت';
        return text.replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
    }

    /**
     * Downloads one lesson.
     *
     * Order matters: permission first, bytes second. If the manifest call is
     * refused — revoked enrolment, expired window, offline turned off for this
     * lesson — nothing is fetched and nothing is stored.
     */
    function download(button, uuid) {
        var scope = window.HeleXa.scope();
        if (!scope) {
            render(button, 'idle', 'ابتدا وارد حساب خود شوید.');
            return Promise.resolve(false);
        }

        render(button, 'working');
        progress(button, 'بررسی دسترسی…');

        return fetch('/api/offline/manifest/' + encodeURIComponent(uuid), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) { throw new Error('NOT_AUTHORIZED'); }
            return response.json();
        }).then(function (data) {
            var meta = data.package;
            progress(button, 'دریافت محتوا…');

            return fetch(meta.stream_url, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) { throw new Error('STREAM_FAILED'); }
                return response.text();
            }).then(function (html) {
                progress(button, 'ذخیره‌سازی…');
                // Images and fonts inside these lessons are embedded as data
                // URIs, so storing the document stores every asset with it.
                return DB.saveContent(scope, meta, html);
            }).then(function (record) {
                render(button, 'ready', 'ذخیره‌شده — ' + humanBytes(record.byte_size));
                document.dispatchEvent(new CustomEvent('helexa:offline-changed', { detail: { uuid: uuid } }));
                return true;
            });
        }).catch(function (error) {
            var message = String(error.message || error);
            render(button, 'idle', message === 'NOT_AUTHORIZED'
                ? 'دسترسی شما به این محتوا تأیید نشد.'
                : 'ذخیره‌سازی انجام نشد. اتصال خود را بررسی کنید.');
            return false;
        });
    }

    function refreshState(button, uuid) {
        var scope = window.HeleXa.scope();
        if (!scope) { return; }

        DB.getContent(scope, uuid).then(function (record) {
            if (!record) { render(button, 'idle'); return; }
            if (!DB.leaseValid(record)) { render(button, 'expired', 'برای تمدید، دوباره ذخیره کنید.'); return; }

            var serverVersion = button.dataset.version || '';
            if (serverVersion && record.version && serverVersion !== record.version) {
                render(button, 'update', 'نسخه ذخیره‌شده قدیمی است.');
                return;
            }
            render(button, 'ready', 'ذخیره‌شده — ' + humanBytes(record.byte_size));
        });
    }

    function wire() {
        var buttons = document.querySelectorAll('[data-offline-save]');
        if (!buttons.length) { return; }

        buttons.forEach(function (button) {
            var uuid = button.getAttribute('data-offline-save');

            if (button.dataset.offlineAllowed === '0') {
                render(button, 'disallowed', 'ناشر این محتوا مطالعه آفلاین را غیرفعال کرده است.');
                return;
            }

            refreshState(button, uuid);

            button.addEventListener('click', function () {
                var current = button.dataset.offlineState;

                if (current === 'ready') {
                    if (!window.confirm('نسخه آفلاین این محتوا از دستگاه حذف شود؟')) { return; }
                    DB.removeContent(window.HeleXa.scope(), uuid).then(function () {
                        render(button, 'idle');
                        document.dispatchEvent(new CustomEvent('helexa:offline-changed', { detail: { uuid: uuid } }));
                    });
                    return;
                }

                download(button, uuid);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }

    window.HeleXaOffline = { download: download, refreshState: refreshState };
})();
