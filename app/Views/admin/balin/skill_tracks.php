<?php
/**
 * Clinical skill tracks — the cross-lesson skills a question can be tagged
 * with.
 *
 * @var array $tracks
 * @var array $categories
 */
?>
<div class="card">
    <div class="card-head"><h3 class="card-title" style="margin:0;">مهارت‌های بالینی</h3></div>

    <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 14px;">
        این مهارت‌ها در همه درس‌ها دنبال می‌شوند و با تسلط درسی فرق دارند. حذف یا غیرفعال کردن یک
        مهارت، تسلط درسی دانشجویان را تغییر نمی‌دهد.
    </p>

    <?php if ($tracks === []): ?>
        <div class="empty">هنوز مهارتی تعریف نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>مهارت</th><th>دسته</th><th>سؤال برچسب‌خورده</th><th>حداقل برای نمایش درصد</th><th>وضعیت</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tracks as $track):
                    $thresholds = json_decode((string) $track['badge_thresholds'], true) ?: [];
                ?>
                    <tr>
                        <td>
                            <span aria-hidden="true"><?= e($track['icon'] ?: '🩺') ?></span>
                            <?= e($track['name']) ?>
                            <?php if ($track['name_en']): ?>
                                <div class="leaf-meta"><?= e($track['name_en']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($categories[$track['category']] ?? $track['category']) ?></td>
                        <td><?= e(fa((int) $track['tagged_questions'])) ?></td>
                        <td><?= e(fa((int) $track['min_questions_for_reliable_mastery'])) ?></td>
                        <td>
                            <span class="stat-chip <?= (int) $track['is_active'] === 1 ? 'chip-green' : 'chip-gray' ?>">
                                <?= (int) $track['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
                            </span>
                        </td>
                        <td class="row-actions">
                            <details>
                                <summary class="btn btn-ghost btn-sm">ویرایش</summary>
                                <form method="post" action="/admin/balin/skill-tracks/<?= (int) $track['id'] ?>" class="form-grid">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="version" value="<?= (int) $track['version'] ?>">

                                    <label class="field"><span>نام *</span>
                                        <input type="text" name="name" required maxlength="120" value="<?= e($track['name']) ?>"></label>
                                    <label class="field"><span>نام انگلیسی</span>
                                        <input type="text" name="name_en" maxlength="120" value="<?= e((string) $track['name_en']) ?>"></label>
                                    <label class="field"><span>نشانی یکتا</span>
                                        <input type="text" name="slug" maxlength="120" value="<?= e($track['slug']) ?>"></label>
                                    <label class="field"><span>آیکون</span>
                                        <input type="text" name="icon" maxlength="8" value="<?= e((string) $track['icon']) ?>"></label>
                                    <label class="field"><span>دسته</span>
                                        <select name="category">
                                            <?php foreach ($categories as $key => $label): ?>
                                                <option value="<?= e($key) ?>" <?= $track['category'] === $key ? 'selected' : '' ?>>
                                                    <?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select></label>
                                    <label class="field"><span>ترتیب</span>
                                        <input type="number" name="display_order" min="1" max="999" value="<?= (int) $track['display_order'] ?>"></label>
                                    <label class="field"><span>حداقل سؤال برای نمایش درصد</span>
                                        <input type="number" name="min_questions" min="1" max="500"
                                               value="<?= (int) $track['min_questions_for_reliable_mastery'] ?>"></label>
                                    <fieldset class="field span-2">
                                        <legend>آستانه نشان‌ها (تعداد سؤال پاسخ‌داده‌شده)</legend>
                                        <div class="balin-weight-row">
                                            <label>برنز <input type="number" name="bronze" min="1" value="<?= (int) ($thresholds['bronze'] ?? 10) ?>"></label>
                                            <label>نقره <input type="number" name="silver" min="1" value="<?= (int) ($thresholds['silver'] ?? 30) ?>"></label>
                                            <label>طلا <input type="number" name="gold" min="1" value="<?= (int) ($thresholds['gold'] ?? 75) ?>"></label>
                                            <label>پلاتین <input type="number" name="platinum" min="1" value="<?= (int) ($thresholds['platinum'] ?? 150) ?>"></label>
                                        </div>
                                    </fieldset>
                                    <label class="field span-2"><span>توضیح</span>
                                        <textarea name="description" rows="2"><?= e((string) $track['description']) ?></textarea></label>
                                    <label class="field check">
                                        <input type="checkbox" name="is_active" value="1" <?= (int) $track['is_active'] === 1 ? 'checked' : '' ?>>
                                        <span>فعال</span></label>

                                    <div class="form-actions span-2">
                                        <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
                                    </div>
                                </form>

                                <form method="post" action="/admin/balin/skill-tracks/<?= (int) $track['id'] ?>/delete"
                                      data-confirm="این مهارت حذف شود؟ برچسب سؤال‌ها هم برداشته می‌شود.">
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
    <h3 class="card-title">مهارت جدید</h3>
    <form method="post" action="/admin/balin/skill-tracks" class="form-grid">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <label class="field"><span>نام *</span><input type="text" name="name" required maxlength="120"></label>
        <label class="field"><span>نام انگلیسی</span><input type="text" name="name_en" maxlength="120"></label>
        <label class="field"><span>نشانی یکتا</span><input type="text" name="slug" maxlength="120" placeholder="از نام ساخته می‌شود"></label>
        <label class="field"><span>آیکون</span><input type="text" name="icon" maxlength="8" placeholder="🩺"></label>
        <label class="field"><span>دسته</span>
            <select name="category">
                <?php foreach ($categories as $key => $label): ?>
                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label class="field"><span>ترتیب</span><input type="number" name="display_order" min="1" max="999" value="100"></label>
        <label class="field"><span>حداقل سؤال برای نمایش درصد</span>
            <input type="number" name="min_questions" min="1" max="500" value="10"></label>
        <fieldset class="field span-2">
            <legend>آستانه نشان‌ها</legend>
            <div class="balin-weight-row">
                <label>برنز <input type="number" name="bronze" min="1" value="10"></label>
                <label>نقره <input type="number" name="silver" min="1" value="30"></label>
                <label>طلا <input type="number" name="gold" min="1" value="75"></label>
                <label>پلاتین <input type="number" name="platinum" min="1" value="150"></label>
            </div>
        </fieldset>
        <label class="field check"><input type="checkbox" name="is_active" value="1" checked><span>فعال</span></label>
        <div class="form-actions span-2"><button class="btn btn-primary" type="submit">افزودن مهارت</button></div>
    </form>
</div>
