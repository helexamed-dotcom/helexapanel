<?php
/**
 * The shared-tag picker: tick existing tags, filter them by typing, or type
 * new ones (created on save). Read back with SharedTags::fromRequest().
 *
 * @var array       $allTags
 * @var list<int>   $selected
 * @var string|null $name     field name (default "tags")
 * @var string|null $hint
 */
$name = $name ?? 'tags';
$selected = array_map('intval', $selected ?? []);
$hint = $hint ?? 'همان برچسب‌های بانک سوال، درسنامه، فلش‌کارت، بازی با شکل و بالین — برای تحلیل نقاط قوت و ضعف.';
?>
<div class="tp" data-tag-picker>
    <div class="tp-head">
        <b>برچسب‌ها</b>
        <input class="tp-filter" type="search" placeholder="جستجوی برچسب…" data-tp-filter aria-label="جستجوی برچسب">
    </div>
    <div class="tp-list">
        <?php foreach ($allTags as $t): $on = in_array((int) $t['id'], $selected, true); ?>
            <label class="tp-tag<?= $on ? ' is-on' : '' ?>" data-title="<?= e(mb_strtolower($t['title'])) ?>">
                <input type="checkbox" name="<?= e($name) ?>[]" value="<?= (int) $t['id'] ?>" <?= $on ? 'checked' : '' ?>>
                <span class="stat-chip <?= e($t['color'] ?: 'chip-gray') ?>">#<?= e($t['title']) ?></span>
            </label>
        <?php endforeach; ?>
        <?php if ($allTags === []): ?><small class="hx-muted">هنوز برچسبی نیست؛ پایین بنویسید تا ساخته شود.</small><?php endif; ?>
    </div>
    <label class="tp-new">
        <span>+ برچسب تازه</span>
        <input class="input" name="new_<?= e($name) ?>" maxlength="400" placeholder="مثلاً: کلیات باکتری‌شناسی، کوکسی‌ها" autocomplete="off">
    </label>
    <small class="tp-hint"><?= e($hint) ?></small>
</div>
