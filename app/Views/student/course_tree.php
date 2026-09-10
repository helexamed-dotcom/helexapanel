<?php
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

$renderContents = function (array $list) use ($statuses, $statusMeta, $offlineEnabled, $csrf_token): void {
    foreach ($list as $item) {
        $status = $statuses[(int) $item['id']]['status'] ?? 'unread';
        [$chip, $label] = $statusMeta[$status] ?? $statusMeta['unread']; ?>
        <div class="tree-leaf">
            <a class="leaf-link-inline" href="/content/<?= e($item['uuid']) ?>">
                <span class="leaf-icon">📄</span>
                <span style="min-width:0;">
                    <span class="leaf-title"><?= e($item['title']) ?></span>
                    <?php if ($item['description']): ?>
                        <span class="leaf-meta"><?= e($item['description']) ?></span>
                    <?php endif; ?>
                </span>
            </a>
            <span class="stat-chip <?= e($chip) ?>"><?= e($label) ?></span>
            <?php if (!empty($offlineEnabled)): ?>
                <button class="btn btn-sm offline-btn btn-ghost"
                        type="button"
                        data-offline-save="<?= e($item['uuid']) ?>"
                        data-version="<?= e($item['checksum'] ?? '') ?>"
                        data-offline-allowed="<?= (int) ($item['offline_enabled'] ?? 1) === 1 ? '1' : '0' ?>"
                        data-needs-network>ذخیره برای مطالعه آفلاین</button>
            <?php endif; ?>
        </div>
    <?php }
};

$renderSection = function (array $section) use (&$renderSection, $children, $bySection, $renderContents): void {
    $id = (int) $section['id']; ?>
    <div class="tree-node">
        <div class="tree-head">
            <span class="leaf-icon">📁</span>
            <div class="leaf-title" style="flex:1;"><?= e($section['title']) ?></div>
        </div>
        <div class="tree-body">
            <?php $renderContents($bySection[(string) $id] ?? []); ?>
            <?php foreach ($children[$id] ?? [] as $child) { $renderSection($child); } ?>
        </div>
    </div>
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
