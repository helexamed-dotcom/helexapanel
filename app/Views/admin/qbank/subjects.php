<?php
/**
 * The درس ← زیردرس ← عنوان tree.
 *
 * Every node opens to show its own edit form and a form to add a child under
 * it, so the admin builds the tree where they are looking at it rather than
 * choosing a parent from a dropdown somewhere else.
 *
 * @var array $tree        flat, in depth-first order
 * @var array $depthLabels
 * @var int   $maxDepth
 */
$canEdit  = can('qbank.manage_taxonomy');
$children = [];
foreach ($tree as $row) {
    $children[(int) ($row['parent_id'] ?? 0)][] = $row;
}
$icon = static fn (string $n) => \HeleXa\Core\View::partial('partials.icon', ['name' => $n]);

$renderNode = function (array $node) use (&$renderNode, $children, $depthLabels, $maxDepth, $canEdit, $csrf_token): void {
    $depth    = (int) $node['depth'];
    $kids     = $children[(int) $node['id']] ?? [];
    $isOff    = (int) $node['is_active'] !== 1;
    $childLbl = $depthLabels[$depth + 1] ?? '';
    ?>
    <details class="qb-node depth-<?= $depth ?><?= $isOff ? ' is-off' : '' ?><?= $kids === [] && $depth >= $maxDepth ? ' is-leaf' : '' ?>">
        <summary>
            <span class="qb-level"><?= e($depthLabels[$depth] ?? '') ?></span>
            <span class="qb-node-title"><?= e($node['title']) ?></span>
            <?php if ($kids !== []): ?>
                <span class="qb-count"><?= e(fa((string) count($kids))) ?> <?= e($childLbl) ?></span>
            <?php endif; ?>
            <a class="qb-count" href="/admin/qbank/questions?subject_id=<?= (int) $node['id'] ?>"><?= e(fa((string) $node['question_count'])) ?> سوال</a>
            <a class="qb-count qb-lesson-chip<?= trim((string) ($node['lesson_note'] ?? '')) !== '' ? ' has-note' : '' ?>"
               href="/admin/qbank/subjects/<?= e($node['uuid']) ?>/lesson">
                📘 <?= trim((string) ($node['lesson_note'] ?? '')) !== '' ? 'درسنامه دارد' : 'درسنامه' ?>
            </a>
        </summary>

        <div class="qb-node-body">
            <?php if (!empty($node['description'])): ?>
                <p class="qb-hint"><?= e($node['description']) ?></p>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <form method="post" action="/admin/qbank/subjects/<?= e($node['uuid']) ?>" class="qb-inline-form">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <div class="field">
                        <label class="label">عنوان</label>
                        <input class="input" name="title" value="<?= e($node['title']) ?>" maxlength="191" required>
                    </div>
                    <div class="field">
                        <label class="label">توضیح</label>
                        <input class="input" name="description" value="<?= e($node['description'] ?? '') ?>" maxlength="255">
                    </div>
                    <div class="field narrow">
                        <label class="label">ترتیب</label>
                        <input class="input" type="number" name="sort_order" value="<?= (int) $node['sort_order'] ?>" dir="ltr">
                    </div>
                    <label class="remember-row" style="margin:0 0 10px;">
                        <input type="checkbox" name="is_active" value="1" <?= $isOff ? '' : 'checked' ?>>
                        <span>فعال</span>
                    </label>
                    <button class="btn btn-ghost btn-sm" type="submit">ذخیره</button>
                </form>
                <form method="post" action="/admin/qbank/subjects/<?= e($node['uuid']) ?>/delete"
                      data-confirm="«<?= e($node['title']) ?>» و همه زیرمجموعه‌هایش حذف شوند؟ سوال‌ها حذف نمی‌شوند و بدون طبقه‌بندی می‌مانند."
                      style="margin:0;">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                </form>
            <?php endif; ?>

            <?php foreach ($kids as $kid) { $renderNode($kid); } ?>

            <?php if ($canEdit && $depth < $maxDepth): ?>
                <form method="post" action="/admin/qbank/subjects" class="qb-inline-form"
                      style="border-top:1px dashed var(--line); padding-top:10px;">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="parent" value="<?= e($node['uuid']) ?>">
                    <div class="field">
                        <label class="label">افزودن <?= e($childLbl) ?> زیر «<?= e($node['title']) ?>»</label>
                        <input class="input" name="title" maxlength="191" required
                               placeholder="<?= $depth === 1 ? 'مثلاً آناتومی سر و گردن' : 'مثلاً عضلات صورت' ?>">
                    </div>
                    <div class="field narrow">
                        <label class="label">ترتیب</label>
                        <input class="input" type="number" name="sort_order" value="0" dir="ltr">
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">+ افزودن</button>
                </form>
            <?php endif; ?>
        </div>
    </details>
    <?php
};
?>
<div class="qb-page">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon"><?php $icon('layers'); ?></div>
            <div>
                <h2>دروس و زیردروس</h2>
                <p>سه سطح: <strong>درس</strong> (مثل آناتومی) ← <strong>زیردرس</strong> (مثل آناتومی اعصاب) ← <strong>عنوان</strong>.
                   هر سطح را باز کن تا ویرایشش کنی یا زیرمجموعه اضافه کنی.</p>
            </div>
        </div>
    </section>

    <?php if ($canEdit): ?>
        <section class="qb-section">
            <div class="qb-section-head"><h3><span class="qb-step">+</span> درس جدید</h3></div>
            <form method="post" action="/admin/qbank/subjects" class="qb-inline-form">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <div class="field">
                    <label class="label" for="new-subject">عنوان درس</label>
                    <input class="input" id="new-subject" name="title" maxlength="191" required placeholder="مثلاً آناتومی، فیزیولوژی، بیوشیمی">
                </div>
                <div class="field">
                    <label class="label" for="new-subject-desc">توضیح (اختیاری)</label>
                    <input class="input" id="new-subject-desc" name="description" maxlength="255">
                </div>
                <div class="field narrow">
                    <label class="label" for="new-subject-order">ترتیب</label>
                    <input class="input" id="new-subject-order" type="number" name="sort_order" value="0" dir="ltr">
                </div>
                <button class="btn btn-primary" type="submit" data-lock-on-submit>افزودن درس</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="qb-section">
        <div class="qb-section-head"><h3><?php $icon('list'); ?> ساختار فعلی</h3></div>
        <?php if (($children[0] ?? []) === []): ?>
            <div class="empty">هنوز درسی ساخته نشده است.</div>
        <?php else: ?>
            <div class="qb-tree">
                <?php foreach ($children[0] as $root) { $renderNode($root); } ?>
            </div>
        <?php endif; ?>
    </section>
</div>
