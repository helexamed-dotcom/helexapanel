<?php
/**
 * The درس cards a student holds.
 *
 * @var array $subjects
 * @var array $stats   subject id → answered/correct/distinct
 */
?>
<div class="qb-page">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'qbank']); ?></div>
            <div>
                <h2>بانک سوال</h2>
                <p>یک درس را انتخاب کن و سوال‌ها را یکی‌یکی تمرین کن. پاسخ تشریحی بعد از هر جواب نمایش داده می‌شود.</p>
            </div>
        </div>
    </section>

    <?php if ($subjects === []): ?>
        <div class="empty">هنوز درسی برایت فعال نشده است.</div>
    <?php else: ?>
        <div class="qb-subjects">
            <?php foreach ($subjects as $subject):
                $s        = $stats[(int) $subject['id']] ?? ['answered' => 0, 'correct' => 0, 'distinct' => 0];
                $count    = (int) $subject['question_count'];
                $coverage = $count > 0 ? min(100, (int) round($s['distinct'] * 100 / $count)) : 0;
                $accuracy = $s['answered'] > 0 ? (int) round($s['correct'] * 100 / $s['answered']) : null;
            ?>
                <a class="qb-subject-card" href="/student/qbank/<?= e($subject['uuid']) ?>">
                    <h3><?= e($subject['title']) ?></h3>
                    <?php if (!empty($subject['description'])): ?>
                        <p class="qb-hint"><?= e($subject['description']) ?></p>
                    <?php endif; ?>
                    <div class="qb-progress" role="progressbar" aria-valuenow="<?= $coverage ?>" aria-valuemin="0" aria-valuemax="100"
                         aria-label="پیشرفت">
                        <i style="width: <?= $coverage ?>%;"></i>
                    </div>
                    <div class="qb-subject-foot">
                        <span><?= e(fa((string) $s['distinct'])) ?> از <?= e(fa((string) $count)) ?> سوال</span>
                        <span><?= $accuracy === null ? 'هنوز شروع نکرده‌ای' : 'دقت ' . e(fa((string) $accuracy)) . '٪' ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
