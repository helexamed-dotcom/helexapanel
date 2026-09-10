<?php
$kindLabel = $kind === 'midterm' ? 'میان‌ترم' : 'امتحان';
$today = strtotime(date('Y-m-d'));
?>

<?php if (!$hasTerm): ?>
    <div class="card">
        <div class="empty">ترم شما مشخص نشده است، بنابراین برنامه امتحانی برای شما وجود ندارد.</div>
    </div>
<?php else: ?>
    <div class="card">
        <h3 class="card-title"><?= e($kindLabel) ?>‌های پیش‌رو</h3>
        <?php if ($upcoming === []): ?>
            <div class="empty">فعلاً <?= e($kindLabel) ?>ی ثبت نشده است.</div>
        <?php else: ?>
            <div class="tree">
                <?php foreach ($upcoming as $exam): ?>
                    <?php
                    $days = (int) round((strtotime((string) $exam['exam_date']) - $today) / 86400);
                    $chip = $days <= 3 ? 'chip-red' : ($days <= 10 ? 'chip-amber' : 'chip-blue');
                    ?>
                    <div class="tree-leaf">
                        <span class="leaf-icon">📝</span>
                        <div style="min-width:0; flex:1;">
                            <div class="leaf-title"><?= e($exam['title']) ?></div>
                            <div class="leaf-meta">
                                <?= e($exam['course_title'] ?? 'بدون درس') ?>
                                <?php if (!empty($exam['term_title'])): ?> · <?= e($exam['term_title']) ?><?php endif; ?>
                                · <?= e(jdate($exam['exam_date'])) ?>
                                <?php if ($exam['start_time']): ?>
                                    · ساعت <?= e(fa(substr((string) $exam['start_time'], 0, 5))) ?>
                                <?php endif; ?>
                                <?php if ($exam['location']): ?> · <?= e($exam['location']) ?><?php endif; ?>
                            </div>
                            <?php if ($exam['description']): ?>
                                <div class="leaf-meta"><?= e($exam['description']) ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="stat-chip <?= e($chip) ?>">
                            <?= $days === 0 ? 'امروز' : ($days === 1 ? 'فردا' : e(fa((string) $days)) . ' روز مانده') ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($past !== []): ?>
        <div class="card" style="margin-top:16px;">
            <h3 class="card-title"><?= e($kindLabel) ?>‌های برگزارشده</h3>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>عنوان</th><th>درس</th><th>تاریخ</th></tr></thead>
                    <tbody>
                    <?php foreach ($past as $exam): ?>
                        <tr>
                            <td><?= e($exam['title']) ?></td>
                            <td><?= e($exam['course_title'] ?? '—') ?></td>
                            <td><?= e(jdate($exam['exam_date'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
