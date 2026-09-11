<?php
/**
 * Rank tiers — the names behind the level numbers.
 *
 * Twenty rows of ten levels each cover levels 1–200; the title a student
 * sees is built at runtime as "<tier> · قدم N", so there is nothing here to
 * edit per level.
 *
 * @var array $tiers
 */
?>
<div class="card">
    <div class="card-head"><h3 class="card-title" style="margin:0;">عنوان سطح‌ها</h3></div>

    <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 14px;">
        عنوان نهایی به‌صورت «<strong>عنوان رتبه · قدم N</strong>» ساخته می‌شود؛ مثلاً سطح ۹۵ می‌شود
        «رزیدنت میانی · قدم ۵». بازه‌ها نباید هم‌پوشانی داشته باشند.
    </p>

    <?php if ($tiers === []): ?>
        <div class="empty">هیچ رتبه‌ای تعریف نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>بازه سطح</th><th>عنوان</th><th>نماد</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tiers as $tier): ?>
                    <tr>
                        <td><?= e(fa((int) $tier['min_level'])) ?>–<?= e(fa((int) $tier['max_level'])) ?></td>
                        <td><?= e($tier['title']) ?></td>
                        <td><?= e((string) $tier['icon']) ?></td>
                        <td class="row-actions">
                            <details>
                                <summary class="btn btn-ghost btn-sm">ویرایش</summary>
                                <form method="post" action="/admin/balin/rank-tiers/<?= (int) $tier['id'] ?>" class="form-grid">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="version" value="<?= (int) $tier['version'] ?>">
                                    <label class="field"><span>از سطح</span>
                                        <input type="number" name="min_level" min="0" max="9999" value="<?= (int) $tier['min_level'] ?>"></label>
                                    <label class="field"><span>تا سطح</span>
                                        <input type="number" name="max_level" min="0" max="9999" value="<?= (int) $tier['max_level'] ?>"></label>
                                    <label class="field"><span>عنوان *</span>
                                        <input type="text" name="title" required maxlength="120" value="<?= e($tier['title']) ?>"></label>
                                    <label class="field"><span>نماد</span>
                                        <input type="text" name="icon" maxlength="8" value="<?= e((string) $tier['icon']) ?>"></label>
                                    <label class="field"><span>ترتیب</span>
                                        <input type="number" name="display_order" min="1" max="999" value="<?= (int) $tier['display_order'] ?>"></label>
                                    <div class="form-actions span-2">
                                        <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
                                    </div>
                                </form>
                                <form method="post" action="/admin/balin/rank-tiers/<?= (int) $tier['id'] ?>/delete"
                                      data-confirm="این رتبه حذف شود؟">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">رتبه جدید</h3>
    <form method="post" action="/admin/balin/rank-tiers" class="form-grid">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <label class="field"><span>از سطح</span><input type="number" name="min_level" min="0" max="9999" required></label>
        <label class="field"><span>تا سطح</span><input type="number" name="max_level" min="0" max="9999" required></label>
        <label class="field"><span>عنوان *</span><input type="text" name="title" required maxlength="120"></label>
        <label class="field"><span>نماد</span><input type="text" name="icon" maxlength="8" placeholder="🌱"></label>
        <label class="field"><span>ترتیب</span><input type="number" name="display_order" min="1" max="999" value="1"></label>
        <div class="form-actions span-2"><button class="btn btn-primary" type="submit">افزودن رتبه</button></div>
    </form>
</div>
