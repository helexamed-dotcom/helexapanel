<?php
/**
 * The Word-like editor: toolbar, the page itself and an HTML source view.
 * Used inside a form marked data-lesson-form (see lesson-editor.js), which
 * also carries data-upload for pasted and dropped images.
 *
 * @var string $html         initial HTML, already sanitised
 * @var string $placeholder
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$textColors = ['#111827', '#dc2626', '#ea580c', '#ca8a04', '#16a34a', '#0d9488', '#2563eb', '#7c3aed', '#db2777', '#64748b'];
$hlColors   = ['#fef08a', '#bbf7d0', '#fbcfe8', '#bfdbfe', '#fed7aa', '#ddd6fe', '#fecaca', '#e2e8f0'];
?>
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
         data-placeholder="<?= e($placeholder ?? 'متن را این‌جا بنویسید یا از ورد بچسبانید…') ?>"><?= $html /* already sanitised on save */ ?></div>
    <textarea class="le-source" data-le-source hidden dir="ltr" spellcheck="false"></textarea>
</div>
<div class="le-status"><span data-le-count>۰ کلمه</span><span data-le-draft></span></div>
