<?php
/**
 * Gateway credentials, code policy and a live test — the three things an
 * operator needs to run SMS sign-in without opening a file on the server.
 *
 * The API key is rendered as a mask, never as its value. The field is
 * pre-filled with a marker the controller recognises as "unchanged", so
 * saving the form after editing the sender number does not require retyping
 * the credential and does not overwrite it with asterisks either.
 */
$hasKey = !empty($sms['has_api_key']);
?>
<div class="card" style="max-width:820px;">
    <h3 class="card-title">تنظیمات پیامک (ملی پیامک)</h3>
    <p style="color:var(--ink-3); font-size:13px; margin:-8px 0 18px;">
        این اطلاعات فقط روی سرور استفاده می‌شود و هرگز به مرورگر فرستاده نمی‌شود.
        کلید API به‌صورت رمزنگاری‌شده در دیتابیس ذخیره می‌شود و در این صفحه فقط ماسک‌شده دیده می‌شود.
    </p>

    <?php if (empty($cryptoAvailable)): ?>
        <div class="alert alert-error">
            رمزنگاری روی این سرور در دسترس نیست (افزونه OpenSSL یا کلید برنامه). تا رفع این مشکل،
            کلید API قابل ذخیره نیست.
        </div>
    <?php endif; ?>

    <?php
    /* A one-line answer to "is this working?", so the operator does not have
       to infer it from three separate fields. */
    if (!empty($sms['operational'])): ?>
        <div class="alert alert-success">ارسال پیامک فعال و آماده است.</div>
    <?php elseif (!empty($sms['configured'])): ?>
        <div class="alert alert-success" style="background:#fffbeb; color:#92400e; border-color:#fde68a;">
            تنظیمات کامل است اما کلید «فعال بودن ارسال پیامک» خاموش است.
        </div>
    <?php else: ?>
        <div class="alert alert-success" style="background:#fffbeb; color:#92400e; border-color:#fde68a;">
            تنظیمات کامل نیست. تا زمانی که نام کاربری، کلید API و شماره فرستنده ذخیره نشوند،
            ورود با کد پیامکی کار نمی‌کند.
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/sms">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

        <h4 class="card-title">اتصال به ملی پیامک</h4>

        <label class="switch-row">
            <input type="checkbox" name="sms_enabled" value="1" <?= !empty($sms['enabled']) ? 'checked' : '' ?>>
            <span>فعال بودن ارسال پیامک</span>
        </label>

        <div class="form-grid">
            <div class="field">
                <label class="label" for="sms_username">نام کاربری ملی پیامک</label>
                <input class="input" type="text" id="sms_username" name="sms_username" dir="ltr"
                       autocomplete="off" value="<?= e($sms['username'] ?? '') ?>">
            </div>

            <div class="field">
                <label class="label" for="sms_from">شماره فرستنده</label>
                <input class="input" type="text" id="sms_from" name="sms_from" dir="ltr"
                       autocomplete="off" placeholder="50002..." value="<?= e($sms['from'] ?? '') ?>">
            </div>

            <div class="field">
                <label class="label" for="sms_api_key">کلید API (رمز وب‌سرویس)</label>
                <?php
                /* Deliberately never pre-filled, not even with a marker: a form
                   that carries a credential puts it into autofill, browser
                   history and every screenshot of this page. */
                ?>
                <input class="input" type="password" id="sms_api_key" name="sms_api_key" dir="ltr"
                       autocomplete="new-password"
                       placeholder="<?= $hasKey ? 'برای تغییر، کلید جدید را وارد کن' : 'کلید API را وارد کن' ?>">
                <div style="color:var(--ink-3); font-size:11.5px; margin-top:4px;">
                    <?php if ($hasKey): ?>
                        کلید فعلی: <span class="mono" dir="ltr"><?= e($sms['api_key_masked']) ?></span>
                        — این فیلد را خالی بگذار تا کلید فعلی حفظ شود.
                    <?php else: ?>
                        در پنل ملی پیامک، این همان رمز وب‌سرویس است.
                    <?php endif; ?>
                </div>

                <?php if ($hasKey): ?>
                    <label class="switch-row" style="margin-top:10px;">
                        <input type="checkbox" name="sms_api_key_clear" value="1">
                        <span>کلید فعلی حذف شود</span>
                    </label>
                <?php endif; ?>
            </div>
        </div>

        <h4 class="card-title" style="margin-top:24px;">کد یکبارمصرف (OTP)</h4>

        <label class="switch-row">
            <input type="checkbox" name="otp_enabled" value="1" <?= !empty($otp['enabled']) ? 'checked' : '' ?>>
            <span>فعال بودن ورود با کد پیامکی</span>
        </label>
        <label class="switch-row">
            <input type="checkbox" name="otp_registration_enabled" value="1" <?= !empty($otp['registration_enabled']) ? 'checked' : '' ?>>
            <span>ثبت‌نام خودکار شماره‌های جدید (اگر خاموش باشد، فقط دانشجویان موجود می‌توانند وارد شوند)</span>
        </label>

        <div class="form-grid">
            <?php
            $labels = [
                'otp_ttl_seconds'         => 'مدت اعتبار کد (ثانیه)',
                'otp_resend_seconds'      => 'فاصله تا ارسال مجدد (ثانیه)',
                'otp_max_attempts'        => 'حداکثر تلاش نادرست برای هر کد',
                'otp_max_per_hour'        => 'حداکثر کد در ساعت برای هر شماره',
                'otp_max_per_ip_per_hour' => 'حداکثر کد در ساعت برای هر IP',
            ];
            foreach ($labels as $key => $label):
                [$min, $max] = $ranges[$key];
            ?>
                <div class="field">
                    <label class="label" for="<?= e($key) ?>"><?= e($label) ?></label>
                    <input class="input" type="number" id="<?= e($key) ?>" name="<?= e($key) ?>" dir="ltr"
                           min="<?= (int) $min ?>" max="<?= (int) $max ?>" value="<?= (int) $otp[$key] ?>">
                    <div style="color:var(--ink-3); font-size:11.5px; margin-top:4px;">
                        بازه مجاز: <?= e(fa((string) $min)) ?> تا <?= e(fa((string) $max)) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="field">
            <label class="label" for="otp_message_template">متن پیامک کد</label>
            <input class="input" type="text" id="otp_message_template" name="otp_message_template"
                   maxlength="400" value="<?= e($otp['template'] ?? '') ?>">
            <div style="color:var(--ink-3); font-size:11.5px; margin-top:4px;">
                عبارت <span class="mono" dir="ltr">{code}</span> هنگام ارسال با کد واقعی جایگزین می‌شود.
                اگر آن را ننویسی، به انتهای متن اضافه می‌شود.
            </div>
        </div>

        <button class="btn btn-primary" type="submit">ذخیره تنظیمات</button>
    </form>
</div>

<div class="card" style="max-width:820px; margin-top:18px;">
    <h3 class="card-title">تست ارسال پیامک</h3>
    <p style="color:var(--ink-3); font-size:13px; margin:-8px 0 16px;">
        یک پیامک واقعی به شماره‌ای که وارد می‌کنی فرستاده می‌شود و از اعتبار پنل ملی پیامک کم می‌کند.
        این تست حتی وقتی کلید «فعال بودن ارسال پیامک» خاموش است هم کار می‌کند، تا بتوانی قبل از
        روشن کردن آن از درستی تنظیمات مطمئن شوی.
    </p>

    <form method="post" action="/admin/sms/test" class="filters" style="margin:0;">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <div class="field" style="margin:0;">
            <label class="label" for="test_phone">شماره موبایل آزمایشی</label>
            <input class="input" type="tel" id="test_phone" name="test_phone" dir="ltr"
                   inputmode="tel" placeholder="09123456789" required>
        </div>
        <button class="btn btn-ghost" type="submit"
                <?= empty($sms['configured']) ? 'disabled' : '' ?>>ارسال پیامک آزمایشی</button>
    </form>

    <?php if (empty($sms['configured'])): ?>
        <p style="color:var(--ink-3); font-size:12px; margin-top:12px;">
            برای فعال شدن این دکمه، ابتدا نام کاربری، کلید API و شماره فرستنده را ذخیره کن.
        </p>
    <?php endif; ?>
</div>
