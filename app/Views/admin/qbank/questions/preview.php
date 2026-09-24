<?php
/**
 * An admin's read-only view of one question, answer revealed.
 *
 * @var array $question
 * @var array $options
 * @var array $tags
 * @var array $difficulties
 */
$letters = ['الف', 'ب', 'ج', 'د', 'ه', 'و', 'ز', 'ح'];
$img     = static fn (?string $name): string => '/admin/qbank/image/' . rawurlencode((string) $name);
$path    = array_filter([$question['subject_title'], $question['sub_subject_title'], $question['topic_title']]);
?>
<div class="qb-page" style="max-width:860px;">
    <section class="qb-section qb-player">
        <div class="qb-counter">
            <div class="qb-row-meta">
                <span class="stat-chip <?= $question['status'] === 'published' ? 'chip-green' : 'chip-gray' ?>">
                    <?= $question['status'] === 'published' ? 'منتشرشده' : 'پیش‌نویس' ?>
                </span>
                <span class="qb-diff <?= e($question['difficulty']) ?>"><?= e($difficulties[$question['difficulty']] ?? '') ?></span>
                <?php if ($path !== []): ?>
                    <span class="qb-path"><?php foreach ($path as $part): ?><span><?= e($part) ?></span><?php endforeach; ?></span>
                <?php else: ?>
                    <span class="qb-unfiled">بدون طبقه‌بندی</span>
                <?php endif; ?>
                <?php foreach ($tags as $tag): ?>
                    <span class="stat-chip <?= e($tag['color'] ?: 'chip-gray') ?>"><?= e($tag['title']) ?></span>
                <?php endforeach; ?>
            </div>
            <div class="row-actions" style="margin:0;">
                <?php if (can('qbank.manage_questions')): ?>
                    <a class="btn btn-primary btn-sm" href="/admin/qbank/questions/<?= e($question['uuid']) ?>/edit">ویرایش</a>
                <?php endif; ?>
                <a class="btn btn-ghost btn-sm" href="/admin/qbank/questions">فهرست</a>
            </div>
        </div>

        <?php if (!empty($question['stem_text'])): ?>
            <div class="qb-stem"><?= e($question['stem_text']) ?></div>
        <?php endif; ?>
        <?php if (!empty($question['stem_image'])): ?>
            <figure class="qb-figure"><img src="<?= e($img($question['stem_image'])) ?>" alt="تصویر سوال"></figure>
        <?php endif; ?>

        <div class="qb-answers">
            <?php foreach ($options as $i => $option): ?>
                <div class="qb-answer<?= (int) $option['is_correct'] === 1 ? ' is-right' : '' ?>">
                    <span class="qb-option-letter"><?= e($letters[$i] ?? (string) ($i + 1)) ?></span>
                    <span>
                        <?= e((string) ($option['body_text'] ?? '')) ?>
                        <?php if (!empty($option['body_image'])): ?>
                            <img src="<?= e($img($option['body_image'])) ?>" alt="تصویر گزینه">
                        <?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($question['explanation_text']) || !empty($question['explanation_image'])): ?>
            <div class="qb-explain is-open">
                <h4>پاسخ تشریحی</h4>
                <?= e((string) ($question['explanation_text'] ?? '')) ?>
                <?php if (!empty($question['explanation_image'])): ?>
                    <figure class="qb-figure"><img src="<?= e($img($question['explanation_image'])) ?>" alt="تصویر پاسخ"></figure>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="qb-hint">این سوال پاسخ تشریحی ندارد.</p>
        <?php endif; ?>
    </section>
</div>
