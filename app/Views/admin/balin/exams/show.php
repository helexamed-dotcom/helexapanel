<?php
/**
 * The exam workbench: its settings, its questions, and how students are
 * doing on it.
 *
 * @var array $exam
 * @var array $lesson
 * @var array $stages
 * @var array $tracks
 * @var array $attached
 * @var array $available
 * @var int   $pool_count
 * @var array $stats
 */
$chips = ['published' => 'chip-green', 'draft' => 'chip-gray', 'archived' => 'chip-red'];
$names = ['published' => 'منتشرشده', 'draft' => 'پیش‌نویس', 'archived' => 'بایگانی'];
$isPool = $exam['question_source_mode'] === 'random_pool';
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">🎯 <?= e($exam['title']) ?></h3>
        <div class="row-actions">
            <a class="btn btn-ghost btn-sm" href="/admin/balin/lessons/<?= e($lesson['uuid']) ?>#exams">بازگشت</a>
            <?php if (can('balin.publish')): ?>
                <form method="post" action="/admin/balin/exams/<?= e($exam['uuid']) ?>/status">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="status" value="<?= $exam['status'] === 'published' ? 'draft' : 'published' ?>">
                    <button class="btn btn-primary btn-sm" type="submit">
                        <?= $exam['status'] === 'published' ? 'بازگشت به پیش‌نویس' : 'انتشار آزمون' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="balin-inline-meta">
        <span class="stat-chip <?= e($chips[$exam['status']] ?? 'chip-gray') ?>">
            <?= e($names[$exam['status']] ?? $exam['status']) ?>
        </span>
        <?php if ((int) $exam['is_gating'] === 1): ?>
            <span class="stat-chip chip-blue">دروازه‌ای</span>
        <?php endif; ?>
        <span class="leaf-meta"><?= e($lesson['title']) ?></span>
    </div>

    <?php if ($isPool && $pool_count < (int) $exam['num_questions']): ?>
        <div class="balin-warning">
            استخر این مهارت فقط <?= e(fa($pool_count)) ?> سؤال منتشرشده دارد، اما آزمون
            <?= e(fa((int) $exam['num_questions'])) ?> سؤال می‌خواهد. سؤال بیشتری برچسب بزن.
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/balin/exams/<?= e($exam['uuid']) ?>" class="form-grid">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <input type="hidden" name="version" value="<?= (int) $exam['version'] ?>">

        <label class="field span-2"><span>عنوان *</span>
            <input type="text" name="title" required maxlength="191" value="<?= e($exam['title']) ?>"></label>

        <label class="field span-2"><span>توضیح</span>
            <textarea name="description" rows="2"><?= e((string) $exam['description']) ?></textarea></label>

        <label class="field">
            <span>جایگاه</span>
            <select name="position_type">
                <?php foreach (['after_stage' => 'بعد از یک مرحله', 'before_stage' => 'قبل از یک مرحله',
                                'after_lesson' => 'پایان درس'] as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $exam['position_type'] === $key ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>مرحله مرجع</span>
            <select name="anchor_stage_id">
                <option value="">—</option>
                <?php foreach ($stages as $stage): ?>
                    <option value="<?= (int) $stage['id'] ?>"
                        <?= (int) $exam['anchor_stage_id'] === (int) $stage['id'] ? 'selected' : '' ?>>
                        <?= e($stage['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>مهارت اصلی</span>
            <select name="primary_skill_track_id">
                <option value="">—</option>
                <?php foreach ($tracks as $track): ?>
                    <option value="<?= (int) $track['id'] ?>"
                        <?= (int) $exam['primary_skill_track_id'] === (int) $track['id'] ? 'selected' : '' ?>>
                        <?= e($track['icon'] ?? '') ?> <?= e($track['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>منبع سؤال</span>
            <select name="question_source_mode">
                <option value="fixed_list"  <?= !$isPool ? 'selected' : '' ?>>لیست ثابت</option>
                <option value="random_pool" <?= $isPool ? 'selected' : '' ?>>استخر تصادفی</option>
            </select>
        </label>

        <label class="field"><span>تعداد سؤال</span>
            <input type="number" name="num_questions" min="1" max="100" value="<?= (int) $exam['num_questions'] ?>"></label>
        <label class="field"><span>نمره قبولی (٪)</span>
            <input type="number" name="pass_threshold_percent" min="1" max="100" value="<?= (int) $exam['pass_threshold_percent'] ?>"></label>
        <label class="field"><span>حداکثر تلاش (خالی = نامحدود)</span>
            <input type="number" name="max_attempts" min="1" max="100" value="<?= $exam['max_attempts'] === null ? '' : (int) $exam['max_attempts'] ?>"></label>
        <label class="field"><span>فاصله بین تلاش‌ها (ساعت)</span>
            <input type="number" name="cooldown_hours" min="0" max="720" value="<?= (int) $exam['cooldown_hours_between_attempts'] ?>"></label>
        <label class="field"><span>مدت آزمون (دقیقه)</span>
            <input type="number" name="time_limit_minutes" min="1" max="600" value="<?= $exam['time_limit_minutes'] === null ? '' : (int) $exam['time_limit_minutes'] ?>"></label>
        <label class="field"><span>امتیاز قبولی</span>
            <input type="number" name="xp_reward" min="0" max="10000" value="<?= (int) $exam['xp_reward'] ?>"></label>

        <label class="field check span-2">
            <input type="checkbox" name="is_gating" value="1" <?= (int) $exam['is_gating'] === 1 ? 'checked' : '' ?>>
            <span>دروازه‌ای: تا قبولی، مرحله بعد باز نمی‌شود</span>
        </label>

        <div class="form-actions span-2"><button class="btn btn-primary btn-sm" type="submit">ذخیره آزمون</button></div>
    </form>
</div>

<?php if (!$isPool): ?>
<div class="card">
    <div class="card-head"><h3 class="card-title" style="margin:0;">سؤال‌های آزمون</h3></div>

    <?php if ($attached === []): ?>
        <div class="empty">هنوز سؤالی به این آزمون اضافه نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>#</th><th>سؤال</th><th>سختی</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($attached as $index => $question): ?>
                    <tr>
                        <td><?= e(fa($index + 1)) ?></td>
                        <td><?= e(mb_substr((string) $question['prompt'], 0, 90, 'UTF-8')) ?></td>
                        <td><?= e(['easy' => 'آسان', 'medium' => 'متوسط', 'hard' => 'دشوار',
                                   'expert' => 'تخصصی'][$question['difficulty']] ?? '') ?></td>
                        <td class="row-actions">
                            <form method="post" action="/admin/balin/exams/<?= e($exam['uuid']) ?>/questions/detach">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/balin/exams/<?= e($exam['uuid']) ?>/questions/attach" class="form-grid balin-inline-form">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <label class="field span-2">
            <span>افزودن سؤال از بانک این درس</span>
            <select name="question_id" required>
                <?php foreach ($available as $question): ?>
                    <option value="<?= (int) $question['id'] ?>">
                        <?= e(mb_substr((string) $question['prompt'], 0, 80, 'UTF-8')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">افزودن</button></div>
    </form>
</div>
<?php else: ?>
<div class="card">
    <h3 class="card-title">استخر تصادفی</h3>
    <p style="color:var(--ink-3); font-size:12.5px;">
        سؤال‌ها در هر تلاش به‌صورت تصادفی از میان
        <strong><?= e(fa($pool_count)) ?></strong>
        سؤال منتشرشده‌ای که با مهارت اصلی این آزمون برچسب خورده‌اند انتخاب می‌شوند.
    </p>
</div>
<?php endif; ?>

<div class="card">
    <h3 class="card-title">نتایج</h3>
    <div class="stat-grid">
        <div class="stat-card"><span class="stat-value"><?= e(fa((int) $stats['students'])) ?></span><span class="stat-label">دانشجو</span></div>
        <div class="stat-card"><span class="stat-value"><?= e(fa((int) $stats['attempts'])) ?></span><span class="stat-label">تلاش</span></div>
        <div class="stat-card"><span class="stat-value"><?= e(fa((int) $stats['passes'])) ?></span><span class="stat-label">قبولی</span></div>
        <div class="stat-card"><span class="stat-value"><?= e(fa($stats['average_score'])) ?>٪</span><span class="stat-label">میانگین نمره</span></div>
        <div class="stat-card"><span class="stat-value"><?= e(fa($stats['average_attempts'])) ?></span><span class="stat-label">میانگین تلاش تا قبولی</span></div>
    </div>

    <form method="post" action="/admin/balin/exams/<?= e($exam['uuid']) ?>/attempts/reset" class="form-grid balin-inline-form">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <label class="field">
            <span>پاک کردن تلاش‌های یک دانشجو (شناسه کاربر)</span>
            <input type="number" name="user_id" min="1" required>
            <small>سقف تلاش او برای این آزمون از صفر شروع می‌شود. این کار ثبت می‌شود.</small>
        </label>
        <div class="form-actions">
            <button class="btn btn-danger btn-sm" type="submit"
                    data-confirm="تلاش‌های این دانشجو برای این آزمون پاک شود؟">پاک کردن</button>
        </div>
    </form>
</div>

<?php if (can('balin.delete')): ?>
    <div class="card danger-zone">
        <h3 class="card-title">حذف آزمون</h3>
        <form method="post" action="/admin/balin/exams/<?= e($exam['uuid']) ?>/delete"
              data-confirm="این آزمون و تلاش‌های ثبت‌شده آن حذف شود؟">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <button class="btn btn-danger btn-sm" type="submit">حذف آزمون</button>
        </form>
    </div>
<?php endif; ?>
