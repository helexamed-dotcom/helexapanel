<h2 class="greet">دوره‌های من</h2>
<p class="greet-sub">فقط دوره‌هایی که دسترسی فعال دارید نمایش داده می‌شوند.</p>

<?php if (!empty($packages)): ?>
    <div class="card" style="margin-bottom:16px;">
        <h3 class="card-title">پکیج‌های فعال شما</h3>
        <div class="row-actions">
            <?php foreach ($packages as $package): ?>
                <span class="stat-chip chip-purple" style="font-size:12.5px; padding:6px 12px;">
                    <?= e($package['title']) ?>
                    · <?= e(fa((string) $package['course_count'])) ?> دوره
                    <?php if ($package['ends_at']): ?> · تا <?= e(jdate($package['ends_at'])) ?><?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($courses === []): ?>
    <div class="card"><div class="empty">هنوز دوره‌ای برای شما فعال نشده است.</div></div>
<?php else: ?>
    <div class="grid grid-2">
        <?php foreach ($courses as $course): ?>
            <?php
            $total    = (int) $course['content_count'];
            $done     = (int) $course['completed_count'];
            $percent  = $total > 0 ? (int) round($done / $total * 100) : 0;
            ?>
            <a class="card course-card" href="/student/courses/<?= e($course['uuid']) ?>">
                <div class="course-bar" style="background: <?= e($course['color'] ?: '#2563eb') ?>"></div>
                <?php if (!empty($course['thumbnail_path'])): ?>
                    <img class="course-icon" src="/assets/<?= e($course['thumbnail_path']) ?>" alt="">
                <?php endif; ?>
                <h3 class="card-title" style="margin-bottom:6px;"><?= e($course['title']) ?></h3>
                <p style="color:var(--ink-3); font-size:13px; margin:0 0 14px;">
                    <?= e(mb_substr((string) ($course['description'] ?? ''), 0, 90, 'UTF-8')) ?>
                </p>
                <div class="progress"><span style="width: <?= $percent ?>%"></span></div>
                <div style="display:flex; justify-content:space-between; font-size:12.5px; color:var(--ink-3); margin-top:8px;">
                    <span><?= e(fa((string) $done)) ?> از <?= e(fa((string) $total)) ?> مطالعه‌شده</span>
                    <span><?= e(fa((string) $percent)) ?>٪</span>
                </div>
                <?php if ($course['ends_at']): ?>
                    <div style="font-size:12px; color:var(--amber); margin-top:8px;">
                        اعتبار دسترسی تا <?= e(jdate($course['ends_at'])) ?>
                    </div>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
