<?php
/**
 * Tree builder. Sections nest up to four levels; contents are the leaves.
 * Ordering uses plain POST buttons so the page works without JavaScript.
 */
$children = [];
foreach ($sections as $section) {
    $children[$section['parent_id'] === null ? 0 : (int) $section['parent_id']][] = $section;
}

$typeLabels = [
    'folder' => 'پوشه', 'full_notes' => 'جزوه کامل', 'summary_notes' => 'خلاصه',
    'question_bank' => 'بانک تست', 'chat_learn' => 'Chat Learn', 'mind_map' => 'Mind Map', 'custom' => 'سفارشی',
];
$statusChip = ['published' => 'chip-green', 'draft' => 'chip-gray', 'hidden' => 'chip-red'];

$renderContents = function (array $list) use ($course, $csrf_token, $typeLabels, $statusChip): void {
    foreach ($list as $item) { ?>
        <div class="tree-leaf">
            <span class="leaf-icon">📄</span>
            <div style="min-width:0; flex:1;">
                <div class="leaf-title"><?= e($item['title']) ?></div>
                <div class="leaf-meta">
                    <?= e($typeLabels[$item['content_type']] ?? $item['content_type']) ?>
                    · <?= e(fa(number_format(((int) $item['byte_size']) / 1024, 0))) ?> کیلوبایت
                    <?php if ($item['original_filename']): ?> · <span class="mono"><?= e($item['original_filename']) ?></span><?php endif; ?>
                </div>
            </div>
            <span class="stat-chip <?= e($statusChip[$item['status']] ?? 'chip-gray') ?>"><?= e($item['status']) ?></span>
            <div class="row-actions">
                <a class="btn btn-ghost btn-sm" href="/content/<?= e($item['uuid']) ?>" target="_blank" rel="noopener">پیش‌نمایش</a>
                <a class="btn btn-ghost btn-sm" href="/admin/courses/<?= e($course['uuid']) ?>/contents/<?= e($item['uuid']) ?>/edit">ویرایش</a>
                <form method="post" action="/admin/courses/<?= e($course['uuid']) ?>/contents/<?= e($item['uuid']) ?>/move">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="direction" value="up">
                    <button class="btn btn-ghost btn-sm" type="submit" title="بالا">↑</button>
                </form>
                <form method="post" action="/admin/courses/<?= e($course['uuid']) ?>/contents/<?= e($item['uuid']) ?>/move">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="direction" value="down">
                    <button class="btn btn-ghost btn-sm" type="submit" title="پایین">↓</button>
                </form>
                <form method="post" action="/admin/courses/<?= e($course['uuid']) ?>/contents/<?= e($item['uuid']) ?>/delete"
                      data-confirm="این محتوا حذف شود؟">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                </form>
            </div>
        </div>
    <?php }
};

$renderSection = function (array $section) use (
    &$renderSection, $children, $bySection, $course, $csrf_token, $typeLabels, $statusChip, $renderContents
): void {
    $id = (int) $section['id']; ?>
    <div class="tree-node">
        <div class="tree-head">
            <span class="leaf-icon">📁</span>
            <div style="min-width:0; flex:1;">
                <div class="leaf-title"><?= e($section['title']) ?></div>
                <div class="leaf-meta"><?= e($typeLabels[$section['section_type']] ?? $section['section_type']) ?></div>
            </div>
            <span class="stat-chip <?= e($statusChip[$section['status']] ?? 'chip-gray') ?>"><?= e($section['status']) ?></span>
            <div class="row-actions">
                <a class="btn btn-ghost btn-sm" href="/admin/courses/<?= e($course['uuid']) ?>/contents/create?section_id=<?= $id ?>">+ محتوا</a>
                <form method="post" action="/admin/courses/<?= e($course['uuid']) ?>/sections/<?= $id ?>/move">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="direction" value="up">
                    <button class="btn btn-ghost btn-sm" type="submit">↑</button>
                </form>
                <form method="post" action="/admin/courses/<?= e($course['uuid']) ?>/sections/<?= $id ?>/move">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="direction" value="down">
                    <button class="btn btn-ghost btn-sm" type="submit">↓</button>
                </form>
                <form method="post" action="/admin/courses/<?= e($course['uuid']) ?>/sections/<?= $id ?>/delete"
                      data-confirm="این بخش و همه زیرمجموعه‌ها و محتواهای داخلش حذف شوند؟">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                </form>
            </div>
        </div>

        <div class="tree-body">
            <?php $renderContents($bySection[(string) $id] ?? []); ?>
            <?php foreach ($children[$id] ?? [] as $child) { $renderSection($child); } ?>

            <details class="inline-add">
                <summary>+ زیربخش در «<?= e($section['title']) ?>»</summary>
                <form method="post" action="/admin/courses/<?= e($course['uuid']) ?>/sections" class="filters" style="margin-top:10px;">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="parent_id" value="<?= $id ?>">
                    <input class="input" name="title" placeholder="عنوان زیربخش" required>
                    <select class="input" name="section_type">
                        <?php foreach ($typeLabels as $key => $label): ?>
                            <option value="<?= e($key) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary btn-sm" type="submit">افزودن</button>
                </form>
            </details>
        </div>
    </div>
<?php };
?>

<div class="card">
    <div class="card-head">
        <div>
            <h3 class="card-title" style="margin:0;">ساختار «<?= e($course['title']) ?>»</h3>
            <div style="color:var(--ink-3); font-size:12.5px;">حداکثر حجم آپلود سرور: <span class="mono"><?= e($maxUpload) ?></span></div>
        </div>
        <div class="row-actions">
            <a class="btn btn-primary btn-sm" href="/admin/courses/<?= e($course['uuid']) ?>/contents/create">+ محتوا در ریشه</a>
            <a class="btn btn-ghost btn-sm" href="/admin/courses">بازگشت</a>
        </div>
    </div>

    <details class="inline-add" open>
        <summary>+ بخش اصلی جدید</summary>
        <form method="post" action="/admin/courses/<?= e($course['uuid']) ?>/sections" class="filters" style="margin-top:10px;">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <input class="input" name="title" placeholder="مثلاً جزوه کامل درس" required>
            <select class="input" name="section_type">
                <?php foreach ($typeLabels as $key => $label): ?>
                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm" type="submit">افزودن بخش</button>
        </form>
    </details>

    <div class="tree" style="margin-top:16px;">
        <?php $renderContents($bySection['root'] ?? []); ?>
        <?php foreach ($children[0] ?? [] as $section) { $renderSection($section); } ?>
        <?php if (($children[0] ?? []) === [] && ($bySection['root'] ?? []) === []): ?>
            <div class="empty">هنوز بخشی یا محتوایی اضافه نشده است.</div>
        <?php endif; ?>
    </div>
</div>
