/* =====================================================================
   HeleXa Med — «فروشگاه»: add to cart in place (the product flies into
   the 🛒), discount codes without a reload, the product gallery, copy
   buttons for the card number and the receipt drop zone.
   Every form still works without this file.
   ===================================================================== */
(function () {
    'use strict';

    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function toast(m, t) { if (window.HlxUI) { window.HlxUI.toast(m, t); } }
    function post(url, fd) {
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); });
    }
    var fa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };

    /* ------------------------------------------------ add to cart */
    function flyToCart(from) {
        var target = document.querySelector('[data-cart-badge]');
        target = target ? target.parentElement : null;
        if (!target || reduce || !from) { return; }
        var a = from.getBoundingClientRect();
        var b = target.getBoundingClientRect();
        var ghost = document.createElement('div');
        ghost.className = 'sh-fly';
        var card = from.closest('.sh-item, .sh-product');
        var img = card && card.querySelector('img');
        if (img) { ghost.appendChild(img.cloneNode()); }
        var tone = card && getComputedStyle(card);
        if (tone) { ghost.style.setProperty('--t1', tone.getPropertyValue('--t1')); ghost.style.setProperty('--t2', tone.getPropertyValue('--t2')); }
        ghost.style.left = (a.left + a.width / 2 - 23) + 'px';
        ghost.style.top = (a.top + a.height / 2 - 23) + 'px';
        document.body.appendChild(ghost);
        var dx = (b.left + b.width / 2) - (a.left + a.width / 2);
        var dy = (b.top + b.height / 2) - (a.top + a.height / 2);
        var anim = ghost.animate([
            { transform: 'translate(0,0) scale(1)', opacity: 1 },
            { transform: 'translate(' + dx * 0.5 + 'px,' + (dy * 0.5 - 90) + 'px) scale(.85)', opacity: 1, offset: 0.55 },
            { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(.2)', opacity: 0.4 }
        ], { duration: 720, easing: 'cubic-bezier(.5,0,.3,1)' });
        anim.onfinish = function () {
            ghost.remove();
            target.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.25)' }, { transform: 'scale(1)' }], { duration: 380, easing: 'cubic-bezier(.3,1.6,.5,1)' });
        };
    }

    document.querySelectorAll('[data-add-cart]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = form.querySelector('button');
            btn.classList.add('is-busy');
            post(form.action, new FormData(form)).then(function (res) {
                btn.classList.remove('is-busy');
                if (!res.ok) { toast(res.message || 'اضافه نشد.', 'bad'); return; }
                btn.classList.add('is-in');
                flyToCart(btn);
                if (window.HxShell) { window.HxShell.updateCartBadge(res.count); }
                var pill = document.querySelector('[data-cart-count]');
                if (pill) { pill.textContent = res.count > 0 ? fa(res.count) : ''; }
                if (form.hasAttribute('data-go-cart')) {
                    var t = form.querySelector('[data-cta-text]');
                    if (t) { t.textContent = 'در سبد است — افزودن دوباره'; }
                    var link = document.querySelector('[data-cart-link]');
                    if (link) { link.hidden = false; }
                }
                toast(res.message, 'ok');
            }).catch(function () { btn.classList.remove('is-busy'); form.submit(); });
        });
    });

    /* ------------------------------------------------ discount code */
    var coupon = document.querySelector('[data-coupon-form]');
    if (coupon) {
        var msg = coupon.querySelector('[data-coupon-msg]');
        var submitter = null;
        coupon.addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) { submitter = b; } });
        coupon.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(coupon);
            if (submitter && submitter.name === 'remove') { fd.append('remove', '1'); }
            submitter = null;
            msg.className = 'sh-coupon-msg';
            msg.textContent = 'در حال بررسی…';
            post(coupon.action, fd).then(function (res) {
                msg.textContent = res.message || '';
                msg.classList.add(res.ok && res.hasDiscount ? 'is-ok' : (res.ok ? '' : 'is-bad'));
                if (!res.ok) { coupon.classList.remove('is-shake'); void coupon.offsetWidth; coupon.classList.add('is-shake'); }
                coupon.querySelector('[data-coupon-apply]').hidden = !!res.hasDiscount;
                coupon.querySelector('[data-coupon-remove]').hidden = !res.hasDiscount;
                if (!res.hasDiscount && fd.get('remove')) { coupon.querySelector('input[name="code"]').value = ''; }
                var set = function (sel, v) { var el = document.querySelector(sel); if (el) { el.textContent = v; } };
                set('[data-sum-subtotal]', res.subtotal);
                set('[data-sum-discount]', '− ' + res.discount);
                set('[data-sum-total]', res.total);
                var row = document.querySelector('[data-sum-discount-row]');
                if (row) { row.hidden = !res.hasDiscount; }
                var methods = document.querySelector('[data-methods]');
                if (methods) { methods.hidden = !!res.free; }
                set('[data-pay-text]', res.free ? 'ثبت سفارش رایگان' : 'پرداخت ' + res.total);
                if (res.hasDiscount) {
                    var total = document.querySelector('[data-sum-total]');
                    if (total && total.animate) { total.animate([{ transform: 'scale(1.25)', color: '#059669' }, { transform: 'scale(1)' }], { duration: 500, easing: 'cubic-bezier(.3,1.6,.5,1)' }); }
                }
            }).catch(function () { coupon.submit(); });
        });
    }

    /* ------------------------------------------------ gallery */
    var gallery = document.querySelector('[data-gallery]');
    if (gallery) {
        gallery.querySelectorAll('[data-thumb]').forEach(function (t) {
            t.addEventListener('click', function () {
                var n = t.getAttribute('data-thumb');
                gallery.querySelectorAll('[data-slide]').forEach(function (s) { s.hidden = s.getAttribute('data-slide') !== n; });
                gallery.querySelectorAll('[data-thumb]').forEach(function (x) { x.classList.toggle('is-on', x === t); });
            });
        });
    }

    /* ------------------------------------------------ copy buttons */
    document.querySelectorAll('[data-copy]').forEach(function (b) {
        b.addEventListener('click', function () {
            var text = b.getAttribute('data-copy');
            var done = function () {
                b.classList.add('is-done');
                toast('کپی شد ✓', 'ok');
                window.setTimeout(function () { b.classList.remove('is-done'); }, 1400);
            };
            if (navigator.clipboard) { navigator.clipboard.writeText(text).then(done, function () {}); }
        });
    });

    /* ------------------------------------------------ receipt */
    var drop = document.querySelector('[data-drop]');
    if (drop) {
        var input = drop.querySelector('[data-receipt-file]');
        var preview = drop.querySelector('[data-receipt-preview]');
        var show = function () {
            var f = input.files && input.files[0];
            if (!f) { return; }
            preview.src = URL.createObjectURL(f);
            preview.hidden = false;
            drop.classList.add('has-file');
        };
        input.addEventListener('change', show);
        ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('is-over'); }); });
    }
})();
