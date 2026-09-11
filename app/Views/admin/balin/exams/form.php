<?php
/**
 * Creating a checkpoint exam.
 *
 * @var array  $lesson
 * @var ?array $exam
 * @var array  $stages
 * @var array  $tracks
 * @var array  $old
 * @var array  $errors
 */
$value = static fn (string $key, mixed $fallback = '') => e((string) ($old[$key] ?? $exam[$key] ?? $fallback));
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">آزمون بین‌مرحله‌ای جدید</h3>
        <a class="btn btn-ghost btn-sm" href="/admin/balin/lessons/<?= e($lesson['uuid']) ?>#exams">بازگشت</a>
    </div>

    <?php foreach ($errors as $message): ?>
        <div class="balin-warning"><?= e($message) ?></div>
    <?php endforeach; ?>

    <form method="post" action="/admin/balin/lessons/<?= e($lesson['uuid']) ?>/exams" class="form-grid">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

        <label class="field span-2">
            <span>عنوان آزمون *</span>
            <input type="text" name="title" required maxlength="191" value="<?= $value('title') ?>"
                   placeholder="مثلاً: آزمون شرح‌حال‌گیری">
        </label>

        <label class="field span-2">
            <span>توضیح</span>
            <textarea name="description" rows="2"><?= $value('description') ?></textarea>
        </label>

        <label class="field">
            <span>جایگاه</span>
            <select name="position_type">
                <option value="after_stage"  <?= $value('position_type', 'after_stage') === 'after_stage'  ? 'selected' : '' ?>>بعد از یک مرحله</option>
                <option value="before_stage" <?= $value('position_type') === 'before_stage' ? 'selected' : '' ?>>قبل از یک مرحله</option>
                <option value="after_lesson" <?= $value('position_type') === 'after_lesson' ? 'selected' : '' ?>>پایان درس</option>
            </select>
        </label>

        <label class="field">
            <span>مرحله مرجع</span>
            <select name="anchor_stage_id">
                <option value="">—</option>
                <?php foreach ($stages as $stage): ?>
                    <option value="<?= (int) $stage['id'] ?>"><?= e($stage['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>مهارت اصلی</span>
            <select name="primary_skill_track_id">
                <option value="">—</option>
                <?php foreach ($tracks as $track): ?>
                    <option value="<?= (int) $track['id'] ?>">
                        <?= e($track['icon'] ?? '') ?> <?= e($track['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>منبع سؤال</span>
            <select name="question_source_mode">
                <option value="fixed_list">لیست ثابت (سؤال‌ها را خودم انتخاب می‌کنم)</option>
                <option value="random_pool">استخر تصادفی (از سؤالات برچسب‌خورده مهارت اصلی)</option>
            </select>
            <small>در حالت تصادفی، هر تلاش سؤال‌های متفاوتی می‌گیرد.</small>
        </label>

        <label class="field"><span>تعداد سؤال</span>
            <input type="number" name="num_questions" min="1" max="100" value="<?= $value('num_questions', '10') ?>"></label>

        <label class="field"><span>نمره قبولی (٪)</span>
            <input type="number" name="pass_threshold_percent" min="1" max="100" value="<?= $value('pass_threshold_percent', '70') ?>"></label>

        <label class="field"><span>حداکثر تلاش (خالی = نامحدود)</span>
            <input type="number" name="max_attempts" min="1" max="100" value="<?= $value('max_attempts', '3') ?>"></label>

        <label class="field"><span>فاصله بین تلاش‌ها (ساعت)</span>
            <input type="number" name="cooldown_hours" min="0" max="720" value="<?= $value('cooldown_hours_between_attempts', '24') ?>"></label>

        <label class="field"><span>مدت آزمون (دقیقه، خالی = بدون محدودیت)</span>
            <input type="number" name="time_limit_minutes" min="1" max="600" value="<?= $value('time_limit_minutes') ?>"></label>

        <label class="field"><span>امتیاز قبولی</span>
            <input type="number" name="xp_reward" min="0" max="10000" value="<?= $value('xp_reward', '50') ?>"></label>

        <label class="field check span-2">
            <input type="checkbox" name="is_gating" value="1">
            <span>دروازه‌ای: تا قبولی در این آزمون، مرحله بعد باز نمی‌شود</span>
        </label>

        <div class="form-actions span-2">
            <button class="btn btn-primary" type="submit">ساخت آزمون</button>
            <a class="btn btn-ghost" href="/admin/balin/lessons/<?= e($lesson['uuid']) ?>#exams">انصراف</a>
        </div>
    </form>
</div>
