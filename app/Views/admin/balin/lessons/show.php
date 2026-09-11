<?php
/**
 * The lesson workbench: stages, checkpoint exams and the question bank.
 *
 * @var array $lesson
 * @var array $stages
 * @var array $exams
 * @var array $questions
 * @var int   $learners
 */
$chips = ['published' => 'chip-green', 'draft' => 'chip-gray', 'archived' => 'chip-red'];
$names = ['published' => 'منتشرشده', 'draft' => 'پیش‌نویس', 'archived' => 'بایگانی'];
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">
            <span aria-hidden="true"><?= e($lesson['icon'] ?: '🩺') ?></span> <?= e($lesson['title']) ?>
        </h3>
        <div class="row-actions">
            <?php if (can('balin.edit')): ?>
                <a class="btn btn-ghost btn-sm" href="/admin/balin/lessons/<?= e($lesson['uuid']) ?>/edit">ویرایش</a>
            <?php endif; ?>
            <?php if (can('balin.publish')): ?>
                <form method="post" action="/admin/balin/lessons/<?= e($lesson['uuid']) ?>/status">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="status" value="<?= $lesson['status'] === 'published' ? 'draft' : 'published' ?>">
                    <button class="btn btn-primary btn-sm" type="submit">
                        <?= $lesson['status'] === 'published' ? 'بازگشت به پیش‌نویس' : 'انتشار درس' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="balin-inline-meta">
        <span class="stat-chip <?= e($chips[$lesson['status']] ?? 'chip-gray') ?>">
            <?= e($names[$lesson['status']] ?? $lesson['status']) ?>
        </span>
        <?php if ($learners > 0): ?>
            <span class="leaf-meta"><?= e(fa($learners)) ?> دانشجو این درس را گذرانده‌اند</span>
        <?php endif; ?>
    </div>

    <?php if ($lesson['description']): ?>
        <p style="color:var(--ink-3); font-size:13px;"><?= e($lesson['description']) ?></p>
    <?php endif; ?>
</div>

<div class="card" id="stages">
    <div class="card-head"><h3 class="card-title" style="margin:0;">مرحله‌ها</h3></div>

    <?php if ($stages === []): ?>
        <div class="empty">هنوز مرحله‌ای ساخته نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>#</th><th>عنوان</th><th>وضعیت</th><th>بلوک</th><th>امتیاز</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($stages as $index => $stage): ?>
                    <tr>
                        <td><?= e(fa($index + 1)) ?></td>
                        <td>
                            <?= e($stage['title']) ?>
                            <?php if ((int) $stage['is_final_case'] === 1): ?>
                                <span class="stat-chip chip-blue">کیس نهایی</span>
                            <?php endif; ?>
                            <?php if ($stage['subtitle']): ?>
                                <div class="leaf-meta"><?= e($stage['subtitle']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="stat-chip <?= e($chips[$stage['status']] ?? 'chip-gray') ?>">
                                <?= e($names[$stage['status']] ?? $stage['status']) ?>
                            </span>
                        </td>
                        <td><?= e(fa((int) $stage['block_count'])) ?></td>
                        <td><?= e(fa((int) $stage['xp_reward'])) ?></td>
                        <td class="row-actions">
                            <?php if (can('balin.edit')): ?>
                                <form method="post" action="/admin/balin/stages/<?= e($stage['uuid']) ?>/move">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button class="btn btn-ghost btn-sm" type="submit" aria-label="بالا">↑</button>
                                </form>
                                <form method="post" action="/admin/balin/stages/<?= e($stage['uuid']) ?>/move">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button class="btn btn-ghost btn-sm" type="submit" aria-label="پایین">↓</button>
                                </form>
                            <?php endif; ?>
                            <a class="btn btn-primary btn-sm" href="/admin/balin/stages/<?= e($stage['uuid']) ?>">ساخت محتوا</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (can('balin.create')): ?>
        <form method="post" action="/admin/balin/lessons/<?= e($lesson['uuid']) ?>/stages" class="form-grid balin-inline-form">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <label class="field"><span>عنوان مرحله جدید *</span>
                <input type="text" name="title" required maxlength="191"></label>
            <label class="field"><span>زیرعنوان</span>
                <input type="text" name="subtitle" maxlength="191"></label>
            <label class="field"><span>امتیاز</span>
                <input type="number" name="xp_reward" min="0" max="10000" value="0"></label>
            <label class="field"><span>مدت (دقیقه)</span>
                <input type="number" name="estimated_minutes" min="0" max="1000" value="0"></label>
            <label class="field check"><input type="checkbox" name="is_final_case" value="1">
                <span>کیس نهایی این درس است</span></label>
            <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">افزودن مرحله</button></div>
        </form>
    <?php endif; ?>
</div>

<div class="card" id="exams">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">آزمون‌های بین‌مرحله‌ای</h3>
        <?php if (can('balin.manage_checkpoint_exams')): ?>
            <a class="btn btn-primary btn-sm" href="/admin/balin/lessons/<?= e($lesson['uuid']) ?>/exams/create">
                + آزمون جدید
            </a>
        <?php endif; ?>
    </div>

    <?php if ($exams === []): ?>
        <div class="empty">آزمونی برای این درس تعریف نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>عنوان</th><th>جایگاه</th><th>مهارت</th><th>قبولی</th><th>دروازه‌ای</th><th>وضعیت</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($exams as $exam): ?>
                    <tr>
                        <td>🎯 <?= e($exam['title']) ?></td>
                        <td>
                            <?= e(['before_stage' => 'قبل از', 'after_stage' => 'بعد از',
                                   'after_lesson' => 'پایان درس'][$exam['position_type']] ?? '') ?>
                            <?php if ($exam['anchor_stage_title']): ?>
                                <div class="leaf-meta"><?= e($exam['anchor_stage_title']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($exam['skill_track_name'] ?? '—') ?></td>
                        <td><?= e(fa((int) $exam['pass_threshold_percent'])) ?>٪</td>
                        <td><?= (int) $exam['is_gating'] === 1 ? 'بله' : 'خیر' ?></td>
                        <td>
                            <span class="stat-chip <?= e($chips[$exam['status']] ?? 'chip-gray') ?>">
                                <?= e($names[$exam['status']] ?? $exam['status']) ?>
                            </span>
                        </td>
                        <td class="row-actions">
                            <a class="btn btn-primary btn-sm" href="/admin/balin/exams/<?= e($exam['uuid']) ?>">مدیریت</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card" id="questions">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">بانک سؤال</h3>
        <?php if (can('balin.manage_questions')): ?>
            <a class="btn btn-primary btn-sm" href="/admin/balin/lessons/<?= e($lesson['uuid']) ?>/questions/create">
                + سؤال جدید
            </a>
        <?php endif; ?>
    </div>

    <?php if ($questions === []): ?>
        <div class="empty">هنوز سؤالی ساخته نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>سؤال</th><th>مرحله</th><th>سختی</th><th>گزینه</th><th>پاسخ‌ها</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($questions as $question): ?>
                    <tr>
                        <td><?= e(mb_substr((string) $question['prompt'], 0, 80, 'UTF-8')) ?></td>
                        <td><?= e($question['stage_title'] ?? 'بانک آزمون') ?></td>
                        <td><?= e(['easy' => 'آسان', 'medium' => 'متوسط', 'hard' => 'دشوار',
                                   'expert' => 'تخصصی'][$question['difficulty']] ?? '') ?></td>
                        <td><?= e(fa((int) $question['option_count'])) ?></td>
                        <td><?= e(fa((int) $question['answer_count'])) ?></td>
                        <td class="row-actions">
                            <?php if (can('balin.manage_questions')): ?>
                                <a class="btn btn-ghost btn-sm" href="/admin/balin/questions/<?= e($question['uuid']) ?>/edit">ویرایش</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if (can('balin.delete')): ?>
    <div class="card danger-zone">
        <h3 class="card-title">حذف درس</h3>
        <p style="color:var(--ink-3); font-size:12.5px;">
            با حذف درس، مرحله‌ها، بلوک‌ها، سؤال‌ها و آزمون‌های آن هم حذف می‌شوند.
        </p>
        <form method="post" action="/admin/balin/lessons/<?= e($lesson['uuid']) ?>/delete"
              data-confirm="این درس و همه محتوای داخل آن حذف شود؟ این کار برگشت‌پذیر نیست.">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <button class="btn btn-danger btn-sm" type="submit">حذف درس</button>
        </form>
    </div>
<?php endif; ?>
