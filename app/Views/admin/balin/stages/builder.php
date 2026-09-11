<?php
/**
 * The stage builder.
 *
 * Each block carries its own small form rather than the stage being one
 * large document — that keeps a save unambiguous, and two admins editing
 * different blocks of the same stage never collide.
 *
 * @var array $stage
 * @var array $lesson
 * @var array $blocks
 * @var array $characters
 * @var array $questions
 * @var array $media
 * @var array $exams
 */
$chips = ['published' => 'chip-green', 'draft' => 'chip-gray', 'archived' => 'chip-red'];
$names = ['published' => 'منتشرشده', 'draft' => 'پیش‌نویس', 'archived' => 'بایگانی'];

$types = [
    'chat'              => 'پیام گفت‌وگو',
    'text'              => 'متن',
    'finding'           => 'یافته بالینی',
    'hint'              => 'راهنما',
    'warning'           => 'هشدار',
    'system'            => 'پیام سیستمی',
    'question'          => 'سؤال',
    'image'             => 'تصویر',
    'audio'             => 'صوت',
    'video'             => 'ویدیو',
    'divider'           => 'جداکننده',
    'checkpoint_anchor' => 'ارجاع به آزمون',
];

/** Renders the shared body of a block form; used for both add and edit. */
$fields = static function (array $block, array $characters, array $questions, array $media, array $exams, array $types): void { ?>
    <label class="field">
        <span>نوع بلوک</span>
        <select name="block_type" required>
            <?php foreach ($types as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= ($block['block_type'] ?? '') === $value ? 'selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="field">
        <span>شخصیت (برای پیام گفت‌وگو)</span>
        <select name="character_id">
            <option value="">—</option>
            <?php foreach ($characters as $character): ?>
                <option value="<?= (int) $character['id'] ?>"
                    <?= (int) ($block['character_id'] ?? 0) === (int) $character['id'] ? 'selected' : '' ?>>
                    <?= e($character['icon'] ?? '') ?> <?= e($character['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="field span-2">
        <span>متن</span>
        <textarea name="body" rows="3"><?= e((string) ($block['body'] ?? '')) ?></textarea>
    </label>

    <label class="field">
        <span>سؤال (برای بلوک سؤال)</span>
        <select name="question_id">
            <option value="">—</option>
            <?php foreach ($questions as $question): ?>
                <option value="<?= (int) $question['id'] ?>"
                    <?= (int) ($block['question_id'] ?? 0) === (int) $question['id'] ? 'selected' : '' ?>>
                    <?= e(mb_substr((string) $question['prompt'], 0, 60, 'UTF-8')) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="field">
        <span>رسانه (تصویر / صوت / ویدیو)</span>
        <select name="media_id">
            <option value="">—</option>
            <?php foreach ($media as $file): ?>
                <option value="<?= (int) $file['id'] ?>"
                    <?= (int) ($block['media_id'] ?? 0) === (int) $file['id'] ? 'selected' : '' ?>>
                    [<?= e($file['kind']) ?>] <?= e($file['original_name'] ?: $file['storage_path']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="field">
        <span>آزمون (برای ارجاع)</span>
        <select name="checkpoint_exam_id">
            <option value="">—</option>
            <?php foreach ($exams as $exam): ?>
                <option value="<?= (int) $exam['id'] ?>"
                    <?= (int) ($block['checkpoint_exam_id'] ?? 0) === (int) $exam['id'] ? 'selected' : '' ?>>
                    <?= e($exam['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="field">
        <span>سمت نمایش</span>
        <select name="side_override">
            <option value="">پیش‌فرض شخصیت</option>
            <option value="right" <?= ($block['side_override'] ?? '') === 'right' ? 'selected' : '' ?>>راست</option>
            <option value="left"  <?= ($block['side_override'] ?? '') === 'left'  ? 'selected' : '' ?>>چپ</option>
        </select>
    </label>

    <label class="field">
        <span>وضعیت</span>
        <select name="status">
            <option value="published" <?= ($block['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>منتشرشده</option>
            <option value="draft"     <?= ($block['status'] ?? '') === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
        </select>
    </label>

    <label class="field check">
        <input type="checkbox" name="is_required" value="1"
               <?= (int) ($block['is_required'] ?? 1) === 1 ? 'checked' : '' ?>>
        <span>الزامی است (تا انجام نشود مرحله کامل نمی‌شود)</span>
    </label>
<?php };
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;"><?= e($stage['title']) ?></h3>
        <div class="row-actions">
            <a class="btn btn-ghost btn-sm" href="/admin/balin/lessons/<?= e($lesson['uuid']) ?>">بازگشت به درس</a>
            <?php if (can('balin.publish')): ?>
                <form method="post" action="/admin/balin/stages/<?= e($stage['uuid']) ?>/status">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="status" value="<?= $stage['status'] === 'published' ? 'draft' : 'published' ?>">
                    <button class="btn btn-primary btn-sm" type="submit">
                        <?= $stage['status'] === 'published' ? 'بازگشت به پیش‌نویس' : 'انتشار مرحله' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="balin-inline-meta">
        <span class="stat-chip <?= e($chips[$stage['status']] ?? 'chip-gray') ?>">
            <?= e($names[$stage['status']] ?? $stage['status']) ?>
        </span>
        <span class="leaf-meta"><?= e($lesson['title']) ?></span>
    </div>

    <?php if (can('balin.edit')): ?>
        <form method="post" action="/admin/balin/stages/<?= e($stage['uuid']) ?>" class="form-grid">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <input type="hidden" name="version" value="<?= (int) $stage['version'] ?>">

            <label class="field"><span>عنوان *</span>
                <input type="text" name="title" required maxlength="191" value="<?= e($stage['title']) ?>"></label>
            <label class="field"><span>زیرعنوان</span>
                <input type="text" name="subtitle" maxlength="191" value="<?= e((string) $stage['subtitle']) ?>"></label>
            <label class="field"><span>امتیاز</span>
                <input type="number" name="xp_reward" min="0" max="10000" value="<?= (int) $stage['xp_reward'] ?>"></label>
            <label class="field"><span>مدت (دقیقه)</span>
                <input type="number" name="estimated_minutes" min="0" max="1000" value="<?= (int) $stage['estimated_minutes'] ?>"></label>
            <label class="field check">
                <input type="checkbox" name="is_final_case" value="1" <?= (int) $stage['is_final_case'] === 1 ? 'checked' : '' ?>>
                <span>کیس نهایی</span></label>
            <label class="field span-2"><span>توضیح</span>
                <textarea name="description" rows="2"><?= e((string) $stage['description']) ?></textarea></label>

            <div class="form-actions span-2"><button class="btn btn-primary btn-sm" type="submit">ذخیره مرحله</button></div>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">بلوک‌ها</h3>
        <?php if (can('balin.edit') && $blocks !== []): ?>
            <form method="post" action="/admin/balin/stages/<?= e($stage['uuid']) ?>/rebalance">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button class="btn btn-ghost btn-sm" type="submit">مرتب‌سازی ترتیب</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($blocks === []): ?>
        <div class="empty">هنوز بلوکی اضافه نشده است. از فرم پایین شروع کن.</div>
    <?php else: ?>
        <ol class="balin-block-list">
            <?php foreach ($blocks as $index => $block): ?>
                <li class="balin-block" id="block-<?= (int) $block['id'] ?>">
                    <div class="balin-block-head">
                        <span class="balin-block-order"><?= e(fa($index + 1)) ?></span>
                        <span class="balin-block-type"><?= e($types[$block['block_type']] ?? $block['block_type']) ?></span>
                        <?php if ((int) $block['is_required'] === 1): ?>
                            <span class="stat-chip chip-blue">الزامی</span>
                        <?php endif; ?>
                        <?php if ($block['status'] !== 'published'): ?>
                            <span class="stat-chip chip-gray">پیش‌نویس</span>
                        <?php endif; ?>

                        <span class="balin-block-actions">
                            <?php if (can('balin.edit')): ?>
                                <form method="post" action="/admin/balin/blocks/<?= e($block['uuid']) ?>/move">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button class="btn btn-ghost btn-sm" type="submit" aria-label="بالا">↑</button>
                                </form>
                                <form method="post" action="/admin/balin/blocks/<?= e($block['uuid']) ?>/move">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button class="btn btn-ghost btn-sm" type="submit" aria-label="پایین">↓</button>
                                </form>
                            <?php endif; ?>
                            <?php if (can('balin.create')): ?>
                                <form method="post" action="/admin/balin/blocks/<?= e($block['uuid']) ?>/duplicate">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">تکثیر</button>
                                </form>
                            <?php endif; ?>
                            <?php if (can('balin.delete')): ?>
                                <form method="post" action="/admin/balin/blocks/<?= e($block['uuid']) ?>/delete"
                                      data-confirm="این بلوک حذف شود؟">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                </form>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="balin-block-preview">
                        <?php if ($block['character_name']): ?>
                            <strong><?= e($block['character_name']) ?>:</strong>
                        <?php endif; ?>
                        <?php if ($block['body']): ?>
                            <?= e(mb_substr((string) $block['body'], 0, 140, 'UTF-8')) ?>
                        <?php elseif ($block['question_prompt']): ?>
                            <?= e(mb_substr((string) $block['question_prompt'], 0, 140, 'UTF-8')) ?>
                        <?php elseif ($block['media_path']): ?>
                            <span class="leaf-meta"><?= e($block['media_kind']) ?> — <?= e((string) $block['media_path']) ?></span>
                        <?php elseif ($block['exam_title']): ?>
                            🎯 <?= e($block['exam_title']) ?>
                        <?php else: ?>
                            <span class="leaf-meta">—</span>
                        <?php endif; ?>
                    </div>

                    <?php if (can('balin.edit')): ?>
                        <details class="balin-block-edit">
                            <summary>ویرایش</summary>
                            <form method="post" action="/admin/balin/blocks/<?= e($block['uuid']) ?>" class="form-grid">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <input type="hidden" name="version" value="<?= (int) $block['version'] ?>">
                                <?php $fields($block, $characters, $questions, $media, $exams, $types); ?>
                                <div class="form-actions span-2">
                                    <button class="btn btn-primary btn-sm" type="submit">ذخیره بلوک</button>
                                </div>
                            </form>
                        </details>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if (can('balin.create')): ?>
        <details class="balin-block-add" open>
            <summary>افزودن بلوک</summary>
            <form method="post" action="/admin/balin/stages/<?= e($stage['uuid']) ?>/blocks" class="form-grid">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <?php $fields(['is_required' => 1, 'status' => 'published'], $characters, $questions, $media, $exams, $types); ?>
                <div class="form-actions span-2">
                    <button class="btn btn-primary btn-sm" type="submit">افزودن</button>
                </div>
            </form>
        </details>
    <?php endif; ?>
</div>

<?php if (can('balin.delete')): ?>
    <div class="card danger-zone">
        <h3 class="card-title">حذف مرحله</h3>
        <form method="post" action="/admin/balin/stages/<?= e($stage['uuid']) ?>/delete"
              data-confirm="این مرحله و همه بلوک‌های آن حذف شود؟">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <button class="btn btn-danger btn-sm" type="submit">حذف مرحله</button>
        </form>
    </div>
<?php endif; ?>
