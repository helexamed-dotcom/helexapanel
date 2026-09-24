<?php
/**
 * The calendar hub's tab strip.
 *
 * @var array  $tabs  key → label
 * @var string $tab   the open one
 */
$tabIcons = ['month' => '📅', 'classes' => '🎓', 'finals' => '📝', 'midterms' => '⏱'];
?>
<nav class="seg-tabs" aria-label="بخش‌های تقویم">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="seg-tab<?= $tab === $key ? ' is-active' : '' ?>" href="/student/calendar<?= $key === 'month' ? '' : '?tab=' . e($key) ?>"
           <?= $tab === $key ? 'aria-current="page"' : '' ?>>
            <span aria-hidden="true"><?= $tabIcons[$key] ?? '' ?></span> <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</nav>