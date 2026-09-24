/* =====================================================================
   HeleXa Med — the admin's store pages: the product form (fields that
   depend on the kind, money inputs with separators, a live card preview)
   and the appearance settings with a live preview of the store itself.
   ===================================================================== */
(function () {
    'use strict';

    var fa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };
    var latin = function (s) { return String(s).replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }); };
    var num = function (s) { return parseInt(latin(s).replace(/[^0-9]/g, ''), 10) || 0; };
    var group = function (n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ','); };

    /* ------------------------------------------------ product form */
    var form = document.querySelector('[data-product-form]');
    if (form) {
        var kinds = form.querySelectorAll('[data-kind]');
        var applyKind = function () {
            var k = (form.querySelector('[data-kind]:checked') || {}).value || 'package';
            form.querySelectorAll('[data-for-kind]').forEach(function (el) {
                el.hidden = el.getAttribute('data-for-kind').split(' ').indexOf(k) === -1;
            });
        };
        kinds.forEach(function (r) { r.addEventListener('change', applyKind); });
        applyKind();

        form.querySelectorAll('[data-money]').forEach(function (inp) {
            inp.addEventListener('input', function () {
                var n = num(inp.value);
                inp.value = n ? group(n) : '';
                preview();
            });
        });

        var pv = form.querySelector('[data-preview]');
        var get = function (name) { var el = form.querySelector('[data-pv="' + name + '"]'); return el ? el.value : ''; };
        var preview = function () {
            if (!pv) { return; }
            pv.querySelector('[data-pv-title]').textContent = get('title') || 'نام محصول';
            pv.querySelector('[data-pv-subtitle]').textContent = get('subtitle');
            var price = num(get('price')), cmp = num(get('compare'));
            pv.querySelector('[data-pv-price]').textContent = price ? fa(group(price)) + ' تومان' : 'رایگان';
            pv.querySelector('[data-pv-compare]').textContent = cmp > price ? fa(group(cmp)) : '';
            var badge = pv.querySelector('[data-pv-badge]');
            badge.textContent = get('badge');
            badge.hidden = !get('badge');
            var tone = form.querySelector('[data-tone]:checked');
            if (tone) { pv.className = pv.className.replace(/tone-\w+/, 'tone-' + tone.value); }
        };
        form.querySelectorAll('[data-pv], [data-tone]').forEach(function (el) { el.addEventListener('input', preview); el.addEventListener('change', preview); });
        var cover = form.querySelector('[data-cover-input]');
        if (cover) {
            cover.addEventListener('change', function () {
                var f = cover.files && cover.files[0];
                if (!f) { return; }
                var media = pv.querySelector('[data-pv-media]');
                var img = media.querySelector('img') || document.createElement('img');
                img.src = URL.createObjectURL(f);
                media.querySelectorAll('.ic').forEach(function (i) { i.remove(); });
                media.prepend(img);
            });
        }
        preview();
    }

    /* ------------------------------------------------ appearance */
    var tf = document.querySelector('[data-theme-form]');
    if (tf) {
        var shop = tf.querySelector('[data-pv-shop]');
        var q = function (s) { return shop.querySelector(s); };
        var val = function (name) {
            var el = tf.querySelector('[data-t="' + name + '"]:checked') || tf.querySelector('[data-t="' + name + '"]:not([type="radio"])');
            if (!el) { return ''; }
            return el.type === 'checkbox' ? el.checked : el.value;
        };
        var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
        var check = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="width:14px;height:14px;flex-basis:14px"><path d="m5.2 12.6 4.6 4.6L18.8 7.4"/></svg>';
        var bannerUrl = null;
        var render = function () {
            shop.className = shop.className.replace(/tone-\w+/, 'tone-' + val('tone')).replace(/sh-card-\w+/, 'sh-card-' + val('card'));
            shop.style.setProperty('--sh-cols', val('columns'));
            shop.style.setProperty('--sh-r', val('radius') + 'px');
            tf.querySelector('[data-out="columns"]').textContent = fa(val('columns'));
            tf.querySelector('[data-out="radius"]').textContent = fa(val('radius'));
            q('[data-pv-title]').textContent = val('title') || 'فروشگاه';
            q('[data-pv-subtitle]').textContent = val('subtitle');
            q('[data-pv-notice]').hidden = !val('notice');
            q('[data-pv-notice-text]').textContent = val('notice');
            q('[data-pv-trust]').innerHTML = String(val('trust')).split(/\n/).filter(function (l) { return l.trim(); }).slice(0, 5)
                .map(function (l) { return '<li>' + check + ' ' + esc(l.trim()) + '</li>'; }).join('');
            q('[data-pv-footer]').innerHTML = esc(val('footer')).replace(/\n/g, '<br>');
            shop.querySelectorAll('[data-pv-currency]').forEach(function (el) { el.textContent = val('currency'); });
            var hero = q('[data-pv-hero]');
            hero.className = 'sh-hero is-' + val('hero');
            if (bannerUrl) { hero.style.setProperty('--sh-banner', 'url("' + bannerUrl + '")'); }
            q('[data-pv-grid]').className = 'sh-grid is-' + val('layout');
            q('[data-pv-search]').hidden = !val('show_search');
            q('[data-pv-cats]').hidden = !val('show_categories');
            shop.querySelectorAll('[data-pv-sold]').forEach(function (el) { el.hidden = !val('show_sold'); });
            shop.querySelectorAll('[data-pv-compare]').forEach(function (el) { el.hidden = !val('show_compare'); });
            var imgRow = tf.querySelector('[data-when-hero]');
            if (imgRow) { imgRow.hidden = val('hero') !== 'image'; }
        };
        tf.addEventListener('input', render);
        tf.addEventListener('change', render);
        var banner = tf.querySelector('[data-banner]');
        if (banner) {
            banner.addEventListener('change', function () {
                var f = banner.files && banner.files[0];
                if (f) { bannerUrl = URL.createObjectURL(f); render(); }
            });
        }
        render();
    }
})();
