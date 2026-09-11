/**
 * 🏝️ جزیره بالین — the clinical case player.
 *
 * Two jobs. It reveals the scene a block at a time, so a case reads as a
 * conversation being had rather than a wall of text already over; and it
 * submits answers.
 *
 * Answering is a round trip on purpose. The correct option is never in the
 * page for an unanswered question, so the only way to learn it is to commit
 * to a choice first. What comes back is the verdict, the right answer and
 * the explanation — which is the teaching moment, and the reason a wrong
 * answer is not a dead end.
 *
 * Everything degrades: with no JavaScript the whole scene is rendered and
 * readable, and the buttons simply do nothing rather than lying about
 * having worked.
 */
(function () {
    'use strict';

    var root = document.querySelector('.balin-stage');
    if (!root) {
        return;
    }

    var stageUuid = root.getAttribute('data-stage');
    var csrf      = root.getAttribute('data-csrf');
    var isReplay  = root.getAttribute('data-replay') === '1';

    var scene   = root.querySelector('[data-scene]');
    var advance = root.querySelector('[data-advance]');
    var waiting = root.querySelector('[data-waiting]');
    var label   = root.querySelector('[data-advance-label]');

    /* --------------------------------------------------- stepped reveal */

    var steps = scene ? Array.prototype.slice.call(scene.children) : [];
    var shown = 0;   // how many blocks are on screen right now

    /**
     * Blocks are hidden two ways on purpose. The attribute carries the
     * meaning and takes the block out of the accessibility tree; the inline
     * style is the guarantee. .balin-bubble and its siblings set `display`
     * themselves, so the attribute only bites because of a single [hidden]
     * rule in the stylesheet — and a stylesheet served stale from the
     * service worker would leave the whole case on screen at once, which is
     * precisely the failure this player exists to prevent. An inline style
     * outranks any stylesheet, stale or not.
     */
    function hide(el) {
        el.hidden = true;
        el.style.display = 'none';
    }

    function show(el) {
        el.hidden = false;
        el.style.display = '';
    }

    /** A block the student must deal with before the scene may continue. */
    function isOpenQuestion(el) {
        return !!el
            && el.classList.contains('balin-question')
            && !el.classList.contains('is-answered');
    }

    /** True while the last block on screen is a question still owed an answer. */
    function blocked() {
        return isOpenQuestion(steps[shown - 1]);
    }

    /**
     * Where a half-finished stage picks up: after the last question that was
     * answered, but never past one that was not. Answering out of order is
     * not possible through the player, but a page loaded once without
     * JavaScript can leave that state behind, and resuming past an open
     * question would hand out the rest of the case for free.
     */
    function resumeIndex() {
        var last = -1;
        for (var i = 0; i < steps.length; i++) {
            if (isOpenQuestion(steps[i])) { break; }
            if (steps[i].classList.contains('balin-question')) { last = i; }
        }
        return last;
    }

    /** A scene break carries no words, so it is never a press of its own. */
    function isPassive(el) {
        return !!el
            && (el.tagName === 'HR' || el.classList.contains('balin-divider'));
    }

    /**
     * The button is offered only when there is something to advance to and
     * nothing is waiting on the student. An unanswered question takes it
     * away and says why, so the one way forward is to answer.
     */
    function syncAdvance() {
        var finished = shown >= steps.length;
        var hold     = blocked();

        if (advance) {
            if (finished || hold) { hide(advance); } else { show(advance); }
        }
        if (waiting) {
            if (hold) { show(waiting); } else { hide(waiting); }
        }
        if (label) {
            label.textContent = shown === 0 ? 'شروع چت' : 'ادامه چت';
        }
    }

    function next() {
        // Nothing left to say, or a question still owed an answer: the
        // conversation does not move until the student has dealt with it.
        if (shown >= steps.length || blocked()) {
            return;
        }

        var el;
        do {
            el = steps[shown];
            show(el);
            el.classList.add('is-entering');
            shown++;
        } while (shown < steps.length && isPassive(el));

        syncAdvance();
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function startStepping() {
        if (steps.length === 0) {
            return;
        }

        steps.forEach(hide);

        // A finished stage is re-read from the top — a case is a conversation
        // the second time too. A half-finished one resumes, rather than making
        // the student click back through what they have already read.
        var upto = isReplay ? -1 : resumeIndex();
        for (var i = 0; i <= upto; i++) {
            show(steps[i]);
        }
        shown = upto + 1;

        syncAdvance();

        // Tells the guard in the page that the player is running, so it does
        // not put the scene back.
        if (scene) {
            scene.setAttribute('data-stepping', 'ready');
        }
    }

    if (advance) {
        advance.addEventListener('click', function (event) {
            if (event.target.closest('.js-balin-next')) {
                next();
            }
        });
    }

    startStepping();

    /* ------------------------------------------------------------- hints */

    root.addEventListener('click', function (event) {
        var trigger = event.target.closest('.js-balin-hint');
        if (!trigger) {
            return;
        }

        var box = trigger.parentElement.querySelector('.balin-hint-text');
        if (box) {
            box.hidden = false;
        }
        trigger.hidden = true;

        // The penalty is applied server-side from this flag; revealing the
        // hint client-side alone would be trivially skippable.
        var question = trigger.closest('.balin-question');
        if (question) {
            question.setAttribute('data-used-hint', '1');
        }
    });

    /* --------------------------------------------------------- answering */

    root.addEventListener('click', function (event) {
        var option = event.target.closest('.balin-option');
        if (!option || option.disabled) {
            return;
        }

        var question = option.closest('.balin-question');
        if (!question || question.classList.contains('is-answered') || question.dataset.busy === '1') {
            return;
        }

        submit(question, option);
    });

    function submit(question, option) {
        question.dataset.busy = '1';
        setDisabled(question, true);
        option.classList.add('is-chosen', 'is-pending');

        var body = new URLSearchParams();
        body.set('_token', csrf);
        body.set('question_id', question.getAttribute('data-question'));
        body.set('option_id', option.getAttribute('data-option'));
        body.set('used_hint', question.getAttribute('data-used-hint') === '1' ? '1' : '0');

        fetch('/student/balin/stage/' + encodeURIComponent(stageUuid) + '/answer', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                // Lets a retried request land as the same answer rather than
                // a second one, should the connection drop mid-flight.
                'Idempotency-Key': stageUuid + ':' + question.getAttribute('data-question')
            },
            body: body.toString(),
            credentials: 'same-origin'
        })
            .then(function (response) {
                // A refusal from the server is not a broken connection, and
                // saying so sends the student to check their wifi over a
                // problem only the page can explain. Read the body either way.
                return response.json()
                    .catch(function () { return null; })
                    .then(function (payload) {
                        return { ok: response.ok, status: response.status, payload: payload };
                    });
            })
            .then(function (result) {
                option.classList.remove('is-pending');

                if (!result.ok || !result.payload || result.payload.ok === false) {
                    recover(question, option);
                    showError(question, messageFor(result));
                    return;
                }

                render(question, option, result.payload);
            })
            .catch(function () {
                // Only a genuine transport failure reaches here.
                recover(question, option);
                showError(question, 'ارتباط با سرور برقرار نشد. اتصالت را بررسی کن و دوباره تلاش کن.');
            });
    }

    /** Turns a failed response into something a student can act on. */
    function messageFor(result) {
        if (result.payload && result.payload.message) {
            return result.payload.message;
        }
        if (result.status === 419) {
            return 'اعتبار صفحه تمام شده. صفحه را تازه کن و دوباره تلاش کن.';
        }
        if (result.status === 429) {
            return 'کمی سریع پیش رفتی. چند لحظه صبر کن و دوباره تلاش کن.';
        }
        if (result.status === 403) {
            return 'این مرحله برای تو باز نیست.';
        }
        return 'ثبت پاسخ ناموفق بود (خطای ' + result.status + ').';
    }

    function recover(question, option) {
        option.classList.remove('is-chosen');
        setDisabled(question, false);
        question.dataset.busy = '';
    }

    function render(question, option, result) {
        question.dataset.busy = '';
        question.classList.add('is-answered');

        var feedback = question.querySelector('.balin-feedback');
        if (!feedback) {
            return;
        }

        markOptions(question, option, result);

        feedback.hidden = false;
        feedback.innerHTML = '';

        var line = document.createElement('div');
        line.className = 'balin-feedback-line ' + (result.is_correct ? 'is-correct' : 'is-wrong');

        if (result.already_answered) {
            line.textContent = 'به این سؤال قبلاً پاسخ داده‌ای.';
        } else if (result.is_correct) {
            line.textContent = result.xp_awarded > 0
                ? '✅ پاسخ درست بود · ' + toFa(result.xp_awarded) + '+ امتیاز'
                : '✅ پاسخ درست بود';
        } else {
            line.textContent = '❌ پاسخ صحیح نبود';
        }
        feedback.appendChild(line);

        if (result.explanation) {
            var explanation = document.createElement('div');
            explanation.className = 'balin-explanation';
            explanation.textContent = result.explanation;
            feedback.appendChild(explanation);
        }

        if (result.levelled_up) {
            announceLevelUp(result);
        }

        updateFinishButton(result.requirements_met);
        // The question is settled, so the conversation may go on.
        syncAdvance();
    }

    /** Marks the chosen option and, when it was wrong, the one that was right. */
    function markOptions(question, chosen, result) {
        chosen.classList.add(result.is_correct ? 'is-correct' : 'is-wrong');

        if (!result.is_correct && result.correct_option_id) {
            var right = question.querySelector('.balin-option[data-option="' + result.correct_option_id + '"]');
            if (right) {
                right.classList.add('is-correct');
            }
        }
    }

    function updateFinishButton(met) {
        var button = document.querySelector('.js-balin-finish');
        var note   = document.querySelector('.js-balin-remaining');

        if (button) {
            button.disabled = !met;
        }
        if (note) {
            note.hidden = !!met;
        }
    }

    function setDisabled(question, disabled) {
        question.querySelectorAll('.balin-option').forEach(function (button) {
            button.disabled = disabled;
        });
    }

    function showError(question, message) {
        var feedback = question.querySelector('.balin-feedback');
        if (!feedback) {
            return;
        }
        feedback.hidden = false;
        feedback.innerHTML = '';

        var line = document.createElement('div');
        line.className = 'balin-feedback-line is-wrong';
        line.textContent = message;
        feedback.appendChild(line);
    }

    /**
     * A level-up is worth noticing but not worth interrupting a case for, so
     * it is a small banner that leaves on its own rather than a modal.
     */
    function announceLevelUp(result) {
        var banner = document.createElement('div');
        banner.className = 'balin-levelup';
        banner.setAttribute('role', 'status');
        banner.textContent = '🎉 سطح ' + toFa(result.level)
            + (result.rank ? ' — ' + result.rank : '');

        document.body.appendChild(banner);

        window.setTimeout(function () {
            banner.classList.add('is-leaving');
            window.setTimeout(function () {
                banner.remove();
            }, 400);
        }, 3200);
    }

    var DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    function toFa(value) {
        return String(value).replace(/[0-9]/g, function (digit) {
            return DIGITS[Number(digit)];
        });
    }
}());
