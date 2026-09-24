/* =====================================================================
   HeleXa Med — the profile: a post opens out of its tile like a photo in
   the gallery, double-tap or ♥ to like, follow in place, the composer's
   image preview, and «copy profile link».
   ===================================================================== */
(function () {
    'use strict';

    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var fa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };
    function toast(m, t) { if (window.HlxUI) { window.HlxUI.toast(m, t); } }
    function post(url, fd) {
        fd = fd || new FormData();
        fd.append('_token', token);
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); });
    }
    function setLike(btn, liked, count) {
        btn.classList.toggle('is-on', !!liked);
        var b = btn.querySelector('b');
        if (b) { b.textContent = fa(count); }
        btn.classList.remove('is-pop');
        void btn.offsetWidth;
        if (liked) { btn.classList.add('is-pop'); }
    }

    /* ------------------------------------------------ the post viewer */
    var viewer = document.querySelector('[data-viewer]');
    var current = null;
    if (viewer) {
        // A fixed layer inside an animated page would be trapped by it.
        document.body.appendChild(viewer);
        var media = viewer.querySelector('[data-viewer-media]');
        var body = viewer.querySelector('[data-viewer-body]');
        var likeBtn = viewer.querySelector('[data-like]');
        var dateEl = viewer.querySelector('[data-viewer-date]');
        var del = viewer.querySelector('[data-delete]');
        var card = viewer.querySelector('.pf-viewer-card');

        var open = function (tile) {
            current = tile;
            media.innerHTML = '';
            var img = tile.querySelector('img');
            var full = tile.querySelector('template[data-full]');
            var text = full ? full.innerHTML.trim() : '';
            if (img) {
                var big = document.createElement('img');
                big.src = img.src;
                big.alt = '';
                media.appendChild(big);
                body.innerHTML = text;
            } else {
                var t = document.createElement('div');
                t.className = 'pf-textcard ' + (tile.className.match(/tone-\w+/) || [''])[0];
                t.innerHTML = text;
                media.appendChild(t);
                body.innerHTML = '';
            }
            setLike(likeBtn, tile.getAttribute('data-liked') === '1', +tile.getAttribute('data-likes'));
            likeBtn.classList.remove('is-pop');
            dateEl.textContent = tile.getAttribute('data-date') + (tile.getAttribute('data-aud') === 'me' ? ' · 🔒 فقط من' : tile.getAttribute('data-aud') === 'followers' ? ' · 👥 دنبال‌کننده‌ها' : '');
            // grow out of the tile, like the iPhone gallery
            var r = tile.getBoundingClientRect();
            viewer.hidden = false;
            var c = card.getBoundingClientRect();
            card.style.setProperty('--ox', (r.left + r.width / 2 - c.left) + 'px');
            card.style.setProperty('--oy', (r.top + r.height / 2 - c.top) + 'px');
            viewer.classList.remove('is-closing');
            viewer.classList.add('is-open');
            document.documentElement.style.overflow = 'hidden';
        };
        var close = function () {
            if (viewer.hidden) { return; }
            viewer.classList.add('is-closing');
            window.setTimeout(function () {
                viewer.hidden = true;
                viewer.classList.remove('is-open', 'is-closing');
                document.documentElement.style.overflow = '';
            }, 280);
        };
        document.querySelectorAll('[data-post]').forEach(function (tile) {
            tile.addEventListener('click', function () { open(tile); });
        });
        viewer.querySelectorAll('[data-viewer-close]').forEach(function (b) { b.addEventListener('click', close); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });

        var like = function () {
            if (!current) { return; }
            var tile = current;
            post('/profile/posts/' + tile.getAttribute('data-uuid') + '/like').then(function (res) {
                if (!res.ok) { return; }
                tile.setAttribute('data-liked', res.liked ? '1' : '0');
                tile.setAttribute('data-likes', res.count);
                var meta = tile.querySelector('.pf-post-meta');
                if (meta && meta.lastChild) { meta.lastChild.nodeValue = ' ' + fa(res.count) + meta.lastChild.nodeValue.replace(/^\s*[۰-۹0-9]+/, ''); }
                setLike(likeBtn, res.liked, res.count);
            });
        };
        likeBtn.addEventListener('click', like);
        var lastTap = 0;
        media.addEventListener('click', function () {
            var now = Date.now();
            if (now - lastTap < 320 && likeBtn && !likeBtn.classList.contains('is-on')) { like(); }
            lastTap = now;
        });
        media.addEventListener('dblclick', function () { if (!likeBtn.classList.contains('is-on')) { like(); } });

        if (del) {
            del.addEventListener('click', function () {
                if (!current || !window.confirm('این پست حذف شود؟')) { return; }
                var tile = current;
                post('/profile/posts/' + tile.getAttribute('data-uuid') + '/delete').then(function (res) {
                    if (!res.ok) { return; }
                    close();
                    tile.animate([{ transform: 'scale(1)', opacity: 1 }, { transform: 'scale(.6)', opacity: 0 }], { duration: 300, easing: 'ease-in' }).onfinish = function () { tile.remove(); };
                    toast('پست حذف شد.', 'ok');
                });
            });
        }
    }

    /* ------------------------------------------------ likes in the feed */
    document.querySelectorAll('[data-like-inline]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            post('/profile/posts/' + btn.getAttribute('data-like-inline') + '/like').then(function (res) {
                if (res.ok) { setLike(btn, res.liked, res.count); }
            });
        });
    });

    /* ------------------------------------------------ follow in place */
    document.querySelectorAll('[data-follow]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = form.querySelector('[data-follow-btn]');
            btn.disabled = true;
            post(form.action, new FormData(form)).then(function (res) {
                btn.disabled = false;
                if (!res.ok) { toast(res.message || 'انجام نشد.', 'bad'); return; }
                var s = res.status || 'none';
                btn.setAttribute('data-state', s);
                btn.classList.toggle('is-primary', s === 'none');
                btn.textContent = s === 'accepted' ? 'دنبال می‌کنی ✓' : (s === 'pending' ? 'درخواست فرستاده شد' : 'دنبال کردن');
                toast(res.message, 'ok');
            }).catch(function () { btn.disabled = false; form.submit(); });
        });
    });

    /* ------------------------------------------------ composer preview */
    var file = document.querySelector('[data-compose-file]');
    if (file) {
        var prev = document.querySelector('[data-compose-preview]');
        var name = document.querySelector('[data-compose-name]');
        file.addEventListener('change', function () {
            var f = file.files && file.files[0];
            if (!f) { return; }
            prev.src = URL.createObjectURL(f);
            prev.hidden = false;
            name.textContent = '۱ عکس';
        });
    }

    /* ------------------------------------------------ share */
    document.querySelectorAll('[data-share]').forEach(function (b) {
        b.addEventListener('click', function () {
            var url = window.location.origin + b.getAttribute('data-share');
            if (navigator.share) { navigator.share({ url: url }).catch(function () {}); return; }
            if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function () { toast('پیوند پروفایل کپی شد ✓', 'ok'); }); }
        });
    });
})();
