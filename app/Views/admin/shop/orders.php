<?php
/**
 * @var array $rows
 * @var array $filters
 * @var array $counts
 */
use HeleXa\Core\View;
use HeleXa\Models\ShopRepository;
use HeleXa\Services\Shop\Shop;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$method = ['gateway' => 'آنلاین', 'card' => 'کارت به کارت', 'free' => 'رایگان'];
?>
<div class="ad-page sa">
    <section class="ad-card">
        <header class="ad-card-head">
            <span class="app-ic tone-orange"><?php $icon('receipt'); ?></span>
            <div><h3>سفارش‌ها</h3><p>رسیدهای کارت به کارت را از این‌جا تأیید کنید؛ با تأیید، پکیج‌ها خودکار برای دانشجو فعال می‌شود.</p></div>
        </header>

        <nav class="ad-tabs">
            <a class="ad-tab<?= $filters['status'] === '' ? ' is-on' : '' ?>" href="/admin/shop/orders">همه <small><?= e(fa((string) array_sum($counts))) ?></small></a>
            <?php foreach (ShopRepository::STATUS as $k => [$label, $tone]): ?>
                <a class="ad-tab<?= $filters['status'] === $k ? ' is-on' : '' ?>" href="/admin/shop/orders?status=<?= e($k) ?>"><?= e($label) ?> <small><?= e(fa((string) $counts[$k])) ?></small></a>
            <?php endforeach; ?>
        </nav>
        <form class="sa-filter" method="get" action="/admin/shop/orders">
            <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
            <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="شماره سفارش، نام، نام کاربری یا موبایل…">
            <button class="btn btn-ghost" type="submit"><?php $icon('search', 16); ?> جستجو</button>
        </form>

        <?php if ($rows === []): ?>
            <div class="ad-empty">سفارشی پیدا نشد.</div>
        <?php else: ?>
            <div class="ad-scroll">
                <table class="ad-table sa-orders">
                    <thead><tr><th>سفارش</th><th>دانشجو</th><th>اقلام</th><th>مبلغ</th><th>روش</th><th>وضعیت</th><th>زمان</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $o): [$label, $tone] = ShopRepository::STATUS[$o['status']]; ?>
                        <tr class="<?= $o['status'] === 'review' ? 'is-hot' : '' ?>">
                            <td><b class="sa-num"><?= e(fa((string) $o['number'])) ?></b></td>
                            <td><b><?= e($o['full_name']) ?></b><small class="hx-muted" dir="ltr"><?= e((string) ($o['mobile'] ?: $o['username'])) ?></small></td>
                            <td class="sa-titles"><?= e((string) $o['titles']) ?></td>
                            <td><b><?= e(fa(number_format((int) $o['total']))) ?></b><?php if ((int) $o['discount'] > 0): ?><small class="hx-muted">کد <?= e((string) $o['coupon_code']) ?></small><?php endif; ?></td>
                            <td><?= e($method[$o['method']]) ?></td>
                            <td><span class="sa-status tone-<?= e($tone) ?>"><?= e($label) ?></span></td>
                            <td><small><?= e(jdate($o['created_at'])) ?></small></td>
                            <td><a class="btn btn-ghost btn-sm" href="/admin/shop/orders/<?= e($o['uuid']) ?>"><?= $o['status'] === 'review' ? 'بررسی رسید' : 'جزئیات' ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
