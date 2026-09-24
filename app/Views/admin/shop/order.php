<?php
/**
 * One order, with its receipt big enough to read and the decision beside it.
 *
 * @var array $order
 * @var array $items
 * @var array $history  the student's other orders
 */
use HeleXa\Core\View;
use HeleXa\Models\ShopRepository;
use HeleXa\Services\Shop\Shop;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
[$label, $tone] = ShopRepository::STATUS[$order['status']];
$u = $order['user'];
$open = in_array($order['status'], ['pending', 'review', 'rejected'], true);
$method = ['gateway' => 'پرداخت آنلاین', 'card' => 'کارت به کارت', 'free' => 'رایگان'];
?>
<div class="ad-page sa">
    <a class="sa-back" href="/admin/shop/orders"><?php $icon('chevron', 16); ?> سفارش‌ها</a>

    <section class="sa-order-head tone-<?= e($tone) ?>">
        <span class="app-ic tone-<?= e($tone) ?>"><?php $icon($order['status'] === 'paid' ? 'check' : 'receipt', 24); ?></span>
        <div>
            <small>سفارش <?= e(fa((string) $order['number'])) ?> · <?= e(jdate($order['created_at'])) ?> · <?= e($method[$order['method']]) ?></small>
            <h2><?= e($label) ?></h2>
        </div>
        <strong><?= e(Shop::money((int) $order['total'])) ?></strong>
    </section>

    <div class="sa-order">
        <div class="sa-order-main">
            <?php if ($order['receipt_path'] || $order['receipt_ref']): ?>
                <section class="ad-card">
                    <header class="ad-card-head">
                        <span class="app-ic tone-teal"><?php $icon('receipt'); ?></span>
                        <div><h3>رسید کارت به کارت</h3><p>ارسال‌شده در <?= e(jdate($order['receipt_at'])) ?></p></div>
                    </header>
                    <?php if ($order['receipt_path']): ?>
                        <a class="sa-receipt" href="/media/receipts/<?= e($order['receipt_path']) ?>" target="_blank"><img src="/media/receipts/<?= e($order['receipt_path']) ?>" alt="رسید"></a>
                    <?php endif; ?>
                    <dl class="sa-dl">
                        <?php if ($order['receipt_ref']): ?><div><dt>شماره پیگیری</dt><dd dir="ltr"><?= e($order['receipt_ref']) ?></dd></div><?php endif; ?>
                        <?php if ($order['receipt_note']): ?><div><dt>توضیح دانشجو</dt><dd><?= e($order['receipt_note']) ?></dd></div><?php endif; ?>
                        <div><dt>مبلغی که باید واریز شده باشد</dt><dd><b><?= e(Shop::money((int) $order['total'])) ?></b></dd></div>
                    </dl>
                </section>
            <?php endif; ?>

            <section class="ad-card">
                <header class="ad-card-head"><span class="app-ic tone-violet"><?php $icon('bag'); ?></span><div><h3>اقلام</h3></div></header>
                <ul class="ad-list">
                    <?php foreach ($items as $it): ?>
                        <li class="ad-row">
                            <span class="app-ic tone-<?= e($it['tone'] ?? 'slate') ?>"><?php $icon($it['kind'] === 'package' ? 'package' : 'bag', 18); ?></span>
                            <span class="ad-row-main"><b><?= e($it['title']) ?></b>
                                <small><?= e(fa((string) $it['qty'])) ?> × <?= e(Shop::money((int) $it['price'])) ?><?= $it['package_id'] ? ' · ' . ($it['access_days'] ? e(fa((string) $it['access_days'])) . ' روز' : 'بدون محدودیت') : '' ?></small></span>
                            <?php if ($it['fulfilled_at']): ?><span class="ad-pill is-on">✓ تحویل شد</span><?php elseif ($it['package_id']): ?><span class="ad-pill">بعد از تأیید فعال می‌شود</span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <dl class="sa-dl is-sum">
                    <div><dt>جمع</dt><dd><?= e(Shop::money((int) $order['subtotal'])) ?></dd></div>
                    <?php if ((int) $order['discount'] > 0): ?><div><dt>تخفیف (<?= e((string) $order['coupon_code']) ?>)</dt><dd>− <?= e(Shop::money((int) $order['discount'])) ?></dd></div><?php endif; ?>
                    <div><dt>مبلغ نهایی</dt><dd><b><?= e(Shop::money((int) $order['total'])) ?></b></dd></div>
                    <?php if ($order['gateway_ref']): ?><div><dt>کد پیگیری درگاه</dt><dd dir="ltr"><?= e($order['gateway_ref']) ?> <?= $order['gateway_card'] ? '· ' . e($order['gateway_card']) : '' ?></dd></div><?php endif; ?>
                </dl>
            </section>

            <?php if (!empty($order['shipping'])): $s = $order['shipping']; ?>
                <section class="ad-card">
                    <header class="ad-card-head"><span class="app-ic tone-amber"><?php $icon('send'); ?></span><div><h3>ارسال</h3></div></header>
                    <dl class="sa-dl">
                        <div><dt>گیرنده</dt><dd><?= e($s['name'] ?? '') ?></dd></div>
                        <div><dt>تلفن</dt><dd dir="ltr"><?= e($s['phone'] ?? '') ?></dd></div>
                        <div><dt>شهر</dt><dd><?= e($s['city'] ?? '') ?></dd></div>
                        <div><dt>نشانی</dt><dd><?= e($s['address'] ?? '') ?></dd></div>
                        <?php if (!empty($s['postal'])): ?><div><dt>کد پستی</dt><dd dir="ltr"><?= e($s['postal']) ?></dd></div><?php endif; ?>
                    </dl>
                </section>
            <?php endif; ?>
        </div>

        <aside class="sa-order-side">
            <?php if ($open): ?>
                <section class="ad-card sa-decide">
                    <h3><?= $order['status'] === 'review' ? 'تصمیم درباره رسید' : 'ثبت دستی پرداخت' ?></h3>
                    <form method="post" action="/admin/shop/orders/<?= e($order['uuid']) ?>/approve" data-confirm="پرداخت تأیید و دسترسی‌ها فعال شود؟">
                        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                        <button class="btn btn-primary sa-approve" type="submit"><?php $icon('check', 18); ?> تأیید و فعال‌سازی</button>
                    </form>
                    <?php if ($order['status'] !== 'rejected'): ?>
                        <form class="sa-reject" method="post" action="/admin/shop/orders/<?= e($order['uuid']) ?>/reject">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <textarea class="input" name="note" rows="2" maxlength="500" placeholder="دلیل رد (برای دانشجو فرستاده می‌شود) — مثلاً: مبلغ واریزی کمتر است"></textarea>
                            <div class="sa-quick-notes">
                                <?php foreach (['مبلغ واریزی با سفارش یکی نیست.', 'تصویر رسید خوانا نیست.', 'واریزی با این مشخصات پیدا نشد.'] as $q): ?>
                                    <button type="button" class="ad-pill" data-note="<?= e($q) ?>"><?= e($q) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <button class="btn btn-ghost sa-rejectbtn" type="submit"><?php $icon('close', 16); ?> رد رسید</button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="ad-card">
                <h3 class="sa-mini-t">دانشجو</h3>
                <div class="sa-user">
                    <span class="app-ic tone-blue"><?php $icon('user', 20); ?></span>
                    <span><b><?= e((string) ($u['full_name'] ?? '')) ?></b><small dir="ltr"><?= e((string) ($u['mobile'] ?? $u['username'] ?? '')) ?></small></span>
                </div>
                <?php if (!empty($u['uuid'])): ?><a class="hx-link" href="/admin/students/<?= e($u['uuid']) ?>/edit">پرونده دانشجو ←</a><?php endif; ?>
                <?php if ($history !== []): ?>
                    <ul class="sa-history">
                        <?php foreach ($history as $h): [$hl, $ht] = ShopRepository::STATUS[$h['status']]; ?>
                            <li><a href="/admin/shop/orders/<?= e($h['uuid']) ?>"><span><?= e(fa((string) $h['number'])) ?></span><span class="sa-status tone-<?= e($ht) ?>"><?= e($hl) ?></span><b><?= e(fa(number_format((int) $h['total']))) ?></b></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="ad-card">
                <form method="post" action="/admin/shop/orders/<?= e($order['uuid']) ?>/note">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <label class="hx-field">یادداشت مدیر<textarea class="input" name="note" rows="3" maxlength="500"><?= e((string) ($order['admin_note'] ?? '')) ?></textarea></label>
                    <button class="btn btn-ghost btn-sm" type="submit">ذخیره یادداشت</button>
                </form>
                <?php if ($order['reviewed_at']): ?><p class="sa-hint">بررسی‌شده در <?= e(jdate($order['reviewed_at'])) ?></p><?php endif; ?>
            </section>
        </aside>
    </div>
</div>
<script nonce="<?= e($cspNonce) ?>">
document.querySelectorAll('[data-note]').forEach(function (b) {
    b.addEventListener('click', function () { var t = b.closest('form').querySelector('textarea'); t.value = b.getAttribute('data-note'); t.focus(); });
});
</script>
