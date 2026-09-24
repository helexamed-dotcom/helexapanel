<?php
/**
 * The store at a glance.
 *
 * @var array $sales   today / month / month_orders / all_time
 * @var array $daily   Y-m-d => paid total
 * @var array $counts  status => orders
 * @var array $review  orders waiting for a receipt check
 * @var array $recent
 * @var array $top
 * @var int   $products
 * @var bool  $gateway
 * @var bool  $card
 */
use HeleXa\Core\View;
use HeleXa\Models\ShopRepository;
use HeleXa\Services\Shop\Shop;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$max = max(1, max($daily));
?>
<div class="ad-page sa">
    <section class="ad-hero tone-orange">
        <div>
            <h2>فروشگاه</h2>
            <p>فروش امروز <b><?= e(Shop::money((int) $sales['today'])) ?></b> · ۳۰ روز اخیر <b><?= e(Shop::money((int) $sales['month'])) ?></b> در <?= e(fa((string) $sales['month_orders'])) ?> سفارش</p>
        </div>
        <div class="ad-quick">
            <a class="ad-quick-btn" href="/admin/shop/products/create"><?php $icon('plus', 16); ?> محصول تازه</a>
            <a class="ad-quick-btn" href="/admin/shop/coupons"><?php $icon('percent', 16); ?> کد تخفیف</a>
            <a class="ad-quick-btn" href="/admin/shop/settings?tab=look"><?php $icon('palette', 16); ?> ظاهر</a>
            <a class="ad-quick-btn" href="/shop" target="_blank"><?php $icon('eye', 16); ?> دیدن فروشگاه</a>
        </div>
    </section>

    <?php if (!$gateway && !$card): ?>
        <a class="sa-alert" href="/admin/shop/settings">
            <span class="app-ic tone-red"><?php $icon('creditcard', 18); ?></span>
            <span><b>هنوز هیچ روش پرداختی فعال نیست.</b><small>درگاه زرین‌پال یا کارت به کارت را فعال کنید تا دانشجوها بتوانند خرید کنند.</small></span>
            <?php $icon('chevron', 16); ?>
        </a>
    <?php endif; ?>

    <div class="ad-stats">
        <a class="ad-stat" href="/admin/shop/orders?status=review">
            <span class="app-ic tone-orange"><?php $icon('hourglass', 20); ?></span>
            <span><b><?= e(fa((string) $counts['review'])) ?></b><small>رسید در انتظار بررسی</small></span>
        </a>
        <a class="ad-stat" href="/admin/shop/orders?status=paid">
            <span class="app-ic tone-green"><?php $icon('check', 20); ?></span>
            <span><b><?= e(fa((string) $counts['paid'])) ?></b><small>سفارش پرداخت‌شده</small></span>
        </a>
        <a class="ad-stat" href="/admin/shop/orders?status=pending">
            <span class="app-ic tone-amber"><?php $icon('receipt', 20); ?></span>
            <span><b><?= e(fa((string) $counts['pending'])) ?></b><small>در انتظار پرداخت</small></span>
        </a>
        <a class="ad-stat" href="/admin/shop/products">
            <span class="app-ic tone-violet"><?php $icon('bag', 20); ?></span>
            <span><b><?= e(fa((string) $products)) ?></b><small>محصول</small></span>
        </a>
        <div class="ad-stat">
            <span class="app-ic tone-blue"><?php $icon('chart', 20); ?></span>
            <span><b class="sa-money"><?= e(Shop::money((int) $sales['all_time'])) ?></b><small>کل فروش</small></span>
        </div>
    </div>

    <div class="ad-two">
        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-blue"><?php $icon('chart'); ?></span>
                <div><h3>فروش ۱۴ روز اخیر</h3><p>مجموع سفارش‌های پرداخت‌شده در هر روز.</p></div>
            </header>
            <div class="sa-chart" role="img" aria-label="نمودار فروش روزانه">
                <?php foreach ($daily as $d => $t): ?>
                    <span class="sa-bar<?= $d === date('Y-m-d') ? ' is-today' : '' ?>" style="--h: <?= round($t * 100 / $max, 1) ?>%" title="<?= e(\HeleXa\Services\Jalali::date((int) strtotime($d))) ?>: <?= e(Shop::money($t)) ?>">
                        <i></i><small><?= e(fa(\HeleXa\Services\Jalali::fromGregorian((int) substr($d, 0, 4), (int) substr($d, 5, 2), (int) substr($d, 8, 2))[2] . '')) ?></small>
                    </span>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-orange"><?php $icon('hourglass'); ?></span>
                <div><h3>رسیدهای منتظر</h3><p>کارت به کارت‌هایی که باید تأیید یا رد شوند.</p></div>
            </header>
            <?php if ($review === []): ?>
                <div class="ad-empty">همه رسیدها بررسی شده‌اند ✓</div>
            <?php else: ?>
                <ul class="ad-list">
                    <?php foreach ($review as $o): ?>
                        <li><a class="ad-row" href="/admin/shop/orders/<?= e($o['uuid']) ?>">
                            <span class="app-ic tone-orange"><?php $icon('receipt', 18); ?></span>
                            <span class="ad-row-main"><b><?= e($o['full_name']) ?></b><small><?= e((string) $o['titles']) ?> · <?= e(jdate($o['receipt_at'] ?? $o['created_at'])) ?></small></span>
                            <span class="ad-pill is-on"><?= e(Shop::money((int) $o['total'])) ?></span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="ad-two">
        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-slate"><?php $icon('receipt'); ?></span>
                <div><h3>آخرین سفارش‌ها</h3></div>
                <a class="hx-link" href="/admin/shop/orders">همه</a>
            </header>
            <?php if ($recent === []): ?>
                <div class="ad-empty">هنوز سفارشی ثبت نشده است.</div>
            <?php else: ?>
                <ul class="ad-list">
                    <?php foreach ($recent as $o): [$label, $tone] = ShopRepository::STATUS[$o['status']]; ?>
                        <li><a class="ad-row" href="/admin/shop/orders/<?= e($o['uuid']) ?>">
                            <span class="sa-num"><?= e(fa((string) $o['number'])) ?></span>
                            <span class="ad-row-main"><b><?= e($o['full_name']) ?></b><small><?= e((string) $o['titles']) ?></small></span>
                            <span class="sa-status tone-<?= e($tone) ?>"><?= e($label) ?></span>
                            <b class="sa-amt"><?= e(fa(number_format((int) $o['total']))) ?></b>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-amber"><?php $icon('trophy'); ?></span>
                <div><h3>پرفروش‌ها</h3></div>
                <a class="hx-link" href="/admin/shop/products">محصولات</a>
            </header>
            <?php if ($top === []): ?>
                <div class="ad-empty">وقتی اولین فروش ثبت شود، این‌جا می‌آید.</div>
            <?php else: ?>
                <ul class="ad-list">
                    <?php foreach ($top as $i => $p): ?>
                        <li><a class="ad-row" href="/admin/shop/products/<?= e($p['uuid']) ?>/edit">
                            <span class="sa-rank"><?= e(fa((string) ($i + 1))) ?></span>
                            <span class="ad-row-main"><b><?= e($p['title']) ?></b><small><?= e(Shop::money((int) $p['price'])) ?></small></span>
                            <span class="ad-pill"><?= e(fa((string) $p['sold_count'])) ?> فروش</span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
