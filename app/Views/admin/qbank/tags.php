<?php
/**
 * برچسب‌های سوال — the admin's own labels.
 *
 * @var array $tags
 * @var array $colors
 */
$canEdit     = can('qbank.manage_taxonomy');
$colorLabels = [
    'chip-gray' => 'خاکستری', 'chip-blue' => 'آبی', 'chip-green' => 'سبز', 'chip-amber' => 'نارنجی',
    'chip-red' => 'قرمز', 'chip-purple' => 'بنفش', 'chip-teal' => 'فیروزه‌ای',
];
?>
<div class="qb-page">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'tag']); ?></div>
            <div>
                <h2>برچسب‌های سوال</h2>
                <p>برچسب نوع سوال را مشخص می‌کند — مثل «علوم پایه» یا «تالیفی» — و مستقل از درس است.
                   هر سوال می‌تواند چند برچسب داشته باشد یا هیچ‌کدام.</p>
            </div>
        </div>
    </section>

    <?php if ($canEdit): ?>
        <section class="qb-section">
            <div class="qb-section-head"><h3><span class="qb-step">+</span> برچسب جدید</h3></div>
            <form method="post" action="/admin/qbank/tags" class="qb-inline-form">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <div class="field">
                    <label class="label" for="tag-title">عنوان</label>
                    <input class="input" id="tag-title" name="title" maxlength="96" required placeholder="مثلاً آزمون ارتقا، پره‌انترنی">
                </div>
                <div class="field narrow" style="flex-basis:140px;">
                    <label class="label" for="tag-color">رنگ</label>
                    <select class="input" id="tag-color" name="color">
                        <?php foreach ($colors as $color): ?>
                            <option value="<?= e($color) ?>"><?= e($colorLabels[$color] ?? $color) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field narrow">
                    <label class="label" for="tag-order">ترتیب</label>
                    <input class="input" id="tag-order" type="number" name="sort_order" value="0" dir="ltr">
                </div>
                <button class="btn btn-primary" type="submit" data-lock-on-submit>افزودن</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="qb-section">
        <?php if ($tags === []): ?>
            <div class="empty">هنوز برچسبی ساخته نشده است.</div>
        <?php else: ?>
            <div class="qb-list">
                <?php foreach ($tags as $tag): ?>
                    <div class="qb-row">
                        <?php if ($canEdit): ?>
                            <form method="post" action="/admin/qbank/tags/<?= (int) $tag['id'] ?>" class="qb-inline-form">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <span class="stat-chip <?= e($tag['color'] ?: 'chip-gray') ?>" style="align-self:center;">
                                    <?= e($tag['title']) ?> · <?= e(fa((string) $tag['question_count'])) ?>
                                </span>
                                <div class="field">
                                    <label class="label">عنوان</label>
                                    <input class="input" name="title" value="<?= e($tag['title']) ?>" maxlength="96" required>
                                </div>
                                <div class="field narrow" style="flex-basis:130px;">
                                    <label class="label">رنگ</label>
                                    <select class="input" name="color">
                                        <?php foreach ($colors as $color): ?>
                                            <option value="<?= e($color) ?>" <?= $tag['color'] === $color ? 'selected' : '' ?>>
                                                <?= e($colorLabels[$color] ?? $color) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field narrow">
                                    <label class="label">ترتیب</label>
                                    <input class="input" type="number" name="sort_order" value="<?= (int) $tag['sort_order'] ?>" dir="ltr">
                                </div>
                                <label class="remember-row" style="margin:0 0 10px;">
                                    <input type="checkbox" name="is_active" value="1" <?= (int) $tag['is_active'] === 1 ? 'checked' : '' ?>>
                                    <span>فعال</span>
                                </label>
                                <button class="btn btn-ghost btn-sm" type="submit">ذخیره</button>
                            </form>
                            <div class="qb-row-actions">
                                <form method="post" action="/admin/qbank/tags/<?= (int) $tag['id'] ?>/delete"
                                      data-confirm="برچسب «<?= e($tag['title']) ?>» حذف شود؟ سوال‌ها باقی می‌مانند.">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <span class="stat-chip <?= e($tag['color'] ?: 'chip-gray') ?>"><?= e($tag['title']) ?></span>
                            <span class="qb-count"><?= e(fa((string) $tag['question_count'])) ?> سوال</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
