<div class="grid grid-3">
    <div class="card">
        <div class="stat-label">حساب‌های متصل</div>
        <div class="stat-value"><?= e(fa((string) $stats['linked'])) ?></div>
    </div>
    <div class="card">
        <div class="stat-label">فعال امروز</div>
        <div class="stat-value"><?= e(fa((string) $stats['active_today'])) ?></div>
    </div>
    <div class="card">
        <div class="stat-label">پیام ارسال‌شده امروز</div>
        <div class="stat-value"><?= e(fa((string) $stats['sent_today'])) ?></div>
    </div>
    <div class="card">
        <div class="stat-label">یادآوری‌های امروز</div>
        <div class="stat-value"><?= e(fa((string) $stats['reminders_today'])) ?></div>
        <div class="leaf-meta">برنامه فردا + امتحانات پیش‌رو</div>
    </div>
    <div class="card">
        <div class="stat-label">صف ارسال</div>
        <div class="stat-value" style="color: <?= $stats['queue']['pending'] > 0 ? 'var(--amber)' : 'var(--green)' ?>">
            <?= e(fa((string) $stats['queue']['pending'])) ?>
        </div>
        <div class="leaf-meta">
            ارسال‌شده: <?= e(fa((string) $stats['queue']['sent'])) ?> ·
            ناموفق: <?= e(fa((string) $stats['queue']['failed'])) ?>
        </div>
    </div>
    <div class="card">
        <div class="stat-label">اطلاع‌رسانی فعال</div>
        <div class="leaf-meta" style="line-height:2;">
            کلی: <?= e(fa((string) $stats['preferences']['general'])) ?> ·
            برنامه: <?= e(fa((string) $stats['preferences']['schedule'])) ?> ·
            امتحان: <?= e(fa((string) $stats['preferences']['exams'])) ?><br>
            اطلاعیه: <?= e(fa((string) $stats['preferences']['announcements'])) ?> ·
            پشتیبانی: <?= e(fa((string) $stats['preferences']['support'])) ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top:16px; max-width:680px;">
    <h3 class="card-title">اتصال ربات</h3>

    <?php if (!$siteConfigured): ?>
        <div class="alert alert-error">
            آدرس سایت در فایل تنظیمات نصب (config/config.php → app.url) خالی است.
            ثبت webhook بدون آن ممکن نیست چون تلگرام باید بداند کجا پیام بفرستد.
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/telegram">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

        <div class="field">
            <label class="label" for="telegram_bot_token">توکن ربات</label>
            <input class="input" type="password" id="telegram_bot_token" name="telegram_bot_token" dir="ltr"
                   autocomplete="off" placeholder="مثلاً 8532764649:AAH...."
                   value="<?= e($token) ?>">
            <div style="color:var(--ink-3); font-size:11.5px; margin-top:4px;">
                از <a href="https://t.me/BotFather" target="_blank" rel="noopener">BotFather@</a> داخل تلگرام
                با دستور <span class="mono">/newbot</span> بگیرید و اینجا بچسبانید. این مقدار فقط در دیتابیس
                ذخیره می‌شود، هرگز داخل هیچ فایل کدی نیست.
            </div>
        </div>

        <label class="switch-row">
            <input type="checkbox" name="telegram_bot_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
            <span>ربات فعال باشد</span>
        </label>

        <button class="btn btn-primary" type="submit" style="margin-top:12px;">ذخیره</button>
    </form>

    <?php if ($token !== ''): ?>
        <div class="row-actions" style="margin-top:16px; padding-top:16px; border-top:1px solid var(--line);">
            <form method="post" action="/admin/telegram/test" style="margin:0;">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button class="btn btn-ghost btn-sm" type="submit">تست اتصال</button>
            </form>
            <form method="post" action="/admin/telegram/webhook/set" style="margin:0;">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button class="btn btn-primary btn-sm" type="submit">فعال‌سازی webhook</button>
            </form>
            <form method="post" action="/admin/telegram/webhook/remove" style="margin:0;"
                  data-confirm="ربات دیگر پیام دریافت نمی‌کند. مطمئنید؟">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button class="btn btn-danger btn-sm" type="submit">حذف webhook</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($username !== ''): ?>
        <p style="margin-top:14px; font-size:13px;">
            ربات شما: <a href="https://t.me/<?= e($username) ?>" target="_blank" rel="noopener">@<?= e($username) ?></a>
        </p>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:16px; max-width:680px;">
    <h3 class="card-title">مراحل راه‌اندازی</h3>
    <ol style="font-size:13px; color:var(--ink-2); line-height:2.1; padding-inline-start:18px; margin:0;">
        <li>در تلگرام به <span class="mono">@BotFather</span> پیام دهید و با <span class="mono">/newbot</span> یک ربات بسازید.</li>
        <li>توکنی که می‌دهد را بالا بچسبانید، «ربات فعال باشد» را بزنید و ذخیره کنید.</li>
        <li>«تست اتصال» را بزنید تا مطمئن شوید توکن درست است.</li>
        <li>«فعال‌سازی webhook» را بزنید — از این لحظه ربات پیام‌ها را دریافت می‌کند.</li>
        <li>در تلگرام ربات خودتان را باز کنید و <span class="mono">/start</span> بزنید.</li>
    </ol>
</div>

<div class="card" style="margin-top:16px; max-width:680px;">
    <h3 class="card-title">یادآوری‌های خودکار (نیاز به تنظیم Cron)</h3>
    <p style="color:var(--ink-3); font-size:12.5px; margin:-6px 0 14px;">
        صف عادی پیام‌ها با ترافیک سایت خودش خالی می‌شود، اما یادآوری‌ها باید دقیقاً در
        یک ساعت مشخص فرستاده شوند، نه فقط «هر وقت کسی سایت را باز کرد». برای این کار
        باید از پنل دایرکت‌ادمین یک Cron Job واقعی بسازید:
    </p>
    <ol style="font-size:13px; color:var(--ink-2); line-height:2.1; padding-inline-start:18px; margin:0 0 12px;">
        <li>در دایرکت‌ادمین وارد بخش <b>Cron Jobs</b> شوید.</li>
        <li>یک ورودی جدید با زمان <span class="mono">0 22 * * *</span> (هر شب ساعت ۲۲:۰۰) و دستور زیر بسازید تا برنامه فردا فرستاده شود:</li>
    </ol>
    <pre style="background:var(--canvas); border:1px solid var(--line); border-radius:10px; padding:10px 12px; font-size:12px; direction:ltr; text-align:left; overflow-x:auto; margin:0 0 12px;">php <?= e(dirname($_SERVER['DOCUMENT_ROOT'] ?? '/home/user/domains/example.com/public_html')) ?>/cron/telegram_reminders.php schedule</pre>
    <ol start="3" style="font-size:13px; color:var(--ink-2); line-height:2.1; padding-inline-start:18px; margin:0 0 12px;">
        <li>یک ورودی دیگر با زمان <span class="mono">0 9 * * *</span> (هر روز ساعت ۹ صبح) برای یادآوری امتحانات:</li>
    </ol>
    <pre style="background:var(--canvas); border:1px solid var(--line); border-radius:10px; padding:10px 12px; font-size:12px; direction:ltr; text-align:left; overflow-x:auto; margin:0;">php <?= e(dirname($_SERVER['DOCUMENT_ROOT'] ?? '/home/user/domains/example.com/public_html')) ?>/cron/telegram_reminders.php exams</pre>
    <p style="color:var(--ink-3); font-size:11.5px; margin:12px 0 0;">
        اجرای دوباره‌ی این دستور در یک روز هیچ پیام تکراری نمی‌فرستد — هر یادآوری فقط
        یک‌بار در روز برای هر دانشجو ساخته می‌شود، حتی اگر Cron چند بار اجرا شود.
    </p>
</div>
