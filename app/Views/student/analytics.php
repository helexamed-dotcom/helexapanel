<?php use HeleXa\Services\StudyAnalytics; ?>

<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">
            مطالعه <?= e(['هفته جاری', 'هفته گذشته', 'دو هفته قبل', 'سه هفته قبل'][$weeksAgo] ?? '') ?>
        </h3>
        <form method="get" action="/student/analytics" style="margin:0;">
            <select class="input" name="week" data-auto-submit style="width:auto; padding:8px 12px;">
                <?php foreach (['هفته جاری', 'هفته گذشته', 'دو هفته قبل', 'سه هفته قبل'] as $index => $label): ?>
                    <option value="<?= $index ?>" <?= $weeksAgo === $index ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-ghost btn-sm" type="submit">نمایش</button></noscript>
        </form>
    </div>

    <div style="display:flex; gap:22px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <div class="stat-label">مجموع این هفته</div>
            <div class="stat-value" style="font-size:22px;"><?= e(StudyAnalytics::humanDuration((int) $week['total'])) ?></div>
        </div>
        <div>
            <div class="stat-label">هفته قبل از آن</div>
            <div class="stat-value" style="font-size:22px; color:var(--ink-3);">
                <?= e(StudyAnalytics::humanDuration((int) $previousTotal)) ?>
            </div>
        </div>
        <div>
            <div class="stat-label">میانگین روزانه</div>
            <div class="stat-value" style="font-size:22px;">
                <?= e(StudyAnalytics::humanDuration((int) round(((int) $week['total']) / 7))) ?>
            </div>
        </div>
    </div>

    <?php \HeleXa\Core\View::partial('partials.week_chart', ['week' => $week]); ?>
</div>

<div class="grid grid-2" style="margin-top:16px;">
    <div class="card">
        <h3 class="card-title">زمان مطالعه به تفکیک درس</h3>
        <?php if ($courses === []): ?>
            <div class="empty">در این هفته مطالعه‌ای ثبت نشده است.</div>
        <?php else: ?>
            <?php $maxCourse = max(1, max(array_map('intval', array_column($courses, 'seconds')))); ?>
            <?php foreach ($courses as $course): ?>
                <div style="margin-bottom:14px;">
                    <div style="display:flex; justify-content:space-between; font-size:13.5px; margin-bottom:6px;">
                        <span><?= e($course['title']) ?></span>
                        <span style="color:var(--ink-3)"><?= e(StudyAnalytics::humanDuration((int) $course['seconds'])) ?></span>
                    </div>
                    <div class="progress">
                        <span style="width: <?= (int) round(((int) $course['seconds']) / $maxCourse * 100) ?>%"></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 class="card-title">پیشرفت هر درس</h3>
        <?php if ($breakdown === []): ?>
            <div class="empty">دوره فعالی ندارید.</div>
        <?php else: ?>
            <?php foreach ($breakdown as $row): ?>
                <?php
                $total     = (int) $row['total'];
                $completed = (int) $row['completed'];
                $percent   = $total > 0 ? (int) round($completed / $total * 100) : 0;
                $advice    = $percent >= 100 ? ['chip-green', 'تکمیل شده']
                           : ($percent >= 50 ? ['chip-blue', 'ادامه بده'] : ['chip-amber', 'بیشتر مطالعه کن']);
                ?>
                <div style="margin-bottom:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; font-size:13.5px; margin-bottom:6px;">
                        <a href="/student/courses/<?= e($row['uuid']) ?>"><?= e($row['title']) ?></a>
                        <span class="stat-chip <?= e($advice[0]) ?>"><?= e($advice[1]) ?></span>
                    </div>
                    <div class="progress"><span style="width: <?= $percent ?>%"></span></div>
                    <div style="display:flex; gap:10px; font-size:11.5px; color:var(--ink-3); margin-top:6px; flex-wrap:wrap;">
                        <span><?= e(fa((string) $percent)) ?>٪</span>
                        <span>کامل: <?= e(fa((string) $completed)) ?></span>
                        <span>در حال مطالعه: <?= e(fa((string) (int) $row['studying'])) ?></span>
                        <span>مرور بعدی: <?= e(fa((string) (int) $row['review_later'])) ?></span>
                        <span>مطالعه نشده: <?= e(fa((string) max(0, $total - $completed - (int) $row['studying'] - (int) $row['review_later']))) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h3 class="card-title">این عددها از کجا می‌آیند</h3>
    <p style="color:var(--ink-2); font-size:13.5px; margin:0;">
        زمان مطالعه با ساعت سرور اندازه‌گیری می‌شود، نه با تایمر مرورگر. نمایشگر هر چند ثانیه
        یک علامت زنده‌بودن می‌فرستد و سرور فاصله‌ی بین دو علامت را حساب می‌کند. اگر تب مخفی باشد
        یا علامت‌ها قطع شوند، آن فاصله شمرده نمی‌شود.
    </p>
</div>
