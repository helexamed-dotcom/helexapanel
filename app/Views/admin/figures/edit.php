<?php
/**
 * The hotspot editor: click the picture to add a spot, drag it into
 * place, name it, and (optionally) give it a question of its own.
 *
 * @var array $fig
 * @var array $spots
 * @var array $subjects
 * @var array $packages
 * @var array $tags
 * @var array $lessons
 * @var array $tones
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$boot = [
    'image' => $fig['image_path'] ? '/media/figures/' . $fig['image_path'] : null,
    'w'     => (int) $fig['image_w'],
    'h'     => (int) $fig['image_h'],
    'spots' => array_map(static fn (array $s): array => [
        'key' => $s['skey'], 'label' => $s['label'], 'x' => $s['x'], 'y' => $s['y'], 'r' => $s['r'], 'question' => $s['question'] ?? '',
        'options' => $s['options'], 'hint' => $s['hint'] ?? '', 'explanation' => $s['explanation'] ?? '',
        'tag_id' => (int) ($s['tag_id'] ?? 0), 'lesson_id' => (int) ($s['lesson_id'] ?? 0),
    ], $spots),
];
?>
<div class="fe" data-fe data-save="/admin/figures/<?= e($fig['uuid']) ?>" data-image-url="/admin/figures/<?= e($fig['uuid']) ?>/image">
    <script type="application/json" data-boot nonce="<?= e($cspNonce) ?>"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

    <div class="me-bar">
        <a class="ad-icon-btn" href="/admin/figures" title="بازگشت"><?php $icon('chevron', 16); ?></a>
        <input class="me-title" data-meta="title" value="<?= e($fig['title']) ?>" maxlength="191" aria-label="عنوان">
        <div class="me-group">
            <label title="تصویر تازه"><?php $icon('image', 16); ?> تصویر<input type="file" accept="image/*" data-image-input></label>
            <button type="button" data-act="io"><?php $icon('download', 16); ?> JSON</button>
            <a class="ad-icon-btn" href="/student/figures/<?= e($fig['uuid']) ?>?preview=1" target="_blank" title="امتحان کردن بازی"><?php $icon('play', 16); ?></a>
        </div>
        <span class="me-state" data-state>ذخیره‌شده</span>
        <button class="btn btn-primary me-save" type="button" data-act="save"><?php $icon('check', 16); ?> ذخیره</button>
    </div>

    <div class="fe-body">
        <div class="fe-stage-wrap">
            <?php if (!$fig['image_path']): ?>
                <label class="fe-drop" data-first-image>
                    <input type="file" accept="image/*" data-image-input>
                    <?php $icon('upload', 36); ?><b>تصویر شکل را این‌جا بیندازید</b><small>یک صفحه اطلس، شمای عصب‌ها، برش بافت‌شناسی… (JPG / PNG / WEBP)</small>
                </label>
            <?php endif; ?>
            <div class="fe-stage" data-stage <?= $fig['image_path'] ? '' : 'hidden' ?>>
                <img alt="" data-img draggable="false" <?= $fig['image_path'] ? 'src="/media/figures/' . e($fig['image_path']) . '"' : '' ?>>
                <div class="fe-spots" data-spots></div>
            </div>
            <p class="me-help fe-help">روی تصویر بزنید تا نقطه تازه اضافه شود · نقطه را بکشید تا جابه‌جا شود · <kbd>Del</kbd> حذف · <kbd>Ctrl+S</kbd> ذخیره</p>
        </div>

        <aside class="me-side">
            <section class="me-card">
                <h4><?php $icon('list', 15); ?> نقطه‌ها <small class="hx-muted" data-count></small></h4>
                <ol class="fe-list" data-list></ol>
            </section>

            <section class="me-card" data-inspector>
                <h4><?php $icon('pen', 15); ?> نقطه انتخاب‌شده</h4>
                <p class="me-empty" data-none>روی تصویر بزنید تا نقطه بسازید، یا یکی از فهرست بالا را انتخاب کنید.</p>
                <div data-some hidden style="display:grid;gap:10px">
                    <label class="hx-field">نام ساختار <small class="hx-muted">(در حالت «پیدا کن» پرسیده می‌شود)</small><input class="input" data-f="label" maxlength="160" placeholder="مثلاً: عصب مدین"></label>
                    <label class="hx-field">اندازه ناحیه: <b data-r-out></b><input type="range" min="0.8" max="25" step="0.1" data-f="r"></label>
                    <label class="hx-field">سوال اختصاصی <small class="hx-muted">(خالی = «این ساختار چیست؟» با گزینه از نام نقطه‌های دیگر)</small><input class="input" data-f="question" maxlength="500" placeholder="مثلاً: آسیب این عصب چه علامتی می‌دهد؟"></label>
                    <div class="hx-field" data-options-wrap>گزینه‌ها <small class="hx-muted">(دایره = پاسخ درست)</small>
                        <div class="fe-options" data-options></div>
                        <button type="button" class="btn btn-ghost btn-sm" data-add-option><?php $icon('plus', 14); ?> گزینه</button>
                    </div>
                    <label class="hx-field">راهنمایی<input class="input" data-f="hint" maxlength="300" placeholder="بعد از پاسخ غلط نشان داده می‌شود"></label>
                    <label class="hx-field">توضیح<textarea class="input" data-f="explanation" rows="3" maxlength="1000" placeholder="بعد از پاسخ نشان داده می‌شود"></textarea></label>
                    <label class="hx-field">برچسب مشترک
                        <select class="input" data-f="tag_id"><option value="0">— بدون برچسب —</option><?php foreach ($tags as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['title']) ?></option><?php endforeach; ?></select>
                    </label>
                    <label class="hx-field">درسنامه
                        <select class="input" data-f="lesson_id"><option value="0">— از روی برچسب —</option><?php foreach ($lessons as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['title']) ?><?= $l['status'] !== 'published' ? ' (پیش‌نویس)' : '' ?></option><?php endforeach; ?></select>
                    </label>
                    <button type="button" class="btn btn-ghost fe-del" data-act="remove"><?php $icon('trash', 15); ?> حذف این نقطه</button>
                </div>
            </section>

            <section class="me-card">
                <h4><?php $icon('sliders', 15); ?> تنظیمات</h4>
                <div class="hx-field">وضعیت
                    <div class="seg-pick">
                        <label><input type="radio" name="fe-status" value="draft" data-meta="status" <?= $fig['status'] !== 'published' ? 'checked' : '' ?>><span>پیش‌نویس</span></label>
                        <label><input type="radio" name="fe-status" value="published" data-meta="status" <?= $fig['status'] === 'published' ? 'checked' : '' ?>><span>منتشر</span></label>
                    </div>
                </div>
                <label class="hx-field">توضیح کوتاه<input class="input" data-meta="summary" maxlength="300" value="<?= e((string) ($fig['summary'] ?? '')) ?>"></label>
                <label class="hx-field">درس / زیردرس
                    <select class="input" data-meta="subject_id"><option value="0">— بدون درس —</option><?php foreach ($subjects as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) ($fig['subject_id'] ?? 0) === $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select>
                </label>
                <label class="hx-field">فقط برای دارندگان پکیج
                    <select class="input" data-meta="package_id"><option value="0">— همه —</option><?php foreach ($packages as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($fig['package_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?></option><?php endforeach; ?></select>
                </label>
                <div class="hx-field">رنگ کارت
                    <div class="me-colors" data-tones><?php foreach ($tones as $t): ?><button type="button" data-tone="<?= e($t) ?>" class="tone-<?= e($t) ?><?= $fig['tone'] === $t ? ' is-on' : '' ?>" style="--c: var(--t2)"></button><?php endforeach; ?></div>
                </div>
            </section>
        </aside>
    </div>

    <div class="me-dialog" data-io hidden>
        <div>
            <h3>نقطه‌ها به صورت JSON</h3>
            <p class="me-empty">برای انتقال نقطه‌ها به سایت دیگر یا ساختن با هوش مصنوعی. x و y درصد عرض و ارتفاع تصویرند (۰ تا ۱۰۰) و r شعاع به درصد عرض. با «جایگزین کن» نقطه‌های فعلی جایگزین می‌شوند.</p>
            <div class="me-row"><a class="btn btn-ghost" href="/admin/figures/<?= e($fig['uuid']) ?>/export"><?php $icon('download', 16); ?> دانلود JSON</a></div>
            <textarea class="input" data-io-text placeholder='{"spots":[{"label":"عصب مدین","x":42.5,"y":61,"r":3}]}'></textarea>
            <div class="me-row" style="justify-content:space-between"><button class="btn btn-primary" type="button" data-io-apply>جایگزین کن</button><button class="btn btn-ghost" type="button" data-io-close>بستن</button></div>
        </div>
    </div>
</div>
