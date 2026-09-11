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
    var label   = root.querySelector('[data-advance-label]');

    /* --------------------------------------------------- stepped reveal */

    var steps   = scene ? Array.prototype.slice.call(scene.children) : [];
    var shown   = 0;   // how many blocks are currently visible

    /** A block the student must deal with before the scene may continue. */
    function isOpenQuestion(el) {
        return !!el
            && el.classList.contains('balin-question')
            && !el.classList.contains('is-answered');
    }

    function lastAnsweredIndex() {
        var last = -1;
        steps.forEach(function (el, index) {
            if (el.classList.contains('balin-question') && el.classList.contains('is-answered')) {
                last = index;
            }
        });
        return last;
    }

    function revealThrough(index) {
        for (var i = 0; i <= index && i < steps.length; i++) {
            steps[i].hidden = false;
        }
        shown = Math.min(index + 1, steps.length);
        syncAdvance();
    }

    /**
     * The button is only offered when there is something to advance to and
     * nothing is waiting on the student. An unanswered question hides it,
     * so the only way forward is to answer.
     */
    function syncAdvance() {
        if (!advance) {
            return;
        }

        var finished = shown >= steps.length;
        var waiting  = isOpenQuestion(steps[shown - 1]);

        advance.hidden = finished || waiting;
        if (label) {
            label.textContent = shown === 0 ? 'شروع گفت‌وگو' : 'ادامه گفت‌وگو';
        }
    }

    function next() {
        if (shown >= steps.length) {
            return;
        }

        var el = steps[shown];
        el.hidden = false;
        el.classList.add('is-entering');
        shown++;

        syncAdvance();
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function startStepping() {
        // A finished stage is being re-read, and a scene with nothing to
        // reveal has nothing to step through.
        if (isReplay || steps.length === 0) {
            return;
        }

        steps.forEach(function (el) { el.hidden = true; });

        // Coming back to a half-finished stage picks up where it was left,
        // rather than making the student click through what they already read.
        revealThrough(lastAnsweredIndex());
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
