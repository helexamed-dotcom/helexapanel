<?php
/**
 * @var array      $lessons
 * @var string     $selected
 * @var string     $prompt
 * @var array|null $report
 * @var bool       $canImport
 * @var bool       $canPublish
 * @var bool       $clinicalReady
 */
$c = $report['counts'] ?? [];
?>
<div class="qb-page">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'island']); ?></div>
            <div>
                <h2>ورود و خروج JSON جزیره بالین</h2>
                <p>یک درس کامل — مرحله‌ها، بلوک‌ها، شخصیت‌ها، بانک سوال و آزمون‌ها — را یک‌جا وارد یا خروجی بگیرید؛ یا با هوش مصنوعی بسازید.</p>
            </div>
        </div>
        <div class="qb-hero-actions">
            <a class="btn btn-ghost" href="/admin/balin/transfer/sample">دریافت فایل نمونه</a>
            <a class="btn btn-ghost" href="/admin/balin/lessons">درس‌های بالینی</a>
        </div>
    </section>

    <?php if (!$clinicalReady): ?>
        <div class="alert alert-error"><div>
            بلوک‌های تخصصی (علائم حیاتی، آزمایش، تشخیص افتراقی، نکته کلیدی، منبع) هنوز فعال نیستند؛ فایل
            <span class="mono">database/migrations/2026_09_22_balin_clinical_blocks.sql</span> را در phpMyAdmin اجرا کنید.
            تا آن زمان این بلوک‌ها به‌صورت «یافته بالینی / راهنما / متن» وارد می‌شوند.
        </div></div>
    <?php endif; ?>

    <?php if ($report !== null): ?>
        <section class="qb-section" id="report">
            <div class="qb-section-head"><h3><?= $report['dry_run'] ? '🔎 نتیجه بررسی (چیزی ذخیره نشد)' : '✅ نتیجه ورود' ?></h3></div>
            <div class="xfer-stats">
                <div class="ok"><b><?= e(fa((string) ($c['lessons'] ?? 0))) ?></b><span>درس</span></div>
                <div><b><?= e(fa((string) ($c['stages'] ?? 0))) ?></b><span>مرحله</span></div>
                <div><b><?= e(fa((string) ($c['blocks'] ?? 0))) ?></b><span>بلوک</span></div>
                <div><b><?= e(fa((string) ($c['questions'] ?? 0))) ?></b><span>سؤال</span></div>
                <div><b><?= e(fa((string) ($c['exams'] ?? 0))) ?></b><span>آزمون</span></div>
                <div class="info"><b><?= e(fa((string) (($c['characters'] ?? 0) + ($c['tracks'] ?? 0)))) ?></b><span>شخصیت / مهارت جدید</span></div>
                <div class="bad"><b><?= e(fa((string) count($report['errors']))) ?></b><span>خطا</span></div>
            </div>

            <?php if (!$report['dry_run'] && $report['lessons'] !== []): ?>
                <h4 class="xfer-h">درس‌های واردشده</h4>
                <ul class="xfer-list">
                    <?php foreach ($report['lessons'] as $l): ?>
                        <li><a href="/admin/balin/lessons/<?= e($l['uuid']) ?>"><?= e($l['title']) ?></a> — <?= e(fa((string) $l['stages'])) ?> مرحله</li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($report['errors'] !== []): ?>
                <h4 class="xfer-h">خطاها (این درس‌ها وارد نشدند)</h4>
                <ul class="xfer-list bad"><?php foreach ($report['errors'] as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if ($report['warnings'] !== []): ?>
                <h4 class="xfer-h">هشدارها (<?= e(fa((string) $report['warning_count'])) ?>)</h4>
                <ul class="xfer-list"><?php foreach ($report['warnings'] as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="xfer-grid">
        <section class="qb-section">
            <div class="qb-section-head"><h3><span class="qb-step">⬇</span> خروجی گرفتن</h3></div>
            <form method="get" action="/admin/balin/transfer/export">
                <div class="field"><label class="label" for="bx-lesson">درس</label>
                    <select class="input" id="bx-lesson" name="lesson">
                        <option value="">همه درس‌ها</option>
                        <?php foreach ($lessons as $l): ?>
                            <option value="<?= e($l['uuid']) ?>" <?= $selected === $l['uuid'] ? 'selected' : '' ?>><?= e(($l['icon'] ?: '') . ' ' . $l['title']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <p class="qb-hint">خروجی شامل مرحله‌ها، همه بلوک‌ها به ترتیب، شخصیت‌ها، مهارت‌ها، بانک سوال و آزمون‌هاست.
                    فایل‌های رسانه با شناسه‌شان می‌آیند (فقط روی همین سایت قابل استفاده‌اند).</p>
                <div class="row-actions"><button class="btn btn-primary" type="submit">دریافت فایل JSON</button></div>
            </form>
        </section>

        <?php if ($canImport): ?>
        <section class="qb-section">
            <div class="qb-section-head"><h3><span class="qb-step">⬆</span> وارد کردن</h3></div>
            <form method="post" action="/admin/balin/transfer/import" enctype="multipart/form-data">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <div class="field"><label class="label" for="bi-file">فایل ‎.json</label>
                    <input class="input" id="bi-file" type="file" name="file" accept=".json,application/json"></div>
                <div class="field"><label class="label" for="bi-json">یا متن JSON (مثلاً جواب هوش مصنوعی)</label>
                    <textarea class="input xfer-code" id="bi-json" name="json" rows="7" dir="ltr" spellcheck="false" placeholder='{"format":"helexa-balin","lessons":[ ... ]}'></textarea></div>
                <div class="field"><label class="label" for="bi-status">وضعیت درس‌ها</label>
                    <select class="input" id="bi-status" name="status">
                        <option value="keep">طبق فایل</option>
                        <option value="draft">همه پیش‌نویس (پیشنهادی برای بازبینی)</option>
                        <?php if ($canPublish): ?><option value="published">همه منتشرشده</option><?php endif; ?>
                    </select></div>
                <label class="remember-row"><input type="checkbox" name="create_missing" value="1" checked>
                    <span>شخصیت‌ها و مهارت‌هایی که وجود ندارند ساخته شوند</span></label>
                <label class="remember-row"><input type="checkbox" name="dry_run" value="1">
                    <span>فقط بررسی کن، ذخیره نکن</span></label>
                <p class="qb-hint">هر درس به‌صورت کامل وارد می‌شود یا اصلاً وارد نمی‌شود. درس‌ها همیشه جدید ساخته می‌شوند
                    (اگر نشانی تکراری باشد شماره می‌گیرد) و به درس‌های موجود دست نمی‌زند.</p>
                <div class="row-actions"><button class="btn btn-primary" type="submit" data-lock-on-submit>وارد کردن</button></div>
            </form>
        </section>
        <?php endif; ?>
    </div>

    <section class="qb-section">
        <div class="qb-section-head">
            <h3><span class="qb-step">✨</span> ساخت درس با هوش مصنوعی</h3>
            <button class="btn btn-ghost btn-sm" type="button" data-copy-target="#balin-prompt">کپی پرامپت</button>
        </div>
        <p class="qb-hint">این متن را در ChatGPT، Claude یا Gemini بچسبانید، بخش «موضوع و مشخصات» بالای آن را پر کنید
            و جواب را در کادر «وارد کردن» بگذارید. پیشنهاد: اول با «فقط بررسی» امتحان کنید، بعد به‌صورت پیش‌نویس وارد کنید
            و قبل از انتشار، محتوای علمی را یک عضو هیئت علمی بازبینی کند.</p>
        <textarea class="input xfer-code" id="balin-prompt" rows="18" readonly dir="rtl"><?= e($prompt) ?></textarea>
    </section>

    <section class="qb-section">
        <div class="qb-section-head"><h3>راهنمای فیلدها</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>بخش</th><th>فیلدها</th></tr></thead>
                <tbody>
                    <tr><td>درس</td><td dir="ltr">title, slug, description, icon, color, estimated_minutes, xp_reward, extra_notes, status, stages[], question_bank[], checkpoint_exams[]</td></tr>
                    <tr><td>مرحله</td><td dir="ltr">key, title, subtitle, description, xp_reward, estimated_minutes, is_final_case, status, blocks[]</td></tr>
                    <tr><td>بلوک</td><td dir="ltr">type, text, character, side (left|right), required, status, question{…} | question_ref, rows[] (vitals/lab), items[] (ddx/reference/pearl), media (uuid) | image (data:image/…;base64), exam_ref</td></tr>
                    <tr><td>نوع بلوک</td><td dir="ltr">chat, system, text, finding, hint, warning, vitals, lab, ddx, pearl, reference, question, image, audio, video, divider, checkpoint_anchor</td></tr>
                    <tr><td>سؤال</td><td dir="ltr">key, prompt, options[{text, correct}] | options[] + answer, explanation, hint, difficulty, xp_reward, required, final_case_step, skill_tracks[], stage</td></tr>
                    <tr><td>آزمون</td><td dir="ltr">key, title, description, position (before_stage|after_stage|after_lesson), anchor_stage, mode (fixed_list|random_pool), num_questions, pass_percent, gating, max_attempts, cooldown_hours, time_limit_minutes, xp_reward, primary_skill_track, questions[]</td></tr>
                    <tr><td>شخصیت</td><td dir="ltr">name, type (teacher|student|doctor|patient|nurse|other), gender, icon, side, color</td></tr>
                    <tr><td>مهارت</td><td dir="ltr">slug, name, name_en, category, icon, color, description</td></tr>
                    <tr><td>vitals</td><td dir="ltr">rows: [{name, value, unit, flag: normal|high|low|critical}]</td></tr>
                    <tr><td>lab</td><td dir="ltr">rows: [{test, result, unit, range, flag}]</td></tr>
                </tbody>
            </table>
        </div>
    </section>
</div>