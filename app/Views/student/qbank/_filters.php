<?php
/**
 * The filter form shared by the player and the list, so both read and write
 * the same query string and a link between them keeps the set.
 *
 * @var string $action
 * @var array  $filters
 * @var array  $filing
 * @var array  $allTags
 * @var array  $difficulties
 * @var bool   $open
 */
$subs   = array_filter($filing, static fn (array $r): bool => (int) $r['depth'] === 2);
$topics = array_filter($filing, static fn (array $r): bool => (int) $r['depth'] === 3);
$modes  = [
    ''         => 'همه سوال‌ها',
    'new'      => 'پاسخ‌نداده',
    'answered' => 'پاسخ‌داده',
    'correct'  => 'درست جواب داده',
    'wrong'    => 'غلط جواب داده',
    'saved'    => '⭐ نشان‌شده',
    'review'   => '🔁 نیاز به مرور',
];
?>
<details class="qb-section" <?= !empty($open) ? 'open' : '' ?>>
    <summary style="cursor:pointer; font-weight:600;">جستجو و فیلتر</summary>
    <form method="get" action="<?= e($action) ?>" class="qb-filters" style="margin-top:12px;">
        <div class="field" style="flex:1 1 220px;">
            <label class="label" for="pf-q">جستجو در متن سوال</label>
            <input class="input" id="pf-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="مثلاً انفارکتوس">
        </div>
        <div class="field" style="flex:0 1 170px;">
            <label class="label" for="pf-mode">وضعیت</label>
            <select class="input" id="pf-mode" name="mode">
                <?php foreach ($modes as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $filters['mode'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php /* زیردرس → عنوان: picking a زیردرس leaves only its own عنوان‌ها. */ ?>
        <div class="qb-cascade" data-tree-cascade style="display:contents;">
        <?php if ($subs !== []): ?>
            <div class="field" style="flex:1 1 160px;">
                <label class="label" for="pf-sub">زیردرس</label>
                <select class="input" id="pf-sub" name="sub" data-level="2">
                    <option value="">همه</option>
                    <?php foreach ($subs as $row): ?>
                        <option value="<?= (int) $row['id'] ?>" <?= (int) $filters['sub_subject_id'] === (int) $row['id'] ? 'selected' : '' ?>><?= e($row['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if ($topics !== []): ?>
            <div class="field" style="flex:1 1 160px;">
                <label class="label" for="pf-topic">عنوان</label>
                <select class="input" id="pf-topic" name="topic" data-level="3">
                    <option value="">همه</option>
                    <?php foreach ($topics as $row): ?>
                        <option value="<?= (int) $row['id'] ?>" data-parent="<?= (int) $row['parent_id'] ?>"
                            <?= (int) $filters['topic_id'] === (int) $row['id'] ? 'selected' : '' ?>><?= e($row['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        </div>
        <div class="field" style="flex:0 1 120px;">
            <label class="label" for="pf-diff">سختی</label>
            <select class="input" id="pf-diff" name="difficulty">
                <option value="">همه</option>
                <?php foreach ($difficulties as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $filters['difficulty'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($allTags !== []): ?>
            <div class="field" style="flex:0 1 130px;">
                <label class="label" for="pf-tag">برچسب</label>
                <select class="input" id="pf-tag" name="tag">
                    <option value="">همه</option>
                    <?php foreach ($allTags as $tag): ?>
                        <option value="<?= (int) $tag['id'] ?>" <?= (int) $filters['tag_id'] === (int) $tag['id'] ? 'selected' : '' ?>><?= e($tag['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="row-actions" style="margin:0;">
            <button class="btn btn-primary btn-sm" type="submit">اعمال</button>
            <a class="btn btn-ghost btn-sm" href="<?= e($action) ?>">پاک کردن</a>
        </div>
    </form>
</details>
