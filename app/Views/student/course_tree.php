<?php
/**
 * A course's contents, as a folder tree.
 *
 * Each item is a card laid out down the page rather than a single row: on a
 * phone a title, a status chip and two buttons cannot share one line without
 * colliding, and the title is the part that gets crushed. Stacked, every
 * piece gets the width it needs and the study button is the obvious thing to
 * press.
 */
$children = [];
foreach ($sections as $section) {
    $children[$section['parent_id'] === null ? 0 : (int) $section['parent_id']][] = $section;
}

$statusMeta = [
    'completed'    => ['chip-green', 'کامل مطالعه شد'],
    'studying'     => ['chip-amber', 'در حال مطالعه'],
    'review_later' => ['chip-blue',  'مرور بعدی'],
    'unread'       => ['chip-gray',  'مطالعه نشده'],
];

/** Each kind of material gets the icon that says what it is. */
$typeMeta = [
    'full_notes'     => ['book',    'جزوه کامل'],
    'summary_notes'  => ['list',    'خلاصه'],
    'question_bank'  => ['exam',    'بانک سؤال'],
    'chat_learn'     => ['message', 'آموزش گفت‌وگویی'],
    'mind_map'       => ['layers',  'نقشه ذهنی'],
    'custom'         => ['folder',  'محتوا'],
];

$renderContents = function (array $list) use ($statuses, $statusMeta, $typeMeta, $offlineEnabled, $csrf_token): void {
    foreach ($list as $item) {
        $status = $statuses[(int) $item['id']]['status'] ?? 'unread';
        [$chip, $label] = $statusMeta[$status] ?? $statusMeta['unread'];
        [$icon, $typeLabel] = $typeMeta[$item['content_type'] ?? 'custom'] ?? $typeMeta['custom'];
        $minutes = (int) ($item['estimated_minutes'] ?? 0);
        ?>
        <article class="leaf-card is-<?= e($status) ?>">
            <div class="leaf-card-head">
                <span class="leaf-card-icon" aria-hidden="true">
                    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => $icon]); ?>
                </span>
                <div class="leaf-card-titles">
                    <h4 class="leaf-card-title"><?= e($item['title']) ?></h4>
                    <?php if ($item['description']): ?>
                        <p class="leaf-card-desc"><?= e($item['description']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="leaf-card-meta">
                <span class="stat-chip <?= e($chip) ?>"><?= e($label) ?></span>
                <span class="leaf-card-type"><?= e($typeLabel) ?></span>
                <?php if ($minutes > 0): ?>
                    <span class="leaf-card-time">~<?= e(fa((string) $minutes)) ?> دقیقه</span>
                <?php endif; ?>
            </div>

            <div class="leaf-card-actions">
                <a class="btn btn-primary btn-sm leaf-card-study" href="/content/<?= e($item['uuid']) ?>">
                    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'book', 'size' => 16]); ?>
                    <span>مطالعه</span>
                </a>
                <?php if (!empty($offlineEnabled)): ?>
                    <button class="btn btn-ghost btn-sm offline-btn"
                            type="button"
                            data-offline-save="<?= e($item['uuid']) ?>"
                            data-version="<?= e($item['checksum'] ?? '') ?>"
                            data-offline-allowed="<?= (int) ($item['offline_enabled'] ?? 1) === 1 ? '1' : '0' ?>"
                            data-needs-network>
                        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'download', 'size' => 16]); ?>
                        <span>ذخیره آفلاین</span>
                    </button>
                <?php endif; ?>
            </div>
        </article>
    <?php }
};

$renderSection = function (array $section) use (&$renderSection, $children, $bySection, $renderContents): void {
    $id    = (int) $section['id'];
    $items = $bySection[(string) $id] ?? [];
    $subs  = $children[$id] ?? [];
    ?>
    <details class="tree-node" open>
        <summary class="tree-head">
            <span class="tree-head-icon" aria-hidden="true">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'folder']); ?>
            </span>
            <span class="tree-head-title"><?= e($section['title']) ?></span>
            <span class="tree-head-count"><?= e(fa((string) count($items))) ?></span>
        </summary>
        <div class="tree-body">
            <?php $renderContents($items); ?>
            <?php foreach ($subs as $child) { $renderSection($child); } ?>
            <?php if ($items === [] && $subs === []): ?>
                <div class="empty">این پوشه هنوز خالی است.</div>
            <?php endif; ?>
        </div>
    </details>
<?php };
?>

<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;"><?= e($course['title']) ?></h3>
        <a class="btn btn-ghost btn-sm" href="/student/courses">بازگشت</a>
    </div>

    <div class="tree">
        <?php $renderContents($bySection['root'] ?? []); ?>
        <?php foreach ($children[0] ?? [] as $section) { $renderSection($section); } ?>
        <?php if (($children[0] ?? []) === [] && ($bySection['root'] ?? []) === []): ?>
            <div class="empty">هنوز محتوایی برای این دوره منتشر نشده است.</div>
        <?php endif; ?>
    </div>
</div>
