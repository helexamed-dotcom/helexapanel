<?php
/**
 * Write or edit one question, in four numbered steps:
 * filing → question → options → explanation, then a sticky save bar.
 *
 * The three filing selects list every row of their depth, each tagged with
 * its parent. qbank.js narrows each select to the children of the one above
 * it; with script off all rows remain selectable and the server still checks
 * that the chosen path is consistent.
 *
 * @var array|null $question
 * @var array      $options
 * @var array      $tagIds
 * @var array      $prefill
 * @var array      $tree
 * @var array      $tags
 * @var array      $difficulties
 * @var int        $minOptions
 * @var int        $maxOptions
 * @var int        $maxImageKb
 */
$isEdit     = $question !== null;
$action     = $isEdit ? '/admin/qbank/questions/' . $question['uuid'] : '/admin/qbank/questions';
$canPublish = can('qbank.publish');
$status     = $question['status'] ?? 'draft';
$letters    = ['الف', 'ب', 'ج', 'د', 'ه', 'و', 'ز', 'ح'];

$byDepth = [1 => [], 2 => [], 3 => []];
foreach ($tree as $row) {
    $byDepth[(int) $row['depth']][] = $row;
}

$icon = static fn (string $n) => \HeleXa\Core\View::partial('partials.icon', ['name' => $n]);

$filingSelect = function (string $name, int $depth, string $label, string $emptyLabel) use ($byDepth, $prefill): void {
    $current = (int) ($prefill[$name] ?? 0);
    ?>
    <div class="field" style="margin:0;">
        <label class="label" for="qb-<?= e($name) ?>"><?= e($label) ?></label>
        <select class="input" id="qb-<?= e($name) ?>" name="<?= e($name) ?>" data-qb-level="<?= $depth ?>">
            <option value=""><?= e($emptyLabel) ?></option>
            <?php foreach ($byDepth[$depth] as $row): ?>
                <option value="<?= (int) $row['id'] ?>"
                        data-parent="<?= (int) ($row['parent_id'] ?? 0) ?>"
                        <?= $current === (int) $row['id'] ? 'selected' : '' ?>
                        <?= (int) $row['is_active'] !== 1 && $current !== (int) $row['id'] ? 'disabled data-off' : '' ?>>
                    <?= e($row['title']) ?><?= (int) $row['is_active'] !== 1 ? ' (غیرفعال)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php
};
?>
<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data"
      class="qb-page" data-qb-editor data-max-options="<?= (int) $maxOptions ?>"
      data-min-options="<?= (int) $minOptions ?>" data-max-kb="<?= (int) $maxImageKb ?>">
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="version" value="<?= (int) $question['version'] ?>">
    <?php endif; ?>

    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon"><?php $icon('exam'); ?></div>
            <div>
                <h2><?= $isEdit ? 'ویرایش سوال' : 'سوال جدید' ?></h2>
                <p>برای هر بخش می‌توانی متن بنویسی، تصویر آپلود کنی یا عکس را مستقیم بچسبانی.</p>
            </div>
        </div>
        <div class="qb-hero-actions">
            <?php if ($isEdit): ?>
                <a class="btn btn-ghost" href="/admin/qbank/questions/<?= e($question['uuid']) ?>">پیش‌نمایش</a>
            <?php endif; ?>
            <a class="btn btn-ghost" href="/admin/qbank/questions">بازگشت به فهرست</a>
        </div>
    </section>

    <?php /* ------------------------------------------------ 1. filing */ ?>
    <section class="qb-section">
        <div class="qb-section-head">
            <h3><span class="qb-step">۱</span> طبقه‌بندی</h3>
            <a class="qb-hint" href="/admin/qbank/subjects" target="_blank" rel="noopener">مدیریت دروس ↗</a>
        </div>
        <div class="qb-grid-3" data-qb-filing>
            <?php
            $filingSelect('subject_id', 1, 'درس', '— بدون درس —');
            $filingSelect('sub_subject_id', 2, 'زیردرس', '— بدون زیردرس —');
            $filingSelect('topic_id', 3, 'عنوان درس', '— بدون عنوان —');
            ?>
        </div>
        <p class="qb-hint" style="margin-top:8px;">همه اختیاری‌اند. سوال بدون درس ذخیره می‌شود ولی تا درس نگیرد منتشر نمی‌شود.</p>

        <div class="field" style="margin:16px 0 0;">
            <span class="label">درجه سختی</span>
            <div class="qb-seg" role="radiogroup" aria-label="درجه سختی">
                <?php foreach ($difficulties as $key => $label): ?>
                    <label class="<?= e($key) ?>">
                        <input type="radio" name="difficulty" value="<?= e($key) ?>"
                               <?= ($prefill['difficulty'] ?? 'medium') === $key ? 'checked' : '' ?>>
                        <span><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="field" style="margin:16px 0 0;">
            <span class="label">برچسب‌ها</span>
            <?php if ($tags === []): ?>
                <p class="qb-hint">هنوز برچسبی نساخته‌ای. <a href="/admin/qbank/tags">ساخت برچسب</a></p>
            <?php else: ?>
                <div class="qb-tags">
                    <?php foreach ($tags as $tag): ?>
                        <label class="qb-tag-toggle">
                            <input type="checkbox" name="tags[]" value="<?= (int) $tag['id'] ?>"
                                   <?= in_array((int) $tag['id'], $tagIds, true) ? 'checked' : '' ?>>
                            <span><?= e($tag['title']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php /* ------------------------------------------------ 2. stem */ ?>
    <section class="qb-section">
        <div class="qb-section-head">
            <h3><span class="qb-step">۲</span> صورت سوال</h3>
            <span class="qb-hint">حداکثر حجم تصویر: <?= e(fa((string) $maxImageKb)) ?> کیلوبایت</span>
        </div>
        <?php \HeleXa\Core\View::partial('admin.qbank.questions._rich', [
            'textName'    => 'stem_text',
            'textValue'   => $question['stem_text'] ?? '',
            'dataName'    => 'stem_image_data',
            'fileName'    => 'stem_image_file',
            'removeName'  => 'stem_image_remove',
            'image'       => $question['stem_image'] ?? null,
            'placeholder' => 'متن سوال را بنویس… یا تصویر سوال را اینجا بچسبان',
            'rows'        => 5,
            'label'       => 'صورت سوال',
        ]); ?>
    </section>

    <?php /* ------------------------------------------------ 3. options */ ?>
    <section class="qb-section">
        <div class="qb-section-head">
            <h3><span class="qb-step">۳</span> گزینه‌ها</h3>
            <span class="qb-hint">گزینه درست را با «پاسخ صحیح» مشخص کن. ردیف خالی نادیده گرفته می‌شود.</span>
        </div>

        <div class="qb-options" data-qb-options>
            <?php foreach ($options as $i => $option):
                $key = 'k' . $i;
                $isCorrect = (int) ($option['is_correct'] ?? 0) === 1;
            ?>
                <div class="qb-option<?= $isCorrect ? ' is-correct' : '' ?>" data-qb-option>
                    <input type="hidden" name="options[<?= $i ?>][key]" value="<?= e($key) ?>" data-qb-key>
                    <input type="hidden" name="options[<?= $i ?>][uuid]" value="<?= e($option['uuid'] ?? '') ?>">
                    <div class="qb-option-letter" data-qb-letter><?= e($letters[$i] ?? (string) ($i + 1)) ?></div>
                    <div>
                        <?php \HeleXa\Core\View::partial('admin.qbank.questions._rich', [
                            'textName'    => "options[{$i}][text]",
                            'textValue'   => $option['body_text'] ?? '',
                            'dataName'    => "options[{$i}][image_data]",
                            'fileName'    => "option_{$key}_image_file",
                            'removeName'  => "options[{$i}][image_remove]",
                            'image'       => $option['body_image'] ?? null,
                            'placeholder' => 'متن گزینه…',
                            'rows'        => 2,
                            'label'       => 'گزینه ' . ($letters[$i] ?? ($i + 1)),
                        ]); ?>
                    </div>
                    <div class="qb-option-side">
                        <label class="qb-correct-pick">
                            <input type="radio" name="correct" value="<?= e($key) ?>" <?= $isCorrect ? 'checked' : '' ?> data-qb-correct>
                            <span>✓ پاسخ صحیح</span>
                        </label>
                        <button type="button" class="qb-option-remove" data-qb-option-remove>حذف گزینه</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="button" class="qb-add-option" data-qb-add-option style="margin-top:12px;">+ افزودن گزینه</button>

        <?php /* The template the "add option" button clones. __I__ and __K__
                 are replaced with the new row's index and key. */ ?>
        <template data-qb-option-template>
            <div class="qb-option" data-qb-option>
                <input type="hidden" name="options[__I__][key]" value="__K__" data-qb-key>
                <input type="hidden" name="options[__I__][uuid]" value="">
                <div class="qb-option-letter" data-qb-letter></div>
                <div>
                    <?php \HeleXa\Core\View::partial('admin.qbank.questions._rich', [
                        'textName'    => 'options[__I__][text]',
                        'textValue'   => '',
                        'dataName'    => 'options[__I__][image_data]',
                        'fileName'    => 'option___K___image_file',
                        'removeName'  => 'options[__I__][image_remove]',
                        'image'       => null,
                        'placeholder' => 'متن گزینه…',
                        'rows'        => 2,
                        'label'       => 'گزینه',
                    ]); ?>
                </div>
                <div class="qb-option-side">
                    <label class="qb-correct-pick">
                        <input type="radio" name="correct" value="__K__" data-qb-correct>
                        <span>✓ پاسخ صحیح</span>
                    </label>
                    <button type="button" class="qb-option-remove" data-qb-option-remove>حذف گزینه</button>
                </div>
            </div>
        </template>
    </section>

    <?php /* ------------------------------------------------ 4. explanation */ ?>
    <section class="qb-section">
        <div class="qb-section-head">
            <h3><span class="qb-step">۴</span> پاسخ تشریحی</h3>
            <span class="qb-hint">بعد از پاسخ دادن به دانشجو نمایش داده می‌شود.</span>
        </div>
        <?php \HeleXa\Core\View::partial('admin.qbank.questions._rich', [
            'textName'    => 'explanation_text',
            'textValue'   => $question['explanation_text'] ?? '',
            'dataName'    => 'explanation_image_data',
            'fileName'    => 'explanation_image_file',
            'removeName'  => 'explanation_image_remove',
            'image'       => $question['explanation_image'] ?? null,
            'placeholder' => 'توضیح کامل پاسخ… (اختیاری)',
            'rows'        => 5,
            'label'       => 'پاسخ تشریحی',
        ]); ?>
    </section>

    <?php /* ------------------------------------------------ 5. lesson note */ ?>
    <section class="qb-section">
        <div class="qb-section-head">
            <h3><span class="qb-step">۵</span> درسنامه این سوال (اختیاری)</h3>
            <span class="qb-hint">
                اگر خالی بماند، درسنامه عنوان / زیردرس / درسِ همین سوال نمایش داده می‌شود
                (از «دروس و زیردروس» ← 📘 درسنامه).
            </span>
        </div>
        <textarea class="input" name="lesson_note" rows="6"
                  placeholder="## عنوان&#10;- نکته اول&#10;- نکته دوم&#10;**متن پررنگ**"><?= e((string) ($question['lesson_note'] ?? '')) ?></textarea>
    </section>

    <?php /* ------------------------------------------------ save bar */ ?>
    <div class="qb-savebar">
        <div class="qb-status-switch" role="radiogroup" aria-label="وضعیت">
            <label>
                <input type="radio" name="status" value="draft" <?= $status === 'draft' ? 'checked' : '' ?>>
                <span>پیش‌نویس</span>
            </label>
            <label title="<?= $canPublish ? '' : 'انتشار به دسترسی «انتشار سوالات» نیاز دارد' ?>">
                <input type="radio" name="status" value="published" <?= $status === 'published' ? 'checked' : '' ?>
                       <?= !$canPublish && $status !== 'published' ? 'disabled' : '' ?>>
                <span>منتشرشده</span>
            </label>
        </div>
        <div class="row-actions" style="margin:0;">
            <?php if (!$isEdit): ?>
                <button class="btn btn-ghost" type="submit" name="add_another" value="1" data-lock-on-submit>ذخیره و سوال بعدی</button>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit" data-lock-on-submit>ذخیره سوال</button>
        </div>
    </div>
</form>
