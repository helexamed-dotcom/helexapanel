<?php
/**
 * One "text or image" field: a textarea, an upload button, and paste support.
 *
 * Three channels travel for every field, and the server decides which wins
 * (see QuestionController::resolveImage):
 *
 *   dataName    hidden — a data: URL written by qbank.js when an image is
 *               pasted or dropped
 *   fileName    a real file input, for the upload button
 *   removeName  hidden — "1" when the admin removes the existing image
 *
 * With JavaScript off, the textarea and the file input still work; only
 * paste and drop need script.
 *
 * @var string      $textName
 * @var string|null $textValue
 * @var string      $dataName
 * @var string      $fileName
 * @var string      $removeName
 * @var string|null $image       existing stored filename
 * @var string      $placeholder
 * @var int         $rows
 * @var string      $label       accessible name
 */
$fieldId = 'qb-' . preg_replace('/[^a-z0-9]/i', '-', $textName);
$hasImg  = !empty($image);
?>
<div class="qb-rich" data-qb-rich>
    <textarea id="<?= e($fieldId) ?>" name="<?= e($textName) ?>" rows="<?= (int) ($rows ?? 3) ?>"
              maxlength="8000" aria-label="<?= e($label ?? '') ?>"
              placeholder="<?= e($placeholder ?? '') ?>"><?= e($textValue ?? '') ?></textarea>

    <div class="qb-preview<?= $hasImg ? ' has-image' : '' ?>" data-qb-preview>
        <img src="<?= $hasImg ? '/admin/qbank/image/' . e(rawurlencode((string) $image)) : '' ?>" alt="تصویر این بخش" data-qb-img>
        <button type="button" class="qb-preview-remove" data-qb-remove aria-label="حذف تصویر">×</button>
        <span class="qb-preview-badge" data-qb-badge><?= $hasImg ? 'تصویر فعلی' : '' ?></span>
    </div>

    <input type="hidden" name="<?= e($dataName) ?>" value="" data-qb-data>
    <input type="hidden" name="<?= e($removeName) ?>" value="" data-qb-remove-flag>

    <div class="qb-rich-bar">
        <div class="qb-rich-tools">
            <label class="qb-tool">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'image']); ?>
                آپلود تصویر
                <input type="file" name="<?= e($fileName) ?>" accept="image/jpeg,image/png,image/webp,image/gif" data-qb-file>
            </label>
        </div>
        <span class="qb-rich-tip">می‌توانی عکس را مستقیم اینجا <strong>Ctrl+V</strong> کنی یا بکشی و رها کنی</span>
    </div>
</div>
