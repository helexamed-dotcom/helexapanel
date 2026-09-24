/**
 * بانک سوال — editor and practice player.
 *
 * Editor:  paste / drop / upload an image into any field, add and remove
 *          options, narrow the three filing selects as a cascade.
 * Player:  send the chosen option, then reveal the verdict and explanation.
 *
 * Nothing here is a control. The server decides which image wins, whether
 * the filing path is consistent, and whether an answer is right; this file
 * only makes those decisions pleasant to reach.
 */
(function () {
    'use strict';

    var LETTERS = ['الف', 'ب', 'ج', 'د', 'ه', 'و', 'ز', 'ح'];
    var meta    = document.querySelector('meta[name="csrf-token"]');
    var TOKEN   = meta ? meta.getAttribute('content') : '';

    /* =================================================================
       Editor
       ================================================================= */

    var editor = document.querySelector('[data-qb-editor]');

    if (editor) {
        var maxBytes   = (parseInt(editor.getAttribute('data-max-kb'), 10) || 3072) * 1024;
        var maxOptions = parseInt(editor.getAttribute('data-max-options'), 10) || 8;
        var minOptions = parseInt(editor.getAttribute('data-min-options'), 10) || 2;

        /* ---------------------------------------------- rich fields */

        function wireRich(box) {
            if (box.getAttribute('data-qb-wired') === '1') { return; }
            box.setAttribute('data-qb-wired', '1');

            var preview    = box.querySelector('[data-qb-preview]');
            var img        = box.querySelector('[data-qb-img]');
            var badge      = box.querySelector('[data-qb-badge]');
            var dataField  = box.querySelector('[data-qb-data]');
            var removeFlag = box.querySelector('[data-qb-remove-flag]');
            var fileInput  = box.querySelector('[data-qb-file]');
            var removeBtn  = box.querySelector('[data-qb-remove]');
            var objectUrl  = null;

            function show(src, label) {
                if (objectUrl && objectUrl !== src) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
                img.src = src;
                badge.textContent = label;
                preview.classList.add('has-image');
                removeFlag.value = '';
            }

            function clearAll() {
                if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
                img.removeAttribute('src');
                preview.classList.remove('has-image');
                dataField.value = '';
                fileInput.value = '';
                // Tells the server to drop the image the question already had.
                removeFlag.value = '1';
            }

            function tooBig(size) {
                if (size > maxBytes) {
                    window.alert('حجم تصویر بیشتر از ' + Math.round(maxBytes / 1024) + ' کیلوبایت است.');
                    return true;
                }
                return false;
            }

            /** Pasted and dropped images travel as a data: URL in a hidden field. */
            function takeBlob(blob, label) {
                if (!blob || !/^image\//.test(blob.type)) { return false; }
                if (tooBig(blob.size)) { return true; }

                var reader = new FileReader();
                reader.onload = function () {
                    dataField.value = String(reader.result || '');
                    // A pasted image replaces any file chosen earlier; two
                    // pending images in one field would leave the admin
                    // guessing which one the server keeps.
                    fileInput.value = '';
                    show(dataField.value, label);
                };
                reader.readAsDataURL(blob);
                return true;
            }

            box.addEventListener('paste', function (event) {
                var items = (event.clipboardData && event.clipboardData.items) || [];
                for (var i = 0; i < items.length; i++) {
                    if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
                        // Only an image paste is intercepted; pasting text
                        // into the textarea behaves exactly as normal.
                        event.preventDefault();
                        takeBlob(items[i].getAsFile(), 'تصویر چسبانده‌شده');
                        return;
                    }
                }
            });

            box.addEventListener('dragover', function (event) {
                if (event.dataTransfer && Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') !== -1) {
                    event.preventDefault();
                    box.classList.add('is-dragover');
                }
            });
            box.addEventListener('dragleave', function () { box.classList.remove('is-dragover'); });
            box.addEventListener('drop', function (event) {
                box.classList.remove('is-dragover');
                var files = event.dataTransfer && event.dataTransfer.files;
                if (files && files.length) {
                    event.preventDefault();
                    takeBlob(files[0], 'تصویر کشیده‌شده');
                }
            });

            fileInput.addEventListener('change', function () {
                var file = fileInput.files && fileInput.files[0];
                if (!file) { return; }
                if (tooBig(file.size)) { fileInput.value = ''; return; }
                dataField.value = '';
                objectUrl = URL.createObjectURL(file);
                show(objectUrl, file.name);
            });

            removeBtn.addEventListener('click', clearAll);
        }

        editor.querySelectorAll('[data-qb-rich]').forEach(wireRich);

        /* ---------------------------------------------- options */

        var list     = editor.querySelector('[data-qb-options]');
        var addBtn   = editor.querySelector('[data-qb-add-option]');
        var template = editor.querySelector('[data-qb-option-template]');
        var counter  = 1000;

        function rows() { return list.querySelectorAll('[data-qb-option]'); }

        function refresh() {
            var all = rows();
            all.forEach(function (row, i) {
                var letter = row.querySelector('[data-qb-letter]');
                if (letter) { letter.textContent = LETTERS[i] || String(i + 1); }
                var radio = row.querySelector('[data-qb-correct]');
                row.classList.toggle('is-correct', !!(radio && radio.checked));
                var remove = row.querySelector('[data-qb-option-remove]');
                if (remove) { remove.hidden = all.length <= minOptions; }
            });
            if (addBtn) { addBtn.disabled = all.length >= maxOptions; }
        }

        if (addBtn && template) {
            addBtn.addEventListener('click', function () {
                if (rows().length >= maxOptions) { return; }
                counter++;
                var html = template.innerHTML
                    .split('__I__').join(String(counter))
                    .split('__K__').join('n' + counter);
                var holder = document.createElement('div');
                holder.innerHTML = html.trim();
                var row = holder.firstElementChild;
                list.appendChild(row);
                row.querySelectorAll('[data-qb-rich]').forEach(wireRich);
                refresh();
                var ta = row.querySelector('textarea');
                if (ta) { ta.focus(); }
            });
        }

        list.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-qb-option-remove]');
            if (!btn) { return; }
            if (rows().length <= minOptions) { return; }
            btn.closest('[data-qb-option]').remove();
            refresh();
        });

        list.addEventListener('change', function (event) {
            if (event.target.matches('[data-qb-correct]')) { refresh(); }
        });

        refresh();

        /* ---------------------------------------------- filing cascade */

        var levels = [1, 2, 3].map(function (n) {
            return editor.querySelector('[data-qb-level="' + n + '"]');
        });

        function narrow(depth) {
            var parentSelect = levels[depth - 2];
            var select       = levels[depth - 1];
            if (!parentSelect || !select) { return; }

            var parentId = parentSelect.value;
            Array.prototype.forEach.call(select.options, function (opt) {
                if (!opt.value) { return; }
                // With no parent chosen, every row stays available: the
                // server fills in the parent from the tree.
                var visible = !parentId || opt.getAttribute('data-parent') === parentId;
                // hidden alone is ignored inside <select> by Safari, so the
                // option is disabled as well. Rows the server rendered as
                // disabled (inactive subjects) stay disabled regardless.
                opt.hidden   = !visible;
                opt.disabled = !visible || opt.hasAttribute('data-off');
                if (!visible && opt.selected) { select.value = ''; }
            });
        }

        function parentOf(select) {
            var opt = select && select.value ? select.options[select.selectedIndex] : null;
            return opt ? opt.getAttribute('data-parent') : '';
        }

        if (levels[0] && levels[1]) {
            levels[0].addEventListener('change', function () { narrow(2); narrow(3); });

            // Picking a deeper level first fills in the levels above it, so
            // the three selects always read as one consistent path.
            levels[1].addEventListener('change', function () {
                var p = parentOf(levels[1]);
                if (p) { levels[0].value = p; }
                narrow(2); narrow(3);
            });

            if (levels[2]) {
                levels[2].addEventListener('change', function () {
                    var sub = parentOf(levels[2]);
                    if (sub) {
                        levels[1].value = sub;
                        var subject = parentOf(levels[1]);
                        if (subject) { levels[0].value = subject; }
                    }
                    narrow(2); narrow(3);
                });
            }

            narrow(2);
            narrow(3);
        }
    }
    /* =================================================================
       Practice player
       ================================================================= */

    var player = document.querySelector('[data-qb-player]');

    function toFa(n) {
        return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[Number(d)]; });
    }

    if (player) {
        /* ------------------------------------------------ personal marks */
        var markUrl = player.getAttribute('data-mark-url');
        player.querySelectorAll('[data-qb-mark]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (btn.classList.contains('is-busy')) { return; }
                btn.classList.add('is-busy');
                var body = new FormData();
                body.append('_token', TOKEN);
                body.append('mark', btn.getAttribute('data-qb-mark'));
                fetch(markUrl, {
                    method: 'POST', body: body, credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': TOKEN, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.ok) {
                            btn.classList.toggle('is-on', !!data.on);
                            btn.setAttribute('aria-pressed', data.on ? 'true' : 'false');
                            var onLabel = btn.getAttribute('data-on-label');
                            if (onLabel) {
                                btn.textContent = data.on ? onLabel : btn.getAttribute('data-off-label');
                            }
                            if (data.mark === 'exam' && window.HlxUI) {
                                window.HlxUI.toast(data.on
                                    ? 'به «آزمون‌های من» اضافه شد ✓'
                                    : 'از «آزمون‌های من» برداشته شد', data.on ? 'ok' : '');
                            }
                        }
                    })
                    .catch(function () { window.alert('ذخیره نشان ناموفق بود.'); })
                    .then(function () { btn.classList.remove('is-busy'); });
            });
        });

        var url      = player.getAttribute('data-answer-url');
        var buttons  = player.querySelectorAll('[data-qb-answer]');
        var verdict  = player.querySelector('[data-qb-verdict]');
        var explain  = document.querySelector('[data-qb-explain]');
        var explText = document.querySelector('[data-qb-explain-text]');
        var explImg  = document.querySelector('[data-qb-explain-img]');
        var explWait = document.querySelector('[data-qb-explain-wait]');
        var explOpen = player.querySelector('[data-qb-explain-open]');
        var sheetOn  = window.matchMedia ? window.matchMedia('(max-width: 1199px)') : { matches: false };

        /* On a phone the explanation is a sheet, opened by its own button. */
        // The page animates in with a transform, which would trap a fixed
        // sheet inside it under its own backdrop; it goes to <body> while open.
        var scrim = null;
        var explHome = explain ? explain.parentNode : null;
        function openSheet() {
            if (!explain || !sheetOn.matches) { return; }
            document.body.appendChild(explain);
            scrim = document.createElement('div');
            scrim.className = 'qb-sheet-scrim';
            scrim.addEventListener('click', closeSheet);
            document.body.appendChild(scrim);
            explain.classList.add('is-sheet');
            document.documentElement.classList.add('qb-sheet-open');
        }
        function closeSheet() {
            if (!explain) { return; }
            explain.classList.remove('is-sheet');
            if (explHome && explain.parentNode !== explHome) { explHome.appendChild(explain); }
            document.documentElement.classList.remove('qb-sheet-open');
            if (scrim) { scrim.remove(); scrim = null; }
        }
        if (explOpen) { explOpen.addEventListener('click', openSheet); }
        document.querySelectorAll('[data-qb-explain-close]').forEach(function (b) { b.addEventListener('click', closeSheet); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeSheet(); } });
        var busy     = false;

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (busy || button.disabled) { return; }
                busy = true;

                var body = new FormData();
                body.append('_token', TOKEN);
                body.append('option', button.getAttribute('data-qb-answer'));

                fetch(url, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': TOKEN, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                })
                    .then(function (res) { return res.json().then(function (data) { return { res: res, data: data }; }); })
                    .then(function (r) {
                        if (!r.res.ok || !r.data.ok) {
                            busy = false;
                            window.alert((r.data && r.data.message) || 'ثبت پاسخ ناموفق بود. دوباره تلاش کن.');
                            return;
                        }
                        reveal(button, r.data);
                    })
                    .catch(function () {
                        busy = false;
                        window.alert('ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی کن.');
                    });
            });
        });

        /**
         * How the class split on this question, drawn as a bar inside each
         * option. Only arrives with the answer, so it can never be a hint.
         */
        function showShares(dist) {
            if (!dist || !dist.total) { return; }
            buttons.forEach(function (b) {
                var share = b.querySelector('[data-qb-share]');
                if (!share) { return; }
                var pct = dist.options[b.getAttribute('data-qb-answer')] || 0;
                share.querySelector('b').textContent = toFa(pct) + '٪';
                share.hidden = false;
                // Set on the next frame so the width animates from zero.
                window.requestAnimationFrame(function () {
                    share.querySelector('i').style.width = pct + '%';
                });
            });
            var note = player.querySelector('[data-qb-share-note]');
            if (note) {
                note.textContent = 'درصدها بر اساس اولین پاسخ ' + toFa(dist.total) + ' نفر است.';
                note.hidden = false;
            }
        }

        function reveal(chosen, data) {
            buttons.forEach(function (b) {
                b.disabled = true;
                b.classList.remove('is-prev');
                var uuid = b.getAttribute('data-qb-answer');
                if (uuid === data.correct_option) {
                    b.classList.add('is-right');
                } else if (b === chosen) {
                    b.classList.add('is-wrong');
                } else {
                    b.classList.add('is-faded');
                }
            });

            verdict.textContent = data.correct ? '✓ آفرین! پاسخ درست بود.' : '✗ پاسخ درست نبود. گزینه صحیح سبز شده است.';
            var lessonBtn = player.querySelector('[data-qb-lesson]');
            if (lessonBtn) { lessonBtn.hidden = false; }
            verdict.classList.add(data.correct ? 'is-right' : 'is-wrong');

            showShares(data.distribution);

            if (data.xp && data.xp.xp) {
                var badge = document.createElement('span');
                badge.className = 'qb-xp-pop';
                badge.textContent = '+' + toFa(data.xp.xp) + ' XP' + (data.xp.levelled_up ? ' · سطح ' + toFa(data.xp.level) + ' 🎉' : '');
                verdict.appendChild(badge);
            }

            var hasText  = !!(data.explanation_text && data.explanation_text.length);
            var hasImage = !!data.explanation_image;
            if (hasText || hasImage) {
                // textContent, never innerHTML: the explanation is admin text,
                // and it is shown as text exactly the way the server escapes
                // every other field.
                explText.textContent = data.explanation_text || '';
                if (hasImage) {
                    explImg.src = data.explanation_image;
                    explImg.hidden = false;
                }
                explain.classList.add('is-open');
                if (explWait) { explWait.hidden = true; }
                if (explOpen) { explOpen.hidden = false; }
            } else if (explWait) {
                explWait.textContent = 'برای این سوال پاسخ تشریحی ثبت نشده است.';
            }
            // Linked درسنامه‌ها (the question's own tags): a way to read up at once.
            var links = document.querySelector('[data-qb-explain-links]');
            if (links && data.lessons && data.lessons.length) {
                links.textContent = '';
                var h = document.createElement('small');
                h.textContent = 'درسنامه‌های مرتبط';
                links.appendChild(h);
                data.lessons.forEach(function (l) {
                    var a = document.createElement('a');
                    a.href = l.url;
                    a.textContent = '📘 ' + l.title;
                    links.appendChild(a);
                });
                if (explOpen) { explOpen.hidden = false; }
                explain.classList.add('is-open');
            }

            var next = player.querySelector('[data-qb-next]');
            if (next) {
                // In a drill, this question may just have left the sequence;
                // if so, the next one now occupies the current position.
                var mode    = next.getAttribute('data-mode');
                var removed = mode === 'new'
                    || (mode === 'wrong' && data.correct)
                    || (mode === 'correct' && !data.correct);
                if (removed && next.getAttribute('data-removed-href')) {
                    next.href = next.getAttribute('data-removed-href');
                    next.textContent = 'سوال بعدی ←';
                }
                next.focus({ preventScroll: true });
            }
        }
    }
})();

/* Bulk selection on the question list: select all, shift-click ranges,
   live count, and a confirmation that names the action and the count. */
(function () {
    'use strict';
    var form = document.querySelector('[data-bulk]');
    if (!form) { return; }

    var all    = form.querySelector('[data-bulk-all]');
    var count  = form.querySelector('[data-bulk-count]');
    var action = form.querySelector('[data-bulk-action]');
    var submit = form.querySelector('[data-bulk-submit]');
    var items  = Array.prototype.slice.call(document.querySelectorAll('[data-bulk-item]'));
    var last   = null;
    var LABELS = { publish: 'منتشر', draft: 'به پیش‌نویس برگردانده', delete: 'حذف' };

    function fa(n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }

    function sync() {
        var picked = items.filter(function (box) { return box.checked; }).length;
        items.forEach(function (box) {
            var row = box.closest('.qb-row');
            if (row) { row.classList.toggle('is-selected', box.checked); }
        });
        all.checked = picked > 0 && picked === items.length;
        all.indeterminate = picked > 0 && picked < items.length;
        count.textContent = picked ? fa(picked) + ' سوال انتخاب شده' : 'هیچ سوالی انتخاب نشده';
        form.classList.toggle('is-active', picked > 0);
        submit.disabled = !picked || !action.value;

        // app.js reads data-confirm on submit, so it always names what will happen.
        if (picked && action.value) {
            form.setAttribute('data-confirm', fa(picked) + ' سوال ' + LABELS[action.value] + ' شود؟' +
                (action.value === 'delete' ? ' سوال‌های حذف‌شده از دید دانشجو و فهرست خارج می‌شوند.' : ''));
        } else {
            form.removeAttribute('data-confirm');
        }
    }

    all.addEventListener('change', function () {
        items.forEach(function (box) { box.checked = all.checked; });
        sync();
    });

    items.forEach(function (box) {
        box.addEventListener('click', function (event) {
            // Shift-click selects everything between the last click and this one.
            if (event.shiftKey && last && last !== box) {
                var a = items.indexOf(last), b = items.indexOf(box);
                items.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function (x) { x.checked = box.checked; });
            }
            last = box;
            sync();
        });
    });

    action.addEventListener('change', sync);
    sync();
})();

/* -----------------------------------------------------------------------
   «درسنامه» and «گزارش اشکال» — on the practice player and on an exam's
   answer review alike, so they are wired by delegation.
   ----------------------------------------------------------------------- */
(function () {
    'use strict';
    var TOKEN = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function ui() { return window.HlxUI; }

    function openLesson(btn) {
        var UI = ui();
        if (!UI || btn.classList.contains('is-busy')) { return; }
        btn.classList.add('is-busy');
        fetch(btn.getAttribute('data-url'), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var body = UI.el('div', 'qb-lesson-note');
                if (!data || !data.ok) {
                    body.appendChild(UI.el('p', 'qb-lesson-empty', (data && data.message) || 'درسنامه باز نشد.'));
                    UI.modal({ title: '📘 درسنامه', body: body });
                    return;
                }
                if (!data.found) {
                    var empty = UI.el('div', 'qb-lesson-empty');
                    empty.appendChild(UI.el('div', 'qb-lesson-empty-icon', '📭'));
                    empty.appendChild(UI.el('p', '', data.message || 'برای این درس درسنامه‌ای وجود ندارد.'));
                    body.appendChild(empty);
                    UI.modal({ title: '📘 درسنامه', body: body });
                    return;
                }
                // The server escaped the admin's text and added only its own
                // few tags (headings, lists, bold), so this markup is ours.
                body.innerHTML = data.html;
                UI.modal({ title: '📘 ' + data.title, body: body, wide: true });
            })
            .catch(function () { UI.toast('ارتباط با سرور برقرار نشد.', 'bad'); })
            .then(function () { btn.classList.remove('is-busy'); });
    }

    var REASONS = [
        ['wrong_key', 'کلید (پاسخ صحیح) اشتباه است'],
        ['wrong_text', 'غلط تایپی یا متن اشتباه'],
        ['unclear', 'سوال یا گزینه‌ها مبهم است'],
        ['image', 'تصویر مشکل دارد'],
        ['duplicate', 'سوال تکراری است'],
        ['other', 'سایر']
    ];

    function openReport(btn) {
        var UI = ui();
        if (!UI) { return; }
        var form = UI.el('div', 'qb-report-form');
        form.appendChild(UI.el('p', 'qb-hint', 'چه مشکلی در این سوال دیدی؟ گزارشت مستقیم برای مدیر ارسال می‌شود.'));

        var list = UI.el('div', 'qb-report-reasons');
        REASONS.forEach(function (r, i) {
            var label = UI.el('label', 'qb-report-reason');
            var input = document.createElement('input');
            input.type = 'radio';
            input.name = 'qb-report-reason';
            input.value = r[0];
            if (i === 0) { input.checked = true; }
            label.appendChild(input);
            label.appendChild(UI.el('span', '', r[1]));
            list.appendChild(label);
        });
        form.appendChild(list);

        var area = document.createElement('textarea');
        area.className = 'input';
        area.rows = 4;
        area.maxLength = 2000;
        area.placeholder = 'توضیح (مثلاً: گزینه ب درست است، چون …)';
        form.appendChild(area);

        UI.modal({
            title: '⚠️ گزارش اشکال سوال',
            body: form,
            actions: [
                { label: 'انصراف' },
                {
                    label: 'ارسال گزارش', primary: true,
                    onClick: function (close, button) {
                        var picked = form.querySelector('input[name="qb-report-reason"]:checked');
                        var data = new FormData();
                        data.append('_token', TOKEN);
                        data.append('reason', picked ? picked.value : 'other');
                        data.append('body', area.value);
                        button.disabled = true;
                        fetch(btn.getAttribute('data-url'), {
                            method: 'POST', body: data, credentials: 'same-origin',
                            headers: { 'X-CSRF-Token': TOKEN, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                        })
                            .then(function (res) { return res.json(); })
                            .then(function (res) {
                                if (res && res.ok) {
                                    close();
                                    UI.toast(res.message || 'گزارش ثبت شد.', 'ok');
                                    btn.classList.add('is-sent');
                                    btn.textContent = '✓ گزارش ارسال شد';
                                } else {
                                    button.disabled = false;
                                    UI.toast((res && res.message) || 'ارسال نشد.', 'bad');
                                }
                            })
                            .catch(function () { button.disabled = false; UI.toast('ارتباط با سرور برقرار نشد.', 'bad'); });
                    }
                }
            ]
        });
    }

    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-qb-lesson], [data-qb-report]') : null;
        if (!t) { return; }
        e.preventDefault();
        if (t.hasAttribute('data-qb-lesson')) { openLesson(t); } else { openReport(t); }
    });
})();

/* -----------------------------------------------------------------------
   «آزمون‌های من»: the builder, the answer sheet and the review filter.
   ----------------------------------------------------------------------- */
(function () {
    'use strict';
    var TOKEN = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    function fa(n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[Number(d)]; }); }

    /* ------------------------------------------------ builder */
    var builder = document.querySelector('[data-exam-builder]');
    if (builder) {
        var syncScope = function () {
            var picked = builder.querySelector('[data-scope]:checked');
            var id = picked ? picked.value : 'all';
            builder.querySelectorAll('[data-subs-for]').forEach(function (box) {
                var on = box.getAttribute('data-subs-for') === id;
                box.hidden = !on;
                box.querySelectorAll('input').forEach(function (i) { i.disabled = !on; if (!on) { i.checked = false; } });
            });
        };
        builder.querySelectorAll('[data-scope]').forEach(function (r) { r.addEventListener('change', syncScope); });
        syncScope();

        var timed = builder.querySelector('[data-timed]');
        var minutes = builder.querySelector('[data-minutes]');
        if (timed && minutes) {
            var syncTimed = function () { minutes.hidden = !timed.checked; };
            timed.addEventListener('change', syncTimed);
            syncTimed();
        }
    }

    /* ------------------------------------------------ answer sheet */
    var sheet = document.querySelector('[data-exam-sheet]');
    if (sheet) {
        var saveUrl  = sheet.getAttribute('data-save-url');
        var sections = sheet.querySelectorAll('[data-question]');
        var total    = sections.length;
        var answeredEl = sheet.querySelector('[data-answered]');
        var progress   = sheet.querySelector('[data-progress]');
        var submitting = false;

        var count = function () {
            var n = 0;
            sections.forEach(function (s) { if (s.querySelector('input[type="radio"]:checked')) { n++; } });
            return n;
        };
        var refresh = function () {
            var n = count();
            if (answeredEl) { answeredEl.textContent = fa(n); }
            if (progress) { progress.style.width = Math.round(n * 100 / Math.max(1, total)) + '%'; }
        };
        var save = function (section, optionUuid) {
            var body = new FormData();
            body.append('_token', TOKEN);
            body.append('question', section.getAttribute('data-question'));
            body.append('option', optionUuid || '');
            return fetch(saveUrl, {
                method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'X-CSRF-Token': TOKEN, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (!res || !res.ok) {
                    if (window.HlxUI) { window.HlxUI.toast((res && res.message) || 'پاسخ ذخیره نشد؛ با تحویل آزمون ثبت می‌شود.', 'bad'); }
                }
            }).catch(function () { /* offline: the final submit still carries the whole sheet */ });
        };

        sections.forEach(function (section) {
            var id = section.getAttribute('data-question');
            var jump = sheet.querySelector('[data-jump="' + id + '"]');
            var clear = section.querySelector('[data-clear]');
            section.querySelectorAll('input[type="radio"]').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    section.classList.add('is-answered');
                    if (jump) { jump.classList.add('is-done'); }
                    if (clear) { clear.hidden = false; }
                    refresh();
                    save(section, radio.value);
                });
            });
            if (clear) {
                clear.addEventListener('click', function () {
                    section.querySelectorAll('input[type="radio"]').forEach(function (r) { r.checked = false; });
                    section.classList.remove('is-answered');
                    if (jump) { jump.classList.remove('is-done'); }
                    clear.hidden = true;
                    refresh();
                    save(section, '');
                });
            }
        });

        sheet.addEventListener('submit', function (e) {
            if (submitting) { return; }
            var left = total - count();
            if (left > 0 && !window.confirm(fa(left) + ' سوال را جواب نداده‌ای. آزمون تحویل داده شود؟')) {
                e.preventDefault();
                return;
            }
            submitting = true;
        });

        /* the timer, measured against the server's clock */
        var deadline = parseInt(sheet.getAttribute('data-deadline'), 10) || 0;
        var timerEl  = sheet.querySelector('[data-timer]');
        if (deadline > 0 && timerEl) {
            var skew = (parseInt(sheet.getAttribute('data-now'), 10) || 0) * 1000 - Date.now();
            var tick = function () {
                var left = Math.max(0, Math.round((deadline * 1000 - (Date.now() + skew)) / 1000));
                var m = Math.floor(left / 60), s = left % 60;
                timerEl.textContent = fa((m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s);
                timerEl.classList.toggle('is-low', left <= 60);
                if (left <= 0) {
                    window.clearInterval(handle);
                    if (!submitting) {
                        submitting = true;
                        if (window.HlxUI) { window.HlxUI.toast('زمان تمام شد؛ آزمون تحویل داده شد.', ''); }
                        sheet.submit();
                    }
                }
            };
            var handle = window.setInterval(tick, 1000);
            tick();
        }

        // Leaving mid-exam loses nothing (every tick is saved), but a
        // reminder saves a wasted attempt.
        window.addEventListener('beforeunload', function (e) {
            if (!submitting && count() < total && count() > 0) { e.preventDefault(); e.returnValue = ''; }
        });
    }

    /* ------------------------------------------------ review filter */
    var filters = document.querySelectorAll('[data-review-filter]');
    if (filters.length) {
        filters.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var want = btn.getAttribute('data-review-filter');
                filters.forEach(function (b) { b.classList.toggle('is-on', b === btn); });
                document.querySelectorAll('[data-review]').forEach(function (q) {
                    q.hidden = want !== 'all' && q.getAttribute('data-review') !== want;
                });
            });
        });
    }
})();

/* The question editor's «درسنامه‌های مرتبط» search box. */
(function () {
    'use strict';
    var box = document.querySelector('[data-lesson-filter]');
    var list = document.querySelector('[data-lesson-list]');
    if (!box || !list) { return; }
    box.addEventListener('input', function () {
        var q = box.value.trim();
        list.querySelectorAll('[data-title]').forEach(function (l) {
            l.hidden = q !== '' && l.getAttribute('data-title').indexOf(q) === -1 && !l.querySelector('input').checked;
        });
    });
})();

/* -----------------------------------------------------------------------
   The compact player's side panel and keys: every filter applies at once,
   the panel is a sheet on a phone, and 1–8 / arrows drive the question.
   ----------------------------------------------------------------------- */
(function () {
    'use strict';

    var form = document.querySelector('form[data-qx-auto]');
    if (form) {
        var send = function () {
            // Empty fields stay out of the address, which stays readable.
            Array.prototype.forEach.call(form.elements, function (el) {
                if (el.name && !el.value) { el.disabled = true; }
            });
            form.classList.add('is-busy');
            form.submit();
        };
        form.addEventListener('change', function (e) {
            if (e.target.matches('input[type="radio"], select')) {
                // The tree cascade (app.js) has already cleared a stale عنوان.
                window.setTimeout(send, 0);
            }
        });
        form.addEventListener('submit', function (e) { e.preventDefault(); send(); });
    }

    var jump = document.querySelector('form[data-qx-jump]');
    if (jump) {
        jump.addEventListener('submit', function () {
            Array.prototype.forEach.call(jump.elements, function (el) { if (el.name && !el.value) { el.disabled = true; } });
        });
    }

    /* the filter sheet on a phone */
    var panel = document.querySelector('[data-qx-filters]');
    var home = panel ? panel.parentNode : null;
    var marker = panel ? document.createComment('qx-filters') : null;
    var scrim = null;
    if (panel) { home.insertBefore(marker, panel); }

    function openPanel() {
        if (!panel || panel.classList.contains('is-sheet')) { return; }
        // Fixed inside an animated parent would be trapped under its transform.
        document.body.appendChild(panel);
        scrim = document.createElement('div');
        scrim.className = 'qb-sheet-scrim';
        scrim.addEventListener('click', closePanel);
        document.body.appendChild(scrim);
        panel.classList.add('is-sheet');
        document.documentElement.classList.add('qb-sheet-open');
    }
    function closePanel() {
        if (!panel || !panel.classList.contains('is-sheet')) { return; }
        panel.classList.remove('is-sheet');
        home.insertBefore(panel, marker.nextSibling);
        document.documentElement.classList.remove('qb-sheet-open');
        if (scrim) { scrim.remove(); scrim = null; }
    }
    document.querySelectorAll('[data-qx-filters-open]').forEach(function (b) { b.addEventListener('click', openPanel); });
    document.querySelectorAll('[data-qx-filters-close]').forEach(function (b) { b.addEventListener('click', closePanel); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePanel(); } });

    /* keys */
    var player = document.querySelector('.qx[data-qb-player]');
    if (!player) { return; }
    var FA = '۰۱۲۳۴۵۶۷۸۹';
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey || e.defaultPrevented) { return; }
        var t = e.target;
        if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) { return; }
        if (document.querySelector('.hlx-modal-back, .qb-sheet-scrim')) { return; }
        var key = e.key;
        var d = FA.indexOf(key);
        if (d > 0) { key = String(d); }
        if (/^[1-8]$/.test(key)) {
            var opt = player.querySelector('[data-qb-answer][data-key="' + key + '"]');
            if (opt && !opt.disabled) { e.preventDefault(); opt.click(); }
            return;
        }
        // RTL: the arrow pointing left moves forward.
        if (key === 'ArrowLeft') {
            var next = player.querySelector('[data-qb-next]');
            if (next) { e.preventDefault(); window.location.href = next.href; }
        } else if (key === 'ArrowRight') {
            var prev = player.querySelector('[data-qx-prev]');
            if (prev) { e.preventDefault(); window.location.href = prev.href; }
        }
    });
})();
