<?php
/**
 * Characters: the teacher and the fictional students who carry a case.
 *
 * @var array $characters
 */
$typeNames = ['teacher' => 'استاد', 'student' => 'دانشجو', 'doctor' => 'پزشک',
              'patient' => 'بیمار', 'nurse' => 'پرستار', 'other' => 'سایر'];
?>
<div class="card">
    <div class="card-head"><h3 class="card-title" style="margin:0;">شخصیت‌ها</h3></div>

    <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 14px;">
        این‌ها موجودیت آموزشی‌اند، نه حساب کاربری واقعی. استاد به‌صورت پیش‌فرض سمت راست و
        دانشجوها سمت چپ نمایش داده می‌شوند.
    </p>

    <?php if ($characters === []): ?>
        <div class="empty">هنوز شخصیتی تعریف نشده است.</div>
    <?php else: ?>
        <div class="balin-character-grid">
            <?php foreach ($characters as $character): ?>
                <div class="balin-character-card<?= (int) $character['is_active'] === 0 ? ' is-off' : '' ?>">
                    <div class="balin-character-head">
                        <span class="balin-character-icon" aria-hidden="true">
                            <?php if ($character['svg_path']): ?>
                                <img src="/assets/<?= e($character['svg_path']) ?>" alt="">
                            <?php else: ?>
                                <?= e($character['icon'] ?: '🧑') ?>
                            <?php endif; ?>
                        </span>
                        <div>
                            <strong><?= e($character['name']) ?></strong>
                            <span class="leaf-meta">
                                <?= e($typeNames[$character['char_type']] ?? $character['char_type']) ?>
                                · <?= $character['side'] === 'right' ? 'راست' : 'چپ' ?>
                                <?= (int) $character['is_active'] === 0 ? ' · غیرفعال' : '' ?>
                            </span>
                        </div>
                    </div>

                    <details>
                        <summary>ویرایش</summary>
                        <form method="post" action="/admin/balin/characters/<?= e($character['uuid']) ?>"
                              enctype="multipart/form-data" class="form-grid">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <input type="hidden" name="version" value="<?= (int) $character['version'] ?>">

                            <label class="field"><span>نام *</span>
                                <input type="text" name="name" required maxlength="120" value="<?= e($character['name']) ?>"></label>
                            <label class="field"><span>نقش</span>
                                <select name="char_type">
                                    <?php foreach ($typeNames as $key => $label): ?>
                                        <option value="<?= e($key) ?>" <?= $character['char_type'] === $key ? 'selected' : '' ?>>
                                            <?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select></label>
                            <label class="field"><span>جنسیت</span>
                                <select name="gender">
                                    <option value="male"   <?= $character['gender'] === 'male' ? 'selected' : '' ?>>مرد</option>
                                    <option value="female" <?= $character['gender'] === 'female' ? 'selected' : '' ?>>زن</option>
                                </select></label>
                            <label class="field"><span>سمت</span>
                                <select name="side">
                                    <option value="left"  <?= $character['side'] === 'left' ? 'selected' : '' ?>>چپ</option>
                                    <option value="right" <?= $character['side'] === 'right' ? 'selected' : '' ?>>راست</option>
                                </select></label>
                            <label class="field"><span>آیکون (اموجی)</span>
                                <input type="text" name="icon" maxlength="8" value="<?= e((string) $character['icon']) ?>"></label>
                            <label class="field"><span>رنگ</span>
                                <input type="text" name="color" maxlength="16" value="<?= e((string) $character['color']) ?>"></label>
                            <label class="field"><span>ترتیب</span>
                                <input type="number" name="display_order" min="1" max="999" value="<?= (int) $character['display_order'] ?>"></label>
                            <label class="field"><span>آیکون SVG</span>
                                <input type="file" name="svg" accept="image/svg+xml,image/png,image/webp"></label>
                            <label class="field check">
                                <input type="checkbox" name="is_active" value="1" <?= (int) $character['is_active'] === 1 ? 'checked' : '' ?>>
                                <span>فعال</span></label>

                            <div class="form-actions span-2">
                                <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
                            </div>
                        </form>

                        <form method="post" action="/admin/balin/characters/<?= e($character['uuid']) ?>/delete"
                              data-confirm="این شخصیت حذف شود؟">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                        </form>
                    </details>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">شخصیت جدید</h3>
    <form method="post" action="/admin/balin/characters" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <label class="field"><span>نام *</span><input type="text" name="name" required maxlength="120"></label>
        <label class="field"><span>نقش</span>
            <select name="char_type">
                <?php foreach ($typeNames as $key => $label): ?>
                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label class="field"><span>جنسیت</span>
            <select name="gender"><option value="male">مرد</option><option value="female">زن</option></select></label>
        <label class="field"><span>سمت</span>
            <select name="side"><option value="left">چپ</option><option value="right">راست</option></select></label>
        <label class="field"><span>آیکون (اموجی)</span><input type="text" name="icon" maxlength="8" placeholder="🧑‍⚕️"></label>
        <label class="field"><span>رنگ</span><input type="text" name="color" maxlength="16" placeholder="#2563eb"></label>
        <label class="field"><span>ترتیب</span><input type="number" name="display_order" min="1" max="999" value="100"></label>
        <label class="field"><span>آیکون SVG</span><input type="file" name="svg" accept="image/svg+xml,image/png,image/webp"></label>
        <label class="field check"><input type="checkbox" name="is_active" value="1" checked><span>فعال</span></label>
        <div class="form-actions span-2"><button class="btn btn-primary" type="submit">افزودن شخصیت</button></div>
    </form>
</div>
