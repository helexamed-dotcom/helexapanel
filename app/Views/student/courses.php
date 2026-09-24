<?php
/**
 * The student's courses and packages, as a grid or a list.
 *
 * Every card has a square cover. The admin's uploaded image fills it with
 * object-fit: cover, so any aspect ratio lands cleanly in the square; a course
 * without an image gets the built-in book artwork in the course colour.
 *
 * @var array $courses
 * @var array $packages
 */
$cover = static function (?string $path, ?string $color, string $glyph = 'book'): void {
    // Only a hex colour reaches the style attribute; anything else in the
    // column falls back rather than becoming CSS.
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $color) === 1 ? $color : ($glyph === 'book' ? '#2f6bff' : '#7c6cf3');
    ?>
    <span class="cv-cover" style="--cv: <?= e($color) ?>;">
        <?php if ($path): ?>
            <img src="/assets/<?= e($path) ?>" alt="" loading="lazy">
        <?php elseif ($glyph === 'book'): ?>
            <svg viewBox="0 0 64 64" aria-hidden="true">
                <path d="M14 12h26a8 8 0 0 1 8 8v32H22a8 8 0 0 1-8-8z" fill="rgba(255,255,255,.95)"/>
                <path d="M14 44a8 8 0 0 1 8-8h26v16H22a8 8 0 0 1-8-8z" fill="rgba(255,255,255,.55)"/>
                <path d="M22 20h18M22 26h14" stroke="var(--cv)" stroke-width="3" stroke-linecap="round"/>
            </svg>
        <?php else: ?>
            <svg viewBox="0 0 64 64" aria-hidden="true">
                <path d="m32 10 20 10v24L32 54 12 44V20z" fill="rgba(255,255,255,.95)"/>
                <path d="m12 20 20 10 20-10M32 30v24" stroke="var(--cv)" stroke-width="3" fill="none" stroke-linejoin="round"/>
            </svg>
        <?php endif; ?>
    </span>
<?php };
?>
<div class="cv-head">
    <div>
        <h2 class="greet" style="margin:0;">مطالعه</h2>
        <p class="greet-sub">دوره‌ها و پکیج‌هایی که دسترسی فعال دارید.</p>
    </div>
    <div class="seg-tabs cv-switch" role="group" aria-label="نحوه نمایش" data-view-switch="courses">
        <button type="button" class="seg-tab is-active" data-view="grid" aria-pressed="true">▦ شبکه‌ای</button>
        <button type="button" class="seg-tab" data-view="list" aria-pressed="false">☰ لیستی</button>
    </div>
</div>

<div class="cv-wrap" data-view-target="courses" data-view="grid">
    <?php if (!empty($packages)): ?>
        <h3 class="cv-section">پکیج‌های من</h3>
        <div class="cv-items">
            <?php foreach ($packages as $package): ?>
                <div class="cv-card">
                    <?php $cover(null, $package['color'] ?? null, 'package'); ?>
                    <div class="cv-body">
                        <span class="cv-kind">پکیج</span>
                        <h4 class="cv-title"><?= e($package['title']) ?></h4>
                        <p class="cv-desc"><?= e(fa((string) $package['course_count'])) ?> دوره</p>
                        <?php if ($package['ends_at']): ?>
                            <span class="cv-note">اعتبار تا <?= e(jdate($package['ends_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h3 class="cv-section">دوره‌های من</h3>
    <?php if ($courses === []): ?>
        <div class="card"><div class="empty">هنوز دوره‌ای برای شما فعال نشده است.</div></div>
    <?php else: ?>
        <div class="cv-items">
            <?php foreach ($courses as $course):
                $total   = (int) $course['content_count'];
                $done    = (int) $course['completed_count'];
                $percent = $total > 0 ? (int) round($done / $total * 100) : 0;
            ?>
                <a class="cv-card" href="/student/courses/<?= e($course['uuid']) ?>">
                    <?php $cover($course['thumbnail_path'] ?? null, $course['color'] ?? null); ?>
                    <div class="cv-body">
                        <h4 class="cv-title"><?= e($course['title']) ?></h4>
                        <?php if (!empty($course['description'])): ?>
                            <p class="cv-desc"><?= e(mb_substr((string) $course['description'], 0, 110, 'UTF-8')) ?></p>
                        <?php endif; ?>
                        <div class="cv-progress">
                            <div class="progress"><span style="width: <?= $percent ?>%"></span></div>
                            <div class="cv-meta">
                                <span><?= e(fa((string) $done)) ?> از <?= e(fa((string) $total)) ?> مطالعه‌شده</span>
                                <strong><?= e(fa((string) $percent)) ?>٪</strong>
                            </div>
                        </div>
                        <?php if ($course['ends_at']): ?>
                            <span class="cv-note">اعتبار تا <?= e(jdate($course['ends_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
