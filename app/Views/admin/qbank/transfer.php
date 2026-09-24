<?php
/**
 * JSON import / export for the question bank.
 *
 * @var array       $tree
 * @var array       $tags
 * @var array       $difficulties
 * @var string      $prompt
 * @var array|null  $report
 * @var bool        $canWrite
 * @var bool        $canPublish
 */
$indent = [1 => '', 2 => '— ', 3 => '—— '];
?>
<div class="qb-page">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'exam']); ?></div>
            <div>
                <h2>ورود و خروج JSON</h2>
                <p>سوال‌ها را با درس، برچسب، سطح سختی، گزینه‌ها و پاسخ تشریحی یکجا خروجی بگیرید، یا فایلی که خودتان یا هوش مصنوعی ساخته وارد کنید.</p>
            </div>
        </div>
        <div class="qb-hero-actions">
            <a class="btn btn-ghost" href="/admin/qbank/transfer/sample">دریافت فایل نمونه</a>
        </div>
    </section>

    <?php if ($report !== null): ?>
        <section class="qb-section" id="report">
            <div class="qb-section-head"><h3><?= $report['dry_run'] ? '🔎 نتیجه بررسی (چیزی ذخیره نشد)' : '✅ نتیجه ورود' ?></h3></div>
            <div class="xfer-stats">
                <div><b><?= e(fa((string) $report['total'])) ?></b><span>سوال در فایل</span></div>
                <div class="ok"><b><?= e(fa((string) $report['created'])) ?></b><span><?= $report['dry_run'] ? 'ساخته می‌شود' : 'ساخته شد' ?></span></div>
                <div class="info"><b><?= e(fa((string) $report['updated'])) ?></b><span><?= $report['dry_run'] ? 'به‌روز می‌شود' : 'به‌روز شد' ?></span></div>
                <div class="bad"><b><?= e(fa((string) $report['error_count'])) ?></b><span>خطا</span></div>
                <div><b><?= e(fa((string) $report['subjects'])) ?> / <?= e(fa((string) $report['tags'])) ?></b><span>درس / برچسب جدید</span></div>
            </div>
            <?php if ($report['errors'] !== []): ?>
                <h4 class="xfer-h">خطاها</h4>
                <ul class="xfer-list bad">
                    <?php foreach ($report['errors'] as $n => $msg): ?><li><b>سوال <?= e(fa((string) $n)) ?>:</b> <?= e($msg) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($report['warnings'] !== []): ?>
                <h4 class="xfer-h">هشدارها</h4>
                <ul class="xfer-list">
                    <?php foreach ($report['warnings'] as $n => $msg): ?><li><b>سوال <?= e(fa((string) $n)) ?>:</b> <?= e($msg) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!$report['dry_run'] && ($report['created'] + $report['updated']) > 0): ?>
                <div class="row-actions"><a class="btn btn-primary btn-sm" href="/admin/qbank/questions">مشاهده سوالات</a></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="xfer-grid">
        <section class="qb-section">
            <div class="qb-section-head"><h3><span class="qb-step">⬇</span> خروجی گرفتن</h3></div>
            <form method="get" action="/admin/qbank/export">
                <div class="field"><label class="label" for="xf-s">درس</label>
                    <select class="input" id="xf-s" name="subject_id">
                        <option value="">همه سوالات</option>
                        <option value="unfiled">بدون طبقه‌بندی</option>
                        <?php foreach ($tree as $node): ?>
                            <option value="<?= (int) $node['id'] ?>"><?= e(($indent[(int) $node['depth']] ?? '') . $node['title']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="qb-grid-3">
                    <div class="field" style="margin:0;"><label class="label" for="xf-d">سختی</label>
                        <select class="input" id="xf-d" name="difficulty"><option value="">همه</option>
                            <?php foreach ($difficulties as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="field" style="margin:0;"><label class="label" for="xf-st">وضعیت</label>
                        <select class="input" id="xf-st" name="status"><option value="">همه</option>
                            <option value="published">منتشرشده</option><option value="draft">پیش‌نویس</option></select></div>
                    <div class="field" style="margin:0;"><label class="label" for="xf-t">برچسب</label>
                        <select class="input" id="xf-t" name="tag_id"><option value="">همه</option>
                            <?php foreach ($tags as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['title']) ?></option><?php endforeach; ?>
                        </select></div>
                </div>
                <label class="remember-row" style="margin-top:12px;"><input type="checkbox" name="images" value="1">
                    <span>تصاویر هم داخل فایل باشند (حجم فایل بیشتر می‌شود)</span></label>
                <div class="row-actions"><button class="btn btn-primary" type="submit">دریافت فایل JSON</button></div>
            </form>
        </section>

        <?php if ($canWrite): ?>
        <section class="qb-section">
            <div class="qb-section-head"><h3><span class="qb-step">⬆</span> وارد کردن</h3></div>
            <form method="post" action="/admin/qbank/import" enctype="multipart/form-data">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <div class="field"><label class="label" for="xi-f">فایل ‎.json</label>
                    <input class="input" id="xi-f" type="file" name="file" accept=".json,application/json"></div>
                <div class="field"><label class="label" for="xi-j">یا متن JSON را اینجا بچسبانید</label>
                    <textarea class="input xfer-code" id="xi-j" name="json" rows="7" dir="ltr" spellcheck="false" placeholder='{"format":"helexa-qbank","questions":[ ... ]}'></textarea></div>
                <div class="field"><label class="label" for="xi-s">وضعیت سوال‌ها</label>
                    <select class="input" id="xi-s" name="status">
                        <option value="keep">طبق فایل</option>
                        <option value="draft">همه پیش‌نویس</option>
                        <?php if ($canPublish): ?><option value="published">همه منتشرشده</option><?php endif; ?>
                    </select></div>
                <label class="remember-row"><input type="checkbox" name="create_missing" value="1" checked>
                    <span>درس‌ها، زیردرس‌ها و برچسب‌هایی که وجود ندارند ساخته شوند</span></label>
                <label class="remember-row"><input type="checkbox" name="dry_run" value="1">
                    <span>فقط بررسی کن، ذخیره نکن</span></label>
                <p class="qb-hint">سوالی که «id» آن با سوال موجود یکی باشد به‌روز می‌شود؛ بقیه سوال جدید ثبت می‌شوند. سوال منتشرشده باید دقیقاً یک گزینه صحیح داشته باشد، وگرنه پیش‌نویس ثبت می‌شود.</p>
                <div class="row-actions"><button class="btn btn-primary" type="submit" data-lock-on-submit>وارد کردن</button></div>
            </form>
        </section>
        <?php endif; ?>
    </div>

    <section class="qb-section">
        <div class="qb-section-head">
            <h3><span class="qb-step">✨</span> ساخت سوال با هوش مصنوعی</h3>
            <button class="btn btn-ghost btn-sm" type="button" data-copy-target="#ai-prompt">کپی متن</button>
        </div>
        <p class="qb-hint">این متن را در ChatGPT، Claude یا Gemini بچسبانید، خط آخر را با موضوع و تعداد سوال عوض کنید و جواب را در کادر «وارد کردن» بگذارید.</p>
        <textarea class="input xfer-code" id="ai-prompt" rows="14" readonly dir="rtl"><?= e($prompt) ?></textarea>
    </section>

    <section class="qb-section">
        <div class="qb-section-head"><h3>راهنمای فیلدها</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>فیلد</th><th>توضیح</th></tr></thead>
                <tbody>
                    <tr><td dir="ltr">id</td><td>اختیاری — شناسه سوال موجود برای به‌روزرسانی (در خروجی هست)</td></tr>
                    <tr><td dir="ltr">subject / sub_subject / topic</td><td>درس، زیردرس، عنوان — با نام؛ اختیاری</td></tr>
                    <tr><td dir="ltr">difficulty</td><td dir="ltr">easy · medium · hard · expert</td></tr>
                    <tr><td dir="ltr">status</td><td dir="ltr">draft · published</td></tr>
                    <tr><td dir="ltr">tags</td><td>آرایه‌ای از نام برچسب‌ها</td></tr>
                    <tr><td dir="ltr">stem · stem_image</td><td>متن صورت سوال، تصویر به شکل data:image/…;base64</td></tr>
                    <tr><td dir="ltr">options</td><td dir="ltr">[{"text": "…", "correct": true, "image": null}] — 2 to 8</td></tr>
                    <tr><td dir="ltr">answer</td><td>جایگزین «correct»: شماره گزینه صحیح (۱، ۲، …) وقتی گزینه‌ها فقط متن‌اند</td></tr>
                    <tr><td dir="ltr">explanation · explanation_image</td><td>پاسخ تشریحی</td></tr>
                </tbody>
            </table>
        </div>
    </section>
</div>