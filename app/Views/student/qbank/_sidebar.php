<?php
/**
 * Search and filters as a side panel (right in RTL). Shared by the player
 * and the list, so both read and write the same query string. Every choice
 * applies at once (qbank.js submits on change); the search box on Enter.
 * On a phone the panel is a sheet opened from the top bar's «فیلتر».
 *
 * @var string $action
 * @var array  $filters
 * @var array  $filing
 * @var array  $allTags
 * @var array  $difficulties
 * @var array  $stats       answered / correct / distinct for the درس
 * @var int    $total       questions in the current set
 * @var string|null $jumpBase  the player's address (for «برو به سوال»), or null
 */
use HeleXa\Core\View;

$icon   = static fn (string $n, int $s = 16) => View::partial('partials.icon', ['name' => $n, 'size' => $s]);
$subs   = array_filter($filing, static fn (array $r): bool => (int) $r['depth'] === 2);
$topics = array_filter($filing, static fn (array $r): bool => (int) $r['depth'] === 3);
$modes  = [
    ''         => ['همه سوال‌ها', 'apps'],
    'new'      => ['پاسخ‌نداده', 'sparkle'],
    'wrong'    => ['غلط‌ها', 'close'],
    'correct'  => ['درست‌ها', 'check'],
    'answered' => ['پاسخ‌داده', 'list'],
    'saved'    => ['نشان‌شده', 'star'],
    'review'   => ['نیاز به مرور', 'refresh'],
];
$active = array_filter([
    $filters['q'] !== '', $filters['mode'] !== '', (int) $filters['sub_subject_id'] > 0, (int) $filters['topic_id'] > 0,
    $filters['difficulty'] !== '', (int) $filters['tag_id'] > 0,
]);
$accuracy = ($stats['answered'] ?? 0) > 0 ? (int) round($stats['correct'] * 100 / $stats['answered']) : null;
?>
<aside class="qx-filters" data-qx-filters aria-label="جستجو و فیلتر">
    <div class="qx-filters-head">
        <b><?php $icon('sliders'); ?> جستجو و فیلتر</b>
        <?php if ($active !== []): ?><a class="qx-clear" href="<?= e($action) ?>">پاک کردن <em><?= e(fa((string) count($active))) ?></em></a><?php endif; ?>
        <button type="button" class="qx-sheet-x" data-qx-filters-close aria-label="بستن"><?php $icon('close', 18); ?></button>
    </div>

    <form method="get" action="<?= e($action) ?>" class="qx-form" data-qx-auto>
        <label class="qx-search">
            <?php $icon('search'); ?>
            <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="جستجو در متن سوال…" aria-label="جستجو در متن سوال" enterkeyhint="search">
        </label>

        <div class="qx-group">
            <span class="qx-label">وضعیت</span>
            <div class="qx-modes">
                <?php foreach ($modes as $key => [$label, $ic]): ?>
                    <label class="qx-mode"><input type="radio" name="mode" value="<?= e($key) ?>" <?= $filters['mode'] === $key ? 'checked' : '' ?>>
                        <span><?php $icon($ic, 14); ?><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($subs !== [] || $topics !== []): ?>
            <div class="qx-group" data-tree-cascade>
                <span class="qx-label">بخش</span>
                <?php if ($subs !== []): ?>
                    <select class="input qx-select" name="sub" data-level="2" aria-label="زیردرس" data-qx-sub>
                        <option value="">همه زیردرس‌ها</option>
                        <?php foreach ($subs as $row): ?>
                            <option value="<?= (int) $row['id'] ?>" <?= (int) $filters['sub_subject_id'] === (int) $row['id'] ? 'selected' : '' ?>><?= e($row['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if ($topics !== []): ?>
                    <select class="input qx-select" name="topic" data-level="3" aria-label="عنوان" data-qx-topic>
                        <option value="">همه عنوان‌ها</option>
                        <?php foreach ($topics as $row): ?>
                            <option value="<?= (int) $row['id'] ?>" data-parent="<?= (int) $row['parent_id'] ?>"
                                <?= (int) $filters['topic_id'] === (int) $row['id'] ? 'selected' : '' ?>><?= e($row['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="qx-group">
            <span class="qx-label">سختی</span>
            <div class="qx-seg">
                <label><input type="radio" name="difficulty" value="" <?= $filters['difficulty'] === '' ? 'checked' : '' ?>><span>همه</span></label>
                <?php foreach ($difficulties as $key => $label): ?>
                    <label><input type="radio" name="difficulty" value="<?= e($key) ?>" <?= $filters['difficulty'] === $key ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($allTags !== []): ?>
            <div class="qx-group">
                <span class="qx-label">برچسب</span>
                <select class="input qx-select" name="tag" aria-label="برچسب">
                    <option value="">همه برچسب‌ها</option>
                    <?php foreach ($allTags as $tag): ?>
                        <option value="<?= (int) $tag['id'] ?>" <?= (int) $filters['tag_id'] === (int) $tag['id'] ? 'selected' : '' ?>><?= e($tag['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <noscript><button class="btn btn-primary btn-sm" type="submit">اعمال</button></noscript>
    </form>

    <div class="qx-foot">
        <div class="qx-stat"><b><?= e(fa((string) $total)) ?></b><small>سوال در این فهرست</small></div>
        <div class="qx-stat"><b><?= e(fa((string) ($stats['distinct'] ?? 0))) ?></b><small>تمرین‌شده</small></div>
        <div class="qx-stat"><b><?= $accuracy === null ? '—' : '٪' . e(fa((string) $accuracy)) ?></b><small>دقت</small></div>
    </div>
    <?php if (!empty($jumpBase) && $total > 1): ?>
        <form class="qx-jump" method="get" action="<?= e($jumpBase) ?>" data-qx-jump>
            <?php foreach (array_filter(['q' => $filters['q'], 'mode' => $filters['mode'], 'sub' => $filters['sub_subject_id'] ?: '', 'topic' => $filters['topic_id'] ?: '', 'difficulty' => $filters['difficulty'], 'tag' => $filters['tag_id'] ?: '']) as $k => $v): ?>
                <input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>">
            <?php endforeach; ?>
            <label>برو به سوال<input class="input" type="number" name="n" min="1" max="<?= (int) $total ?>" inputmode="numeric" placeholder="<?= e(fa('1')) ?>–<?= e(fa((string) $total)) ?>"></label>
            <button class="btn btn-ghost btn-sm" type="submit">برو</button>
        </form>
    <?php endif; ?>
</aside>
