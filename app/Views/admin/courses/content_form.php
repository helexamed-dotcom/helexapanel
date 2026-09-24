<div class="card" style="max-width:760px;">
    <h3 class="card-title"><?= $content === null ? 'افزودن محتوای HTML' : 'ویرایش محتوا' ?></h3>

    <form method="post" enctype="multipart/form-data"
          action="<?= $content === null
              ? '/admin/courses/' . e($course['uuid']) . '/contents'
              : '/admin/courses/' . e($course['uuid']) . '/contents/' . e($content['uuid']) ?>" novalidate>
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

        <div class="field">
            <label class="label" for="title">عنوان</label>
            <input class="input<?= isset($errors['title']) ? ' has-error' : '' ?>" id="title" name="title"
                   placeholder="مثلاً جزوه کامل درس ۱" value="<?= e($old['title'] ?? '') ?>" required>
            <?php if (!empty($errors['title'])): ?><div class="field-error"><?= e($errors['title']) ?></div><?php endif; ?>
        </div>

        <div class="form-grid">
            <div class="field">
                <label class="label" for="section_id">بخش</label>
                <select class="input" id="section_id" name="section_id">
                    <option value="">ریشه دوره</option>
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= (int) $section['id'] ?>" <?= (int) ($old['section_id'] ?? 0) === (int) $section['id'] ? 'selected' : '' ?>>
                            <?= e(str_repeat('— ', (int) $section['depth'])) ?><?= e($section['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label" for="content_type">نوع محتوا</label>
                <select class="input" id="content_type" name="content_type">
                    <?php foreach ([
                        'full_notes' => 'جزوه کامل', 'summary_notes' => 'خلاصه', 'question_bank' => 'بانک تست',
                        'chat_learn' => 'Chat Learn', 'mind_map' => 'Mind Map', 'custom' => 'سفارشی',
                    ] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= ($old['content_type'] ?? 'full_notes') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label" for="status">وضعیت</label>
                <select class="input" id="status" name="status">
                    <?php foreach (['draft' => 'پیش‌نویس', 'published' => 'منتشرشده', 'hidden' => 'مخفی'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= ($old['status'] ?? 'draft') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label class="label" for="description">توضیح کوتاه</label>
            <input class="input" id="description" name="description" value="<?= e($old['description'] ?? '') ?>">
        </div>

        <?php
        $code       = $code ?? null;
        $sourceMode = ($old['source_mode'] ?? '') === 'paste' || ($content !== null && $code !== null)
            ? 'paste' : 'upload';
        ?>
        <div class="field">
            <label class="label">منبع محتوا</label>
            <div class="source-tabs">
                <label class="source-tab<?= $sourceMode === 'upload' ? ' is-on' : '' ?>">
                    <input type="radio" name="source_mode" value="upload" <?= $sourceMode === 'upload' ? 'checked' : '' ?>>
                    <span>آپلود فایل</span>
                </label>
                <label class="source-tab<?= $sourceMode === 'paste' ? ' is-on' : '' ?>">
                    <input type="radio" name="source_mode" value="paste" <?= $sourceMode === 'paste' ? 'checked' : '' ?>>
                    <span>چسباندن کد</span>
                </label>
            </div>
            <?php if (!empty($errors['html_file'])): ?>
                <div class="field-error" style="margin-top:10px;"><?= e($errors['html_file']) ?></div>
            <?php endif; ?>
        </div>

        <div class="source-pane" data-pane="upload" <?= $sourceMode === 'upload' ? '' : 'hidden' ?>>
            <div class="field">
                <label class="label" for="html_file">
                    فایل HTML <?= $content !== null ? '(برای جایگزینی، فایل جدید انتخاب کنید)' : '' ?>
                </label>
                <input class="input" type="file" id="html_file" name="html_file" accept=".html,.htm,text/html">
                <div style="color:var(--ink-3); font-size:12px; margin-top:6px;">
                    مناسب فایل‌های سنگین، مثل جزوه‌ای که تصاویرش داخل خودش جاسازی شده است.
                </div>
            </div>
        </div>

        <div class="source-pane" data-pane="paste" <?= $sourceMode === 'paste' ? '' : 'hidden' ?>>
            <div class="field">
                <label class="label" for="html_code">کد HTML</label>
                <textarea class="input code-editor" id="html_code" name="html_code" rows="18" spellcheck="false"
                          dir="ltr" placeholder="&lt;!DOCTYPE html&gt;&#10;&lt;html lang=&quot;fa&quot; dir=&quot;rtl&quot;&gt;..."><?= e($code ?? '') ?></textarea>
                <?php if ($content !== null && $code === null && $content['storage_path'] !== null): ?>
                    <div class="alert alert-error" style="margin-top:10px;">
                        این محتوا برای ویرایش درون‌خطی بزرگ‌تر از حد مجاز است
                        (<?= e(fa(number_format(((int) $content['byte_size']) / 1024, 0))) ?> کیلوبایت).
                        برای تغییر آن از تب «آپلود فایل» استفاده کنید. اگر اینجا کدی بنویسید، جایگزین فایل فعلی می‌شود.
                    </div>
                <?php endif; ?>
                <div style="color:var(--ink-3); font-size:12px; margin-top:6px;">
                    کد را کامل بچسبانید، از <span class="mono">&lt;!DOCTYPE html&gt;</span> تا
                    <span class="mono">&lt;/html&gt;</span>. اگر فقط یک قطعه بچسبانید، سیستم خودش آن را
                    داخل یک سند کامل قرار می‌دهد.
                </div>
            </div>
        </div>

        <div style="color:var(--ink-3); font-size:12px; margin:-4px 0 14px;">
            در هر دو حالت، کد شما دست‌نخورده ذخیره می‌شود؛ هیچ تگ، استایل یا اسکریپتی حذف نمی‌شود.
            محل ذخیره خارج از <span class="mono">public_html</span> است و فقط پس از بررسی دسترسی سرو می‌شود.
        </div>

        <label class="switch-row">
            <input type="checkbox" name="is_printable" value="1" <?= (int) ($old['is_printable'] ?? 0) === 1 ? 'checked' : '' ?>>
            <span>چاپ این محتوا مجاز باشد (پیش‌فرض: مسدود)</span>
        </label>

        <?php if (!empty($report)): ?>
            <h4 class="card-title" style="margin-top:22px;">گزارش بررسی فایل</h4>
            <table class="data" style="min-width:auto;">
                <tr><th>حجم</th><td><?= e(fa(number_format(((int) $report['bytes']) / 1024, 0))) ?> کیلوبایت</td></tr>
                <tr><th>تصاویر جاسازی‌شده</th><td><?= e(fa((string) $report['inline_images'])) ?></td></tr>
                <tr><th>فونت جاسازی‌شده</th><td><?= e(fa((string) $report['inline_fonts'])) ?></td></tr>
                <tr><th>بلوک اسکریپت / استایل</th><td><?= e(fa((string) $report['script_blocks'])) ?> / <?= e(fa((string) $report['style_blocks'])) ?></td></tr>
                <tr><th>دامنه‌های بیرونی</th>
                    <td class="mono"><?= $report['external_hosts'] === [] ? '—' : e(implode('، ', $report['external_hosts'])) ?></td></tr>
                <tr><th>استفاده از localStorage</th>
                    <td><?= $report['uses_storage'] ? 'بله — سیستم آن را روی سرور نگه می‌دارد' : 'خیر' ?></td></tr>
                <tr><th>درخواست شبکه‌ای</th>
                    <td><?= $report['uses_network'] ? 'بله — در نمایشگر مسدود می‌شود' : 'خیر' ?></td></tr>
            </table>
        <?php endif; ?>

        <div style="display:flex; gap:10px; margin-top:18px;">
            <button class="btn btn-primary" type="submit"><?= $content === null ? 'آپلود و ثبت' : 'ذخیره' ?></button>
            <a class="btn btn-ghost" href="/admin/courses/<?= e($course['uuid']) ?>/builder">انصراف</a>
        </div>
    </form>
</div>

<script nonce="<?= e($cspNonce ?? '') ?>">
(function () {
    var tabs  = document.querySelectorAll('.source-tab input');
    var panes = document.querySelectorAll('.source-pane');
    function sync() {
        var mode = document.querySelector('.source-tab input:checked').value;
        panes.forEach(function (pane) { pane.hidden = pane.getAttribute('data-pane') !== mode; });
        document.querySelectorAll('.source-tab').forEach(function (tab) {
            tab.classList.toggle('is-on', tab.querySelector('input').checked);
        });
    }
    tabs.forEach(function (input) { input.addEventListener('change', sync); });
    sync();
})();
</script>
