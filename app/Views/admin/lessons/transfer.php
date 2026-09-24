<?php
/**
 * @var array  $subjects
 * @var string $sample
 * @var string $guide
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
?>
<div class="ad-page">
    <div class="ad-two">
        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-green"><?php $icon('download'); ?></span>
                <div><h3>خروجی JSON</h3><p>همه درسنامه‌ها یا درسنامه‌های یک درس، با متن کامل، برچسب‌ها و مسیر درس.</p></div>
            </header>
            <form method="get" action="/admin/lessons/export" class="ad-form-grid">
                <label class="hx-field">درس
                    <select class="input" name="subject">
                        <option value="0">همه درسنامه‌ها</option>
                        <?php foreach ($subjects as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['label']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <div><button class="btn btn-primary" type="submit"><?php $icon('download', 15); ?> دانلود JSON</button></div>
            </form>
        </section>

        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-blue"><?php $icon('upload'); ?></span>
                <div><h3>ورود JSON</h3><p>درسنامه با <code>uuid</code> موجود به‌روز می‌شود و بقیه ساخته می‌شوند. برچسب‌های تازه خودکار ساخته می‌شوند.</p></div>
            </header>
            <form method="post" action="/admin/lessons/import" enctype="multipart/form-data">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <div class="ad-form-grid">
                    <label class="hx-field">فایل JSON<input class="input" type="file" name="file" accept=".json,application/json"></label>
                    <label class="hx-field">درس پیش‌فرض (اگر در فایل نیامده باشد)
                        <select class="input" name="subject">
                            <option value="0">—</option>
                            <?php foreach ($subjects as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['label']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="hx-field is-wide">یا متن JSON را بچسبانید
                        <textarea class="input" name="json" rows="7" dir="ltr" style="font-family:ui-monospace,monospace;font-size:12.5px" placeholder='{"format":"helexa-lessons","lessons":[...]}'></textarea>
                    </label>
                    <label class="hx-switch is-wide"><input type="checkbox" name="publish" value="1"><span class="hx-switch-ui"></span><span>همه منتشر شوند</span></label>
                </div>
                <button class="btn btn-primary" type="submit"><?php $icon('upload', 15); ?> ورود</button>
            </form>
        </section>
    </div>

    <section class="ad-card">
        <header class="ad-card-head">
            <span class="app-ic tone-violet"><?php $icon('sparkle'); ?></span>
            <div><h3>ساخت درسنامه با هوش مصنوعی</h3><p>این دستور را به ChatGPT یا Claude بدهید و موضوع را بنویسید؛ JSON خروجی را همین‌جا وارد کنید.</p></div>
            <div class="ad-actions"><button class="btn btn-ghost btn-sm" type="button" data-copy-target="#lesson-guide"><?php $icon('copy', 15); ?> کپی دستور</button></div>
        </header>
        <textarea class="input" id="lesson-guide" rows="12" readonly style="font-size:12.5px;line-height:1.9"><?= e($guide) ?></textarea>
    </section>

    <section class="ad-card">
        <header class="ad-card-head">
            <span class="app-ic tone-slate"><?php $icon('note'); ?></span>
            <div><h3>نمونه فایل</h3></div>
            <div class="ad-actions"><button class="btn btn-ghost btn-sm" type="button" data-copy-target="#lesson-sample"><?php $icon('copy', 15); ?> کپی</button></div>
        </header>
        <textarea class="input" id="lesson-sample" rows="14" readonly dir="ltr" style="font-family:ui-monospace,monospace;font-size:12px"><?= e($sample) ?></textarea>
    </section>
</div>
