<?php
/**
 * The student's question list for one درس: search, status filters, and each
 * row's own history. A row opens the player at that exact question with the
 * same filters, so practice continues through the same set.
 *
 * @var array $subject
 * @var array $rows
 * @var array $filters
 * @var int   $page
 * @var int   $pages
 * @var int   $total
 * @var int   $offset
 * @var array $filing
 * @var array $allTags
 * @var array $difficulties
 * @var array $stats
 */
$base = '/student/qbank/' . rawurlencode((string) $subject['uuid']);
$keep = array_filter([
    'q'          => $filters['q'] ?: null,
    'mode'       => $filters['mode'] ?: null,
    'sub'        => $filters['sub_subject_id'] ?: null,
    'topic'      => $filters['topic_id'] ?: null,
    'difficulty' => $filters['difficulty'] ?: null,
    'tag'        => $filters['tag_id'] ?: null,
]);
?>
<div class="qx qx-listing">
    <header class="qx-bar">
        <a class="qx-subject" href="<?= e($base) ?>" title="صفحه درس">
            <span class="app-ic tone-violet"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'list', 'size' => 18]); ?></span>
            <span class="qx-subject-text"><b><?= e($subject['title']) ?></b><small><?= e(fa((string) $total)) ?> سوال با این فیلترها</small></span>
        </a>
        <div class="qx-bar-end">
            <?php if ($total > 0): ?>
                <a class="btn btn-primary btn-sm" href="<?= e($base . '?' . http_build_query($keep + ['n' => 1])) ?>">▶ تمرین همین‌ها</a>
            <?php endif; ?>
            <button type="button" class="qx-iconbtn qx-filter-btn" data-qx-filters-open aria-label="جستجو و فیلتر">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'sliders', 'size' => 18]); ?><?php if ($keep !== []): ?><em><?= e(fa((string) count($keep))) ?></em><?php endif; ?>
            </button>
        </div>
    </header>

    <div class="qx-grid">
    <?php \HeleXa\Core\View::partial('student.qbank._sidebar', [
        'action' => $base . '/list', 'filters' => $filters, 'filing' => $filing, 'allTags' => $allTags,
        'difficulties' => $difficulties, 'stats' => $stats, 'total' => $total, 'jumpBase' => $base,
    ]); ?>
    <main class="qx-q qx-rows">
    <?php if ($rows === []): ?>
        <div class="empty">سوالی با این فیلترها پیدا نشد.</div>
    <?php else: ?>
        <div class="qb-list">
            <?php foreach ($rows as $i => $row):
                $position = $offset + $i + 1;
                $state    = $row['last_correct'] === null ? '' : ((int) $row['last_correct'] === 1 ? ' is-right' : ' is-wrong');
                $label    = $row['last_correct'] === null ? 'پاسخ‌نداده'
                          : ((int) $row['last_correct'] === 1 ? 'آخرین بار درست' : 'آخرین بار غلط');
            ?>
                <a class="qb-row qb-qrow<?= $state ?>" href="<?= e($base . '?' . http_build_query($keep + ['n' => $position])) ?>">
                    <span class="qb-qnum" title="<?= e($label) ?>"><?= e(fa((string) $position)) ?></span>
                    <div>
                        <div class="qb-row-stem">
                            <?= !empty($row['stem_text']) ? e(mb_substr((string) $row['stem_text'], 0, 200)) : '<span class="qb-unfiled">🖼 سوال تصویری</span>' ?>
                        </div>
                        <div class="qb-row-meta">
                            <span><?= e($label) ?><?= (int) $row['attempts'] > 0 ? ' · ' . e(fa((string) $row['attempts'])) . ' بار' : '' ?></span>
                            <span class="qb-diff <?= e($row['difficulty']) ?>"><?= e($difficulties[$row['difficulty']] ?? '') ?></span>
                            <?php $path = array_filter([$row['sub_subject_title'], $row['topic_title']]); ?>
                            <?php if ($path !== []): ?>
                                <span class="qb-path"><?php foreach ($path as $part): ?><span><?= e($part) ?></span><?php endforeach; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="qb-qflags" aria-label="نشان‌ها">
                        <?= (int) $row['is_saved'] === 1 ? '<span title="نشان‌شده">⭐</span>' : '' ?>
                        <?= (int) $row['is_review'] === 1 ? '<span title="نیاز به مرور">🔁</span>' : '' ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="صفحه‌بندی">
                <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
                    <a class="pager-item<?= $p === $page ? ' is-current' : '' ?>"
                       href="<?= e($base . '/list?' . http_build_query($keep + ['page' => $p])) ?>"><?= e(fa((string) $p)) ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
    </main>
    </div>
</div>
