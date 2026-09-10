<?php if (!$hasTerms): ?>
    <div class="card">
        <div class="empty">
            ترمی برای حساب شما ثبت نشده است.<br>
            دانشگاه، رشته و ترم را مدیر سامانه تعیین می‌کند؛ با پشتیبانی تماس بگیرید.
        </div>
    </div>
<?php elseif ($blocks === []): ?>
    <div class="card"><div class="empty">برنامه‌ای یافت نشد.</div></div>
<?php else: ?>
    <?php foreach ($blocks as $block): ?>
        <div class="card" style="margin-bottom:16px;">
            <div class="card-head">
                <div>
                    <h3 class="card-title" style="margin:0;">
                        <?= e($block['schedule']['title'] ?? 'برنامه هفتگی') ?>
                    </h3>
                    <div class="leaf-meta"><?= e($block['term_title']) ?></div>
                </div>
                <?php if (!empty($block['schedule']['academic_year'])): ?>
                    <span class="stat-chip chip-blue"><?= e(fa((string) $block['schedule']['academic_year'])) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($block['schedule'] === null): ?>
                <div class="empty">برای این ترم هنوز برنامه‌ای ثبت نشده است.</div>
            <?php else: ?>
                <div class="week-grid">
                    <?php foreach ($weekdays as $index => $label): ?>
                        <div class="week-day<?= $index === $todayIndex ? ' is-today' : '' ?>">
                            <div class="week-day-head">
                                <?= e($label) ?>
                                <?php if ($index === $todayIndex): ?><span class="today-dot">امروز</span><?php endif; ?>
                            </div>
                            <?php if (empty($block['byDay'][$index])): ?>
                                <div class="week-empty">کلاسی نیست</div>
                            <?php else: ?>
                                <?php foreach ($block['byDay'][$index] as $item): ?>
                                    <div class="class-card" style="border-right-color: <?= e($item['color'] ?: '#2563eb') ?>">
                                        <div class="class-time">
                                            <?= e(fa(substr((string) $item['start_time'], 0, 5))) ?> — <?= e(fa(substr((string) $item['end_time'], 0, 5))) ?>
                                        </div>
                                        <div class="class-title"><?= e($item['title']) ?></div>
                                        <?php if ($item['teacher'] || $item['location']): ?>
                                            <div class="class-meta">
                                                <?= e(trim(($item['teacher'] ?? '') . ' ' . ($item['location'] ? '· ' . $item['location'] : ''))) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($item['course_uuid']): ?>
                                            <a class="class-link" href="/student/courses/<?= e($item['course_uuid']) ?>">منابع درس ←</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
