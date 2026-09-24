<?php
/** @var array|null $report */
?>
<div class="qb-page">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'users']); ?></div>
            <div>
                <h2>ورود و خروج دانشجویان</h2>
                <p>همه دانشجویان با اطلاعات شخصی، دانشگاه، رشته، ترم‌ها، گروه، دوره‌ها (با تاریخ) و دسترسی‌های بالین، بانک سوال و فلش‌کارت — در یک فایل JSON.</p>
            </div>
        </div>
        <div class="qb-hero-actions"><a class="btn btn-ghost" href="/admin/students">بازگشت به دانشجویان</a></div>
    </section>

    <?php if ($report !== null): ?>
        <section class="qb-section" id="report">
            <div class="qb-section-head"><h3><?= $report['dry_run'] ? '🔎 نتیجه بررسی (چیزی ذخیره نشد)' : '✅ نتیجه ورود' ?></h3></div>
            <div class="xfer-stats">
                <div><b><?= e(fa((string) $report['total'])) ?></b><span>ردیف</span></div>
                <div class="ok"><b><?= e(fa((string) $report['created'])) ?></b><span>حساب جدید</span></div>
                <div class="info"><b><?= e(fa((string) $report['updated'])) ?></b><span>به‌روزرسانی</span></div>
                <div><b><?= e(fa((string) $report['skipped'])) ?></b><span>رد شد (موجود)</span></div>
                <div class="bad"><b><?= e(fa((string) $report['error_count'])) ?></b><span>خطا</span></div>
            </div>

            <?php if ($report['passwords'] !== []): ?>
                <div class="alert alert-error" style="margin-top:14px;"><div>
                    <strong>رمزهای موقت — فقط همین یک بار نمایش داده می‌شوند.</strong>
                    دانشجو با اولین ورود باید رمز را عوض کند. همین حالا کپی یا ذخیره کنید.
                </div></div>
                <div class="row-actions" style="margin:8px 0;">
                    <button class="btn btn-ghost btn-sm" type="button" data-copy-target="#temp-passwords">کپی همه</button>
                </div>
                <textarea class="input xfer-code" id="temp-passwords" rows="8" readonly dir="ltr"><?php
                    foreach ($report['passwords'] as $p) { echo e($p['username'] . "\t" . $p['password']) . "\n"; }
                ?></textarea>
            <?php endif; ?>

            <?php if ($report['errors'] !== []): ?>
                <h4 class="xfer-h">خطاها</h4>
                <ul class="xfer-list bad">
                    <?php foreach ($report['errors'] as $n => $msg): ?><li><b>ردیف <?= e(fa((string) $n)) ?>:</b> <?= e($msg) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($report['warnings'] !== []): ?>
                <h4 class="xfer-h">هشدارها</h4>
                <ul class="xfer-list">
                    <?php foreach ($report['warnings'] as $n => $msg): ?><li><b>ردیف <?= e(fa((string) $n)) ?>:</b> <?= e($msg) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="xfer-grid">
        <section class="qb-section">
            <div class="qb-section-head"><h3><span class="qb-step">⬇</span> خروجی همه دانشجویان</h3></div>
            <form method="get" action="/admin/students/export">
                <label class="remember-row"><input type="checkbox" name="hashes" value="1">
                    <span>رمزهای عبور (به‌صورت هش) هم خروجی گرفته شوند — برای انتقال به سرور دیگر بدون تغییر رمز</span></label>
                <p class="qb-hint">فایل با هش رمزها حساس است؛ آن را جایی عمومی نگذارید. این کار در گزارش فعالیت ثبت می‌شود.</p>
                <div class="row-actions"><button class="btn btn-primary" type="submit">دریافت فایل JSON</button></div>
            </form>
        </section>

        <section class="qb-section">
            <div class="qb-section-head"><h3><span class="qb-step">⬆</span> ورود دانشجویان</h3></div>
            <form method="post" action="/admin/students/import" enctype="multipart/form-data" data-confirm="دانشجویان این فایل ثبت یا به‌روزرسانی شوند؟">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <div class="field"><label class="label" for="si-f">فایل ‎.json</label>
                    <input class="input" id="si-f" type="file" name="file" accept=".json,application/json"></div>
                <div class="field"><label class="label" for="si-j">یا متن JSON</label>
                    <textarea class="input xfer-code" id="si-j" name="json" rows="5" dir="ltr" spellcheck="false" placeholder='{"format":"helexa-students","students":[ ... ]}'></textarea></div>
                <label class="remember-row"><input type="checkbox" name="update_existing" value="1" checked><span>دانشجوی موجود (هم‌شناسه یا هم‌نام کاربری) به‌روزرسانی شود</span></label>
                <label class="remember-row"><input type="checkbox" name="sync_access" value="1" checked><span>دوره‌ها و دسترسی‌ها هم طبق فایل تنظیم شوند</span></label>
                <label class="remember-row"><input type="checkbox" name="restore_hashes" value="1"><span>رمزهای هش‌شده فایل اعمال شوند</span></label>
                <label class="remember-row"><input type="checkbox" name="dry_run" value="1"><span>فقط بررسی کن، ذخیره نکن</span></label>
                <p class="qb-hint">برای حساب جدیدی که رمز ندارد، رمز موقت ساخته و یک بار نمایش داده می‌شود. دانشگاه، رشته، ترم، گروه و دوره‌ها با «نام» پیدا می‌شوند و باید از قبل در سایت باشند.</p>
                <div class="row-actions"><button class="btn btn-primary" type="submit" data-lock-on-submit>وارد کردن</button></div>
            </form>
        </section>
    </div>

    <section class="qb-section">
        <div class="qb-section-head"><h3>نمونه ساختار فایل</h3></div>
        <pre class="xfer-code xfer-pre" dir="ltr">{
  "format": "helexa-students",
  "version": 1,
  "students": [
    {
      "username": "ali.rezaei",
      "full_name": "علی رضایی",
      "mobile": "09120000000",
      "email": "ali@example.com",
      "gender": "male",
      "status": "active",
      "university": "دانشگاه علوم پزشکی تهران",
      "major": "پزشکی",
      "terms": ["ترم ۳"],
      "group": "گروه A",
      "password": "optional-initial-password",
      "courses": [{"title": "فیزیولوژی ۱", "status": "active", "starts_at": null, "ends_at": "2027-03-20"}],
      "balin": true,
      "qbank_subjects": ["فیزیولوژی"],
      "flashcard_courses": ["آناتومی"]
    }
  ]
}</pre>
    </section>
</div>