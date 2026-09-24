<?php
/**
 * The student's clinical profile: level and rank, skills, mastery, streak,
 * missions and badges.
 *
 * A skill with too few answers behind it shows "not enough data" rather than
 * a percentage — a figure from three questions would be believed, and it
 * would be wrong.
 *
 * @var array $profile
 * @var array $competition
 * @var array $rewards
 */
use HeleXa\Services\Balin\Mastery;

$level = $profile['level'];
$rank  = $profile['rank'];
$stats = $profile['stats'];
?>
<div class="balin balin-profile">
    <section class="balin-hero">
        <div class="balin-hero-rank">
            <span class="balin-hero-icon" aria-hidden="true"><?= e($rank['icon'] ?: '🏝️') ?></span>
            <div>
                <div class="balin-hero-title"><?= e($rank['title']) ?></div>
                <div class="balin-hero-sub">سطح <?= e(fa($level['level'])) ?> · <?= e(fa($level['xp'])) ?> امتیاز</div>
            </div>
        </div>

        <div class="balin-xpbar" role="progressbar"
             aria-valuenow="<?= (int) $level['percent'] ?>" aria-valuemin="0" aria-valuemax="100"
             aria-label="پیشرفت تا سطح بعد">
            <span style="width:<?= (float) $level['percent'] ?>%"></span>
        </div>
        <div class="balin-xpbar-note">
            <?= e(fa($level['xp_into_level'])) ?> از <?= e(fa($level['next_level_xp'] - $level['current_level_xp'])) ?>
            امتیاز این سطح
        </div>

        <div class="balin-hero-stats">
            <div><strong><?= e(fa((int) $stats['stages_completed'])) ?></strong><span>مرحله</span></div>
            <div><strong><?= e(fa((int) $stats['lessons_completed'])) ?></strong><span>درس</span></div>
            <div><strong><?= e(fa(round((float) $stats['accuracy_percent']))) ?>٪</strong><span>دقت</span></div>
            <div><strong><?= e(fa((int) $profile['streak']['current_streak'])) ?></strong><span>روز پیاپی</span></div>
        </div>
    </section>

    <?php if ($profile['ranks']['overall'] !== null || $profile['ranks']['weekly'] !== null): ?>
        <section class="card balin-ranks">
            <h3 class="card-title">جایگاه من</h3>
            <div class="balin-rank-row">
                <?php if ($profile['ranks']['overall'] !== null): ?>
                    <div><strong><?= e(fa((int) $profile['ranks']['overall']['rank_position'])) ?></strong><span>رتبه کلی</span></div>
                <?php endif; ?>
                <?php if ($profile['ranks']['weekly'] !== null): ?>
                    <div><strong><?= e(fa((int) $profile['ranks']['weekly']['rank_position'])) ?></strong><span>رتبه هفتگی</span></div>
                <?php endif; ?>
                <?php if ($profile['ranks']['mastery'] !== null): ?>
                    <div><strong><?= e(fa((int) $profile['ranks']['mastery']['rank_position'])) ?></strong><span>رتبه تسلط</span></div>
                <?php endif; ?>
            </div>
            <a class="btn btn-ghost btn-sm" href="/student/balin/leaderboard?island=1">مشاهده جدول کامل</a>
        </section>
    <?php endif; ?>

    <section class="card">
        <h3 class="card-title">مهارت‌های بالینی من</h3>
        <p class="balin-section-note">
            این مهارت‌ها در همه درس‌ها سنجیده می‌شوند، نه فقط یک درس.
        </p>

        <ul class="balin-skill-list" role="list">
            <?php foreach ($profile['skills'] as $skill):
                $track = $skill['track'];
            ?>
                <li>
                    <div class="balin-skill-row">
                        <span class="balin-skill-name">
                            <span aria-hidden="true"><?= e($track['icon'] ?: '🩺') ?></span>
                            <?= e($track['name']) ?>
                            <?php if ($skill['badge'] !== null): ?>
                                <span class="balin-badge is-<?= e($skill['badge']) ?>">
                                    <?= e($skill['badge_label']) ?>
                                </span>
                            <?php endif; ?>
                        </span>
                        <span class="balin-skill-score">
                            <?php if ($skill['reliable']): ?>
                                <?= e(fa(round((float) $skill['mastery_percent']))) ?>٪
                            <?php else: ?>
                                <span class="balin-skill-thin">داده کافی نیست</span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <?php if ($skill['reliable']): ?>
                        <div class="balin-progress" role="progressbar"
                             aria-valuenow="<?= (int) $skill['mastery_percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                            <span style="width:<?= (float) $skill['mastery_percent'] ?>%"></span>
                        </div>
                    <?php else: ?>
                        <div class="balin-skill-hint">
                            <?= e(fa((int) $skill['answered_count'])) ?> از
                            <?= e(fa((int) $skill['minimum_required'])) ?> سؤال لازم برای نمایش درصد
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($profile['weakest_skill'] !== null): ?>
            <div class="balin-callout is-hint">
                <div class="balin-callout-label">پیشنهاد</div>
                <div>
                    شاید بخوای «<?= e($profile['weakest_skill']['name']) ?>» رو بیشتر تمرین کنی.
                </div>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($profile['lesson_mastery'] !== []): ?>
        <section class="card">
            <h3 class="card-title">تسلط درسی</h3>
            <ul class="balin-skill-list" role="list">
                <?php foreach ($profile['lesson_mastery'] as $row): ?>
                    <li>
                        <div class="balin-skill-row">
                            <span class="balin-skill-name">
                                <span aria-hidden="true"><?= e($row['icon'] ?: '🩺') ?></span>
                                <?= e($row['title']) ?>
                            </span>
                            <span class="balin-skill-score"><?= e(fa(round((float) $row['mastery_percent']))) ?>٪</span>
                        </div>
                        <div class="balin-progress" role="progressbar"
                             aria-valuenow="<?= (int) $row['mastery_percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                            <span style="width:<?= (float) $row['mastery_percent'] ?>%"></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($profile['missions'] !== []): ?>
        <section class="card">
            <h3 class="card-title">مأموریت‌ها</h3>
            <ul class="balin-mission-list" role="list">
                <?php foreach ($profile['missions'] as $entry): ?>
                    <li class="balin-mission<?= $entry['completed'] ? ' is-done' : '' ?>">
                        <div class="balin-mission-head">
                            <strong><?= e($entry['mission']['title']) ?></strong>
                            <span>
                                <?php if ($entry['completed']): ?>
                                    انجام شد · <?= e(fa((int) $entry['mission']['xp_reward'])) ?>+ امتیاز
                                <?php else: ?>
                                    <?= e(fa((int) $entry['percent'])) ?>٪
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if ($entry['mission']['description']): ?>
                            <p class="balin-mission-desc"><?= e($entry['mission']['description']) ?></p>
                        <?php endif; ?>
                        <div class="balin-progress" role="progressbar"
                             aria-valuenow="<?= (int) $entry['percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                            <span style="width:<?= (int) $entry['percent'] ?>%"></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($profile['achievements'] !== []): ?>
        <section class="card">
            <h3 class="card-title">نشان‌ها</h3>
            <div class="balin-achievements">
                <?php foreach ($profile['achievements'] as $achievement): ?>
                    <div class="balin-achievement is-<?= e($achievement['tier']) ?>">
                        <span class="balin-achievement-icon" aria-hidden="true"><?= e($achievement['icon'] ?: '🏅') ?></span>
                        <div>
                            <strong><?= e($achievement['title']) ?></strong>
                            <span><?= e(Mastery::BADGE_LABELS[$achievement['tier']] ?? '') ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($rewards !== []): ?>
        <section class="card">
            <h3 class="card-title">جوایز من</h3>
            <ul class="balin-reward-list" role="list">
                <?php foreach ($rewards as $reward): ?>
                    <li>
                        <strong><?= e($reward['title']) ?></strong>
                        <?php if ($reward['competition_title']): ?>
                            <span class="leaf-meta"><?= e($reward['competition_title']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</div>
