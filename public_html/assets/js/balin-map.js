/* 🏝️ Balin island — the lesson map.
 *
 * Draws the winding road through the stage nodes and walks the student's
 * avatar to the stage they are on. The walk starts from where the avatar
 * stood the last time this device showed the map, so finishing a stage and
 * coming back plays the move from the old stage to the new one.
 *
 * Everything is positioned from the rendered nodes, so the road follows the
 * layout at any width; without JavaScript the map is a plain clickable column.
 */
(function () {
    'use strict';

    var world = document.querySelector('[data-bgame-world]');
    if (!world) { return; }

    var steps   = Array.prototype.slice.call(world.querySelectorAll('[data-step]'));
    var svg     = world.querySelector('[data-bgame-road]');
    var roads   = Array.prototype.slice.call(world.querySelectorAll('[data-road]'));
    var done    = world.querySelector('[data-road-done]');
    var avatar  = world.querySelector('[data-avatar]');
    var current = Math.max(0, Math.min(steps.length - 1, parseInt(world.getAttribute('data-current'), 10) || 0));
    var key     = 'helexa_bgame_' + world.getAttribute('data-lesson');
    var reduce  = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    if (!steps.length || !svg || !roads.length) { return; }

    var points  = [];
    var lengths = [];   // road length from the start to each node
    var walking = null;

    function centres() {
        var box = world.getBoundingClientRect();
        return steps.map(function (li) {
            var node = li.querySelector('.bgame-node');
            var r = node.getBoundingClientRect();
            return { x: r.left - box.left + r.width / 2, y: r.top - box.top + r.height / 2 };
        });
    }

    function segment(a, b) {
        var mid = (a.y + b.y) / 2;
        return ' C' + a.x.toFixed(1) + ' ' + mid.toFixed(1) + ' ' + b.x.toFixed(1) + ' ' + mid.toFixed(1) +
            ' ' + b.x.toFixed(1) + ' ' + b.y.toFixed(1);
    }

    function pathTo(upto) {
        if (!points.length) { return ''; }
        var d = 'M' + points[0].x.toFixed(1) + ' ' + points[0].y.toFixed(1);
        for (var i = 1; i <= upto && i < points.length; i++) { d += segment(points[i - 1], points[i]); }
        return d;
    }

    function measure() {
        // One throwaway path per segment; a handful of nodes, so cheap.
        var probe = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        svg.appendChild(probe);
        lengths = [0];
        for (var i = 1; i < points.length; i++) {
            probe.setAttribute('d', 'M' + points[i - 1].x + ' ' + points[i - 1].y + segment(points[i - 1], points[i]));
            lengths.push(lengths[i - 1] + probe.getTotalLength());
        }
        svg.removeChild(probe);
    }

    function layout() {
        points = centres();
        var w = world.clientWidth, h = world.scrollHeight;
        svg.setAttribute('width', w);
        svg.setAttribute('height', h);
        svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);

        var full = pathTo(points.length - 1);
        roads.forEach(function (p) { p.setAttribute('d', full); });
        if (done) { done.setAttribute('d', current > 0 ? pathTo(current) : ''); }
        measure();
    }

    function placeAt(length) {
        if (!avatar) { return; }
        var p = roads[0].getPointAtLength(Math.max(0, length));
        avatar.style.transform = 'translate(' + p.x.toFixed(1) + 'px,' + p.y.toFixed(1) + 'px)';
    }

    function remembered() {
        try {
            var v = parseInt(localStorage.getItem(key), 10);
            return isNaN(v) ? null : v;
        } catch (e) { return null; }
    }

    function remember(i) {
        try { localStorage.setItem(key, String(i)); } catch (e) { /* private mode */ }
    }

    function ease(t) { return t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; }

    function stopWalking() {
        if (walking) {
            cancelAnimationFrame(walking.raf);
            clearInterval(walking.timer);
            walking = null;
        }
        if (avatar) { avatar.classList.remove('is-walking'); }
    }

    function walk(from, to) {
        stopWalking();
        var start = lengths[from] || 0, end = lengths[to] || 0;
        var duration = Math.min(4200, 900 * Math.max(1, Math.abs(to - from)));
        var began = Date.now();
        var state = walking = { raf: 0, timer: 0 };

        avatar.classList.add('is-walking');
        avatar.classList.remove('is-arrived');

        function tick() {
            if (walking !== state) { return false; }
            var t = Math.min(1, (Date.now() - began) / duration);
            placeAt(start + (end - start) * ease(t));
            if (t < 1) { return true; }
            stopWalking();
            avatar.classList.add('is-arrived');
            remember(to);
            return false;
        }

        function frame() {
            if (tick()) { state.raf = requestAnimationFrame(frame); }
        }
        state.raf = requestAnimationFrame(frame);

        // Animation frames pause while a page is not being painted; a timer
        // makes sure the avatar still arrives instead of waiting halfway.
        state.timer = setInterval(function () {
            if (!tick()) { clearInterval(state.timer); }
        }, 120);
    }

    function start() {
        layout();
        if (avatar) { avatar.hidden = false; }

        var from = remembered();
        var target = steps[current];

        if (target && target.scrollIntoView) {
            target.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
        }

        if (avatar && !reduce && document.visibilityState !== 'hidden' && from !== null && from < current && from >= 0) {
            placeAt(lengths[from] || 0);
            // Give the scroll a moment, so the student sees the walk.
            window.setTimeout(function () { walk(from, current); }, 650);
        } else {
            placeAt(lengths[current] || 0);
            remember(current);
        }
    }

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            stopWalking();
            layout();
            placeAt(lengths[current] || 0);
            remember(current);
        }, 150);
    });

    // Fonts and images change the layout after first paint.
    if (document.readyState === 'complete') {
        start();
    } else {
        window.addEventListener('load', start);
    }
})();
