<?php
/**
 * @var bool        $enabled
 * @var bool        $registration
 * @var bool        $hasToken
 * @var string      $username
 * @var string      $webhook
 * @var array|null  $info   getWebhookInfo
 * @var array|null  $me     getMe
 * @var array|null  $stats
 * @var bool        $https
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$hookOk = is_array($info) && ($info['url'] ?? '') === $webhook;
?>
<div class="ad-page sa">
    <section class="ad-hero tone-sky">
        <div>
            <h2>ورود و ثبت‌نام با تلگرام</h2>
            <p>دانشجو ربات را استارت می‌کند، شماره خودش را با دکمه «ارسال شماره» تلگرام می‌فرستد (نه تایپ‌کردنی)، و با لینک یک‌بارمصرف
               در سایت نام کاربری و رمز می‌سازد. از آن به بعد با شماره یا نام کاربری و رمز وارد می‌شود؛ فراموشی رمز هم از همین ربات است.</p>
        </div>
        <?php if ($username !== ''): ?>
            <div class="ad-quick"><a class="ad-quick-btn" href="https://t.me/<?= e($username) ?>" target="_blank" rel="noopener"><?php $icon('send', 16); ?> @<?= e($username) ?></a></div>
        <?php endif; ?>
    </section>

    <?php if ($stats): ?>
        <div class="ad-stats">
            <div class="ad-stat"><span class="app-ic tone-sky"><?php $icon('send', 20); ?></span><span><b><?= e(fa((string) $stats['started'])) ?></b><small>ربات را استارت کرده‌اند</small></span></div>
            <div class="ad-stat"><span class="app-ic tone-green"><?php $icon('check', 20); ?></span><span><b><?= e(fa((string) $stats['verified'])) ?></b><small>شماره تأییدشده</small></span></div>
            <div class="ad-stat"><span class="app-ic tone-violet"><?php $icon('users', 20); ?></span><span><b><?= e(fa((string) $stats['registered'])) ?></b><small>ثبت‌نام با تلگرام</small></span></div>
            <div class="ad-stat"><span class="app-ic tone-amber"><?php $icon('link', 20); ?></span><span><b><?= e(fa((string) $stats['linked'])) ?></b><small>حساب وصل به تلگرام</small></span></div>
        </div>
    <?php endif; ?>

    <div class="ad-two">
        <form class="ad-card" method="post" action="/admin/telegram" autocomplete="off">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <header class="ad-card-head">
                <span class="app-ic tone-sky"><?php $icon('settings'); ?></span>
                <div><h3>تنظیمات ربات</h3><p>در تلگرام به <b dir="ltr">@BotFather</b> پیام بدهید، با <code dir="ltr">/newbot</code> ربات بسازید و توکنی را که می‌دهد این‌جا بگذارید.</p></div>
            </header>
            <label class="hx-field">توکن ربات
                <input class="input" type="password" name="token" dir="ltr" autocomplete="off" placeholder="<?= $hasToken ? '•••••••• (ذخیره شده — برای تغییر، توکن تازه بنویسید)' : '123456789:AAE...' ?>">
            </label>
            <?php if ($me): ?><p class="sa-hint">ربات: <b dir="ltr">@<?= e((string) ($me['username'] ?? '')) ?></b> — <?= e((string) ($me['first_name'] ?? '')) ?></p><?php endif; ?>
            <label class="switch-row"><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>><span><b>ورود با تلگرام فعال باشد</b><br><small class="hx-muted">دکمه «ورود / ثبت‌نام با تلگرام» در صفحه ورود نمایش داده می‌شود.</small></span></label>
            <label class="switch-row"><input type="checkbox" name="registration" value="1" <?= $registration ? 'checked' : '' ?>><span><b>ثبت‌نام حساب تازه از ربات</b><br><small class="hx-muted">خاموش باشد، ربات فقط برای حساب‌های موجود رمز تازه می‌سازد.</small></span></label>
            <div style="margin-top:14px"><button class="btn btn-primary" type="submit"><?php $icon('check', 16); ?> ذخیره و تنظیم وب‌هوک</button></div>
        </form>

        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-<?= $hookOk ? 'green' : 'amber' ?>"><?php $icon('link'); ?></span>
                <div><h3>وب‌هوک</h3><p>تلگرام پیام‌های ربات را به این نشانی می‌فرستد. پیام‌ها با یک رمز مخفی امضا می‌شوند و هر درخواست بدون آن رد می‌شود.</p></div>
            </header>
            <p class="sa-hint">نشانی: <code dir="ltr"><?= e($webhook) ?></code></p>
            <?php if (!$https): ?>
                <p class="sa-alert" style="display:block"><b>نشانی سایت https نیست.</b> تلگرام فقط به https پیام می‌دهد: <code>app.url</code> را در <code>config/config.php</code> به https://دامنه‌شما تغییر دهید.</p>
            <?php endif; ?>
            <?php if (is_array($info)): ?>
                <dl class="sa-dl" style="margin-top:10px">
                    <div><dt>وضعیت</dt><dd><?= isset($info['error']) ? '<span style="color:#dc2626">' . e($info['error']) . '</span>' : ($hookOk ? '<span style="color:#059669">وصل به همین سایت ✓</span>' : 'تنظیم نشده یا به نشانی دیگری') ?></dd></div>
                    <?php if (!isset($info['error'])): ?>
                        <div><dt>پیام‌های در صف</dt><dd><?= e(fa((string) ($info['pending_update_count'] ?? 0))) ?></dd></div>
                        <?php if (!empty($info['last_error_message'])): ?><div><dt>آخرین خطا</dt><dd dir="ltr" style="color:#dc2626"><?= e((string) $info['last_error_message']) ?></dd></div><?php endif; ?>
                    <?php endif; ?>
                </dl>
            <?php elseif (!$hasToken): ?>
                <div class="ad-empty">هنوز توکنی ذخیره نشده است.</div>
            <?php endif; ?>
            <?php if ($hasToken): ?>
                <form method="post" action="/admin/telegram/webhook" class="me-row" style="margin-top:12px;display:flex;gap:8px">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <button class="btn btn-ghost" type="submit" name="action" value="set"><?php $icon('refresh', 16); ?> تنظیم دوباره</button>
                    <button class="btn btn-ghost" type="submit" name="action" value="delete" data-confirm="وب‌هوک برداشته شود؟ ربات دیگر جواب نمی‌دهد.">برداشتن</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
</div>
