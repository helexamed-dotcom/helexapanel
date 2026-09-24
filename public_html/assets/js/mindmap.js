/* =====================================================================
   HeleXa Med — the mind map engine (XMind-like), shared by the admin's
   editor and the student's viewer.

   HxMindmap.mount(element, options) draws a tree of topics with curved
   branches, lays it out on both sides of the centre (or to one side, or
   top-down), and lets you pan, zoom, collapse and — when editable — add,
   edit, move and delete topics with the keyboard and the mouse.

   A node: {id, text, note?, color?, shape?, marker?, image?, lesson?,
            collapsed?, children[]}. Text is always set as textContent.
   ===================================================================== */
(function () {
    'use strict';

    var PALETTES = {
        classic: ['#4f46e5', '#db2777', '#0d9488', '#ea580c', '#2563eb', '#7c3aed', '#059669', '#dc2626'],
        rainbow: ['#ef4444', '#f97316', '#eab308', '#22c55e', '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899'],
        pastel:  ['#f9a8d4', '#a5b4fc', '#86efac', '#fcd34d', '#93c5fd', '#c4b5fd', '#fdba74', '#5eead4'],
        night:   ['#818cf8', '#f472b6', '#2dd4bf', '#fbbf24', '#60a5fa', '#c084fc', '#4ade80', '#fb7185'],
        mono:    ['#334155', '#475569', '#1e293b', '#64748b']
    };
    var TONES = {
        indigo: '#4f46e5', violet: '#7c3aed', blue: '#2563eb', sky: '#0284c7', teal: '#0d9488', green: '#059669',
        amber: '#d97706', orange: '#ea580c', rose: '#e11d48', pink: '#db2777', red: '#dc2626', slate: '#475569'
    };
    var HGAP = 46, VGAP = 14, DOWN_V = 56, DOWN_H = 18;

    function uid() { return Math.random().toString(36).slice(2, 11); }
    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    function each(n, fn, parent, depth) {
        fn(n, parent || null, depth || 0);
        (n.children || []).forEach(function (c) { each(c, fn, n, (depth || 0) + 1); });
    }

    function mount(host, opts) {
        opts = opts || {};
        var editable = !!opts.editable;
        var root = opts.data && opts.data.text !== undefined ? clone(opts.data) : { id: 'root', text: 'موضوع اصلی', children: [] };
        var theme = opts.theme || 'classic';
        var layout = opts.layout || 'map';
        var lessons = opts.lessons || {};
        var media = opts.mediaBase || '/media/mindmaps/';
        var selected = null, editing = null;
        var view = { x: 0, y: 0, k: 1 };
        var els = {};               // id => element
        var index = {};             // id => {node, parent, depth}
        var history = [], future = [];
        var firstPaint = true;
        var touched = false;        // until someone pans or zooms, the map keeps itself fitted

        host.classList.add('mm');
        host.innerHTML = '';
        var viewport = document.createElement('div');
        viewport.className = 'mm-viewport';
        viewport.tabIndex = 0;
        var stage = document.createElement('div');
        stage.className = 'mm-stage';
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'mm-lines');
        var layer = document.createElement('div');
        layer.className = 'mm-nodes';
        stage.appendChild(svg);
        stage.appendChild(layer);
        viewport.appendChild(stage);
        host.appendChild(viewport);

        /* ------------------------------------------------ helpers */
        function reindex() {
            index = {};
            each(root, function (n, p, d) {
                if (!n.id) { n.id = uid(); }
                if (!n.children) { n.children = []; }
                index[n.id] = { node: n, parent: p, depth: d };
            });
        }
        function branchColor(n) {
            var info = index[n.id];
            var pal = PALETTES[theme] || PALETTES.classic;
            var walk = n, top = null;
            while (walk) {
                var i = index[walk.id];
                if (walk.color) { return TONES[walk.color] || walk.color; }
                if (i && i.depth === 1) { top = walk; break; }
                walk = i ? i.parent : null;
            }
            if (!info || info.depth === 0) { return pal[0]; }
            if (top) {
                var k = index[top.id].parent.children.indexOf(top);
                return pal[k % pal.length];
            }
            return pal[0];
        }
        function visibleChildren(n) { return n.collapsed ? [] : n.children; }
        function countAll(n) { var c = 0; each(n, function () { c++; }); return c - 1; }
        function snapshot() {
            history.push(JSON.stringify(root));
            if (history.length > 120) { history.shift(); }
            future = [];
        }
        function changed() { if (opts.onChange) { opts.onChange(api.getData()); } }

        /* ------------------------------------------------ render */
        function nodeEl(n, depth) {
            var el = els[n.id];
            var isNew = !el;
            if (isNew) {
                el = document.createElement('div');
                el.className = 'mm-node';
                el.setAttribute('data-id', n.id);
                el.innerHTML = '<span class="mm-img"></span><span class="mm-body"><span class="mm-marker"></span><span class="mm-text" dir="auto"></span></span>'
                    + '<span class="mm-badges"></span><button type="button" class="mm-fold" tabindex="-1"></button>';
                layer.appendChild(el);
                els[n.id] = el;
                if (!firstPaint) { el.classList.add('is-new'); }
            }
            var color = branchColor(n);
            el.style.setProperty('--c', color);
            el.className = 'mm-node d' + Math.min(depth, 3) + ' shape-' + (n.shape || (depth === 0 ? 'root' : depth === 1 ? 'rounded' : 'underline'))
                + (isNew && !firstPaint ? ' is-new' : '') + (selected === n.id ? ' is-selected' : '') + (n.collapsed ? ' is-collapsed' : '')
                + (n.children.length ? ' has-kids' : '') + (n.note || n.lesson || n.image ? ' has-info' : '');
            if (editing !== n.id) {
                var tx = el.querySelector('.mm-text');
                tx.textContent = n.text;
                // Persian with a Latin term inside ("S1: …") still reads right to left.
                tx.dir = /[\u0600-\u06FF]/.test(n.text) ? 'rtl' : 'ltr';
            }
            el.querySelector('.mm-marker').textContent = n.marker || '';
            var img = el.querySelector('.mm-img');
            if (n.image) {
                if (!img.firstChild || img.firstChild.getAttribute('data-src') !== n.image) {
                    img.innerHTML = '';
                    var im = document.createElement('img');
                    im.src = media + n.image;
                    im.setAttribute('data-src', n.image);
                    im.alt = '';
                    im.draggable = false;
                    im.addEventListener('load', function () { paint(); });
                    img.appendChild(im);
                }
            } else { img.innerHTML = ''; }
            var badges = '';
            if (n.note) { badges += '<i title="یادداشت">📝</i>'; }
            if (n.lesson) { badges += '<i title="درسنامه">📘</i>'; }
            el.querySelector('.mm-badges').innerHTML = badges;
            var fold = el.querySelector('.mm-fold');
            fold.textContent = n.collapsed ? String(countAll(n)).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }) : '−';
            fold.hidden = !n.children.length || depth === 0;
            return el;
        }

        function paint() {
            reindex();
            var live = {};
            (function walk(n, depth) {
                live[n.id] = true;
                var el = nodeEl(n, depth);
                n._w = el.offsetWidth;
                n._h = el.offsetHeight;
                visibleChildren(n).forEach(function (c) { walk(c, depth + 1); });
            })(root, 0);
            Object.keys(els).forEach(function (id) {
                if (!live[id]) { els[id].remove(); delete els[id]; }
            });
            place();
            draw();
            if (firstPaint) { firstPaint = false; fit(); window.requestAnimationFrame(function () { if (!touched) { fit(); } }); }
            window.setTimeout(function () { layer.querySelectorAll('.is-new').forEach(function (e) { e.classList.remove('is-new'); }); }, 450);
        }

        /* ------------------------------------------------ layout */
        function sizeH(n) {
            var kids = visibleChildren(n);
            if (!kids.length) { n._bh = n._h; return n._bh; }
            var sum = 0;
            kids.forEach(function (c) { sum += sizeH(c); });
            sum += VGAP * (kids.length - 1);
            n._bh = Math.max(n._h, sum);
            return n._bh;
        }
        function side(n, list, dir) {
            var total = 0;
            list.forEach(function (c) { total += c._bh; });
            total += VGAP * (list.length - 1);
            var top = n._y - total / 2;
            list.forEach(function (c) {
                c._y = top + c._bh / 2;
                c._x = n._x + dir * (n._w / 2 + HGAP + c._w / 2);
                c._dir = dir;
                top += c._bh + VGAP;
                side(c, visibleChildren(c), dir);
            });
        }
        function sizeW(n) {
            var kids = visibleChildren(n);
            if (!kids.length) { n._bw = n._w; return n._bw; }
            var sum = 0;
            kids.forEach(function (c) { sum += sizeW(c); });
            sum += DOWN_H * (kids.length - 1);
            n._bw = Math.max(n._w, sum);
            return n._bw;
        }
        function down(n) {
            var kids = visibleChildren(n);
            var total = 0;
            kids.forEach(function (c) { total += c._bw; });
            total += DOWN_H * (kids.length - 1);
            var right = n._x + total / 2;            // first child on the right, as a Persian reader starts
            kids.forEach(function (c) {
                c._x = right - c._bw / 2;
                c._y = n._y + n._h / 2 + DOWN_V + c._h / 2;
                c._dir = 0;
                right -= c._bw + DOWN_H;
                down(c);
            });
        }
        function place() {
            root._x = 0; root._y = 0; root._dir = 0;
            if (layout === 'down') {
                sizeW(root);
                down(root);
            } else {
                visibleChildren(root).forEach(sizeH);
                var kids = visibleChildren(root);
                if (layout === 'side') {
                    side(root, kids, -1);
                } else {
                    var all = 0;
                    kids.forEach(function (c) { all += c._bh; });
                    var acc = 0, rightSide = [], leftSide = [];
                    kids.forEach(function (c, i) {
                        if (acc + c._bh / 2 <= all / 2 || i === 0) { rightSide.push(c); acc += c._bh; } else { leftSide.push(c); }
                    });
                    side(root, rightSide, 1);
                    side(root, leftSide, -1);
                }
            }
            var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            (function walk(n) {
                var el = els[n.id];
                el.style.left = (n._x - n._w / 2) + 'px';
                el.style.top = (n._y - n._h / 2) + 'px';
                el.classList.toggle('is-left', n._dir === -1);
                el.classList.toggle('is-down', layout === 'down');
                minX = Math.min(minX, n._x - n._w / 2); maxX = Math.max(maxX, n._x + n._w / 2);
                minY = Math.min(minY, n._y - n._h / 2); maxY = Math.max(maxY, n._y + n._h / 2);
                visibleChildren(n).forEach(walk);
            })(root);
            bounds = { x: minX, y: minY, w: maxX - minX, h: maxY - minY };
        }
        var bounds = { x: 0, y: 0, w: 0, h: 0 };

        function draw() {
            var pad = 40;
            svg.setAttribute('width', bounds.w + pad * 2);
            svg.setAttribute('height', bounds.h + pad * 2);
            svg.style.left = (bounds.x - pad) + 'px';
            svg.style.top = (bounds.y - pad) + 'px';
            var ox = -(bounds.x - pad), oy = -(bounds.y - pad);
            var out = '';
            (function walk(n, depth) {
                visibleChildren(n).forEach(function (c) {
                    var color = branchColor(c);
                    var w = depth === 0 ? 4.5 : depth === 1 ? 2.6 : 1.8;
                    var d;
                    if (layout === 'down') {
                        var x1 = n._x + ox, y1 = n._y + n._h / 2 + oy, x2 = c._x + ox, y2 = c._y - c._h / 2 + oy, my = (y1 + y2) / 2;
                        d = 'M' + x1 + ' ' + y1 + ' C' + x1 + ' ' + my + ' ' + x2 + ' ' + my + ' ' + x2 + ' ' + y2;
                    } else {
                        var dir = c._dir;
                        var sx = n._x + dir * (depth === 0 ? n._w * 0.32 : n._w / 2) + ox;
                        var sy = n._y + (depth === 0 ? 0 : (n.shape || (depth === 1 ? 'rounded' : 'underline')) === 'underline' ? n._h / 2 - 1 : 0) + oy;
                        var ex = c._x - dir * c._w / 2 + ox;
                        var ey = c._y + ((c.shape || (depth + 1 === 1 ? 'rounded' : 'underline')) === 'underline' ? c._h / 2 - 1 : 0) + oy;
                        var mx = (sx + ex) / 2;
                        d = 'M' + sx + ' ' + sy + ' C' + mx + ' ' + sy + ' ' + (sx + (ex - sx) * 0.35) + ' ' + ey + ' ' + ex + ' ' + ey;
                    }
                    out += '<path d="' + d + '" stroke="' + color + '" stroke-width="' + w + '" fill="none" stroke-linecap="round"/>';
                    walk(c, depth + 1);
                });
            })(root, 0);
            svg.innerHTML = out;
        }

        /* ------------------------------------------------ view */
        function apply() {
            stage.style.transform = 'translate(' + view.x + 'px,' + view.y + 'px) scale(' + view.k + ')';
            host.style.setProperty('--mm-k', view.k);
            if (opts.onZoom) { opts.onZoom(view.k); }
        }
        function fit() {
            var r = viewport.getBoundingClientRect();
            if (!r.width || !bounds.w) { view = { x: r.width / 2, y: r.height / 2, k: 1 }; apply(); return; }
            var k = Math.min(1.25, Math.max(0.2, Math.min((r.width - 60) / bounds.w, (r.height - 60) / bounds.h)));
            // On a phone a whole wide map would be unreadably small: stay
            // readable, start at the centre topic, and let the finger pan.
            var minK = r.width < 640 ? 0.62 : 0.2;
            view.k = Math.max(k, minK);
            var cx = view.k > k ? 0 : bounds.x + bounds.w / 2, cy = view.k > k ? 0 : bounds.y + bounds.h / 2;
            view.x = r.width / 2 - cx * view.k;
            view.y = r.height / 2 - cy * view.k;
            stage.classList.add('is-gliding');
            apply();
            window.setTimeout(function () { stage.classList.remove('is-gliding'); }, 400);
        }
        function zoomAt(k, cx, cy) {
            touched = true;
            k = Math.min(3, Math.max(0.15, k));
            var r = viewport.getBoundingClientRect();
            cx = cx === undefined ? r.width / 2 : cx;
            cy = cy === undefined ? r.height / 2 : cy;
            view.x = cx - (cx - view.x) * (k / view.k);
            view.y = cy - (cy - view.y) * (k / view.k);
            view.k = k;
            apply();
        }
        function reveal(id) {
            var n = index[id] && index[id].node;
            if (!n || n._x === undefined) { return; }
            var r = viewport.getBoundingClientRect();
            var sx = n._x * view.k + view.x, sy = n._y * view.k + view.y;
            if (sx < 60 || sx > r.width - 60 || sy < 60 || sy > r.height - 60) {
                view.x += r.width / 2 - sx;
                view.y += r.height / 2 - sy;
                stage.classList.add('is-gliding');
                apply();
                window.setTimeout(function () { stage.classList.remove('is-gliding'); }, 400);
            }
        }

        viewport.addEventListener('wheel', function (e) {
            e.preventDefault();
            touched = true;
            var r = viewport.getBoundingClientRect();
            if (e.ctrlKey || e.metaKey) {
                zoomAt(view.k * Math.exp(-e.deltaY * 0.0022), e.clientX - r.left, e.clientY - r.top);
            } else {
                view.x -= e.deltaX; view.y -= e.deltaY; apply();
            }
        }, { passive: false });

        var pointers = {}, panStart = null, pinch = null, drag = null;
        viewport.addEventListener('pointerdown', function (e) {
            if (e.button !== 0 && e.pointerType === 'mouse') { return; }
            if (editing) { return; }
            pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
            var nodeElHit = e.target.closest('.mm-node');
            var ids = Object.keys(pointers);
            if (ids.length === 2) {
                var a = pointers[ids[0]], b = pointers[ids[1]];
                pinch = { d: Math.hypot(a.x - b.x, a.y - b.y), k: view.k };
                panStart = null; drag = null;
                return;
            }
            if (nodeElHit && e.target.closest('.mm-fold')) { return; }
            if (nodeElHit && editable && nodeElHit.getAttribute('data-id') !== root.id) {
                drag = { id: nodeElHit.getAttribute('data-id'), x: e.clientX, y: e.clientY, on: false };
            } else if (!nodeElHit) {
                panStart = { x: e.clientX, y: e.clientY, vx: view.x, vy: view.y, moved: false };
                viewport.setPointerCapture(e.pointerId);
            }
        });
        viewport.addEventListener('pointermove', function (e) {
            if (pointers[e.pointerId]) { pointers[e.pointerId] = { x: e.clientX, y: e.clientY }; }
            var ids = Object.keys(pointers);
            if (pinch && ids.length === 2) {
                var a = pointers[ids[0]], b = pointers[ids[1]];
                var r = viewport.getBoundingClientRect();
                zoomAt(pinch.k * Math.hypot(a.x - b.x, a.y - b.y) / pinch.d, (a.x + b.x) / 2 - r.left, (a.y + b.y) / 2 - r.top);
                return;
            }
            if (panStart) {
                var dx = e.clientX - panStart.x, dy = e.clientY - panStart.y;
                if (Math.abs(dx) + Math.abs(dy) > 3) { panStart.moved = true; touched = true; viewport.classList.add('is-panning'); }
                view.x = panStart.vx + dx; view.y = panStart.vy + dy; apply();
            } else if (drag) {
                if (!drag.on && Math.abs(e.clientX - drag.x) + Math.abs(e.clientY - drag.y) > 7) {
                    drag.on = true;
                    drag.ghost = els[drag.id].cloneNode(true);
                    drag.ghost.classList.add('mm-ghost');
                    document.body.appendChild(drag.ghost);
                    els[drag.id].classList.add('is-dragging');
                }
                if (drag.on) {
                    drag.ghost.style.left = e.clientX + 'px';
                    drag.ghost.style.top = e.clientY + 'px';
                    drag.ghost.style.display = 'none';
                    var under = document.elementFromPoint(e.clientX, e.clientY);
                    drag.ghost.style.display = '';
                    var t = under && under.closest('.mm-node');
                    layer.querySelectorAll('.is-drop').forEach(function (x) { x.classList.remove('is-drop'); });
                    drag.target = null;
                    if (t) {
                        var tid = t.getAttribute('data-id');
                        if (tid !== drag.id && !isInside(tid, drag.id)) { t.classList.add('is-drop'); drag.target = tid; }
                    }
                }
            }
        });
        function endPointer(e) {
            delete pointers[e.pointerId];
            if (Object.keys(pointers).length < 2) { pinch = null; }
            if (panStart) {
                if (!panStart.moved && !e.target.closest('.mm-node')) { select(null); }
                panStart = null;
                viewport.classList.remove('is-panning');
            }
            if (drag) {
                if (drag.on) {
                    drag.ghost.remove();
                    els[drag.id] && els[drag.id].classList.remove('is-dragging');
                    layer.querySelectorAll('.is-drop').forEach(function (x) { x.classList.remove('is-drop'); });
                    if (drag.target) { move(drag.id, drag.target); }
                }
                drag = null;
            }
        }
        viewport.addEventListener('pointerup', endPointer);
        viewport.addEventListener('pointercancel', endPointer);

        function isInside(id, ancestorId) {
            var i = index[id];
            while (i && i.parent) {
                if (i.parent.id === ancestorId) { return true; }
                i = index[i.parent.id];
            }
            return false;
        }

        /* ------------------------------------------------ selection */
        function select(id, silent) {
            if (selected && els[selected]) { els[selected].classList.remove('is-selected'); }
            selected = id && index[id] ? id : null;
            if (selected && els[selected]) { els[selected].classList.add('is-selected'); reveal(selected); }
            if (!silent && opts.onSelect) { opts.onSelect(selected ? index[selected].node : null, selected ? index[selected] : null); }
        }
        layer.addEventListener('click', function (e) {
            var fold = e.target.closest('.mm-fold');
            var el = e.target.closest('.mm-node');
            if (!el) { return; }
            var id = el.getAttribute('data-id');
            if (fold) { toggle(id); return; }
            if (editing === id) { return; }
            if (!editable && id === selected && index[id].node.children.length && !index[id].node.note && !index[id].node.lesson) { toggle(id); }
            select(id);
        });
        layer.addEventListener('dblclick', function (e) {
            var el = e.target.closest('.mm-node');
            if (!el) { return; }
            if (editable) { edit(el.getAttribute('data-id')); } else { toggle(el.getAttribute('data-id')); }
        });

        /* ------------------------------------------------ editing */
        function edit(id, selectAll) {
            if (!editable || !index[id]) { return; }
            select(id);
            var el = els[id], t = el.querySelector('.mm-text');
            editing = id;
            el.classList.add('is-editing');
            t.contentEditable = 'true';
            t.focus();
            var range = document.createRange();
            range.selectNodeContents(t);
            if (selectAll === false) { range.collapse(false); }
            var s = window.getSelection(); s.removeAllRanges(); s.addRange(range);
        }
        function commit(cancel) {
            if (!editing) { return; }
            var id = editing, el = els[id], t = el.querySelector('.mm-text');
            var val = t.textContent.replace(/\s+/g, ' ').trim().slice(0, 300);
            t.contentEditable = 'false';
            el.classList.remove('is-editing');
            editing = null;
            var n = index[id].node;
            if (!cancel && val && val !== n.text) { snapshot(); n.text = val; paint(); changed(); } else { t.textContent = n.text; }
            viewport.focus({ preventScroll: true });
            if (opts.onSelect) { opts.onSelect(n, index[id]); }
        }
        layer.addEventListener('keydown', function (e) {
            if (!editing) { return; }
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); commit(); }
            else if (e.key === 'Escape') { e.preventDefault(); commit(true); }
            else if (e.key === 'Tab') { e.preventDefault(); commit(); addChild(); }
            e.stopPropagation();
        });
        layer.addEventListener('focusout', function (e) {
            if (editing && e.target.classList && e.target.classList.contains('mm-text')) { commit(); }
        });
        layer.addEventListener('paste', function (e) {
            if (!editing) { return; }
            e.preventDefault();
            document.execCommand('insertText', false, (e.clipboardData.getData('text/plain') || '').replace(/\s+/g, ' '));
        });

        function addChild(parentId) {
            var p = index[parentId || selected || root.id].node;
            snapshot();
            p.collapsed = false;
            var n = { id: uid(), text: p === root ? 'شاخه تازه' : 'زیرموضوع', children: [] };
            p.children.push(n);
            paint(); changed();
            edit(n.id);
            return n;
        }
        function addSibling() {
            if (!selected || selected === root.id) { return addChild(root.id); }
            var info = index[selected];
            snapshot();
            var n = { id: uid(), text: 'موضوع', children: [] };
            info.parent.children.splice(info.parent.children.indexOf(info.node) + 1, 0, n);
            paint(); changed();
            edit(n.id);
        }
        function remove(id) {
            id = id || selected;
            if (!id || id === root.id) { return; }
            var info = index[id];
            snapshot();
            var i = info.parent.children.indexOf(info.node);
            info.parent.children.splice(i, 1);
            var next = info.parent.children[Math.min(i, info.parent.children.length - 1)] || info.parent;
            paint(); changed();
            select(next.id);
        }
        function move(id, targetId) {
            var info = index[id], target = index[targetId].node;
            snapshot();
            info.parent.children.splice(info.parent.children.indexOf(info.node), 1);
            target.collapsed = false;
            target.children.push(info.node);
            paint(); changed(); select(id);
        }
        function shift(delta) {
            if (!selected || selected === root.id) { return; }
            var info = index[selected], sib = info.parent.children, i = sib.indexOf(info.node), j = i + delta;
            if (j < 0 || j >= sib.length) { return; }
            snapshot();
            sib.splice(i, 1); sib.splice(j, 0, info.node);
            paint(); changed(); select(selected, true);
        }
        function toggle(id, force) {
            var n = index[id] && index[id].node;
            if (!n || !n.children.length || n === root) { return; }
            n.collapsed = force === undefined ? !n.collapsed : force;
            paint();
            if (editable) { changed(); }
        }
        function setAll(collapsed) {
            each(root, function (n, p, d) { if (d >= 1 && n.children.length) { n.collapsed = collapsed; } });
            paint(); fit();
        }
        function undo() { if (!history.length) { return; } future.push(JSON.stringify(root)); root = JSON.parse(history.pop()); paint(); changed(); }
        function redo() { if (!future.length) { return; } history.push(JSON.stringify(root)); root = JSON.parse(future.pop()); paint(); changed(); }

        function navigate(key) {
            if (!selected) { select(root.id); return; }
            var info = index[selected], n = info.node;
            var dir = n._dir || 0;
            var kids = visibleChildren(n);
            var toParent = function () { if (info.parent) { select(info.parent.id); } };
            var toChild = function (want) {
                var pool = n === root ? kids.filter(function (c) { return want === undefined || c._dir === want; }) : kids;
                if (pool.length) { select(pool[Math.floor(pool.length / 2)].id); }
            };
            if (layout === 'down') {
                if (key === 'ArrowUp') { toParent(); } else if (key === 'ArrowDown') { toChild(); }
                else if (info.parent) {
                    var s = info.parent.children, i = s.indexOf(n);
                    var t = s[i + (key === 'ArrowLeft' ? 1 : -1)];
                    if (t) { select(t.id); }
                }
                return;
            }
            if (key === 'ArrowUp' || key === 'ArrowDown') {
                if (!info.parent) { return; }
                var sib = visibleChildren(info.parent).filter(function (c) { return c._dir === dir; });
                var j = sib.indexOf(n) + (key === 'ArrowDown' ? 1 : -1);
                if (sib[j]) { select(sib[j].id); }
                return;
            }
            var outward = key === 'ArrowRight' ? 1 : -1;
            if (n === root) { toChild(outward); return; }
            if (dir === outward) { toChild(); } else { toParent(); }
        }

        viewport.addEventListener('keydown', function (e) {
            if (editing) { return; }
            var k = e.key, mod = e.ctrlKey || e.metaKey;
            if (k.indexOf('Arrow') === 0 && !(e.altKey && editable)) { e.preventDefault(); navigate(k); return; }
            if (k === ' ' && selected) { e.preventDefault(); toggle(selected); return; }
            if (k === '0' && mod) { e.preventDefault(); fit(); return; }
            if ((k === '=' || k === '+') && mod) { e.preventDefault(); zoomAt(view.k * 1.2); return; }
            if (k === '-' && mod) { e.preventDefault(); zoomAt(view.k / 1.2); return; }
            if (!editable) { return; }
            if (mod && (k === 'z' || k === 'Z')) { e.preventDefault(); if (e.shiftKey) { redo(); } else { undo(); } return; }
            if (mod && (k === 'y' || k === 'Y')) { e.preventDefault(); redo(); return; }
            if (e.altKey && (k === 'ArrowUp' || k === 'ArrowDown')) { e.preventDefault(); shift(k === 'ArrowUp' ? -1 : 1); return; }
            if (k === 'Tab') { e.preventDefault(); addChild(); return; }
            if (k === 'Enter') { e.preventDefault(); addSibling(); return; }
            if (k === 'F2') { e.preventDefault(); if (selected) { edit(selected, false); } return; }
            if ((k === 'Delete' || k === 'Backspace') && selected) { e.preventDefault(); remove(); return; }
            if (k.length === 1 && !mod && !e.altKey && selected) {
                edit(selected);
                // the typed character replaces the text, as in XMind
            }
        });

        /* ------------------------------------------------ search */
        function search(q) {
            q = (q || '').trim().toLowerCase();
            layer.querySelectorAll('.is-hit').forEach(function (x) { x.classList.remove('is-hit'); });
            if (!q) { host.classList.remove('is-searching'); return 0; }
            var hits = [];
            each(root, function (n) {
                if ((n.text + ' ' + (n.note || '')).toLowerCase().indexOf(q) !== -1) { hits.push(n.id); }
            });
            hits.forEach(function (id) {
                var i = index[id];
                while (i && i.parent) { i.parent.collapsed = false; i = index[i.parent.id]; }
            });
            paint();
            hits.forEach(function (id) { if (els[id]) { els[id].classList.add('is-hit'); } });
            host.classList.toggle('is-searching', hits.length > 0);
            if (hits[0]) { select(hits[0], true); }
            return hits.length;
        }

        var ro = window.ResizeObserver ? new ResizeObserver(function () { if (!touched) { fit(); } }) : null;
        if (ro) { ro.observe(viewport); }

        var api = {
            getData: function () {
                var copy = clone(root);
                each(copy, function (n) { Object.keys(n).forEach(function (k) { if (k.charAt(0) === '_') { delete n[k]; } }); });
                return copy;
            },
            setData: function (d) { snapshot(); root = clone(d); Object.keys(els).forEach(function (id) { els[id].remove(); }); els = {}; firstPaint = true; paint(); changed(); },
            setTheme: function (t) { theme = t; host.setAttribute('data-theme', t); paint(); },
            setLayout: function (l) { layout = l; paint(); fit(); },
            update: function (id, patch) {
                var n = index[id] && index[id].node;
                if (!n) { return; }
                snapshot();
                Object.keys(patch).forEach(function (k) { if (patch[k] === null || patch[k] === '') { delete n[k]; } else { n[k] = patch[k]; } });
                paint(); changed();
            },
            node: function (id) { return index[id] ? index[id].node : null; },
            selected: function () { return selected; },
            select: select, edit: edit, addChild: addChild, addSibling: addSibling, remove: remove, toggle: toggle,
            expandAll: function () { setAll(false); }, collapseAll: function () { setAll(true); },
            undo: undo, redo: redo, fit: function () { touched = false; fit(); }, search: search,
            zoomIn: function () { zoomAt(view.k * 1.2); }, zoomOut: function () { zoomAt(view.k / 1.2); },
            count: function () { return countAll(root) + 1; },
            focus: function () { viewport.focus({ preventScroll: true }); },
            repaint: paint,
            lessons: lessons
        };
        host.setAttribute('data-theme', theme);
        paint();
        apply();
        return api;
    }

    window.HxMindmap = { mount: mount, TONES: TONES };
})();
