/**
 * The sign-in card: tab switching, and the two-or-three step conversation
 * that a texted code requires.
 *
 * Only loaded on the auth pages. Nothing here is a security control — every
 * limit it displays (the cooldown, the attempts left) is enforced again on the
 * server, which is the copy that counts. The countdown exists so the student
 * knows why the button is disabled, not to stop them pressing it.
 */
(function () {
    'use strict';

    var DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    function fa(value) {
        return String(value).replace(/[0-9]/g, function (d) { return DIGITS[Number(d)]; });
    }

    /** Persian and Arabic-Indic digits back to ASCII, so typing either works. */
    function toLatin(value) {
        return String(value)
            .replace(/[۰-۹]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); })
            .replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); });
    }

    var meta  = document.querySelector('meta[name="csrf-token"]');
    var TOKEN = meta ? meta.getAttribute('content') : '';

    /* ----------------------------------------------------------- tabs */

    var tabs = document.querySelectorAll('[data-auth-tab]');

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var name = tab.getAttribute('data-auth-tab');

            tabs.forEach(function (other) {
                var on = other === tab;
                other.classList.toggle('is-active', on);
                other.setAttribute('aria-selected', on ? 'true' : 'false');
            });

            document.querySelectorAll('[data-auth-panel]').forEach(function (panel) {
                var on = panel.getAttribute('data-auth-panel') === name;
                panel.classList.toggle('is-active', on);
                panel.hidden = !on;
            });

            var focusable = document.querySelector('[data-auth-panel="' + name + '"] input');
            if (focusable) { focusable.focus({ preventScroll: true }); }
        });
    });

    /* ------------------------------------------------------ OTP forms */

    document.querySelectorAll('[data-otp]').forEach(function (form) {
        setupOtpForm(form);
    });

    function setupOtpForm(form) {
        var isReset = form.getAttribute('data-mode') === 'reset';

        var urls = {
            request: form.getAttribute('data-request-url'),
            verify:  form.getAttribute('data-verify-url'),
            reset:   form.getAttribute('data-reset-url')
        };
        var resendSeconds = parseInt(form.getAttribute('data-resend'), 10) || 60;

        var phoneStep    = form.querySelector('[data-otp-step="phone"]');
        var codeStep     = form.querySelector('[data-otp-step="code"]');
        var passwordStep = form.querySelector('[data-otp-step="password"]');

        var phoneInput = phoneStep ? phoneStep.querySelector('input') : null;
        var codeInput  = codeStep ? codeStep.querySelector('input') : null;

        var submit    = form.querySelector('[data-otp-submit]');
        var message   = form.querySelector('[data-otp-message]');
        var target    = form.querySelector('[data-otp-target]');
        var resendBox = form.querySelector('[data-otp-resend]');
        var countdown = form.querySelector('[data-otp-countdown]');
        var resendBtn = form.querySelector('[data-otp-resend-btn]');
        var changeBtn = form.querySelector('[data-otp-change]');

        // 'phone' -> 'code' -> ('password' on the reset flow) -> done
        var step   = 'phone';
        var phone  = '';
        var ticket = '';
        var timer  = null;
        var busy   = false;

        /* ------------------------------------------------------ display */

        function say(text, kind) {
            if (!message) { return; }
            message.textContent = text;
            message.className = 'otp-alert is-' + (kind || 'info');
            message.hidden = text === '';
        }

        function setBusy(state) {
            busy = state;
            if (submit) {
                submit.disabled = state;
                submit.classList.toggle('is-busy', state);
            }
        }

        function showStep(next) {
            step = next;
            if (phoneStep)    { phoneStep.hidden    = next !== 'phone'; }
            if (codeStep)     { codeStep.hidden     = next !== 'code'; }
            if (passwordStep) { passwordStep.hidden = next !== 'password'; }

            if (submit) {
                submit.textContent = next === 'phone'
                    ? (isReset ? 'ارسال کد بازیابی' : 'دریافت کد')
                    : next === 'code' ? 'تأیید کد' : 'ثبت رمز عبور جدید';
            }
            if (resendBox) { resendBox.hidden = next !== 'code'; }

            var focusable = next === 'phone' ? phoneInput
                : next === 'code' ? codeInput
                : (passwordStep ? passwordStep.querySelector('input') : null);
            if (focusable) { focusable.focus({ preventScroll: true }); }
        }

        /* ---------------------------------------------------- countdown */

        function startCountdown(seconds) {
            if (timer) { window.clearInterval(timer); }

            var left = seconds;

            function tick() {
                if (left <= 0) {
                    window.clearInterval(timer);
                    timer = null;
                    if (countdown) { countdown.textContent = ''; }
                    if (resendBtn) { resendBtn.hidden = false; }
                    return;
                }
                if (countdown) {
                    countdown.textContent = 'ارسال مجدد کد تا ' + fa(left) + ' ثانیه دیگر';
                }
                left--;
            }

            if (resendBtn) { resendBtn.hidden = true; }
            tick();
            timer = window.setInterval(tick, 1000);
        }

        /* -------------------------------------------------------- calls */

        function post(url, payload) {
            var body = new URLSearchParams();
            body.set('_token', TOKEN);
            Object.keys(payload).forEach(function (key) { body.set(key, payload[key]); });

            return fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type':     'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token':     TOKEN
                },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (response) {
                // A refusal carries a message worth reading, so the body is
                // parsed either way. An unreadable body is reported as a
                // server problem rather than as a network one — telling a
                // student to check their wifi over a 500 sends them nowhere.
                return response.json()
                    .catch(function () { return null; })
                    .then(function (payload) {
                        return { ok: response.ok, status: response.status, data: payload };
                    });
            });
        }

        function messageFrom(result, fallback) {
            if (result.data && result.data.message) { return result.data.message; }
            if (result.status === 419) { return 'اعتبار صفحه تمام شده. صفحه را تازه کن و دوباره تلاش کن.'; }
            if (result.status === 429) { return 'تعداد درخواست‌ها زیاد بوده است. کمی صبر کن.'; }
            return fallback;
        }

        /* ------------------------------------------------------ actions */

        function requestCode(isResend) {
            var typed = toLatin(phoneInput ? phoneInput.value : '').trim();

            if (!/^(\+?98|0098)?0?9\d{9}$/.test(typed.replace(/[\s\-()]/g, ''))) {
                say('شماره موبایل معتبر نیست. نمونه درست: ۰۹۱۲۳۴۵۶۷۸۹', 'error');
                return;
            }

            setBusy(true);
            say(isResend ? 'در حال ارسال دوباره کد…' : 'در حال ارسال کد…', 'info');

            post(urls.request, { phone: typed }).then(function (result) {
                setBusy(false);

                if (!result.ok || !result.data || result.data.ok !== true) {
                    say(messageFrom(result, 'ارسال کد ناموفق بود. دوباره تلاش کن.'), 'error');

                    // A cooldown is not a failure to recover from, it is a
                    // wait: show the timer instead of leaving a dead button.
                    if (result.status === 429 && result.data && result.data.retry_after) {
                        if (step === 'code') { startCountdown(result.data.retry_after); }
                    }
                    return;
                }

                phone = typed;
                if (target) { target.textContent = 'کد به ' + fa(phone) + ' فرستاده شد'; }
                if (codeInput) { codeInput.value = ''; }

                showStep('code');
                startCountdown(result.data.retry_after || resendSeconds);
                say(result.data.message || 'کد تأیید پیامک شد.', 'success');
            }).catch(function () {
                setBusy(false);
                say('ارتباط با سرور برقرار نشد. اتصالت را بررسی کن.', 'error');
            });
        }

        function verifyCode() {
            var code = toLatin(codeInput ? codeInput.value : '').replace(/\D/g, '');

            if (code.length !== 6) {
                say('کد باید ۶ رقم باشد.', 'error');
                return;
            }

            setBusy(true);
            say('در حال بررسی کد…', 'info');

            var payload = { phone: phone, code: code };
            if (!isReset) {
                var remember = form.querySelector('[name="remember"]');
                payload.remember = (remember && remember.checked) ? '1' : '0';
            }

            post(urls.verify, payload).then(function (result) {
                setBusy(false);

                if (!result.ok || !result.data || result.data.ok !== true) {
                    say(messageFrom(result, 'تأیید کد ناموفق بود.'), 'error');
                    if (codeInput) { codeInput.select(); }
                    return;
                }

                if (isReset) {
                    ticket = result.data.ticket || '';
                    if (timer) { window.clearInterval(timer); timer = null; }
                    showStep('password');
                    say('شماره تأیید شد. حالا رمز عبور جدیدت را انتخاب کن.', 'success');
                    return;
                }

                say('وارد شدی. در حال انتقال…', 'success');
                window.location.assign(result.data.redirect || '/');
            }).catch(function () {
                setBusy(false);
                say('ارتباط با سرور برقرار نشد. اتصالت را بررسی کن.', 'error');
            });
        }

        function submitNewPassword() {
            var pass    = passwordStep.querySelector('[name="password"]');
            var confirm = passwordStep.querySelector('[name="password_confirmation"]');
            var minimum = parseInt(form.getAttribute('data-min-length'), 10) || 10;

            if (!pass || pass.value.length < minimum) {
                say('رمز عبور باید حداقل ' + fa(minimum) + ' کاراکتر باشد.', 'error');
                return;
            }
            if (!confirm || pass.value !== confirm.value) {
                say('تکرار رمز عبور مطابقت ندارد.', 'error');
                return;
            }

            setBusy(true);
            say('در حال ثبت رمز جدید…', 'info');

            post(urls.reset, {
                ticket: ticket,
                password: pass.value,
                password_confirmation: confirm.value
            }).then(function (result) {
                setBusy(false);

                if (!result.ok || !result.data || result.data.ok !== true) {
                    say(messageFrom(result, 'ثبت رمز جدید ناموفق بود.'), 'error');
                    return;
                }

                say(result.data.message || 'رمز عبور تغییر کرد.', 'success');
                window.setTimeout(function () { window.location.assign('/login'); }, 1500);
            }).catch(function () {
                setBusy(false);
                say('ارتباط با سرور برقرار نشد. اتصالت را بررسی کن.', 'error');
            });
        }

        /* ------------------------------------------------------- wiring */

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (busy) { return; }

            if (step === 'phone')         { requestCode(false); }
            else if (step === 'code')     { verifyCode(); }
            else if (step === 'password') { submitNewPassword(); }
        });

        if (resendBtn) {
            resendBtn.addEventListener('click', function () {
                if (!busy) { requestCode(true); }
            });
        }

        if (changeBtn) {
            changeBtn.addEventListener('click', function () {
                if (timer) { window.clearInterval(timer); timer = null; }
                say('', 'info');
                showStep('phone');
            });
        }

        // Codes are digits. Stripping anything else as it is typed means a
        // pasted "کد: 123456" still lands as 123456 rather than failing.
        if (codeInput) {
            codeInput.addEventListener('input', function () {
                var cleaned = toLatin(codeInput.value).replace(/\D/g, '').slice(0, 6);
                if (cleaned !== codeInput.value) { codeInput.value = cleaned; }
                if (cleaned.length === 6 && !busy) { verifyCode(); }
            });
        }

        if (phoneInput) {
            phoneInput.addEventListener('input', function () {
                var cleaned = toLatin(phoneInput.value);
                if (cleaned !== phoneInput.value) { phoneInput.value = cleaned; }
            });
        }
    }
}());
