/**
 * 🏝️ جزیره بالین — the clinical case player.
 *
 * Answering is a round trip on purpose. The correct option is never in the
 * page for an unanswered question, so the only way to learn it is to commit
 * to a choice first. What comes back is the verdict, the right answer and
 * the explanation — which is the teaching moment, and the reason a wrong
 * answer is not a dead end.
 *
 * Everything degrades: with no JavaScript the page still reads as a case,
 * and the buttons simply do nothing rather than lying about having worked.
 */
(function () {
    'use strict';

    var root = document.querySelector('.balin-stage');
    if (!root) {
        return;
    }

    var stageUuid = root.getAttribute('data-stage');
    var csrf      = root.getAttribute('data-csrf');

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
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (result) {
                option.classList.remove('is-pending');
                render(question, option, result);
            })
            .catch(function () {
                option.classList.remove('is-chosen', 'is-pending');
                setDisabled(question, false);
                question.dataset.busy = '';
                showError(question, 'ثبت پاسخ ناموفق بود. اتصالت را بررسی کن و دوباره تلاش کن.');
            });
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
