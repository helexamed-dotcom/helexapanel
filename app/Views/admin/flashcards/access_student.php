<?php
/**
 * One student's flashcard grants: a checkbox per course.
 *
 * @var array $student
 * @var array $courses  every flashcard course
 * @var array $granted  course ids currently held
 */
?>
<div class="qb-page" style="max-width:860px;">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="avatar avatar-lg"><?php \HeleXa\Core\View::partial('partials.avatar', ['person' => $student]); ?></div>
            <div>
                <h2><?= e($student['full_name']) ?></h2>
                <p><span class="mono"><?= e($student['username']) ?></span> — دسترسی به درس‌های فلش‌کارت</p>
            </div>
        </div>
        <div class="qb-hero-actions">
            <a class="btn btn-ghost" href="/admin/students/<?= e($student['uuid']) ?>/edit">پرونده دانشجو</a>
            <a class="btn btn-ghost" href="/admin/flashcards/access">فهرست</a>
        </div>
    </section>

    <form method="post" action="/admin/flashcards/access/<?= e($student['uuid']) ?>" class="qb-section">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <div class="qb-section-head">
            <h3>درس‌های فلش‌کارت فعال برای این دانشجو</h3>
            <?php if ($courses !== []): ?>
                <span class="qb-hint"><?= e(fa((string) count($granted))) ?> از <?= e(fa((string) count($courses))) ?> درس</span>
            <?php endif; ?>
        </div>

        <?php if ($courses === []): ?>
            <div class="empty">هنوز درس فلش‌کارتی ساخته نشده است. <a href="/admin/flashcards">ساخت درس</a></div>
        <?php else: ?>
            <div class="qb-check-grid">
                <?php foreach ($courses as $course): ?>
                    <label class="qb-check">
                        <input type="checkbox" name="courses[]" value="<?= (int) $course['id'] ?>"
                               <?= in_array((int) $course['id'], $granted, true) ? 'checked' : '' ?>>
                        <span>
                            <?= e($course['icon'] ?: '📘') ?> <?= e($course['title']) ?>
                            <?php if ($course['status'] !== 'published'): ?>
                                <small>پیش‌نویس — تا منتشر نشود به دانشجو نمایش داده نمی‌شود</small>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="qb-hint" style="margin-top:12px;">
                دسترسی بلافاصله اعمال می‌شود؛ بستن یک درس پیشرفت مرور دانشجو را پاک نمی‌کند.
            </p>
            <div class="row-actions" style="margin-top:14px;">
                <button class="btn btn-primary" type="submit" data-lock-on-submit>ذخیره دسترسی</button>
            </div>
        <?php endif; ?>
    </form>
</div>
