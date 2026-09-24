<?php
/**
 * The rankings: this week's league, all time, and the student's friends.
 *
 * @var string     $tab      week | all | friends
 * @var array      $rows
 * @var array      $handles  user id => handle
 * @var int        $me
 * @var array|null $summary
 * @var bool       $hidden   the student hid themselves from the rankings
 * @var array      $leagues
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$league = $summary['league'] ?? null;
$podium = array_slice($rows, 0, 3);
$rest = array_slice($rows, 3);
$mine = null;
foreach ($rows as $r) {
    if ((int) $r['user_id'] === $me) {
        $mine = $r;
    }
}
$link = static fn (array $r) => '/u/' . ($handles[(int) $r['user_id']] ?? $r['uuid']);
?>
<div class="lbd">
    <?php if ($league): ?>
        <section class="lbd-hero tone-<?= e($league['color']) ?>">
            <span class="lbd-emoji"><?= e($league['emoji']) ?></span>
            <div>
                <small>لیگ این هفته‌ی تو</small>
                <h2><?= e($league['title']) ?></h2>
                <p><?= e(fa(number_format((int) $summary['week']))) ?> امتیاز این هفته<?= $league['next'] ? ' · ' . e(fa(number_format((int) $league['next']['need']))) . ' امتیاز تا ' . e($league['next']['title']) : ' · بالاترین لیگ 👑' ?></p>
            </div>
            <ol class="lbd-ladder" aria-label="لیگ‌ها">
                <?php foreach ($leagues as $k => [$title, , , $emoji]): ?>
                    <li class="<?= $k === $league['key'] ? 'is-on' : '' ?>" title="<?= e($title) ?>"><?= e($emoji) ?></li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>

    <?php if ($hidden): ?>
        <a class="lbd-hidden" href="/account/privacy"><?php $icon('eye-off', 16); ?> نامت در رتبه‌بندی نمایش داده نمی‌شود — تغییر در حریم خصوصی</a>
    <?php endif; ?>

    <nav class="lbd-tabs">
        <?php foreach (['week' => 'این هفته', 'all' => 'همه زمان‌ها', 'friends' => 'دوستان'] as $k => $l): ?>
            <a class="<?= $tab === $k ? 'is-on' : '' ?>" href="?tab=<?= e($k) ?>"><?= e($l) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($rows === []): ?>
        <div class="pf-empty"><span class="app-ic tone-amber"><?php $icon('trophy', 26); ?></span><b><?= $tab === 'friends' ? 'هنوز کسی را دنبال نمی‌کنی' : 'هنوز کسی امتیاز نگرفته' ?></b>
            <small><?= $tab === 'friends' ? '<a href="/student/people">هم‌کلاسی‌هایت را پیدا کن</a>' : 'با حل سوال، خواندن درسنامه و مرور فلش‌کارت اولین نفر باش!' ?></small></div>
    <?php else: ?>
        <div class="lbd-podium">
            <?php foreach ([1, 0, 2] as $idx): if (!isset($podium[$idx])) { continue; } $r = $podium[$idx]; ?>
                <a class="lbd-pod is-<?= $idx + 1 ?><?= (int) $r['user_id'] === $me ? ' is-me' : '' ?>" href="<?= e($link($r)) ?>">
                    <span class="lbd-pod-av"><span class="pf-avatar"><?php View::partial('partials.avatar', ['person' => $r]); ?></span><em><?= ['🥇', '🥈', '🥉'][$idx] ?></em></span>
                    <b><?= e((string) $r['full_name']) ?></b>
                    <small><?= e(fa(number_format((int) $r['xp']))) ?> امتیاز</small>
                    <span class="lbd-pod-bar"><?= e(fa((string) ($idx + 1))) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($rest !== []): ?>
            <ol class="lbd-list" start="4">
                <?php foreach ($rest as $i => $r): ?>
                    <li class="<?= (int) $r['user_id'] === $me ? 'is-me' : '' ?>" style="--i: <?= min($i, 15) ?>">
                        <a href="<?= e($link($r)) ?>">
                            <span class="lbd-place"><?= e(fa((string) $r['place'])) ?></span>
                            <span class="pf-avatar is-sm"><?php View::partial('partials.avatar', ['person' => $r]); ?></span>
                            <b><?= e((string) $r['full_name']) ?></b>
                            <span class="lbd-xp"><?= e(fa(number_format((int) $r['xp']))) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
        <?php if ($mine === null && !$hidden && $tab !== 'friends'): ?>
            <p class="lbd-you">هنوز در این جدول نیستی — امروز چند سوال حل کن تا اسمت این‌جا بیاید 💪</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
