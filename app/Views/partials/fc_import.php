<?php
/**
 * Import cards from a spreadsheet file or from cells pasted out of Excel.
 *
 * @var string $action
 * @var int    $maxRows
 * @var bool   $withSession  course-level import: column D names the session
 */
$withSession = !empty($withSession);
?>
<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="fc-panel" id="import" data-fc-import>
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <div class="fc-head" style="margin-bottom:12px;">
        <h3>📥 ورود کارت از اکسل</h3>
        <div class="fc-tabs" role="tablist">
            <button type="button" class="is-active" data-fc-tab="file" role="tab" aria-selected="true">فایل</button>
            <button type="button" data-fc-tab="paste" role="tab" aria-selected="false">چسباندن از اکسل</button>
        </div>
    </div>

    <div data-fc-pane="file">
        <label class="fc-drop" data-fc-drop>
            <input type="file" name="file" accept=".xlsx,.csv,.tsv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv">
            <span style="font-size:30px;">📄</span>
            <span><strong>فایل را انتخاب کن</strong> یا اینجا بکش و رها کن</span>
            <span class="fc-hint" data-fc-filename>xlsx یا CSV — حداکثر <?= e(fa((string) $maxRows)) ?> ردیف</span>
        </label>
    </div>

    <div data-fc-pane="paste" hidden>
        <div class="field" style="margin:0;">
            <label class="label" for="fc-paste">سلول‌ها را در اکسل انتخاب و کپی کن، سپس اینجا بچسبان</label>
            <textarea class="input" id="fc-paste" name="text" rows="7" dir="auto"
                      placeholder="روی کارت&#9;پشت کارت&#9;راهنما<?= $withSession ? '&#9;جلسه' : '' ?>"></textarea>
        </div>
        <p class="fc-hint" data-fc-paste-count></p>
    </div>

    <table class="fc-sample" aria-label="نمونه ستون‌ها">
        <tr><th>A</th><th>B</th><th>C</th><?php if ($withSession): ?><th>D</th><?php endif; ?></tr>
        <tr><td>روی کارت</td><td>پشت کارت</td><td>راهنما (اختیاری)</td><?php if ($withSession): ?><td>نام جلسه</td><?php endif; ?></tr>
        <tr><td>Abdomen</td><td>شکم</td><td></td><?php if ($withSession): ?><td>جلسه ۱</td><?php endif; ?></tr>
    </table>
    <p class="fc-hint" style="margin-top:8px;">
        ردیف عنوان (مثل «روی کارت / پشت کارت») خودکار نادیده گرفته می‌شود.
        <?php if ($withSession): ?>
            جلسه‌ای که هنوز وجود ندارد ساخته می‌شود؛ ردیف‌های بدون نام جلسه به جلسه پیش‌فرض می‌روند.
        <?php endif; ?>
    </p>

    <div class="fc-inline" style="margin-top:12px;">
        <?php if ($withSession): ?>
            <div class="field">
                <label class="label" for="fc-default-session">جلسه پیش‌فرض</label>
                <input class="input" id="fc-default-session" name="default_session" value="عمومی" maxlength="191">
            </div>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit" data-lock-on-submit>ورود کارت‌ها</button>
    </div>
</form>
