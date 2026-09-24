<?php
/**
 * 🔔 for an admin: what is waiting, one row per queue.
 *
 * @var list<array{label:string,count:int,href:string,icon:string,tone:string}> $inbox
 */
$icon  = static fn (string $n) => \HeleXa\Core\View::partial('partials.icon', ['name' => $n]);
$total = array_sum(array_column($inbox, 'count'));
?>
<div class="hub">
    <div class="hub-title">
        <b>کارهای در انتظار</b>
        <small><?= $total > 0 ? e(fa((string) $total)) . ' مورد نیاز به بررسی دارد' : 'همه چیز مرتب است ✨' ?></small>
    </div>
    <ul class="hub-list">
        <?php foreach ($inbox as $row): ?>
            <li class="hub-row<?= $row['count'] > 0 ? ' is-unread' : '' ?>">
                <span class="hub-row-ic tone-<?= e($row['tone']) ?>"><?php $icon($row['icon']); ?></span>
                <a class="hub-row-text" href="<?= e($row['href']) ?>">
                    <b><?= e($row['label']) ?></b>
                    <small><?= $row['count'] > 0 ? e(fa((string) $row['count'])) . ' مورد' : 'موردی نیست' ?></small>
                </a>
                <?php if ($row['count'] > 0): ?><span class="hub-count"><?= e(fa((string) min(99, $row['count']))) ?></span><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
