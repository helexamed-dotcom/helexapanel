/* HeleXa Ink — the handwriting engine.
 *
 * Shared by the lesson frame (writing on the lesson itself), the note pad and
 * PDF pages. Plain ES5 so it can also be embedded inline in the sandboxed
 * lesson frame, which cannot load our other scripts.
 *
 * Why it feels smooth:
 *  - every coalesced pointer sample is used, not one per frame, so a fast
 *    Apple Pencil stroke keeps all of its ~240 Hz points;
 *  - the stroke being drawn lives on one low-latency canvas above the page
 *    and is redrawn once per animation frame, with the browser's predicted
 *    points appended so the ink keeps up with the pen tip;
 *  - the finished stroke becomes a vector <path>, so it scrolls with the page
 *    for free and stays sharp at any zoom;
 *  - pen pressure sets the width (a finger or mouse gets it from speed), and
 *    the outline is drawn through midpoints with quadratic curves.
 *
 * Palm rejection: once a pen has touched the board, fingers scroll instead
 * of drawing (unless "draw with finger" is switched on again).
 */
(function (root) {
    'use strict';
    if (root.HlxInk) { return; }

    var SVGNS = 'http://www.w3.org/2000/svg';
    var COLOR_RE = /^#[0-9a-f]{6}$/i;
    var MAX_POINTS = 3000;
    var MAX_STROKES = 5000;

    function fx(n) { return Math.round(n * 10) / 10; }
    function half(n) { return Math.round(n * 2) / 2; }
    function uid() {
        return Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    }

    /* ------------------------------------------------------------ geometry */

    function pointsOf(stroke, k) {
        var p = stroke.p, out = [];
        for (var i = 0; i + 2 < p.length; i += 3) {
            out.push([p[i] * k, p[i + 1] * k, p[i + 2]]);
        }
        return out;
    }

    function side(pts) {
        var d = '', i;
        if (pts.length < 3) {
            for (i = 1; i < pts.length; i++) { d += 'L' + fx(pts[i][0]) + ' ' + fx(pts[i][1]); }
            return d;
        }
        for (i = 1; i < pts.length - 1; i++) {
            d += 'Q' + fx(pts[i][0]) + ' ' + fx(pts[i][1]) + ' ' +
                fx((pts[i][0] + pts[i + 1][0]) / 2) + ' ' + fx((pts[i][1] + pts[i + 1][1]) / 2);
        }
        var last = pts[pts.length - 1];
        return d + 'L' + fx(last[0]) + ' ' + fx(last[1]);
    }

    function dot(x, y, r) {
        r = Math.max(0.6, r);
        return 'M' + fx(x - r) + ' ' + fx(y) + 'a' + fx(r) + ' ' + fx(r) + ' 0 1 0 ' + fx(2 * r) + ' 0' +
            'a' + fx(r) + ' ' + fx(r) + ' 0 1 0 ' + fx(-2 * r) + ' 0Z';
    }

    /** Radius at every point: real pressure for a pen, speed for the rest. */
    function radii(pts, size, simulated) {
        var out = [], prev = simulated ? 0.6 : (pts[0][2] || 0.5);
        var thinning = simulated ? 0.45 : 0.72;
        for (var i = 0; i < pts.length; i++) {
            var target;
            if (simulated) {
                var dist = i ? Math.sqrt(Math.pow(pts[i][0] - pts[i - 1][0], 2) + Math.pow(pts[i][1] - pts[i - 1][1], 2)) : 0;
                target = Math.max(0.2, Math.min(1, 1 - dist / (size * 2.4)));
                prev = prev + (target - prev) * 0.22;
            } else {
                target = pts[i][2] > 0 ? pts[i][2] : 0.5;
                prev = prev + (target - prev) * 0.45;
            }
            out.push(Math.max(0.4, (size / 2) * (1 - thinning + thinning * prev)));
        }
        // A short taper on both ends reads as ink rather than as a tube.
        var n = out.length, taper = Math.min(4, Math.floor(n / 4));
        for (var t = 0; t < taper; t++) {
            var f = 0.55 + 0.45 * (t / taper);
            out[t] *= f;
            out[n - 1 - t] *= f;
        }
        return out;
    }

    /** Filled outline for a pen stroke. */
    function outline(pts, size, simulated) {
        var n = pts.length;
        if (!n) { return ''; }
        var r = radii(pts, size, simulated);
        if (n === 1) { return dot(pts[0][0], pts[0][1], r[0]); }

        var nx = 0, ny = 0, i;
        for (i = 1; i < n; i++) {
            var ddx = pts[i][0] - pts[0][0], ddy = pts[i][1] - pts[0][1], dl = Math.sqrt(ddx * ddx + ddy * ddy);
            if (dl > 0.01) { nx = -ddy / dl; ny = ddx / dl; break; }
        }
        if (nx === 0 && ny === 0) { return dot(pts[0][0], pts[0][1], Math.max.apply(null, r)); }

        var left = [], right = [];
        for (i = 0; i < n; i++) {
            var a = pts[Math.max(0, i - 1)], b = pts[Math.min(n - 1, i + 1)];
            var dx = b[0] - a[0], dy = b[1] - a[1], len = Math.sqrt(dx * dx + dy * dy);
            if (len > 0.01) { nx = -dy / len; ny = dx / len; }
            left.push([pts[i][0] + nx * r[i], pts[i][1] + ny * r[i]]);
            right.push([pts[i][0] - nx * r[i], pts[i][1] - ny * r[i]]);
        }

        var STEPS = 6, s, ang;
        var end = pts[n - 1], rEnd = r[n - 1];
        var aEnd = Math.atan2(left[n - 1][1] - end[1], left[n - 1][0] - end[0]);
        var start = pts[0], rStart = r[0];
        var aStart = Math.atan2(left[0][1] - start[1], left[0][0] - start[0]);

        var d = 'M' + fx(left[0][0]) + ' ' + fx(left[0][1]) + side(left);
        for (s = 1; s < STEPS; s++) {
            ang = aEnd - Math.PI * s / STEPS;
            d += 'L' + fx(end[0] + Math.cos(ang) * rEnd) + ' ' + fx(end[1] + Math.sin(ang) * rEnd);
        }
        var back = right.slice().reverse();
        d += 'L' + fx(back[0][0]) + ' ' + fx(back[0][1]) + side(back);
        for (s = 1; s < STEPS; s++) {
            ang = aStart + Math.PI - Math.PI * s / STEPS;
            d += 'L' + fx(start[0] + Math.cos(ang) * rStart) + ' ' + fx(start[1] + Math.sin(ang) * rStart);
        }
        return d + 'Z';
    }

    /** Centre line for a marker stroke, drawn with a wide round stroke. */
    function centre(pts) {
        if (!pts.length) { return ''; }
        var d = 'M' + fx(pts[0][0]) + ' ' + fx(pts[0][1]);
        if (pts.length === 1) { return d + 'l0.01 0'; }
        return d + side(pts);
    }

    function pathFor(stroke, k) {
        var pts = pointsOf(stroke, k);
        return stroke.t === 'marker' ? centre(pts) : outline(pts, stroke.s * k, !!stroke.sim);
    }

    function clean(list) {
        var out = [];
        if (!Array.isArray(list)) { return out; }
        for (var i = 0; i < list.length && out.length < MAX_STROKES; i++) {
            var s = list[i];
            if (!s || !Array.isArray(s.p) || s.p.length < 3 || !COLOR_RE.test(String(s.c))) { continue; }
            var p = [];
            for (var j = 0; j + 2 < s.p.length && j < MAX_POINTS * 3; j += 3) {
                var x = +s.p[j], y = +s.p[j + 1], pr = +s.p[j + 2];
                if (!isFinite(x) || !isFinite(y)) { continue; }
                p.push(x, y, isFinite(pr) ? pr : 0.5);
            }
            if (p.length < 3) { continue; }
            out.push({
                id: String(s.id || uid()).slice(0, 24),
                t: s.t === 'marker' ? 'marker' : 'pen',
                c: String(s.c),
                s: Math.max(0.5, Math.min(60, +s.s || 3)),
                w: Math.max(50, Math.min(10000, +s.w || 800)),
                sim: s.sim ? 1 : 0,
                p: p
            });
        }
        return out;
    }

    function segDist(px, py, ax, ay, bx, by) {
        var dx = bx - ax, dy = by - ay, l2 = dx * dx + dy * dy;
        var t = l2 ? Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / l2)) : 0;
        var cx = ax + t * dx - px, cy = ay + t * dy - py;
        return Math.sqrt(cx * cx + cy * cy);
    }

    /* ------------------------------------------------ the shared live canvas
       One per window, fixed over the viewport, never catching input. */
    var live = null;

    function liveCanvas() {
        if (live) { return live; }
        var canvas = document.createElement('canvas');
        canvas.className = 'hlx-ink-live';
        canvas.setAttribute('aria-hidden', 'true');
        // Sized in pixels from innerWidth/innerHeight, never 100vw/100vh. On
        // iOS 100vh is the height with the address bar hidden, taller than the
        // visible window while the bar shows, so the bitmap was stretched and
        // the ink being drawn slid further below the pen the lower it was on
        // the screen — then jumped into place when the stroke was committed.
        canvas.style.cssText = 'position:fixed;left:0;top:0;pointer-events:none;z-index:2147482600;';
        (document.body || document.documentElement).appendChild(canvas);
        // No { desynchronized: true } here. On many phones a low-latency 2D
        // context used as a fixed, full-viewport overlay (inside an iframe
        // above all) is composited through a hardware-overlay path that does
        // not blend with the page, and the whole screen paints black while a
        // stroke is drawn. The browser does not throw — it just mis-renders —
        // so it cannot be detected and worked around; the plain context is
        // the only safe choice. Finished strokes are SVG either way.
        var ctx = canvas.getContext('2d');
        live = { canvas: canvas, ctx: ctx, dpr: 1 };
        function size() {
            var w = window.innerWidth, hgt = window.innerHeight;
            live.dpr = Math.min(window.devicePixelRatio || 1, 3);
            canvas.style.width = w + 'px';
            canvas.style.height = hgt + 'px';
            canvas.width = Math.round(w * live.dpr);
            canvas.height = Math.round(hgt * live.dpr);
        }
        size();
        window.addEventListener('resize', size);
        // The address bar showing or hiding changes the window without always
        // firing a window resize on iOS; the visual viewport does report it.
        if (window.visualViewport) { window.visualViewport.addEventListener('resize', size); }
        return live;
    }

    function clearLive() {
        if (!live) { return; }
        live.ctx.setTransform(1, 0, 0, 1, 0, 0);
        live.ctx.clearRect(0, 0, live.canvas.width, live.canvas.height);
    }

    var hasPath2D = typeof root.Path2D === 'function';

    /* =============================================================== Board

       opts:
         input      element that receives pointer events
         host       element the vector layer is appended to
         refWidth() width the coordinates are relative to (resize-proof)
         active()   whether drawing is on right now
         clip()     optional client rect to keep live ink inside
         accept(e)  optional veto for a pointerdown (e.g. a note pin)
         onChange(board)    after any change to the strokes
         onPen()            the first time a pen touches this board
         onHistory(board)   when undo/redo availability changes
    */
    function Board(opts) {
        this.o = opts;
        this.strokes = [];
        this.undoStack = [];
        this.redoStack = [];
        this.mode = 'pen';
        this.color = '#1f2937';
        this.size = 3;
        this.fingerDraws = true;
        this.penSeen = false;
        this.cur = null;
        this.pid = null;
        this.erasing = null;
        this.pred = [];
        this.raf = 0;
        this.boxes = {};
        this.lastRef = 0;

        var svg = document.createElementNS(SVGNS, 'svg');
        svg.setAttribute('class', 'hlx-ink-svg');
        svg.setAttribute('aria-hidden', 'true');
        this.svg = svg;
        opts.host.appendChild(svg);

        this._bind();
    }

    Board.prototype._bind = function () {
        var self = this, input = this.o.input;
        var h = this.handlers = {
            down: function (e) { self._down(e); },
            move: function (e) { self._move(e); },
            up: function (e) { self._up(e, false); },
            cancel: function (e) { self._up(e, true); },
            guard: guard
        };

        input.addEventListener('pointerdown', h.down, { passive: false });
        window.addEventListener('pointermove', h.move, { passive: false });
        window.addEventListener('pointerup', h.up);
        window.addEventListener('pointercancel', h.cancel);

        // Stop the page from scrolling under a pen (and under a drawing
        // finger), while a resting palm or a scrolling finger is left alone.
        function guard(e) {
            if (!self.o.active()) { return; }
            var t = e.touches && e.touches[0];
            var stylus = t && t.touchType === 'stylus';
            if (stylus || (self.pid !== null && (self.ptype === 'pen' || (self.ptype === 'touch' && e.touches.length === 1)))) {
                if (e.cancelable) { e.preventDefault(); }
            }
        }
        input.addEventListener('touchstart', guard, { passive: false });
        input.addEventListener('touchmove', guard, { passive: false });
    };

    /** Detaches every listener and removes the vector layer. */
    Board.prototype.destroy = function () {
        var h = this.handlers, input = this.o.input;
        if (h) {
            input.removeEventListener('pointerdown', h.down, { passive: false });
            window.removeEventListener('pointermove', h.move, { passive: false });
            window.removeEventListener('pointerup', h.up);
            window.removeEventListener('pointercancel', h.cancel);
            input.removeEventListener('touchstart', h.guard, { passive: false });
            input.removeEventListener('touchmove', h.guard, { passive: false });
        }
        this._abort();
        if (this.svg.parentNode) { this.svg.parentNode.removeChild(this.svg); }
        this.handlers = null;
    };

    Board.prototype.origin = function () {
        var r = this.svg.getBoundingClientRect();
        return { x: r.left, y: r.top };
    };

    Board.prototype._point = function (ev) {
        return [ev.clientX - this.org.x, ev.clientY - this.org.y, ev.pressure];
    };

    /* The board may move under a stroke — an inertial scroll still settling,
       the address bar sliding away — and every point is relative to where the
       board is now, not where it was at pointerdown. Measured once per frame. */
    Board.prototype._track = function () {
        var now = this.origin();
        if (!this.org || now.x !== this.org.x || now.y !== this.org.y) { this.org = now; }
    };

    Board.prototype._down = function (e) {
        if (!this.o.active()) { return; }
        if (e.pointerType === 'mouse' && e.button !== 0) { return; }
        if (e.pointerType === 'pen' && !this.penSeen) {
            this.penSeen = true;
            if (this.o.onPen) { this.o.onPen(this); }
        }
        if (e.pointerType === 'touch') {
            if (this.pid !== null) { this._abort(); return; }   // a second finger: pinch or scroll
            if (!this.fingerDraws) { return; }
        }
        if (this.pid !== null) { return; }
        if (this.o.accept && !this.o.accept(e)) { return; }

        e.preventDefault();
        this.pid = e.pointerId;
        this.ptype = e.pointerType;
        this.org = this.origin();
        try { if (e.target && e.target.setPointerCapture) { e.target.setPointerCapture(e.pointerId); } } catch (x) { /* not capturable */ }

        if (this.mode === 'eraser') {
            this.erasing = [];
            this._eraseAt(e);
            this._schedule();
            return;
        }

        var ref = this.o.refWidth();
        this.cur = {
            id: uid(),
            t: this.mode === 'marker' ? 'marker' : 'pen',
            c: this.color,
            s: this.mode === 'marker' ? Math.max(8, this.size * 4) : this.size,
            w: half(ref) || 1,
            sim: e.pointerType === 'pen' ? 0 : 1,
            p: []
        };
        this.lastRef = ref;
        this._add(e);
        this._schedule();
    };

    Board.prototype._add = function (e) {
        var list = (e.getCoalescedEvents && e.getCoalescedEvents()) || [];
        if (!list.length) { list = [e]; }
        var p = this.cur.p;
        for (var i = 0; i < list.length; i++) {
            var pt = this._point(list[i]);
            var n = p.length;
            if (n) {
                var lx = p[n - 3], ly = p[n - 2];
                var dx = pt[0] - lx, dy = pt[1] - ly;
                if (dx * dx + dy * dy < 0.36) { continue; }
                // Light streamlining: removes hand jitter without visible lag.
                pt[0] = lx + dx * 0.78;
                pt[1] = ly + dy * 0.78;
            }
            var pr = this.ptype === 'pen' ? (pt[2] > 0 ? pt[2] : 0.5) : 0.5;
            p.push(half(pt[0]), half(pt[1]), Math.round(pr * 100) / 100);
        }
        if (p.length >= MAX_POINTS * 3) {
            // Very long stroke: close it and carry on with a fresh one.
            var carry = p.slice(-3);
            var next = { id: uid(), t: this.cur.t, c: this.cur.c, s: this.cur.s, w: this.cur.w, sim: this.cur.sim, p: carry };
            this._commit();
            this.cur = next;
        }
        var predicted = (e.getPredictedEvents && e.getPredictedEvents()) || [];
        this.pred = [];
        for (var j = 0; j < predicted.length && j < 3; j++) {
            var q = this._point(predicted[j]);
            this.pred.push(q[0], q[1], p.length ? p[p.length - 1] : 0.5);
        }
    };

    Board.prototype._move = function (e) {
        if (e.pointerId !== this.pid) { return; }
        if (e.cancelable) { e.preventDefault(); }
        if (!this.tracked) {
            var self = this;
            this.tracked = true;
            this._track();
            root.requestAnimationFrame(function () { self.tracked = false; });
        }
        if (this.erasing) { this._eraseAt(e); this._schedule(); return; }
        if (!this.cur) { return; }
        this._add(e);
        this._schedule();
    };

    Board.prototype._up = function (e, cancelled) {
        if (e.pointerId !== this.pid) { return; }
        if (this.erasing) {
            if (this.erasing.length) {
                this._push({ op: 'remove', strokes: this.erasing });
                this._changed();
            }
            this.erasing = null;
        } else if (this.cur) {
            if (!cancelled || this.cur.p.length > 6) { this._commit(); }
        }
        this.cur = null;
        this.pid = null;
        this.pred = [];
        this._cancelFrame();
        clearLive();
    };

    Board.prototype._abort = function () {
        this.cur = null;
        this.erasing = null;
        this.pid = null;
        this._cancelFrame();
        clearLive();
    };

    Board.prototype._commit = function () {
        var s = this.cur;
        if (!s || !s.p.length) { return; }
        if (this.strokes.length >= MAX_STROKES) { return; }
        this.strokes.push(s);
        this._draw(s);
        this._push({ op: 'add', strokes: [s] });
        this._changed();
    };

    Board.prototype._schedule = function () {
        var self = this;
        if (this.raf) { return; }
        this.raf = root.requestAnimationFrame(function () { self.raf = 0; self._paintLive(); });
    };

    Board.prototype._cancelFrame = function () {
        if (this.raf) { root.cancelAnimationFrame(this.raf); this.raf = 0; }
    };

    Board.prototype._paintLive = function () {
        var L = liveCanvas(), ctx = L.ctx, dpr = L.dpr;
        clearLive();
        ctx.save();
        if (this.o.clip) {
            var c = this.o.clip();
            if (c) {
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                ctx.beginPath();
                ctx.rect(c.left, c.top, c.right - c.left, c.bottom - c.top);
                ctx.clip();
            }
        }
        ctx.setTransform(dpr, 0, 0, dpr, this.org.x * dpr, this.org.y * dpr);

        if (this.erasing) {
            if (this.eraserAt) {
                ctx.beginPath();
                ctx.arc(this.eraserAt[0], this.eraserAt[1], this._eraserRadius(), 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(239,68,68,.12)';
                ctx.strokeStyle = 'rgba(239,68,68,.7)';
                ctx.lineWidth = 1.2;
                ctx.fill();
                ctx.stroke();
            }
            ctx.restore();
            return;
        }

        var s = this.cur;
        if (!s) { ctx.restore(); return; }
        var view = { t: s.t, s: s.s, sim: s.sim, p: s.p.concat(this.pred) };
        var d = pathFor(view, 1);
        if (!d) { ctx.restore(); return; }

        if (hasPath2D) {
            var path = new root.Path2D(d);
            if (s.t === 'marker') {
                ctx.globalAlpha = 0.35;
                ctx.strokeStyle = s.c;
                ctx.lineWidth = s.s;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.stroke(path);
            } else {
                ctx.fillStyle = s.c;
                ctx.fill(path);
            }
        }
        ctx.restore();
    };

    Board.prototype._draw = function (s) {
        var ref = this.o.refWidth() || s.w;
        var k = s.w > 0 ? ref / s.w : 1;
        var el = document.createElementNS(SVGNS, 'path');
        el.setAttribute('data-id', s.id);
        el.setAttribute('d', pathFor(s, k));
        if (s.t === 'marker') {
            el.setAttribute('fill', 'none');
            el.setAttribute('stroke', s.c);
            el.setAttribute('stroke-width', String(fx(s.s * k)));
            el.setAttribute('stroke-linecap', 'round');
            el.setAttribute('stroke-linejoin', 'round');
            el.setAttribute('stroke-opacity', '0.35');
            el.setAttribute('class', 'hlx-ink-marker');
        } else {
            el.setAttribute('fill', s.c);
        }
        this.svg.appendChild(el);
    };

    Board.prototype._eraserRadius = function () {
        return Math.max(9, this.size * 3.2);
    };

    Board.prototype._box = function (s) {
        var b = this.boxes[s.id];
        if (b) { return b; }
        var x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
        for (var i = 0; i < s.p.length; i += 3) {
            if (s.p[i] < x1) { x1 = s.p[i]; }
            if (s.p[i] > x2) { x2 = s.p[i]; }
            if (s.p[i + 1] < y1) { y1 = s.p[i + 1]; }
            if (s.p[i + 1] > y2) { y2 = s.p[i + 1]; }
        }
        b = this.boxes[s.id] = [x1, y1, x2, y2];
        return b;
    };

    Board.prototype._eraseAt = function (e) {
        var list = (e.getCoalescedEvents && e.getCoalescedEvents()) || [];
        if (!list.length) { list = [e]; }
        var ref = this.o.refWidth();
        var radius = this._eraserRadius();
        for (var n = 0; n < list.length; n++) {
            var pt = this._point(list[n]);
            this.eraserAt = pt;
            for (var i = this.strokes.length - 1; i >= 0; i--) {
                var s = this.strokes[i];
                var k = ref > 0 && s.w > 0 ? ref / s.w : 1;
                var px = pt[0] / k, py = pt[1] / k;
                var reach = radius / k + s.s / 2;
                var b = this._box(s);
                if (px < b[0] - reach || px > b[2] + reach || py < b[1] - reach || py > b[3] + reach) { continue; }
                var hit = false;
                for (var j = 0; j < s.p.length; j += 3) {
                    var ax = s.p[j], ay = s.p[j + 1];
                    var bx = j + 3 < s.p.length ? s.p[j + 3] : ax, by = j + 3 < s.p.length ? s.p[j + 4] : ay;
                    if (segDist(px, py, ax, ay, bx, by) <= reach) { hit = true; break; }
                }
                if (hit) {
                    this.strokes.splice(i, 1);
                    this._undraw(s.id);
                    this.erasing.push(s);
                }
            }
        }
    };

    Board.prototype._undraw = function (id) {
        var nodes = this.svg.querySelectorAll('path[data-id="' + String(id).replace(/[^a-z0-9]/gi, '') + '"]');
        for (var i = 0; i < nodes.length; i++) { nodes[i].parentNode.removeChild(nodes[i]); }
        delete this.boxes[id];
    };

    Board.prototype._push = function (action) {
        this.undoStack.push(action);
        if (this.undoStack.length > 200) { this.undoStack.shift(); }
        this.redoStack.length = 0;
        if (this.o.onHistory) { this.o.onHistory(this); }
    };

    Board.prototype._changed = function () {
        if (this.o.onChange) { this.o.onChange(this); }
    };

    Board.prototype._addAll = function (list) {
        for (var i = 0; i < list.length; i++) { this.strokes.push(list[i]); this._draw(list[i]); }
    };

    Board.prototype._removeAll = function (list) {
        var ids = {};
        for (var i = 0; i < list.length; i++) { ids[list[i].id] = true; this._undraw(list[i].id); }
        this.strokes = this.strokes.filter(function (s) { return !ids[s.id]; });
    };

    /* ------------------------------------------------------------ public */

    Board.prototype.setOptions = function (o) {
        if (o.mode === 'pen' || o.mode === 'marker' || o.mode === 'eraser') { this.mode = o.mode; }
        if (COLOR_RE.test(String(o.color || ''))) { this.color = o.color; }
        if (+o.size > 0) { this.size = Math.max(0.8, Math.min(20, +o.size)); }
        if (typeof o.finger === 'boolean') { this.fingerDraws = o.finger; }
    };

    Board.prototype.canUndo = function () { return this.undoStack.length > 0; };
    Board.prototype.canRedo = function () { return this.redoStack.length > 0; };

    Board.prototype.undo = function () {
        var a = this.undoStack.pop();
        if (!a) { return; }
        if (a.op === 'add') { this._removeAll(a.strokes); } else { this._addAll(a.strokes); }
        this.redoStack.push(a);
        if (this.o.onHistory) { this.o.onHistory(this); }
        this._changed();
    };

    Board.prototype.redo = function () {
        var a = this.redoStack.pop();
        if (!a) { return; }
        if (a.op === 'add') { this._addAll(a.strokes); } else { this._removeAll(a.strokes); }
        this.undoStack.push(a);
        if (this.o.onHistory) { this.o.onHistory(this); }
        this._changed();
    };

    Board.prototype.clear = function () {
        if (!this.strokes.length) { return; }
        var all = this.strokes.slice();
        this._removeAll(all);
        this._push({ op: 'remove', strokes: all });
        this._changed();
    };

    Board.prototype.load = function (list) {
        this.strokes = clean(list);
        this.undoStack = [];
        this.redoStack = [];
        this.rerender();
        if (this.o.onHistory) { this.o.onHistory(this); }
    };

    /** Redraws everything, e.g. after the board changed width. */
    Board.prototype.rerender = function () {
        while (this.svg.firstChild) { this.svg.removeChild(this.svg.firstChild); }
        this.boxes = {};
        for (var i = 0; i < this.strokes.length; i++) { this._draw(this.strokes[i]); }
        this.lastRef = this.o.refWidth();
    };

    Board.prototype.refresh = function () {
        if (Math.abs(this.o.refWidth() - this.lastRef) > 0.5) { this.rerender(); }
    };

    Board.prototype.data = function () {
        return this.strokes.map(function (s) {
            return { id: s.id, t: s.t, c: s.c, s: s.s, w: s.w, sim: s.sim, p: s.p };
        });
    };

    Board.prototype.count = function () { return this.strokes.length; };

    root.HlxInk = {
        Board: Board,
        pathFor: pathFor,
        clean: clean,
        COLORS: ['#111827', '#2563eb', '#dc2626', '#16a34a', '#ea580c', '#7c3aed', '#facc15', '#ec4899'],
        SIZES: [1.8, 3, 5.5]
    };
})(typeof window !== 'undefined' ? window : this);
