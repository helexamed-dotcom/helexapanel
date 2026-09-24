<?php
/**
 * One student's question-bank grants: a checkbox per درس.
 *
 * @var array $student
 * @var array $subjects  depth-1 rows, active and inactive
 * @var array $granted   subject ids currently held
 */
?>
<div class="qb-page" style="max-width:860px;">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="avatar avatar-lg"><?php \HeleXa\Core\View::partial('partials.avatar', ['person' => $student]); ?></div>
            <div>
                <h2><?= e($student['full_name']) ?></h2>
                <p><span class="mono"><?= e($student['username']) ?></span> — دسترسی به درس‌های بانک سوال</p>
            </div>
        </div>
        <div class="qb-hero-actions">
            <a class="btn btn-ghost" href="/admin/students/<?= e($student['uuid']) ?>/edit">پرونده دانشجو</a>
            <a class="btn btn-ghost" href="/admin/qbank/access">فهرست</a>
        </div>
    </section>

    <form method="post" action="/admin/qbank/access/<?= e($student['uuid']) ?>" class="qb-section">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <div class="qb-section-head">
            <h3>درس‌های فعال برای این دانشجو</h3>
            <?php if ($subjects !== []): ?>
                <span class="qb-hint"><?= e(fa((string) count($granted))) ?> از <?= e(fa((string) count($subjects))) ?> درس</span>
            <?php endif; ?>
        </div>

        <?php if ($subjects === []): ?>
            <div class="empty">هنوز درسی در بانک سوال ساخته نشده است. <a href="/admin/qbank/subjects">ساخت درس</a></div>
        <?php else: ?>
            <div class="qb-check-grid">
                <?php foreach ($subjects as $subject): ?>
                    <label class="qb-check">
                        <input type="checkbox" name="subjects[]" value="<?= (int) $subject['id'] ?>"
                               <?= in_array((int) $subject['id'], $granted, true) ? 'checked' : '' ?>>
                        <span>
                            <?= e($subject['title']) ?>
                            <?php if ((int) $subject['is_active'] !== 1): ?>
                                <small>درس غیرفعال — تا فعال نشود به دانشجو نمایش داده نمی‌شود</small>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="qb-hint" style="margin-top:12px;">
                دسترسی بلافاصله اعمال می‌شود؛ بستن یک درس سابقه پاسخ‌های دانشجو را پاک نمی‌کند.
            </p>
            <div class="row-actions" style="margin-top:14px;">
                <button class="btn btn-primary" type="submit" data-lock-on-submit>ذخیره دسترسی</button>
            </div>
        <?php endif; ?>
    </form>
</div>
