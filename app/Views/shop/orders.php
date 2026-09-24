<?php
/**
 * The student's orders.
 *
 * @var array $theme
 * @var array $orders
 */
use HeleXa\Core\View;
use HeleXa\Models\ShopRepository;
use HeleXa\Services\Shop\Shop;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
?>
<div class="sh tone-<?= e($theme['tone']) ?>">
    <a class="sh-back" href="/shop"><?php $icon('chevron', 16); ?> <?= e($theme['title']) ?></a>
    <h2 class="sh-h"><?php $icon('receipt', 20); ?> سفارش‌های من</h2>

    <?php if ($orders === []): ?>
        <div class="sh-empty">
            <span class="app-ic tone-slate"><?php $icon('receipt'); ?></span>
            <b>هنوز سفارشی نداری</b>
            <a class="btn btn-primary" href="/shop">رفتن به فروشگاه</a>
        </div>
    <?php else: ?>
        <div class="sh-orders">
            <?php foreach ($orders as $i => $o): [$label, $tone] = ShopRepository::STATUS[$o['status']]; ?>
                <a class="sh-order hx-zoom" href="/shop/orders/<?= e($o['uuid']) ?>" style="--i: <?= min($i, 12) ?>">
                    <span class="app-ic tone-<?= e($tone) ?>"><?php $icon($o['status'] === 'paid' ? 'check' : ($o['status'] === 'review' ? 'hourglass' : 'receipt'), 20); ?></span>
                    <span class="sh-order-body">
                        <b>سفارش <?= e(fa((string) $o['number'])) ?></b>
                        <small><?= e((string) $o['titles']) ?></small>
                        <small class="hx-muted"><?= e(jdate($o['created_at'])) ?></small>
                    </span>
                    <span class="sh-order-end">
                        <em class="sh-status tone-<?= e($tone) ?>"><?= e($label) ?></em>
                        <strong><?= e(Shop::money((int) $o['total'])) ?></strong>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
