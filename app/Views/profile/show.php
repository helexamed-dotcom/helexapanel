<?php
/**
 * A student's profile, Instagram-like.
 *
 * @var array      $person
 * @var array      $settings
 * @var string     $relation  self | admin | accepted | pending | none
 * @var bool       $locked    private and the viewer does not follow
 * @var array      $can       section => may the viewer see it
 * @var array      $counts    followers / following / pending
 * @var int        $postCount
 * @var array      $posts
 * @var array|null $summary   Points::summary()
 * @var array|null $stats
 * @var array      $badges
 * @var int|null   $place     this week's place
 * @var string     $tab
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$self = $relation === 'self';
$tone = $settings['tone'];
$handle = $settings['handle'];
$level = $summary['progress'] ?? null;
$league = $summary['league'] ?? null;
$profileUrl = '/u/' . ($handle ?: $person['uuid']);
$tierIcon = ['bronze' => '🥉', 'silver' => '🥈', 'gold' => '🥇', 'platinum' => '💠'];
?>
<?php if ($self): ?><?php View::partial('partials.profile_tabs', ['currentPath' => $currentPath, 'currentUser' => $currentUser]); ?><?php endif; ?>

<div class="pf tone-<?= e($tone) ?>">
    <section class="pf-head">
        <div class="pf-cover" aria-hidden="true"><i></i><i></i><i></i></div>
        <div class="pf-id">
            <div class="pf-avatar-wrap">
                <span class="pf-ring"<?= $level ? ' style="--p: ' . (float) $level['percent'] . '"' : '' ?>>
                    <span class="pf-avatar"><?php View::partial('partials.avatar', ['person' => $person]); ?></span>
                </span>
                <?php if ($can['level'] && $level): ?><b class="pf-level" title="سطح"><?= e(fa((string) $level['level'])) ?></b><?php endif; ?>
            </div>
            <div class="pf-counts">
                <a href="?tab=posts"><b><?= e(fa((string) $postCount)) ?></b><small>پست</small></a>
                <a href="<?= $self ? '/student/people?list=followers' : '#' ?>"><b><?= e(fa((string) $counts['followers'])) ?></b><small>دنبال‌کننده</small></a>
                <a href="<?= $self ? '/student/people?list=following' : '#' ?>"><b><?= e(fa((string) $counts['following'])) ?></b><small>دنبال‌شونده</small></a>
            </div>
        </div>

        <div class="pf-who">
            <h2><?= e((string) $person['full_name']) ?>
                <?php if ($settings['is_private']): ?><span class="pf-lock" title="حساب خصوصی"><?php $icon('lock', 15); ?></span><?php endif; ?>
            </h2>
            <?php if ($handle): ?><small dir="ltr">@<?= e($handle) ?></small><?php endif; ?>
            <?php if ($can['level'] && $summary): ?><span class="pf-rank"><?= e((string) ($summary['rank']['title'] ?? '')) ?></span><?php endif; ?>
            <?php if ($settings['bio'] !== ''): ?><p class="pf-bio"><?= nl2br(e($settings['bio'])) ?></p><?php endif; ?>
        </div>

        <div class="pf-actions">
            <?php if ($self): ?>
                <a class="pf-btn" href="/account/edit"><?php $icon('pencil', 16); ?> ویرایش پروفایل</a>
                <a class="pf-btn" href="/account/privacy"><?php $icon('eye', 16); ?> حریم خصوصی<?= $counts['pending'] > 0 ? ' <em>' . e(fa((string) $counts['pending'])) . '</em>' : '' ?></a>
                <button class="pf-btn is-icon" type="button" data-share="<?= e($profileUrl) ?>" title="کپی پیوند پروفایل"><?php $icon('link', 16); ?></button>
            <?php elseif ($relation === 'admin'): ?>
                <a class="pf-btn" href="/admin/students/<?= e($person['uuid']) ?>/edit"><?php $icon('user', 16); ?> پرونده دانشجو</a>
            <?php else: ?>
                <form method="post" action="/profile/follow/<?= e($person['uuid']) ?>" data-follow>
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="back" value="<?= e($profileUrl) ?>">
                    <button class="pf-btn <?= $relation === 'none' ? 'is-primary' : '' ?>" type="submit" data-follow-btn data-state="<?= e($relation) ?>">
                        <?= $relation === 'accepted' ? 'دنبال می‌کنی ✓' : ($relation === 'pending' ? 'درخواست فرستاده شد' : 'دنبال کردن') ?>
                    </button>
                </form>
                <a class="pf-btn" href="/student/people"><?php $icon('users', 16); ?> هم‌کلاسی‌ها</a>
            <?php endif; ?>
        </div>

        <?php if (!$locked && $summary && ($can['league'] || $can['streak'] || $can['level'])): ?>
            <div class="pf-highlights">
                <?php if ($can['league'] && $league): ?>
                    <a class="pf-hl tone-<?= e($league['color']) ?>" href="/student/leaderboard"><span><?= e($league['emoji']) ?></span><small><?= e($league['title']) ?></small></a>
                <?php endif; ?>
                <?php if ($can['league'] && $place !== null): ?>
                    <a class="pf-hl tone-amber" href="/student/leaderboard"><span>#<?= e(fa((string) $place)) ?></span><small>رتبه هفته</small></a>
                <?php endif; ?>
                <?php if ($can['streak']): ?>
                    <span class="pf-hl tone-orange"><span>🔥<?= e(fa((string) $summary['streak'])) ?></span><small>روز پیاپی</small></span>
                <?php endif; ?>
                <?php if ($can['level']): ?>
                    <span class="pf-hl tone-violet"><span>⚡<?= e(fa(number_format((int) $summary['total']))) ?></span><small>امتیاز کل</small></span>
                    <span class="pf-hl tone-blue"><span>+<?= e(fa(number_format((int) $summary['week']))) ?></span><small>این هفته</small></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($locked): ?>
        <section class="pf-locked">
            <span class="app-ic tone-slate"><?php $icon('lock', 26); ?></span>
            <b>این حساب خصوصی است</b>
            <small>برای دیدن پست‌ها و آمار، دنبالش کن تا درخواستت را قبول کند.</small>
        </section>
    <?php else: ?>

        <?php if ($summary && ($can['level'] || $can['league'])): ?>
            <div class="pf-score">
                <?php if ($can['level'] && $level): ?>
                    <section class="pf-card">
                        <div class="pf-card-top"><span class="app-ic tone-violet"><?php $icon('bolt', 20); ?></span><div><b>سطح <?= e(fa((string) $level['level'])) ?></b><small><?= e((string) ($summary['rank']['title'] ?? '')) ?></small></div></div>
                        <div class="pf-bar"><i style="width: <?= (float) $level['percent'] ?>%"></i></div>
                        <small class="pf-note"><?= e(fa(number_format((int) $level['xp_for_next']))) ?> امتیاز تا سطح <?= e(fa((string) ($level['level'] + 1))) ?></small>
                    </section>
                <?php endif; ?>
                <?php if ($can['league'] && $league): $next = $league['next']; ?>
                    <section class="pf-card">
                        <div class="pf-card-top"><span class="app-ic tone-<?= e($league['color']) ?>"><span class="pf-emoji"><?= e($league['emoji']) ?></span></span><div><b><?= e($league['title']) ?></b><small><?= e(fa(number_format((int) $summary['week']))) ?> امتیاز این هفته</small></div></div>
                        <?php if ($next): $span = max(1, $next['min'] - $league['min']); ?>
                            <div class="pf-bar is-league"><i style="width: <?= round(min(100, max(0, ($summary['week'] - $league['min']) * 100 / $span)), 1) ?>%"></i></div>
                            <small class="pf-note"><?= e(fa(number_format((int) $next['need']))) ?> امتیاز تا <?= e($next['title']) ?></small>
                        <?php else: ?>
                            <small class="pf-note">بالاترین لیگ! 👑</small>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <nav class="pf-tabs" role="tablist">
            <?php foreach (['posts' => ['posts', 'پست‌ها'], 'badges' => ['medal', 'دستاوردها'], 'stats' => ['chart', 'آمار']] as $k => [$ic, $label]):
                if (($k === 'posts' && !$can['posts']) || ($k === 'badges' && !$can['badges']) || ($k === 'stats' && !$can['stats'])) { continue; } ?>
                <a class="pf-tab<?= $tab === $k ? ' is-on' : '' ?>" href="?tab=<?= e($k) ?>" role="tab" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?php $icon($ic, 18); ?><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($tab === 'posts' && $can['posts']): ?>
            <?php if ($self): ?>
                <details class="pf-compose" <?= $posts === [] ? 'open' : '' ?>>
                    <summary><span class="pf-compose-ic"><?php $icon('plus', 18); ?></span> پست تازه</summary>
                    <form method="post" action="/profile/posts" enctype="multipart/form-data" class="pf-compose-form" data-compose>
                        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                        <textarea class="input" name="body" rows="3" maxlength="2000" placeholder="از مطالعه امروزت بنویس، یک نکته، یک عکس از جزوه…"></textarea>
                        <div class="pf-compose-row">
                            <label class="pf-attach"><input type="file" name="image" accept="image/*" data-compose-file><?php $icon('camera', 18); ?> <span data-compose-name>عکس</span></label>
                            <div class="pf-tones">
                                <?php foreach (['indigo', 'violet', 'pink', 'orange', 'teal', 'slate'] as $i => $t): ?>
                                    <label class="tone-<?= e($t) ?>"><input type="radio" name="tone" value="<?= e($t) ?>" <?= $i === 0 ? 'checked' : '' ?>><span></span></label>
                                <?php endforeach; ?>
                            </div>
                            <select class="input pf-aud" name="audience" aria-label="چه کسانی ببینند؟">
                                <option value="everyone">🌍 همه</option>
                                <option value="followers">👥 دنبال‌کننده‌ها</option>
                                <option value="me">🔒 فقط من</option>
                            </select>
                            <button class="btn btn-primary" type="submit" data-lock-on-submit><?php $icon('send', 16); ?> انتشار</button>
                        </div>
                        <img class="pf-compose-preview" alt="" hidden data-compose-preview>
                    </form>
                </details>
            <?php endif; ?>

            <?php if ($posts === []): ?>
                <div class="pf-empty"><span class="app-ic tone-<?= e($tone) ?>"><?php $icon('camera', 26); ?></span><b><?= $self ? 'اولین پستت را بگذار' : 'هنوز پستی نیست' ?></b></div>
            <?php else: ?>
                <div class="pf-grid">
                    <?php foreach ($posts as $i => $p): ?>
                        <button type="button" class="pf-post tone-<?= e($p['tone']) ?><?= $p['image_path'] ? ' has-img' : '' ?>" style="--i: <?= min($i, 15) ?>" data-post
                                data-uuid="<?= e($p['uuid']) ?>" data-liked="<?= (int) $p['liked'] ?>" data-likes="<?= (int) $p['like_count'] ?>"
                                data-date="<?= e(jdate($p['created_at'])) ?>" data-aud="<?= e($p['audience']) ?>" data-mine="<?= $self ? 1 : 0 ?>">
                            <?php if ($p['image_path']): ?><img src="/media/posts/<?= e($p['image_path']) ?>" alt="" loading="lazy"><?php endif; ?>
                            <?php if ($p['body']): ?><span class="pf-post-text"><?= e(mb_strimwidth((string) $p['body'], 0, 160, '…')) ?></span><?php endif; ?>
                            <template data-full><?= nl2br(e((string) $p['body'])) ?></template>
                            <span class="pf-post-meta"><?php $icon('heart', 13); ?> <?= e(fa((string) $p['like_count'])) ?><?= $p['audience'] !== 'everyone' ? ' · ' . ($p['audience'] === 'me' ? '🔒' : '👥') : '' ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($tab === 'badges' && $can['badges']): ?>
            <?php if ($badges === []): ?>
                <div class="pf-empty"><span class="app-ic tone-amber"><?php $icon('medal', 26); ?></span><b>هنوز نشانی گرفته نشده</b><small>با حل سوال، مرور فلش‌کارت و پیش رفتن در جزیره بالین نشان بگیر.</small></div>
            <?php else: ?>
                <div class="pf-badges">
                    <?php foreach ($badges as $i => $b): ?>
                        <div class="pf-badge is-<?= e((string) $b['tier']) ?>" style="--i: <?= $i ?>">
                            <span class="pf-badge-medal"><?= e($tierIcon[$b['tier']] ?? '🏅') ?></span>
                            <b><?= e((string) $b['title']) ?></b>
                            <small><?= e((string) ($b['description'] ?? '')) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($tab === 'stats' && $can['stats'] && $stats): ?>
            <div class="pf-stats">
                <?php foreach ([
                    ['qbank', 'blue', 'سوال حل‌شده', $stats['questions']],
                    ['check', 'green', 'پاسخ درست', $stats['correct']],
                    ['lesson', 'indigo', 'درسنامه خوانده‌شده', $stats['lessons']],
                    ['cards', 'pink', 'مرور فلش‌کارت', $stats['cards']],
                    ['exam', 'amber', 'آزمون تمام‌شده', $stats['exams']],
                ] as $i => [$ic, $tn, $label, $n]): ?>
                    <div class="pf-stat" style="--i: <?= $i ?>"><span class="app-ic tone-<?= e($tn) ?>"><?php $icon($ic, 20); ?></span><b><?= e(fa(number_format((int) $n))) ?></b><small><?= e($label) ?></small></div>
                <?php endforeach; ?>
                <?php if ($stats['questions'] > 0): ?>
                    <div class="pf-stat is-wide"><span class="pf-donut" style="--p: <?= round($stats['correct'] * 100 / max(1, $stats['questions'])) ?>"><b>٪<?= e(fa((string) round($stats['correct'] * 100 / max(1, $stats['questions'])))) ?></b></span><small>دقت پاسخ‌ها</small></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="pf-viewer" data-viewer hidden>
    <div class="pf-viewer-scrim" data-viewer-close></div>
    <article class="pf-viewer-card" role="dialog" aria-modal="true">
        <header>
            <span class="pf-viewer-who"><span class="pf-avatar is-sm"><?php View::partial('partials.avatar', ['person' => $person]); ?></span><b><?= e((string) $person['full_name']) ?></b></span>
            <button type="button" class="pf-x" data-viewer-close aria-label="بستن"><?php $icon('close', 18); ?></button>
        </header>
        <div class="pf-viewer-media" data-viewer-media></div>
        <div class="pf-viewer-body" data-viewer-body></div>
        <footer>
            <button type="button" class="pf-like" data-like><span class="pf-heart"><?php $icon('heart', 22); ?></span><b data-like-count>۰</b></button>
            <small data-viewer-date></small>
            <?php if ($self): ?>
                <button type="button" class="pf-del" data-delete title="حذف پست"><?php $icon('trash', 18); ?></button>
            <?php endif; ?>
        </footer>
    </article>
</div>
