<?php
/**
 * «هم‌کلاسی‌ها»: search, follow requests, followers / following, and the
 * feed of people the student follows.
 *
 * @var string $q
 * @var string $list      '' | followers | following
 * @var array  $results
 * @var array  $people
 * @var array  $requests
 * @var array  $counts
 * @var array  $feed
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$row = static function (array $r, ?string $status = null) use ($icon, $csrf_token): void { ?>
    <li>
        <a class="pf-person" href="/u/<?= e($r['handle'] ?: $r['uuid']) ?>">
            <span class="pf-avatar is-sm tone-<?= e($r['tone'] ?? 'indigo') ?>"><?php View::partial('partials.avatar', ['person' => $r]); ?></span>
            <span><b><?= e($r['full_name']) ?><?= !empty($r['is_private']) ? ' 🔒' : '' ?></b><?php if (!empty($r['handle'])): ?><small dir="ltr">@<?= e($r['handle']) ?></small><?php endif; ?></span>
        </a>
        <?php if ($status !== 'skip'): ?>
            <form method="post" action="/profile/follow/<?= e($r['uuid']) ?>" data-follow>
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <input type="hidden" name="back" value="/student/people">
                <button class="pf-btn is-sm <?= $status === null ? 'is-primary' : '' ?>" type="submit" data-follow-btn data-state="<?= e($status ?? 'none') ?>">
                    <?= $status === 'accepted' ? 'دنبال می‌کنی ✓' : ($status === 'pending' ? 'درخواست شد' : 'دنبال کردن') ?>
                </button>
            </form>
        <?php endif; ?>
    </li>
<?php };
?>
<div class="pf-people-page">
    <section class="pf-find">
        <div>
            <h2>هم‌کلاسی‌ها</h2>
            <p><a href="?list=followers"><?= e(fa((string) $counts['followers'])) ?> دنبال‌کننده</a> · <a href="?list=following"><?= e(fa((string) $counts['following'])) ?> دنبال‌شونده</a></p>
        </div>
        <form method="get" action="/student/people" class="pf-search" role="search">
            <?php $icon('search', 18); ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="جستجوی نام یا @نام‌کاربری…" aria-label="جستجو">
        </form>
    </section>

    <?php if ($requests !== [] && $q === ''): ?>
        <section class="pf-set-card">
            <h3><?php $icon('users', 18); ?> درخواست‌ها <em><?= e(fa((string) count($requests))) ?></em></h3>
            <ul class="pf-people">
                <?php foreach ($requests as $r): ?>
                    <li>
                        <a class="pf-person" href="/u/<?= e($r['handle'] ?: $r['uuid']) ?>"><span class="pf-avatar is-sm"><?php View::partial('partials.avatar', ['person' => $r]); ?></span><span><b><?= e($r['full_name']) ?></b></span></a>
                        <form method="post" action="/profile/requests/<?= e($r['uuid']) ?>">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <input type="hidden" name="back" value="people">
                            <button class="pf-btn is-primary is-sm" type="submit" name="decision" value="accept">قبول</button>
                            <button class="pf-btn is-sm" type="submit" name="decision" value="decline">رد</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($q !== ''): ?>
        <section class="pf-set-card">
            <h3><?php $icon('search', 18); ?> نتیجه «<?= e($q) ?>»</h3>
            <?php if ($results === []): ?>
                <p class="pf-muted">کسی با این نام پیدا نشد.</p>
            <?php else: ?>
                <ul class="pf-people"><?php foreach ($results as $r) { $row($r, $r['follow_status']); } ?></ul>
            <?php endif; ?>
        </section>
    <?php elseif ($list !== ''): ?>
        <section class="pf-set-card">
            <h3><?php $icon('users', 18); ?> <?= $list === 'followers' ? 'دنبال‌کننده‌ها' : 'دنبال‌شونده‌ها' ?></h3>
            <?php if ($people === []): ?>
                <p class="pf-muted">هنوز کسی نیست.</p>
            <?php else: ?>
                <ul class="pf-people"><?php foreach ($people as $r) { $row($r, $list === 'following' ? 'accepted' : 'skip'); } ?></ul>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <h3 class="pf-feed-t"><?php $icon('posts', 18); ?> تازه‌ها</h3>
        <?php if ($feed === []): ?>
            <div class="pf-empty"><span class="app-ic tone-indigo"><?php $icon('users', 26); ?></span><b>هم‌کلاسی‌هایت را پیدا کن</b><small>نامشان را بالا جستجو کن و دنبالشان کن تا پست‌هایشان این‌جا بیاید.</small></div>
        <?php else: ?>
            <div class="pf-feed">
                <?php foreach ($feed as $i => $p): ?>
                    <article class="pf-feed-item" style="--i: <?= min($i, 10) ?>">
                        <header>
                            <a class="pf-person" href="/u/<?= e($p['handle'] ?: $p['user_uuid']) ?>">
                                <span class="pf-avatar is-sm"><?php View::partial('partials.avatar', ['person' => ['uuid' => $p['user_uuid'], 'avatar_path' => $p['avatar_path'], 'gender' => $p['gender']]]); ?></span>
                                <span><b><?= e($p['full_name']) ?></b><small><?= e(jdate($p['created_at'])) ?></small></span>
                            </a>
                        </header>
                        <?php if ($p['image_path']): ?><img class="pf-feed-img" src="/media/posts/<?= e($p['image_path']) ?>" alt="" loading="lazy"><?php endif; ?>
                        <?php if ($p['body']): ?><p class="pf-feed-body<?= $p['image_path'] ? '' : ' is-card tone-' . e($p['tone']) ?>"><?= nl2br(e((string) $p['body'])) ?></p><?php endif; ?>
                        <footer>
                            <button type="button" class="pf-like<?= (int) $p['liked'] === 1 ? ' is-on' : '' ?>" data-like-inline="<?= e($p['uuid']) ?>"><span class="pf-heart"><?php $icon('heart', 20); ?></span><b><?= e(fa((string) $p['like_count'])) ?></b></button>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
