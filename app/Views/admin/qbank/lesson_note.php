<?php
/**
 * The درسنامه editor for one درس / زیردرس / عنوان.
 *
 * @var array  $subject
 * @var string $preview  the saved note, as students see it
 */
$levels = [1 => 'درس', 2 => 'زیردرس', 3 => 'عنوان'];
?>
<div class="qb-page" style="max-width:980px;">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon" aria-hidden="true">📘</div>
            <div>
                <h2>درسنامه: <?= e($subject['title']) ?></h2>
                <p><?= e($levels[(int) $subject['depth']] ?? '') ?> · بعد از پاسخ دادن به هر سوالِ این بخش، دانشجو با دکمه «📘 درسنامه» آن را می‌بیند.</p>
            </div>
        </div>
        <div class="qb-hero-actions"><a class="btn btn-ghost" href="/admin/qbank/subjects">بازگشت به دروس</a></div>
    </section>

    <div class="hx-grid-2">
        <form method="post" action="/admin/qbank/subjects/<?= e($subject['uuid']) ?>/lesson" class="qb-section">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <label class="label" for="ln">متن درسنامه</label>
            <textarea class="input" id="ln" name="lesson_note" rows="18" dir="auto"
                      placeholder="## عنوان&#10;یک خلاصه کوتاه…&#10;- نکته اول&#10;- نکته دوم&#10;**نکته امتحانی:** …"><?= e((string) ($subject['lesson_note'] ?? '')) ?></textarea>
            <p class="qb-hint" style="margin-top:8px;">
                قالب ساده: <code>## تیتر</code> · <code>- فهرست</code> · <code>1. فهرست شماره‌دار</code> ·
                <code>**پررنگ**</code> · یک خط خالی = پاراگراف تازه. خالی کردن متن، درسنامه را حذف می‌کند.
            </p>
            <div class="row-actions"><button class="btn btn-primary" type="submit">ذخیره درسنامه</button></div>
        </form>

        <section class="qb-section">
            <div class="label">پیش‌نمایش (نسخه ذخیره‌شده)</div>
            <?php if ($preview === ''): ?>
                <div class="empty">هنوز درسنامه‌ای برای این بخش نوشته نشده است.</div>
            <?php else: ?>
                <div class="qb-lesson-note"><?= $preview /* escaped then formatted by LessonNotes::html() */ ?></div>
            <?php endif; ?>
        </section>
    </div>
</div>
