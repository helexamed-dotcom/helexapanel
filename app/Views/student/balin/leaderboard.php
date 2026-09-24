<?php
/**
 * Leaderboards.
 *
 * Read from a snapshot rather than computed live, so the timestamp under the
 * table is honest about how fresh the numbers are.
 *
 * @var string $board
 * @var ?int   $scope
 * @var array  $page
 * @var array  $lessons
 * @var array  $tracks
 * @var array  $competition
 */
use HeleXa\Models\Balin\BalinLeaderboardRepository;

$link = static function (string $type, ?int $scopeId = null): string {
    $query = ['board' => $type];
    if ($scopeId !== null) {
        $query['scope'] = $scopeId;
    }
    return '/student/balin/leaderboard?' . http_build_query($query + ['island' => 1]);
};
?>
<div class="balin">
    <header class="balin-board-head">
        <h2>جدول رتبه‌بندی</h2>
        <?php if ($competition['competition'] !== null && $competition['mode'] === 'active'): ?>
            <p>رقابت جاری: <strong><?= e($competition['competition']['title']) ?></strong>
                تا <?= e(jdate((string) $competition['competition']['end_date'])) ?></p>
        <?php elseif ($competition['mode'] === 'last_ended' && $competition['competition'] !== null): ?>
            <p>آخرین رقابت: <?= e($competition['competition']['title']) ?> (پایان‌یافته)</p>
        <?php endif; ?>
    </header>

    <nav class="balin-board-tabs" aria-label="نوع جدول">
        <?php foreach (BalinLeaderboardRepository::TYPES as $type => $label):
            if (in_array($type, ['lesson', 'skill'], true)) {
                continue;   // these need a scope, offered in the selects below
            }
        ?>
            <a class="balin-tab<?= $board === $type ? ' is-active' : '' ?>" href="<?= e($link($type)) ?>">
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php /* Plain GET forms, so the scoped boards work with no JavaScript. */ ?>
    <div class="balin-board-filters">
        <?php if ($lessons !== []): ?>
            <form method="get" action="/student/balin/leaderboard" class="balin-filter">
                <input type="hidden" name="island" value="1">
                <input type="hidden" name="board" value="lesson">
                <label class="field">
                    <span>رتبه در یک درس</span>
                    <select name="scope">
                        <?php foreach ($lessons as $lesson): ?>
                            <?php if ($lesson['status'] !== 'published') { continue; } ?>
                            <option value="<?= (int) $lesson['id'] ?>"
                                <?= $board === 'lesson' && $scope === (int) $lesson['id'] ? 'selected' : '' ?>>
                                <?= e($lesson['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-ghost btn-sm" type="submit">نمایش</button>
            </form>
        <?php endif; ?>

        <?php if ($tracks !== []): ?>
            <form method="get" action="/student/balin/leaderboard" class="balin-filter">
                <input type="hidden" name="island" value="1">
                <input type="hidden" name="board" value="skill">
                <label class="field">
                    <span>رتبه در یک مهارت</span>
                    <select name="scope">
                        <?php foreach ($tracks as $track): ?>
                            <option value="<?= (int) $track['id'] ?>"
                                <?= $board === 'skill' && $scope === (int) $track['id'] ? 'selected' : '' ?>>
                                <?= e($track['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-ghost btn-sm" type="submit">نمایش</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($page['own_rank'] !== null): ?>
        <div class="balin-own-rank">
            جایگاه تو در این جدول:
            <strong><?= e(fa((int) $page['own_rank']['rank_position'])) ?></strong>
            با <?= e(fa(round((float) $page['own_rank']['score']))) ?> امتیاز
        </div>
    <?php endif; ?>

    <?php if ($page['rows'] === []): ?>
        <div class="empty">
            <?php if ($board === 'weekly_xp' && $competition['competition'] === null): ?>
                در حال حاضر رقابتی در جریان نیست.
            <?php else: ?>
                هنوز داده‌ای برای این جدول ثبت نشده است.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <ol class="balin-board" role="list">
            <?php foreach ($page['rows'] as $row): ?>
                <li class="balin-board-row<?= (int) $row['rank_position'] <= 3 ? ' is-top' : '' ?>">
                    <span class="balin-board-rank"><?= e(fa((int) $row['rank_position'])) ?></span>
                    <span class="avatar balin-board-avatar">
                        <?php \HeleXa\Core\View::partial('partials.avatar', [
                            'person' => ['uuid' => $row['user_uuid']] + $row,
                        ]); ?>
                    </span>
                    <span class="balin-board-name">
                        <strong><?= e($row['full_name']) ?></strong>
                        <span class="leaf-meta"><?= e($row['rank_title']) ?></span>
                    </span>
                    <span class="balin-board-score"><?= e(fa(round((float) $row['score']))) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($page['pages'] > 1): ?>
            <nav class="pager" aria-label="صفحه‌بندی">
                <?php for ($p = 1; $p <= $page['pages']; $p++):
                    $query = ['board' => $board, 'page' => $p];
                    if ($scope !== null) {
                        $query['scope'] = $scope;
                    }
                ?>
                    <a class="pager-link<?= $p === $page['page'] ? ' is-active' : '' ?>"
                       href="/student/balin/leaderboard?<?= e(http_build_query($query + ['island' => 1])) ?>"><?= e(fa($p)) ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>

        <?php if ($page['generated_at'] !== null): ?>
            <p class="balin-foot-note">آخرین به‌روزرسانی: <?= e(jdate($page['generated_at'])) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
