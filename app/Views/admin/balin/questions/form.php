<?php
/**
 * Writing a question.
 *
 * The skill tags are the link between a question and the cross-lesson skill
 * tracks. Leaving them empty is a valid choice — the question then counts
 * toward no track, only toward the lesson it sits in.
 *
 * @var array  $lesson
 * @var ?array $question
 * @var array  $options
 * @var array  $tagged
 * @var array  $stages
 * @var array  $tracks
 * @var array  $old
 * @var array  $errors
 * @var ?array $stats
 */
$value  = static fn (string $key, mixed $fallback = '') => e((string) ($old[$key] ?? $question[$key] ?? $fallback));
$action = $question === null
    ? '/admin/balin/lessons/' . $lesson['uuid'] . '/questions'
    : '/admin/balin/questions/' . $question['uuid'];

// Always render four option rows so the form does not need JavaScript to
// grow; blank rows are simply dropped on save.
$rows = array_pad(array_values($options), 4, ['label' => '', 'body' => '', 'is_correct' => 0]);
$correctIndex = null;
foreach ($rows as $index => $row) {
    if (!empty($row['is_correct'])) {
        $correctIndex = $index;
    }
}
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;"><?= $question === null ? 'سؤال جدید' : 'ویرایش سؤال' ?></h3>
        <a class="btn btn-ghost btn-sm" href="/admin/balin/lessons/<?= e($lesson['uuid']) ?>#questions">بازگشت</a>
    </div>

    <?php if (!empty($stats) && (int) $stats['attempts'] > 0): ?>
        <div class="balin-inline-meta">
            <span class="leaf-meta">
                <?= e(fa((int) $stats['attempts'])) ?> پاسخ ثبت‌شده ·
                <?= e(fa((int) $stats['correct'])) ?> صحیح ·
                میانگین زمان <?= e(fa(round($stats['avg_ms'] / 1000))) ?> ثانیه
            </span>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $message): ?>
        <div class="balin-warning"><?= e($message) ?></div>
    <?php endforeach; ?>

    <form method="post" action="<?= e($action) ?>" class="form-grid">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <?php if ($question !== null): ?>
            <input type="hidden" name="version" value="<?= (int) $question['version'] ?>">
        <?php endif; ?>

        <label class="field span-2">
            <span>متن سؤال *</span>
            <textarea name="prompt" rows="3" required><?= $value('prompt') ?></textarea>
        </label>

        <fieldset class="field span-2">
            <legend>گزینه‌ها — دقیقاً یکی را به‌عنوان پاسخ صحیح علامت بزن</legend>
            <?php foreach ($rows as $index => $row): ?>
                <div class="balin-option-row">
                    <label class="balin-option-correct">
                        <input type="radio" name="correct_option" value="<?= $index ?>"
                               <?= $correctIndex === $index ? 'checked' : '' ?>>
                        <span><?= e(chr(65 + $index)) ?></span>
                    </label>
                    <input type="text" name="option_body[<?= $index ?>]" maxlength="500"
                           value="<?= e((string) ($row['body'] ?? '')) ?>"
                           placeholder="متن گزینه <?= e(chr(65 + $index)) ?>">
                </div>
            <?php endforeach; ?>
        </fieldset>

        <label class="field span-2">
            <span>توضیح علمی (بعد از پاسخ نمایش داده می‌شود)</span>
            <textarea name="explanation" rows="3"><?= $value('explanation') ?></textarea>
        </label>

        <label class="field span-2">
            <span>راهنما (اختیاری — استفاده از آن امتیاز را کم می‌کند)</span>
            <textarea name="hint" rows="2"><?= $value('hint') ?></textarea>
        </label>

        <label class="field">
            <span>مرحله</span>
            <select name="stage_id">
                <option value="">بانک آزمون (بدون مرحله)</option>
                <?php foreach ($stages as $stage): ?>
                    <option value="<?= (int) $stage['id'] ?>"
                        <?= (int) ($old['stage_id'] ?? $question['stage_id'] ?? 0) === (int) $stage['id'] ? 'selected' : '' ?>>
                        <?= e($stage['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>سختی</span>
            <select name="difficulty">
                <?php foreach (['easy' => 'آسان', 'medium' => 'متوسط', 'hard' => 'دشوار', 'expert' => 'تخصصی'] as $key => $label): ?>
                    <option value="<?= e($key) ?>"
                        <?= ($old['difficulty'] ?? $question['difficulty'] ?? 'medium') === $key ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>امتیاز پاسخ صحیح</span>
            <input type="number" name="xp_reward" min="0" max="1000" value="<?= $value('xp_reward', '10') ?>">
        </label>

        <label class="field">
            <span>وضعیت</span>
            <select name="status">
                <option value="published" <?= ($old['status'] ?? $question['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>منتشرشده</option>
                <option value="draft"     <?= ($old['status'] ?? $question['status'] ?? '') === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
            </select>
        </label>

        <fieldset class="field span-2">
            <legend>مهارت‌های بالینی که این سؤال می‌سنجد</legend>
            <div class="balin-track-picker">
                <?php foreach ($tracks as $track): ?>
                    <label class="balin-track-chip">
                        <input type="checkbox" name="skill_track_ids[]" value="<?= (int) $track['id'] ?>"
                               <?= in_array((int) $track['id'], array_map('intval', $tagged), true) ? 'checked' : '' ?>>
                        <span><?= e($track['icon'] ?? '') ?> <?= e($track['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <small>سؤال بدون برچسب در محاسبه هیچ مهارتی شمرده نمی‌شود.</small>
        </fieldset>

        <label class="field check">
            <input type="checkbox" name="is_required" value="1"
                   <?= (int) ($old['is_required'] ?? $question['is_required'] ?? 1) === 1 ? 'checked' : '' ?>>
            <span>پاسخ به این سؤال برای تکمیل مرحله الزامی است</span>
        </label>

        <label class="field check">
            <input type="checkbox" name="is_final_case_step" value="1"
                   <?= (int) ($old['is_final_case_step'] ?? $question['is_final_case_step'] ?? 0) === 1 ? 'checked' : '' ?>>
            <span>بخشی از کیس نهایی است (وزن بیشتری در تسلط دارد)</span>
        </label>

        <div class="form-actions span-2">
            <button class="btn btn-primary" type="submit">ذخیره سؤال</button>
            <a class="btn btn-ghost" href="/admin/balin/lessons/<?= e($lesson['uuid']) ?>#questions">انصراف</a>
        </div>
    </form>
</div>

<?php if ($question !== null && can('balin.manage_questions')): ?>
    <div class="card danger-zone">
        <h3 class="card-title">حذف سؤال</h3>
        <p style="color:var(--ink-3); font-size:12.5px;">پاسخ‌های ثبت‌شده دانشجویان برای این سؤال هم حذف می‌شوند.</p>
        <form method="post" action="/admin/balin/questions/<?= e($question['uuid']) ?>/delete"
              data-confirm="این سؤال و پاسخ‌های ثبت‌شده آن حذف شود؟">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <button class="btn btn-danger btn-sm" type="submit">حذف سؤال</button>
        </form>
    </div>
<?php endif; ?>
