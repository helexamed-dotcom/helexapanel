<?php
/** @var array $lessons */
$chips = ['published' => 'chip-green', 'draft' => 'chip-gray', 'archived' => 'chip-red'];
$names = ['published' => 'منتشرشده', 'draft' => 'پیش‌نویس', 'archived' => 'بایگانی'];
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">درس‌های بالینی</h3>
        <?php if (can('balin.create')): ?>
            <a class="btn btn-primary btn-sm" href="/admin/balin/lessons/create">+ درس جدید</a>
        <?php endif; ?>
    </div>

    <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 14px;">
        هر درس یک مسیر بالینی است: مرحله‌ها، سؤال‌ها و آزمون‌های بین‌مرحله‌ای داخل آن ساخته می‌شوند.
    </p>

    <?php if ($lessons === []): ?>
        <div class="empty">هنوز درسی ساخته نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>عنوان</th><th>وضعیت</th><th>مرحله</th><th>سؤال</th><th>آزمون</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($lessons as $lesson): ?>
                    <tr>
                        <td>
                            <span aria-hidden="true"><?= e($lesson['icon'] ?: '🩺') ?></span>
                            <?= e($lesson['title']) ?>
                            <div class="leaf-meta"><?= e($lesson['slug']) ?></div>
                        </td>
                        <td>
                            <span class="stat-chip <?= e($chips[$lesson['status']] ?? 'chip-gray') ?>">
                                <?= e($names[$lesson['status']] ?? $lesson['status']) ?>
                            </span>
                        </td>
                        <td><?= e(fa((int) $lesson['stage_count'])) ?></td>
                        <td><?= e(fa((int) $lesson['question_count'])) ?></td>
                        <td><?= e(fa((int) $lesson['exam_count'])) ?></td>
                        <td class="row-actions">
                            <a class="btn btn-primary btn-sm" href="/admin/balin/lessons/<?= e($lesson['uuid']) ?>">مدیریت</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
