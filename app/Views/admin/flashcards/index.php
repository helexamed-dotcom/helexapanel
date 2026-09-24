<?php
/**
 * @var array $courses
 * @var array $colors
 */
$colorLabels = ['blue' => 'آبی', 'purple' => 'بنفش', 'teal' => 'فیروزه‌ای', 'green' => 'سبز',
                'orange' => 'نارنجی', 'pink' => 'صورتی', 'red' => 'قرمز', 'indigo' => 'نیلی'];
?>
<div class="fc-page">
    <section class="fc-hero">
        <div style="position:relative; z-index:1;">
            <h2>🃏 درس‌های فلش‌کارت</h2>
            <p>هر درس چند جلسه دارد و هر جلسه چند کارت. دسترسی دانشجو به کل درس داده می‌شود؛
               جلسه‌ای که بعداً اضافه کنی خودکار به همه دارندگان درس می‌رسد.</p>
        </div>
        <?php if (can('flashcards.manage_students')): ?>
            <a class="btn btn-light" href="/admin/flashcards/access">دسترسی دانشجویان</a>
        <?php endif; ?>
    </section>

    <form method="post" action="/admin/flashcards/courses" class="fc-panel">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <div class="fc-head" style="margin-bottom:12px;"><h3>＋ درس جدید</h3></div>
        <div class="fc-inline">
            <div class="field"><label class="label" for="c-title">عنوان</label>
                <input class="input" id="c-title" name="title" maxlength="191" required placeholder="مثلاً زبان عمومی"></div>
            <div class="field narrow"><label class="label" for="c-icon">آیکون</label>
                <input class="input" id="c-icon" name="icon" maxlength="4" placeholder="📘"></div>
            <div class="field narrow" style="flex-basis:130px;"><label class="label" for="c-color">رنگ</label>
                <select class="input" id="c-color" name="color">
                    <?php foreach ($colors as $color): ?>
                        <option value="<?= e($color) ?>"><?= e($colorLabels[$color] ?? $color) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="field"><label class="label" for="c-desc">توضیح</label>
                <input class="input" id="c-desc" name="description" maxlength="500"></div>
            <button class="btn btn-primary" type="submit" data-lock-on-submit>ساختن درس</button>
        </div>
        <p class="fc-hint" style="margin-top:8px;">درس به‌صورت پیش‌نویس ساخته می‌شود و تا منتشرش نکنی دانشجو آن را نمی‌بیند.</p>
    </form>

    <?php if ($courses === []): ?>
        <div class="fc-panel fc-empty"><div class="fc-big">🗂️</div><strong>هنوز درسی ساخته نشده است.</strong></div>
    <?php else: ?>
        <div class="fc-courses">
            <?php foreach ($courses as $course): ?>
                <a class="fc-course fc-c-<?= e($course['color']) ?>" href="/admin/flashcards/course/<?= e($course['uuid']) ?>">
                    <span class="fc-due"><?= $course['status'] === 'published' ? 'منتشرشده' : 'پیش‌نویس' ?></span>
                    <div class="fc-course-icon"><?= e($course['icon'] ?: '📘') ?></div>
                    <div>
                        <h4><?= e($course['title']) ?></h4>
                        <small><?= e(fa((string) $course['deck_count'])) ?> جلسه · <?= e(fa((string) $course['card_count'])) ?> کارت</small>
                    </div>
                    <div class="fc-course-foot">
                        <div class="fc-course-meta"><span>👥 <?= e(fa((string) $course['student_count'])) ?> دانشجو</span><span>مدیریت ←</span></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
