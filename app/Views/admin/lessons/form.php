<?php
/**
 * The درسنامه editor.
 *
 * @var array|null $lesson
 * @var array      $tagIds
 * @var array      $allTags
 * @var array      $subjects
 * @var array      $packages
 * @var array      $colors
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$l = $lesson ?? ['uuid' => '', 'title' => '', 'summary' => '', 'subject_id' => null, 'package_id' => null, 'color' => 'indigo',
    'cover_path' => null, 'body_html' => '', 'status' => 'draft', 'sort_order' => 0];
$action = $lesson === null ? '/admin/lessons' : '/admin/lessons/' . $l['uuid'];
$textColors = ['#111827', '#dc2626', '#ea580c', '#ca8a04', '#16a34a', '#0d9488', '#2563eb', '#7c3aed', '#db2777', '#64748b'];
$hlColors   = ['#fef08a', '#bbf7d0', '#fbcfe8', '#bfdbfe', '#fed7aa', '#ddd6fe', '#fecaca', '#e2e8f0'];
?>
<form class="le" method="post" action="<?= e($action) ?>" enctype="multipart/form-data" data-lesson-form data-upload="/admin/lessons/media" data-draft-key="lesson-draft-<?= e($l['uuid'] ?: 'new') ?>">
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <input type="hidden" name="body_html" data-le-output>

    <div class="le-main">
        <input class="le-title" name="title" maxlength="191" value="<?= e($l['title']) ?>" placeholder="عنوان درسنامه" required>
        <input class="le-summary" name="summary" maxlength="500" value="<?= e($l['summary'] ?? '') ?>" placeholder="خلاصه یک‌خطی (در کارت درسنامه نمایش داده می‌شود)">

        <div class="le-toolbar" role="toolbar" aria-label="ابزار ویرایش" data-le-toolbar>
            <div class="le-group">
                <button type="button" data-cmd="undo" title="برگرداندن (Ctrl+Z)"><?php $icon('undo', 17); ?></button>
                <button type="button" data-cmd="redo" title="دوباره (Ctrl+Y)"><?php $icon('redo', 17); ?></button>
            </div>
            <div class="le-group">
                <select data-block title="نوع بلوک">
                    <option value="p">متن عادی</option>
                    <option value="h2">تیتر ۱</option>
                    <option value="h3">تیتر ۲</option>
                    <option value="h4">تیتر ۳</option>
                    <option value="blockquote">نقل‌قول</option>
                </select>
                <select data-size title="اندازه متن">
                    <option value="">اندازه</option>
                    <option value="0.85em">کوچک</option>
                    <option value="1em">عادی</option>
                    <option value="1.25em">بزرگ</option>
                    <option value="1.6em">خیلی بزرگ</option>
                </select>
            </div>
            <div class="le-group">
                <button type="button" data-cmd="bold" title="پررنگ (Ctrl+B)"><?php $icon('bold', 17); ?></button>
                <button type="button" data-cmd="italic" title="کج (Ctrl+I)"><?php $icon('italic', 17); ?></button>
                <button type="button" data-cmd="underline" title="زیرخط (Ctrl+U)"><?php $icon('underline', 17); ?></button>
                <button type="button" data-cmd="strikeThrough" title="خط‌خورده"><b style="text-decoration:line-through">S</b></button>
            </div>
            <div class="le-group">
                <div class="le-pick">
                    <button type="button" data-open="color" title="رنگ متن"><?php $icon('type', 17); ?><i class="le-swatch" data-color-now style="background:#dc2626"></i></button>
                    <div class="le-palette" data-palette="color" hidden>
                        <?php foreach ($textColors as $c): ?><button type="button" data-fore="<?= e($c) ?>" style="--c: <?= e($c) ?>" aria-label="<?= e($c) ?>"></button><?php endforeach; ?>
                    </div>
                </div>
                <div class="le-pick">
                    <button type="button" data-open="hl" title="هایلایت"><?php $icon('highlighter', 17); ?><i class="le-swatch" data-hl-now style="background:#fef08a"></i></button>
                    <div class="le-palette" data-palette="hl" hidden>
                        <?php foreach ($hlColors as $c): ?><button type="button" data-hilite="<?= e($c) ?>" style="--c: <?= e($c) ?>" aria-label="<?= e($c) ?>"></button><?php endforeach; ?>
                        <button type="button" data-hilite="transparent" class="is-none" aria-label="بدون هایلایت">✕</button>
                    </div>
                </div>
                <button type="button" data-cmd="removeFormat" title="پاک کردن قالب">⌫</button>
            </div>
            <div class="le-group">
                <button type="button" data-cmd="insertUnorderedList" title="فهرست"><?php $icon('list', 17); ?></button>
                <button type="button" data-cmd="insertOrderedList" title="فهرست شماره‌دار"><b>۱.</b></button>
                <button type="button" data-cmd="justifyRight" title="راست‌چین"><?php $icon('align', 17); ?></button>
                <button type="button" data-cmd="justifyCenter" title="وسط‌چین"><b>≡</b></button>
                <button type="button" data-cmd="justifyFull" title="تراز"><b>☰</b></button>
            </div>
            <div class="le-group">
                <div class="le-pick">
                    <button type="button" data-open="callout" title="کادر نکته"><?php $icon('info', 17); ?></button>
                    <div class="le-palette is-list" data-palette="callout" hidden>
                        <button type="button" data-callout="key">🔑 نکته کلیدی</button>
                        <button type="button" data-callout="tip">💡 نکته</button>
                        <button type="button" data-callout="note">📝 یادداشت</button>
                        <button type="button" data-callout="warn">⚠️ هشدار</button>
                        <button type="button" data-callout="danger">⛔ خطر / اشتباه رایج</button>
                    </div>
                </div>
                <button type="button" data-table title="جدول"><?php $icon('table', 17); ?></button>
                <label class="le-file" title="تصویر"><?php $icon('image', 17); ?><input type="file" accept="image/*" data-image hidden></label>
                <button type="button" data-link title="پیوند"><?php $icon('link', 17); ?></button>
                <button type="button" data-cmd="insertHorizontalRule" title="خط جداکننده">—</button>
                <button type="button" data-cmd="formatBlock" data-arg="blockquote" title="نقل‌قول"><?php $icon('quote', 17); ?></button>
            </div>
            <div class="le-group le-end">
                <button type="button" data-source title="کد HTML">&lt;/&gt;</button>
                <button type="button" data-focus title="حالت تمرکز"><?php $icon('expand-full', 17); ?></button>
            </div>
        </div>

        <div class="le-paper">
            <div class="lx-doc le-editor" contenteditable="true" data-le-editor dir="rtl" spellcheck="true"
                 data-placeholder="متن درسنامه را این‌جا بنویسید یا از ورد بچسبانید…"><?= $l['body_html'] /* already sanitised on save */ ?></div>
            <textarea class="le-source" data-le-source hidden dir="ltr" spellcheck="false"></textarea>
        </div>
        <div class="le-status"><span data-le-count>۰ کلمه</span><span data-le-draft></span></div>
    </div>

    <aside class="le-side">
        <section class="ad-card">
            <div class="hx-field">وضعیت
                <div class="seg-pick">
                    <label><input type="radio" name="status" value="draft" <?= $l['status'] !== 'published' ? 'checked' : '' ?>><span>پیش‌نویس</span></label>
                    <label><input type="radio" name="status" value="published" <?= $l['status'] === 'published' ? 'checked' : '' ?>><span>منتشر</span></label>
                </div>
            </div>
            <div class="le-actions">
                <button class="btn btn-primary" type="submit"><?php $icon('check', 16); ?> ذخیره</button>
                <button class="btn btn-ghost" type="submit" name="stay" value="1">ذخیره و ادامه</button>
            </div>
            <?php if ($lesson !== null): ?>
                <a class="hx-link" href="/student/lessons/<?= e($l['uuid']) ?>?preview=1" target="_blank">پیش‌نمایش دانشجو ←</a>
            <?php endif; ?>
        </section>

        <section class="ad-card">
            <label class="hx-field">درس / زیردرس
                <select class="input" name="subject_id">
                    <option value="0">— بدون درس —</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) ($l['subject_id'] ?? 0) === $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="hx-field" style="margin-top:12px">برچسب‌ها <small class="hx-muted">(مشترک با سوال‌ها و بازی با شکل)</small>
                <div class="le-tags">
                    <?php foreach ($allTags as $t): ?>
                        <label class="le-tag"><input type="checkbox" name="tags[]" value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], array_map('intval', $tagIds), true) ? 'checked' : '' ?>><span class="stat-chip <?= e($t['color'] ?: 'chip-gray') ?>"><?= e($t['title']) ?></span></label>
                    <?php endforeach; ?>
                    <?php if ($allTags === []): ?><small class="hx-muted">هنوز برچسبی نیست؛ از <a href="/admin/lesson-tags">برچسب‌های مشترک</a> بسازید.</small><?php endif; ?>
                </div>
            </div>
        </section>

        <section class="ad-card">
            <div class="hx-field">رنگ کارت
                <div class="ad-swatches">
                    <?php foreach ($colors as $c): ?>
                        <label class="ad-swatch tone-<?= e($c) ?>"><input type="radio" name="color" value="<?= e($c) ?>" <?= $l['color'] === $c ? 'checked' : '' ?>><span></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <label class="hx-field" style="margin-top:12px">تصویر جلد (اختیاری)
                <input class="input" type="file" name="cover" accept="image/*">
            </label>
            <?php if (!empty($l['cover_path'])): ?>
                <div class="le-cover"><img src="/media/lessons/<?= e($l['cover_path']) ?>" alt=""><label class="hx-switch"><input type="checkbox" name="remove_cover" value="1"><span class="hx-switch-ui"></span><span>حذف جلد</span></label></div>
            <?php endif; ?>
            <label class="hx-field" style="margin-top:12px">فقط برای دارندگان پکیج
                <select class="input" name="package_id">
                    <option value="0">همه دانشجویان</option>
                    <?php foreach ($packages as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) ($l['package_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="hx-field" style="margin-top:12px">ترتیب نمایش<input class="input" type="number" name="sort_order" value="<?= (int) $l['sort_order'] ?>" dir="ltr"></label>
        </section>
    </aside>
</form>
