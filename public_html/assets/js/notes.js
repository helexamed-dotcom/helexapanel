/* HeleXa Notes — the note editor.
 *
 * One component used in two places: the side sheet of the lesson viewer and
 * the full note page. A note has three parts, all saved automatically:
 *   ✍️ a handwriting page (pen, marker, eraser — pen pressure aware),
 *   ⌨️ typed text,
 *   📎 PDF and image files, which can be written on page by page.
 *
 * Requires ink.js. PDF pages are drawn with pdf.js when it is installed under
 * /assets/vendor/pdfjs/; without it a PDF still opens in the browser's viewer.
 */
(function (root) {
    'use strict';

    var Ink = root.HlxInk;
    var NOTE_COLORS = ['yellow', 'green', 'blue', 'pink', 'purple', 'gray'];
    var NOTE_COLOR_NAMES = { yellow: 'زرد', green: 'سبز', blue: 'آبی', pink: 'صورتی', purple: 'بنفش', gray: 'خاکستری' };
    var INK_COLOR_NAMES = {
        '#111827': 'مشکی', '#2563eb': 'آبی', '#dc2626': 'قرمز', '#16a34a': 'سبز',
        '#ea580c': 'نارنجی', '#7c3aed': 'بنفش', '#facc15': 'زرد', '#ec4899': 'صورتی'
    };
    var PAGE_RATIO = 1.414;
    var PDFJS_BASE = '/assets/vendor/pdfjs/';

    var ICONS = {
        pencil: '<path d="M15.6 4.6a2.2 2.2 0 0 1 3.1 0l.7.7a2.2 2.2 0 0 1 0 3.1L8.6 19.2 3.8 20.2l1-4.8z"/><path d="m13.8 6.4 3.8 3.8"/>',
        marker: '<path d="m9.2 15.8-3-3 8.9-8.9a2.1 2.1 0 0 1 3 3z"/><path d="m6.2 12.8-2.4 5.4 2 2 5.4-2.4"/><path d="M13.6 20.4h6.6"/>',
        eraser: '<path d="M9.9 20.4 4.6 15a2.1 2.1 0 0 1 0-3l7.6-7.6a2.1 2.1 0 0 1 3 0l4.4 4.4a2.1 2.1 0 0 1 0 3l-8.6 8.6z"/><path d="m8.4 8.8 6.4 6.4"/><path d="M9.9 20.4h10.1"/>',
        undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
        redo: '<path d="m15 14 5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/>',
        trash: '<path d="M4.6 7h14.8"/><path d="M9.8 7V5.6A1.6 1.6 0 0 1 11.4 4h1.2a1.6 1.6 0 0 1 1.6 1.6V7"/><path d="M6.8 7v11.8a2.2 2.2 0 0 0 2.2 2.2h6a2.2 2.2 0 0 0 2.2-2.2V7"/>',
        hand: '<path d="M8.6 12.6V5.8a1.5 1.5 0 0 1 3 0v5.4"/><path d="M11.6 10.6V4.6a1.5 1.5 0 0 1 3 0v6"/><path d="M14.6 10.8V6.2a1.5 1.5 0 0 1 3 0v7.6a7 7 0 0 1-7 7h-.4a6 6 0 0 1-4.6-2.2l-2.5-3.1a1.6 1.6 0 0 1 2.4-2l1.9 1.9"/>',
        close: '<path d="m6.6 6.6 10.8 10.8M17.4 6.6 6.6 17.4"/>',
        expand: '<path d="M14.6 3.8h5.6v5.6M9.4 20.2H3.8v-5.6M20.2 3.8l-6.4 6.4M3.8 20.2l6.4-6.4"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        attach: '<path d="m20 11.4-7.9 7.9a5 5 0 0 1-7.1-7.1l8.3-8.3a3.3 3.3 0 0 1 4.7 4.7l-8.3 8.3a1.7 1.7 0 0 1-2.4-2.4l7.6-7.6"/>',
        file: '<path d="M6 3.5h8l5.5 5.5v10a1.5 1.5 0 0 1-1.5 1.5H6A1.5 1.5 0 0 1 4.5 19V5A1.5 1.5 0 0 1 6 3.5z"/><path d="M13.5 3.5V9H19"/>',
        open: '<path d="M14 4h6v6"/><path d="m20 4-9 9"/><path d="M18 14v4.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10"/>',
        back: '<path d="m9 6 6 6-6 6"/>',
        book: '<path d="M4.4 18.8V5.8A2.8 2.8 0 0 1 7.2 3H19a1 1 0 0 1 1 1v15.6a1 1 0 0 1-1 1H7.2a2.8 2.8 0 0 1 0-5.6H20"/>'
    };

    function svg(name) {
        return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" ' +
            'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';
    }

    function h(tag, props, kids) {
        var node = document.createElement(tag);
        Object.keys(props || {}).forEach(function (key) {
            var v = props[key];
            if (v === null || v === undefined || v === false) { return; }
            if (key === 'text') { node.textContent = v; }
            else if (key === 'html') { node.innerHTML = v; }      // static icon markup only
            else if (key === 'class') { node.className = v; }
            else if (key.indexOf('on') === 0) { node.addEventListener(key.slice(2), v); }
            else { node.setAttribute(key, v === true ? '' : v); }
        });
        (kids || []).forEach(function (kid) {
            if (kid) { node.appendChild(typeof kid === 'string' ? document.createTextNode(kid) : kid); }
        });
        return node;
    }

    function fa(value) {
        return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
    }

    function sizeLabel(bytes) {
        if (bytes < 1024 * 1024) { return fa(Math.max(1, Math.round(bytes / 1024))) + ' کیلوبایت'; }
        return fa((bytes / 1048576).toFixed(1)) + ' مگابایت';
    }

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function api(method, url, body, keepalive) {
        var init = {
            method: method,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        };
        if (body !== undefined) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(body);
        }
        if (keepalive) { init.keepalive = true; }
        return fetch(url, init).then(function (r) {
            return r.json().catch(function () { return { ok: false }; }).then(function (data) {
                if (!r.ok || !data || data.ok === false) {
                    var err = new Error((data && data.message) || 'REQUEST_FAILED');
                    err.status = r.status;
                    throw err;
                }
                return data;
            });
        });
    }

    function store(key, value) {
        try {
            if (value === undefined) { return JSON.parse(localStorage.getItem(key) || 'null'); }
            localStorage.setItem(key, JSON.stringify(value));
        } catch (e) { /* private mode */ }
        return null;
    }

    function coarse() {
        return !!(root.matchMedia && root.matchMedia('(pointer: coarse)').matches);
    }

    /* ================================================================ PenBar
       The pen toolbar: tool, colour, width, "draw with finger", undo, redo,
       clear. The choice is remembered on this device. */
    function PenBar(opts) {
        var self = this;
        this.o = opts;
        var saved = store('helexa_note_pen') || {};
        this.state = {
            mode: saved.mode === 'marker' || saved.mode === 'eraser' ? saved.mode : 'pen',
            color: Ink && Ink.COLORS.indexOf(saved.color) !== -1 ? saved.color : '#111827',
            size: +saved.size > 0 ? +saved.size : 3,
            finger: typeof saved.finger === 'boolean' ? saved.finger : true
        };

        function modeButton(mode, icon, label) {
            return h('button', {
                type: 'button', class: 'pen-btn', 'data-mode': mode, title: label, 'aria-label': label,
                html: svg(icon), onclick: function () { self.set({ mode: mode }); }
            });
        }

        this.modes = h('div', { class: 'pen-group', role: 'group', 'aria-label': 'ابزار' }, [
            modeButton('pen', 'pencil', 'قلم'),
            modeButton('marker', 'marker', 'ماژیک'),
            modeButton('eraser', 'eraser', 'پاک‌کن')
        ]);

        this.colors = h('div', { class: 'pen-group pen-colors', role: 'group', 'aria-label': 'رنگ' },
            (Ink ? Ink.COLORS : []).map(function (c) {
                return h('button', {
                    type: 'button', class: 'pen-color', 'data-color': c, style: '--c:' + c,
                    title: INK_COLOR_NAMES[c] || c, 'aria-label': INK_COLOR_NAMES[c] || c,
                    onclick: function () { self.set({ color: c, mode: self.state.mode === 'eraser' ? 'pen' : self.state.mode }); }
                });
            }));

        this.sizes = h('div', { class: 'pen-group', role: 'group', 'aria-label': 'ضخامت' },
            (Ink ? Ink.SIZES : []).map(function (s, i) {
                var label = ['نازک', 'متوسط', 'ضخیم'][i];
                return h('button', {
                    type: 'button', class: 'pen-size', 'data-size': String(s), title: label, 'aria-label': label,
                    html: '<i style="width:' + (4 + i * 4) + 'px;height:' + (4 + i * 4) + 'px"></i>',
                    onclick: function () { self.set({ size: s }); }
                });
            }));

        this.finger = h('button', {
            type: 'button', class: 'pen-btn pen-finger', title: 'نوشتن با انگشت', 'aria-label': 'نوشتن با انگشت',
            html: svg('hand'), onclick: function () { self.set({ finger: !self.state.finger }, true); }
        });

        this.undoBtn = h('button', { type: 'button', class: 'pen-btn', title: 'واگرد', 'aria-label': 'واگرد', html: svg('undo'), disabled: true,
            onclick: function () { if (opts.onUndo) { opts.onUndo(); } } });
        this.redoBtn = h('button', { type: 'button', class: 'pen-btn', title: 'از نو', 'aria-label': 'از نو', html: svg('redo'), disabled: true,
            onclick: function () { if (opts.onRedo) { opts.onRedo(); } } });
        var clearBtn = h('button', { type: 'button', class: 'pen-btn', title: 'پاک کردن همه', 'aria-label': 'پاک کردن همه', html: svg('trash'),
            onclick: function () {
                if (opts.onClear && root.confirm('همه نوشته‌های این صفحه پاک شود؟')) { opts.onClear(); }
            } });

        this.el = h('div', { class: 'pen-bar', role: 'toolbar', 'aria-label': 'قلم' }, [
            this.modes, h('span', { class: 'pen-sep' }), this.colors, h('span', { class: 'pen-sep' }), this.sizes,
            h('span', { class: 'pen-sep' }), this.finger, this.undoBtn, this.redoBtn, clearBtn
        ].concat(opts.extra || []));

        this.paint();
    }

    PenBar.prototype.set = function (patch, byUser) {
        Object.keys(patch).forEach(function (k) { this.state[k] = patch[k]; }, this);
        if (byUser && typeof patch.finger === 'boolean') { this.state.fingerChosen = true; }
        store('helexa_note_pen', this.state);
        this.paint();
        if (this.o.onChange) { this.o.onChange(this.state); }
    };

    /** A pen was detected: fingers scroll from now on, unless chosen otherwise. */
    PenBar.prototype.penDetected = function () {
        if (this.state.fingerChosen || !this.state.finger) { return; }
        this.set({ finger: false });
    };

    PenBar.prototype.paint = function () {
        var st = this.state;
        Array.prototype.forEach.call(this.modes.children, function (b) {
            b.classList.toggle('is-on', b.getAttribute('data-mode') === st.mode);
            b.setAttribute('aria-pressed', b.getAttribute('data-mode') === st.mode ? 'true' : 'false');
        });
        Array.prototype.forEach.call(this.colors.children, function (b) {
            b.classList.toggle('is-on', b.getAttribute('data-color') === st.color);
        });
        Array.prototype.forEach.call(this.sizes.children, function (b) {
            b.classList.toggle('is-on', +b.getAttribute('data-size') === +st.size);
        });
        this.finger.classList.toggle('is-on', !!st.finger);
        this.finger.setAttribute('aria-pressed', st.finger ? 'true' : 'false');
    };

    PenBar.prototype.history = function (canUndo, canRedo) {
        this.undoBtn.disabled = !canUndo;
        this.redoBtn.disabled = !canRedo;
    };

    PenBar.prototype.options = function () {
        return { mode: this.state.mode, color: this.state.color, size: this.state.size, finger: !!this.state.finger };
    };

    /* ======================================================= visible clip */
    function visibleRect(el, scroller) {
        var r = el.getBoundingClientRect();
        var top = Math.max(r.top, 0), bottom = Math.min(r.bottom, root.innerHeight);
        var left = Math.max(r.left, 0), right = Math.min(r.right, root.innerWidth);
        if (scroller && scroller !== document.scrollingElement) {
            var s = scroller.getBoundingClientRect();
            top = Math.max(top, s.top); bottom = Math.min(bottom, s.bottom);
            left = Math.max(left, s.left); right = Math.min(right, s.right);
        }
        return { top: top, bottom: bottom, left: left, right: right };
    }

    function touchMode(el, finger) {
        el.style.touchAction = finger ? 'none' : 'pan-x pan-y pinch-zoom';
    }

    /* ================================================================ Editor */

    function Editor(host, o) {
        this.host = host;
        this.o = o || {};
        this.uuid = this.o.uuid;
        this.base = '/student/notes/' + encodeURIComponent(this.uuid);
        this.dirty = {};
        this.saveTimer = null;
        this.saving = false;
        this.boards = [];
        this.observers = [];
        this.doc = null;
        this.destroyed = false;
        host.innerHTML = '';
        host.classList.add('note-editor');
        host.appendChild(h('div', { class: 'note-loading', text: 'در حال باز کردن یادداشت…' }));
        this.load();
    }

    Editor.prototype.load = function () {
        var self = this;
        api('GET', this.base + '/data').then(function (data) {
            if (!self.destroyed) { self.render(data.note); }
        }).catch(function () {
            self.host.innerHTML = '';
            self.host.appendChild(h('div', { class: 'note-loading is-error', text: 'یادداشت باز نشد. اتصال اینترنت را بررسی کنید.' }));
        });
    };

    Editor.prototype.render = function (note) {
        var self = this, o = this.o;
        this.note = note;
        this.host.innerHTML = '';

        /* -------- header */
        this.status = h('span', { class: 'note-status', role: 'status', 'aria-live': 'polite' });
        this.title = h('input', {
            class: 'note-title', type: 'text', maxlength: '191', placeholder: 'عنوان یادداشت',
            value: note.title || '', 'aria-label': 'عنوان یادداشت',
            oninput: function () { self.mark('title', self.title.value); self.meta(); }
        });

        var dots = h('div', { class: 'note-colors', role: 'radiogroup', 'aria-label': 'رنگ یادداشت' },
            NOTE_COLORS.map(function (c) {
                return h('button', {
                    type: 'button', class: 'note-dot' + (c === note.color ? ' is-on' : ''), 'data-color': c,
                    role: 'radio', 'aria-checked': c === note.color ? 'true' : 'false',
                    title: NOTE_COLOR_NAMES[c], 'aria-label': NOTE_COLOR_NAMES[c],
                    onclick: function () {
                        note.color = c;
                        Array.prototype.forEach.call(dots.children, function (d) {
                            var on = d.getAttribute('data-color') === c;
                            d.classList.toggle('is-on', on);
                            d.setAttribute('aria-checked', on ? 'true' : 'false');
                        });
                        self.host.setAttribute('data-color', c);
                        self.mark('color', c);
                        self.meta();
                    }
                });
            }));
        this.host.setAttribute('data-color', note.color);

        var actions = h('div', { class: 'note-actions' });
        if (o.compact) {
            actions.appendChild(h('a', { class: 'note-icon-btn', href: this.base, target: '_blank', rel: 'noopener',
                title: 'باز کردن در صفحه کامل', 'aria-label': 'باز کردن در صفحه کامل', html: svg('expand') }));
        } else if (note.content_uuid) {
            actions.appendChild(h('a', { class: 'btn btn-ghost btn-sm', href: '/content/' + encodeURIComponent(note.content_uuid) },
                [h('span', { html: svg('book') }), ' ' + (note.content_title || 'جزوه')]));
        }
        actions.appendChild(h('button', { type: 'button', class: 'note-icon-btn is-danger', title: 'حذف یادداشت',
            'aria-label': 'حذف یادداشت', html: svg('trash'), onclick: function () { self.remove(); } }));
        if (o.onClose) {
            actions.appendChild(h('button', { type: 'button', class: 'note-icon-btn', title: 'بستن', 'aria-label': 'بستن',
                html: svg('close'), onclick: function () { o.onClose(); } }));
        }

        this.host.appendChild(h('div', { class: 'note-head' }, [
            h('div', { class: 'note-head-row' }, [this.title, actions]),
            h('div', { class: 'note-head-row' }, [dots, this.status])
        ]));

        /* -------- tabs */
        var tabs = [
            { key: 'ink', label: '✍️ دست‌نویس' },
            { key: 'text', label: '⌨️ تایپ' },
            { key: 'files', label: '📎 فایل‌ها' }
        ];
        this.tabButtons = {};
        this.panels = {};
        var bar = h('div', { class: 'note-tabs', role: 'tablist' });
        tabs.forEach(function (tab) {
            var b = h('button', {
                type: 'button', class: 'note-tab', role: 'tab', id: 'note-tab-' + tab.key,
                'aria-controls': 'note-panel-' + tab.key, text: tab.label,
                onclick: function () { self.show(tab.key); }
            });
            self.tabButtons[tab.key] = b;
            bar.appendChild(b);
        });
        this.host.appendChild(bar);

        this.panels.ink = this.buildInk(note);
        this.panels.text = this.buildText(note);
        this.panels.files = this.buildFiles(note);
        Object.keys(this.panels).forEach(function (k) {
            self.panels[k].id = 'note-panel-' + k;
            self.panels[k].setAttribute('role', 'tabpanel');
            self.panels[k].setAttribute('aria-labelledby', 'note-tab-' + k);
            self.host.appendChild(self.panels[k]);
        });

        var first = store('helexa_note_tab');
        if (!first) { first = note.body && !(note.ink && note.ink.length) ? 'text' : 'ink'; }
        this.show(this.panels[first] ? first : 'ink');
        this.setStatus(note.updated_at ? 'ذخیره‌شده' : '');
        this.paintFileCount();

        this.onUnload = function () { self.flush(true); };
        root.addEventListener('pagehide', this.onUnload);
        if (o.focusTitle && !note.title) { this.title.focus(); }
    };

    Editor.prototype.show = function (key) {
        var self = this;
        Object.keys(this.panels).forEach(function (k) {
            var on = k === key;
            self.panels[k].hidden = !on;
            self.tabButtons[k].classList.toggle('is-on', on);
            self.tabButtons[k].setAttribute('aria-selected', on ? 'true' : 'false');
        });
        store('helexa_note_tab', key);
        if (key === 'ink' && this.pad) { this.sizePad(); }
        if (key === 'text' && this.textarea) { this.growText(); }
    };

    Editor.prototype.meta = function () {
        if (this.o.onMeta) { this.o.onMeta({ uuid: this.uuid, title: this.title.value, color: this.note.color }); }
    };

    /* -------------------------------------------------------- handwriting */

    Editor.prototype.buildInk = function (note) {
        var self = this;
        var panel = h('div', { class: 'note-panel note-ink' });
        if (!Ink) {
            panel.appendChild(h('p', { class: 'muted', text: 'ابزار قلم بارگذاری نشد.' }));
            return panel;
        }

        this.ratio = Math.max(0.5, +note.ink_ratio || PAGE_RATIO);
        var paper = h('div', { class: 'note-paper' });
        this.pad = paper;

        var penBar = new PenBar({
            onChange: function () { self.applyPen(); },
            onUndo: function () { board.undo(); },
            onRedo: function () { board.redo(); },
            onClear: function () { board.clear(); }
        });
        this.penBar = penBar;

        var board = new Ink.Board({
            input: paper,
            host: paper,
            refWidth: function () { return paper.clientWidth || 1; },
            active: function () { return true; },
            clip: function () { return visibleRect(paper, self.o.scroller); },
            onChange: function (b) { self.mark('ink', b.data()); },
            onHistory: function (b) { penBar.history(b.canUndo(), b.canRedo()); },
            onPen: function () { penBar.penDetected(); }
        });
        this.board = board;
        this.boards.push({ board: board, bar: penBar, el: paper });
        board.load(note.ink || []);

        var more = h('button', {
            type: 'button', class: 'btn btn-ghost btn-sm note-more', html: svg('plus') + '<span>افزودن صفحه</span>',
            onclick: function () {
                self.ratio = Math.round((self.ratio + PAGE_RATIO) * 1000) / 1000;
                self.mark('ink_ratio', self.ratio);
                self.sizePad();
            }
        });

        panel.appendChild(penBar.el);
        panel.appendChild(paper);
        panel.appendChild(h('div', { class: 'note-ink-foot' }, [
            more,
            h('span', { class: 'muted', text: coarse() ? 'با قلم بنویسید؛ با انگشت صفحه را جابه‌جا کنید (دکمه ✋ را روشن کنید تا انگشت هم بنویسد).' : 'با ماوس یا قلم نوری بنویسید.' })
        ]));

        if (root.ResizeObserver) {
            var ro = new ResizeObserver(function () { self.sizePad(); });
            ro.observe(paper);
            this.observers.push(ro);
        }
        this.applyPen();
        return panel;
    };

    Editor.prototype.sizePad = function () {
        if (!this.pad) { return; }
        var w = this.pad.clientWidth;
        if (!w) { return; }
        var hgt = Math.round(w * this.ratio);
        if (Math.abs((parseFloat(this.pad.style.height) || 0) - hgt) > 1) { this.pad.style.height = hgt + 'px'; }
        this.pad.style.setProperty('--line', Math.max(22, Math.round(w / 22)) + 'px');
        if (this.board) { this.board.refresh(); }
    };

    Editor.prototype.applyPen = function () {
        var opts = this.penBar ? this.penBar.options() : null;
        if (!opts) { return; }
        this.boards.forEach(function (entry) {
            entry.board.setOptions(opts);
            touchMode(entry.el, opts.finger);
        });
        if (this.doc) { this.doc.applyPen(opts); }
    };

    /* -------------------------------------------------------------- typing */

    Editor.prototype.buildText = function (note) {
        var self = this;
        var panel = h('div', { class: 'note-panel note-typed' });
        this.textarea = h('textarea', {
            class: 'note-text', dir: 'auto', rows: '10', placeholder: 'اینجا بنویسید…', 'aria-label': 'متن یادداشت',
            oninput: function () { self.mark('body', self.textarea.value); self.growText(); }
        });
        this.textarea.value = note.body || '';
        panel.appendChild(this.textarea);
        return panel;
    };

    Editor.prototype.growText = function () {
        var t = this.textarea;
        t.style.height = 'auto';
        t.style.height = Math.max(220, t.scrollHeight + 4) + 'px';
    };

    /* --------------------------------------------------------------- files */

    Editor.prototype.buildFiles = function (note) {
        var self = this;
        var panel = h('div', { class: 'note-panel note-files' });
        var input = h('input', {
            type: 'file', accept: 'application/pdf,image/jpeg,image/png,image/webp', multiple: true, hidden: true,
            onchange: function () { self.upload(Array.prototype.slice.call(input.files || [])); input.value = ''; }
        });
        this.progress = h('div', { class: 'note-progress', hidden: true }, [h('i')]);
        var zone = h('div', { class: 'note-drop', tabindex: '0', role: 'button', 'aria-label': 'افزودن PDF یا تصویر',
            onclick: function () { input.click(); },
            onkeydown: function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } },
            ondragover: function (e) { e.preventDefault(); zone.classList.add('is-over'); },
            ondragleave: function () { zone.classList.remove('is-over'); },
            ondrop: function (e) {
                e.preventDefault();
                zone.classList.remove('is-over');
                self.upload(Array.prototype.slice.call((e.dataTransfer && e.dataTransfer.files) || []));
            }
        }, [
            h('span', { class: 'note-drop-ic', html: svg('attach') }),
            h('strong', { text: 'افزودن PDF یا تصویر' }),
            h('small', { class: 'muted', text: 'بکشید و اینجا رها کنید یا بزنید تا انتخاب کنید' })
        ]);
        this.fileList = h('div', { class: 'note-file-list' });
        this.docHost = h('div', { class: 'note-doc', hidden: true });

        panel.appendChild(input);
        panel.appendChild(zone);
        panel.appendChild(this.progress);
        panel.appendChild(this.fileList);
        panel.appendChild(this.docHost);
        this.paintFiles(note.files || []);
        return panel;
    };

    Editor.prototype.paintFileCount = function () {
        var n = (this.note.files || []).length;
        this.tabButtons.files.textContent = '📎 فایل‌ها' + (n ? ' (' + fa(n) + ')' : '');
    };

    Editor.prototype.paintFiles = function (files) {
        var self = this;
        this.note.files = files;
        this.fileList.innerHTML = '';
        if (!files.length) {
            this.fileList.appendChild(h('p', { class: 'muted note-empty', text: 'هنوز فایلی اضافه نشده است.' }));
        }
        files.forEach(function (f) {
            var isPdf = f.mime === 'application/pdf';
            var canWrite = !isPdf || self.o.pdfjs;
            self.fileList.appendChild(h('div', { class: 'note-file' }, [
                h('span', { class: 'note-file-ic' + (isPdf ? ' is-pdf' : ''), html: svg('file') }),
                h('div', { class: 'note-file-main' }, [
                    h('strong', { dir: 'auto', text: f.name }),
                    h('small', { class: 'muted', text: sizeLabel(f.size) + (f.has_ink ? ' · ✍️ دارای نوشته' : '') })
                ]),
                canWrite ? h('button', { type: 'button', class: 'btn btn-primary btn-sm', html: svg('pencil') + '<span>نوشتن روی فایل</span>',
                    onclick: function () { self.openDoc(f); } }) : null,
                h('a', { class: 'note-icon-btn', href: f.url, target: '_blank', rel: 'noopener', title: 'باز کردن', 'aria-label': 'باز کردن', html: svg('open') }),
                h('button', { type: 'button', class: 'note-icon-btn is-danger', title: 'حذف فایل', 'aria-label': 'حذف فایل', html: svg('trash'),
                    onclick: function () { self.removeFile(f); } })
            ]));
        });
        if (this.tabButtons) { this.paintFileCount(); }
    };

    Editor.prototype.upload = function (files) {
        var self = this;
        files = files.filter(function (f) { return /^(application\/pdf|image\/(jpeg|png|webp))$/.test(f.type) || /\.pdf$/i.test(f.name); });
        if (!files.length) { this.setStatus('فقط PDF یا تصویر پذیرفته می‌شود.', true); return; }

        var bar = this.progress, fill = bar.firstChild;
        function next() {
            var file = files.shift();
            if (!file) { bar.hidden = true; return; }
            bar.hidden = false;
            fill.style.width = '0%';
            var form = new FormData();
            form.append('file', file);
            form.append('_token', csrf());
            var xhr = new XMLHttpRequest();
            xhr.open('POST', self.base + '/files');
            xhr.setRequestHeader('X-CSRF-Token', csrf());
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.onprogress = function (e) {
                if (e.lengthComputable) { fill.style.width = Math.round(e.loaded / e.total * 100) + '%'; }
            };
            xhr.onload = function () {
                var data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
                if (data && data.ok) {
                    self.paintFiles(data.files || []);
                    self.setStatus('«' + file.name + '» اضافه شد.');
                } else {
                    self.setStatus((data && (data.message || (data.error === 'POST_TOO_LARGE' ? 'حجم فایل زیاد است.' : ''))) || 'آپلود ناموفق بود.', true);
                }
                next();
            };
            xhr.onerror = function () { self.setStatus('آپلود ناموفق بود؛ اتصال را بررسی کنید.', true); next(); };
            xhr.send(form);
        }
        next();
    };

    Editor.prototype.removeFile = function (f) {
        var self = this;
        if (!root.confirm('فایل «' + f.name + '» و نوشته‌های روی آن حذف شود؟')) { return; }
        api('POST', this.base + '/files/' + encodeURIComponent(f.uuid) + '/delete', {}).then(function (data) {
            if (self.doc && self.doc.file.uuid === f.uuid) { self.closeDoc(); }
            self.paintFiles(data.files || []);
        }).catch(function () { self.setStatus('حذف فایل ناموفق بود.', true); });
    };

    Editor.prototype.openDoc = function (f) {
        var self = this;
        if (!Ink) { root.open(f.url, '_blank', 'noopener'); return; }
        this.closeDoc();
        this.fileList.hidden = true;
        this.docHost.hidden = false;
        this.doc = new DocView(this.docHost, f, {
            base: this.base,
            pdfjs: this.o.pdfjs,
            scroller: this.o.scroller,
            pen: this.penBar ? this.penBar.state : null,
            onBack: function () { self.closeDoc(); },
            onStatus: function (text, bad) { self.setStatus(text, bad); },
            onSaved: function () { f.has_ink = true; }
        });
    };

    Editor.prototype.closeDoc = function () {
        if (!this.doc) { return; }
        this.doc.destroy();
        this.doc = null;
        this.docHost.hidden = true;
        this.docHost.innerHTML = '';
        this.fileList.hidden = false;
        this.paintFiles(this.note.files || []);
    };

    /* -------------------------------------------------------------- saving */

    Editor.prototype.mark = function (field, value) {
        this.dirty[field] = value;
        this.setStatus('ذخیره نشده…');
        if (this.saveTimer) { clearTimeout(this.saveTimer); }
        var self = this;
        this.saveTimer = setTimeout(function () { self.save(); }, field === 'ink' ? 900 : 1200);
    };

    Editor.prototype.save = function (keepalive) {
        var self = this;
        if (this.saveTimer) { clearTimeout(this.saveTimer); this.saveTimer = null; }
        if (this.saving && !keepalive) { this.saveTimer = setTimeout(function () { self.save(); }, 600); return Promise.resolve(); }
        var fields = this.dirty;
        if (!Object.keys(fields).length) { return Promise.resolve(); }
        this.dirty = {};
        this.saving = true;
        this.setStatus('در حال ذخیره…');
        return api('POST', this.base, fields, keepalive).then(function () {
            self.saving = false;
            self.setStatus(Object.keys(self.dirty).length ? 'ذخیره نشده…' : 'ذخیره شد ✓');
        }).catch(function (err) {
            self.saving = false;
            // Put the unsaved fields back, unless newer edits replaced them.
            Object.keys(fields).forEach(function (k) {
                if (!(k in self.dirty)) { self.dirty[k] = fields[k]; }
            });
            if (err && err.status === 413) {
                self.setStatus(err.message, true);
                return;
            }
            self.setStatus('ذخیره نشد؛ دوباره تلاش می‌شود…', true);
            if (!self.destroyed) { self.saveTimer = setTimeout(function () { self.save(); }, 4000); }
        });
    };

    Editor.prototype.flush = function (keepalive) {
        var p = this.save(!!keepalive);
        if (this.doc) { this.doc.flush(!!keepalive); }
        return p;
    };

    Editor.prototype.setStatus = function (text, bad) {
        if (!this.status) { return; }
        this.status.textContent = text || '';
        this.status.classList.toggle('is-bad', !!bad);
    };

    Editor.prototype.remove = function () {
        var self = this;
        if (!root.confirm('این یادداشت و همه فایل‌ها و نوشته‌هایش حذف شود؟')) { return; }
        this.dirty = {};
        api('POST', this.base + '/delete', {}).then(function () {
            if (self.o.onDeleted) { self.o.onDeleted(self.uuid); }
        }).catch(function () { self.setStatus('حذف ناموفق بود.', true); });
    };

    Editor.prototype.destroy = function () {
        this.flush(true);
        this.destroyed = true;
        if (this.saveTimer) { clearTimeout(this.saveTimer); }
        if (this.onUnload) { root.removeEventListener('pagehide', this.onUnload); }
        this.observers.forEach(function (ro) { ro.disconnect(); });
        this.boards.forEach(function (entry) { entry.board.destroy(); });
        this.boards = [];
        if (this.doc) { this.doc.destroy(); this.doc = null; }
        this.host.innerHTML = '';
    };

    /* ============================================================== DocView
       A PDF (via pdf.js) or an image, one page per block, with a handwriting
       layer on every page. Pages are rendered when they come near the screen
       and released when they are far away, so a long PDF stays light. */

    var pdfjsPromise = null;

    function loadPdfJs() {
        if (root.pdfjsLib) { return Promise.resolve(root.pdfjsLib); }
        if (pdfjsPromise) { return pdfjsPromise; }
        pdfjsPromise = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = PDFJS_BASE + 'pdf.min.js';
            s.onload = function () {
                if (!root.pdfjsLib) { reject(new Error('PDFJS_MISSING')); return; }
                root.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_BASE + 'pdf.worker.min.js';
                resolve(root.pdfjsLib);
            };
            s.onerror = function () { pdfjsPromise = null; reject(new Error('PDFJS_LOAD')); };
            document.head.appendChild(s);
        });
        return pdfjsPromise;
    }

    function DocView(host, file, o) {
        var self = this;
        this.host = host;
        this.file = file;
        this.o = o;
        this.pages = [];
        this.ink = {};
        this.dirty = false;
        this.saveTimer = null;
        this.destroyed = false;
        this.inkUrl = o.base + '/files/' + encodeURIComponent(file.uuid) + '/ink';

        this.bar = new PenBar({
            onChange: function () { self.applyPen(self.bar.options()); },
            onUndo: function () { var b = self.activeBoard(); if (b) { b.undo(); } },
            onRedo: function () { var b = self.activeBoard(); if (b) { b.redo(); } },
            onClear: function () { var b = self.activeBoard(); if (b) { b.clear(); } },
            extra: [h('span', { class: 'pen-sep' }), h('button', {
                type: 'button', class: 'btn btn-ghost btn-sm', html: svg('back') + '<span>فایل‌ها</span>',
                onclick: function () { o.onBack(); }
            })]
        });
        this.counter = h('span', { class: 'note-doc-name', dir: 'auto', text: file.name });
        this.list = h('div', { class: 'note-doc-pages' });
        host.appendChild(h('div', { class: 'note-doc-bar' }, [this.bar.el, this.counter]));
        host.appendChild(this.list);
        this.list.appendChild(h('div', { class: 'note-loading', text: 'در حال باز کردن فایل…' }));

        api('GET', this.inkUrl).then(function (data) {
            self.ink = data.pages || {};
        }).catch(function () { self.ink = {}; }).then(function () {
            if (self.destroyed) { return; }
            if (file.mime === 'application/pdf') { self.openPdf(); } else { self.openImage(); }
        });

        this.onResize = function () {
            if (self.resizeTimer) { clearTimeout(self.resizeTimer); }
            self.resizeTimer = setTimeout(function () { self.relayout(); }, 180);
        };
        root.addEventListener('resize', this.onResize);
    }

    DocView.prototype.openImage = function () {
        var self = this;
        var img = new Image();
        img.onload = function () {
            if (self.destroyed) { return; }
            self.list.innerHTML = '';
            var page = self.addPage(1, img.naturalHeight / Math.max(1, img.naturalWidth));
            img.className = 'note-doc-img';
            img.alt = '';
            page.el.insertBefore(img, page.el.firstChild);
            page.rendered = true;
        };
        img.onerror = function () { self.fail('تصویر باز نشد.'); };
        img.src = this.file.url;
    };

    DocView.prototype.openPdf = function () {
        var self = this;
        loadPdfJs().then(function (lib) {
            return lib.getDocument({ url: self.file.url, isEvalSupported: false, withCredentials: true }).promise;
        }).then(function (pdf) {
            if (self.destroyed) { pdf.destroy(); return; }
            self.pdf = pdf;
            self.list.innerHTML = '';
            var tasks = [];
            for (var i = 1; i <= pdf.numPages; i++) { tasks.push(pdf.getPage(i)); }
            // The first page's shape is used for all until each is measured;
            // it keeps the scroll length right from the start.
            return pdf.getPage(1).then(function (first) {
                var vp = first.getViewport({ scale: 1 });
                var ratio = vp.height / vp.width;
                for (var n = 1; n <= pdf.numPages; n++) { self.addPage(n, ratio); }
                self.watch();
                return Promise.all(tasks).then(function (all) {
                    all.forEach(function (p, idx) {
                        var v = p.getViewport({ scale: 1 });
                        var page = self.pages[idx];
                        page.pdfPage = p;
                        page.ratio = v.height / v.width;
                    });
                    self.relayout();
                });
            });
        }).catch(function (err) {
            self.fail(err && /PDFJS/.test(err.message) ? 'نمایشگر PDF نصب نیست؛ فایل را با «باز کردن» ببینید.' : 'PDF باز نشد.');
        });
    };

    DocView.prototype.fail = function (text) {
        this.list.innerHTML = '';
        this.list.appendChild(h('div', { class: 'note-loading is-error' }, [
            text + ' ',
            h('a', { href: this.file.url, target: '_blank', rel: 'noopener', text: 'باز کردن فایل' })
        ]));
    };

    DocView.prototype.addPage = function (number, ratio) {
        var self = this;
        var el = h('div', { class: 'note-doc-page', 'data-page': String(number) }, [
            h('span', { class: 'note-doc-num', text: fa(number) })
        ]);
        this.list.appendChild(el);
        var page = { number: number, ratio: ratio, el: el, canvas: null, rendered: false, task: null };

        var board = new Ink.Board({
            input: el,
            host: el,
            refWidth: function () { return el.clientWidth || 1; },
            active: function () { return true; },
            clip: function () { return visibleRect(el, self.o.scroller); },
            onChange: function (b) {
                var data = b.data();
                if (data.length) { self.ink[String(number)] = data; } else { delete self.ink[String(number)]; }
                self.markDirty();
            },
            onHistory: function (b) { self.lastBoard = b; self.bar.history(b.canUndo(), b.canRedo()); },
            onPen: function () { self.bar.penDetected(); }
        });
        el.addEventListener('pointerdown', function () {
            self.lastBoard = board;
            self.bar.history(board.canUndo(), board.canRedo());
        }, true);
        board.load(this.ink[String(number)] || []);
        page.board = board;
        this.pages.push(page);
        this.size(page);
        this.applyPen(this.bar.options());
        return page;
    };

    DocView.prototype.activeBoard = function () {
        return this.lastBoard || (this.pages[0] && this.pages[0].board);
    };

    DocView.prototype.applyPen = function (opts) {
        this.pages.forEach(function (p) {
            p.board.setOptions(opts);
            touchMode(p.el, opts.finger);
        });
    };

    DocView.prototype.size = function (page) {
        var w = page.el.clientWidth;
        if (w) { page.el.style.height = Math.round(w * page.ratio) + 'px'; }
        page.board.refresh();
    };

    DocView.prototype.relayout = function () {
        var self = this;
        this.pages.forEach(function (p) {
            var before = p.width;
            self.size(p);
            if (p.rendered && p.canvas && Math.abs((before || 0) - p.el.clientWidth) > 2) {
                p.rendered = false;
                if (p.visible) { self.render(p); }
            }
        });
    };

    DocView.prototype.watch = function () {
        var self = this;
        if (!root.IntersectionObserver) {
            this.pages.forEach(function (p) { p.visible = true; self.render(p); });
            return;
        }
        var scroller = this.o.scroller && this.o.scroller !== document.scrollingElement ? this.o.scroller : null;
        this.io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                var page = self.pages[+entry.target.getAttribute('data-page') - 1];
                if (!page) { return; }
                page.visible = entry.isIntersecting;
                if (entry.isIntersecting) { self.render(page); } else { self.release(page); }
            });
        }, { root: scroller, rootMargin: '1200px 0px' });
        this.pages.forEach(function (p) { self.io.observe(p.el); });
    };

    DocView.prototype.render = function (page) {
        if (page.rendered || !page.pdfPage || page.task) { return; }
        var w = page.el.clientWidth;
        if (!w) { return; }
        var dpr = Math.min(root.devicePixelRatio || 1, 2);
        var base = page.pdfPage.getViewport({ scale: 1 });
        var scale = Math.min((w * dpr) / base.width, 4096 / base.width);
        var vp = page.pdfPage.getViewport({ scale: scale });
        var canvas = page.canvas || h('canvas', { class: 'note-doc-canvas', 'aria-hidden': 'true' });
        canvas.width = Math.floor(vp.width);
        canvas.height = Math.floor(vp.height);
        if (!page.canvas) { page.el.insertBefore(canvas, page.el.firstChild); page.canvas = canvas; }
        page.width = w;
        page.task = page.pdfPage.render({ canvasContext: canvas.getContext('2d'), viewport: vp });
        page.task.promise.then(function () {
            page.rendered = true;
            page.task = null;
        }).catch(function () { page.task = null; });
    };

    DocView.prototype.release = function (page) {
        if (!page.canvas || !page.pdfPage) { return; }
        if (page.task) { page.task.cancel(); page.task = null; }
        page.canvas.width = 0;
        page.canvas.height = 0;
        page.rendered = false;
    };

    DocView.prototype.markDirty = function () {
        var self = this;
        this.dirty = true;
        this.o.onStatus('ذخیره نشده…');
        if (this.saveTimer) { clearTimeout(this.saveTimer); }
        this.saveTimer = setTimeout(function () { self.flush(false); }, 1000);
    };

    DocView.prototype.flush = function (keepalive) {
        var self = this;
        if (this.saveTimer) { clearTimeout(this.saveTimer); this.saveTimer = null; }
        if (!this.dirty) { return; }
        this.dirty = false;
        this.o.onStatus('در حال ذخیره…');
        api('POST', this.inkUrl, { pages: this.ink }, keepalive).then(function () {
            self.o.onStatus('ذخیره شد ✓');
            self.o.onSaved();
        }).catch(function (err) {
            self.dirty = true;
            self.o.onStatus((err && err.status === 413 && err.message) || 'ذخیره نشد؛ دوباره تلاش می‌شود…', true);
            if (!self.destroyed) { self.saveTimer = setTimeout(function () { self.flush(false); }, 4000); }
        });
    };

    DocView.prototype.destroy = function () {
        this.flush(true);
        this.destroyed = true;
        if (this.saveTimer) { clearTimeout(this.saveTimer); }
        root.removeEventListener('resize', this.onResize);
        if (this.io) { this.io.disconnect(); }
        this.pages.forEach(function (p) {
            if (p.task) { p.task.cancel(); }
            p.board.destroy();
        });
        this.pages = [];
        if (this.pdf) { this.pdf.destroy(); this.pdf = null; }
    };

    root.HlxNotes = { Editor: Editor, PenBar: PenBar, api: api, COLORS: NOTE_COLORS };

    /* ------------------------------------------------ the full note page */
    function boot() {
        var host = document.querySelector('[data-note-page]');
        if (!host) { return; }
        var editor = new Editor(host, {
            uuid: host.getAttribute('data-uuid'),
            pdfjs: host.getAttribute('data-pdfjs') === '1',
            scroller: null,
            focusTitle: true,
            onDeleted: function () { root.location.href = '/student/notes'; }
        });
        root.HlxNotes.current = editor;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window);
