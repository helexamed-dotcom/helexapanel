<?php
/**
 * «پشتیبان‌گیری».
 *
 * @var array|null $report  the last import's counts and warnings
 */
?>
<div class="qb-page">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon" aria-hidden="true">🛟</div>
            <div>
                <h2>پشتیبان‌گیری</h2>
                <p>یک نسخه کامل از همه چیز، یا فقط تنظیمات، پکیج‌ها و دسترسی‌ها برای انتقال و بازگردانی آسان.</p>
            </div>
        </div>
    </section>

    <?php if ($report !== null): ?>
        <section class="qb-section">
            <div class="qb-section-head"><h3>نتیجه بازگردانی</h3></div>
            <div class="hx-chips">
                <span class="hx-chip">⚙️ <?= e(fa((string) ($report['counts']['settings'] ?? 0))) ?> تنظیم</span>
                <span class="hx-chip">📦 <?= e(fa((string) ($report['counts']['packages'] ?? 0))) ?> پکیج</span>
                <span class="hx-chip">🎟️ <?= e(fa((string) ($report['counts']['codes'] ?? 0))) ?> کد</span>
                <span class="hx-chip">👤 <?= e(fa((string) ($report['counts']['students'] ?? 0))) ?> دانشجو</span>
                <span class="hx-chip">🔑 <?= e(fa((string) ($report['counts']['grants'] ?? 0))) ?> دسترسی</span>
            </div>
            <?php if (!empty($report['warnings'])): ?>
                <ul class="qb-hint" style="margin-top:10px;">
                    <?php foreach ($report['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="hx-grid-2">
        <section class="qb-section hx-backup-card">
            <div class="hx-backup-icon" aria-hidden="true">🗄️</div>
            <h3>پشتیبان کامل دیتابیس</h3>
            <p class="qb-hint">
                همه جدول‌ها و همه ردیف‌ها: کاربران، دوره‌ها، بانک سوال، جزیره، پیشرفت‌ها، تنظیمات، پکیج‌ها و دسترسی‌ها.
                برای بازگردانی: phpMyAdmin ← انتخاب دیتابیس ← Import ← همین فایل.
                (فایل‌های آپلودشده مثل تصاویر و جزوه‌ها داخل پوشه‌ها هستند؛ آن‌ها را جداگانه از هاست کپی کنید.)
            </p>
            <div class="row-actions">
                <a class="btn btn-primary" href="/admin/backup/sql">⬇ دانلود SQL</a>
                <a class="btn btn-ghost" href="/admin/backup/sql?gzip=1">⬇ فشرده (gz)</a>
            </div>
        </section>

        <section class="qb-section hx-backup-card">
            <div class="hx-backup-icon" aria-hidden="true">🧩</div>
            <h3>پشتیبان تنظیمات و دسترسی‌ها (JSON)</h3>
            <p class="qb-hint">
                تنظیمات سایت، همه پکیج‌ها (با دوره‌ها، بانک سوال، درس‌های جزیره و فلش‌کارت‌های داخلشان)،
                کدهای فعال‌سازی و دسترسی همه دانشجویان — بر اساس نام کاربری، قابل بازگردانی در همین سایت
                یا سایت تازه‌ای که همان دوره‌ها و دانشجویان را دارد.
            </p>
            <div class="row-actions">
                <a class="btn btn-primary" href="/admin/backup/config">⬇ دانلود JSON</a>
            </div>
        </section>
    </div>

    <form method="post" action="/admin/backup/config" enctype="multipart/form-data" class="qb-section"
          data-confirm="بازگردانی اطلاعات فایل روی سایت اعمال شود؟ چیزی حذف نمی‌شود؛ فقط اضافه یا به‌روز می‌شود.">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <div class="qb-section-head"><h3>♻️ بازگردانی از فایل JSON</h3></div>
        <input class="input" type="file" name="file" accept="application/json,.json" required>
        <div class="qb-check-grid" style="margin-top:12px;">
            <label class="qb-check"><input type="checkbox" name="part_settings" value="1" checked><span>تنظیمات</span></label>
            <label class="qb-check"><input type="checkbox" name="part_packages" value="1" checked><span>پکیج‌ها و محتوای آن‌ها</span></label>
            <label class="qb-check"><input type="checkbox" name="part_codes" value="1" checked><span>کدهای فعال‌سازی</span></label>
            <label class="qb-check"><input type="checkbox" name="part_access" value="1" checked><span>دسترسی دانشجویان</span></label>
        </div>
        <p class="qb-hint">چیزی حذف نمی‌شود: موارد موجود به‌روز و موارد تازه اضافه می‌شوند. همه تغییرات با هم انجام می‌شوند یا هیچ‌کدام.</p>
        <div class="row-actions"><button class="btn btn-primary" type="submit" data-lock-on-submit>بازگردانی</button></div>
    </form>
</div>
