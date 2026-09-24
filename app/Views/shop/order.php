<?php
/**
 * One order: where it stands, what is in it, and — while it waits — how to
 * pay for it (gateway again, or card to card with the receipt).
 *
 * @var array $theme
 * @var array $order
 * @var array $items
 * @var array $card
 * @var bool  $cardOn
 * @var bool  $gateway
 */
use HeleXa\Core\View;
use HeleXa\Models\ShopRepository;
use HeleXa\Services\Shop\Shop;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
[$label, $tone] = ShopRepository::STATUS[$order['status']];
$st = $order['status'];
$open = in_array($st, ['pending', 'rejected'], true);
$steps = [
    ['ثبت سفارش', true],
    [$order['method'] === 'card' ? 'ارسال رسید' : 'پرداخت', in_array($st, ['review', 'paid'], true)],
    ['تأیید', $st === 'paid'],
    ['فعال شد', $st === 'paid'],
];
if ($order['method'] !== 'card') {
    unset($steps[2]);
}
?>
<div class="sh tone-<?= e($theme['tone']) ?>">
    <a class="sh-back" href="/shop/orders"><?php $icon('chevron', 16); ?> سفارش‌های من</a>

    <section class="sh-order-hero tone-<?= e($tone) ?>">
        <span class="app-ic tone-<?= e($tone) ?>"><?php $icon($st === 'paid' ? 'check' : ($st === 'review' ? 'hourglass' : ($st === 'rejected' ? 'close' : 'receipt')), 26); ?></span>
        <div>
            <small>سفارش <?= e(fa((string) $order['number'])) ?> · <?= e(jdate($order['created_at'])) ?></small>
            <h2><?= e($label) ?></h2>
            <p>
                <?php if ($st === 'paid'): ?>همه‌چیز آماده است؛ دسترسی‌ها فعال شده‌اند.
                <?php elseif ($st === 'review'): ?>رسیدت رسید و در صف بررسی است. بعد از تأیید خودکار فعال می‌شود و خبرت می‌کنیم.
                <?php elseif ($st === 'rejected'): ?>رسید تأیید نشد<?= !empty($order['admin_note']) ? ': ' . e($order['admin_note']) : '.' ?> می‌توانی رسید درست را دوباره بفرستی.
                <?php elseif ($st === 'cancelled'): ?>این سفارش لغو شده است.
                <?php else: ?>برای نهایی شدن سفارش، مبلغ را پرداخت کن.<?php endif; ?>
            </p>
        </div>
        <strong class="sh-order-total"><?= e(Shop::money((int) $order['total'])) ?></strong>
    </section>

    <?php if ($st !== 'cancelled'): ?>
        <ol class="sh-steps">
            <?php foreach (array_values($steps) as $i => [$t, $done]): ?>
                <li class="<?= $done ? 'is-done' : '' ?>"><i><?= $done ? '✓' : e(fa((string) ($i + 1))) ?></i><span><?= e($t) ?></span></li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <div class="sh-checkout">
        <div class="sh-lines">
            <?php if ($open || $st === 'review'): ?>
                <?php if ($cardOn && ($order['method'] === 'card' || !$gateway)): ?>
                    <section class="sh-card2card">
                        <h3 class="sh-h"><?php $icon('creditcard', 19); ?> کارت به کارت</h3>
                        <div class="sh-bankcard">
                            <small><?= e($card['bank'] ?: 'شماره کارت') ?></small>
                            <b dir="ltr"><?= e(fa(Shop::cardNumber((string) $card['number']))) ?></b>
                            <span><?= e($card['holder']) ?></span>
                            <button type="button" class="sh-copy" data-copy="<?= e(preg_replace('/\D/', '', (string) $card['number'])) ?>"><?php $icon('copy', 15); ?> کپی شماره کارت</button>
                        </div>
                        <?php if ($card['sheba'] !== ''): ?>
                            <p class="sh-sheba">شبا: <span dir="ltr"><?= e($card['sheba']) ?></span> <button type="button" class="sh-copy is-mini" data-copy="<?= e($card['sheba']) ?>"><?php $icon('copy', 13); ?></button></p>
                        <?php endif; ?>
                        <p class="sh-amount">مبلغ واریز: <b><?= e(Shop::money((int) $order['total'])) ?></b> <button type="button" class="sh-copy is-mini" data-copy="<?= (int) $order['total'] ?>"><?php $icon('copy', 13); ?></button></p>
                        <?php if ($card['note'] !== ''): ?><p class="sh-note"><?= nl2br(e($card['note'])) ?></p><?php endif; ?>

                        <form class="sh-receipt" method="post" action="/shop/orders/<?= e($order['uuid']) ?>/receipt" enctype="multipart/form-data" data-receipt>
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <label class="sh-drop" data-drop>
                                <input type="file" name="receipt" accept="image/*" data-receipt-file>
                                <span class="sh-drop-empty"><?php $icon('upload', 26); ?><b><?= $order['receipt_path'] ? 'جایگزینی تصویر رسید' : 'تصویر رسید را این‌جا بینداز' ?></b><small>یا بزن تا انتخاب کنی — JPG یا PNG</small></span>
                                <img alt="" data-receipt-preview hidden>
                            </label>
                            <input class="input" name="ref" maxlength="64" dir="ltr" value="<?= e((string) ($order['receipt_ref'] ?? '')) ?>" placeholder="شماره پیگیری / ارجاع (اختیاری)">
                            <input class="input" name="note" maxlength="500" placeholder="توضیح برای ما (اختیاری) — مثلاً ساعت واریز یا چهار رقم آخر کارت">
                            <button class="btn btn-primary" type="submit" data-lock-on-submit><?php $icon('send', 16); ?> <?= $st === 'review' ? 'به‌روزرسانی رسید' : 'ارسال رسید' ?></button>
                        </form>
                        <?php if ($order['receipt_path']): ?>
                            <a class="sh-receipt-now" href="/media/receipts/<?= e($order['receipt_path']) ?>" target="_blank"><img src="/media/receipts/<?= e($order['receipt_path']) ?>" alt="رسید ارسال‌شده"><span>رسید فعلی</span></a>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
                <?php if ($open && ($gateway || ($cardOn && $order['method'] !== 'card'))): ?>
                    <form class="sh-alt-pay" method="post" action="/shop/orders/<?= e($order['uuid']) ?>/pay">
                        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                        <?php if ($gateway): ?>
                            <button class="btn btn-primary" type="submit" name="method" value="gateway"><?php $icon('creditcard', 16); ?> پرداخت آنلاین <?= e(Shop::money((int) $order['total'])) ?></button>
                        <?php endif; ?>
                        <?php if ($cardOn && $order['method'] !== 'card'): ?>
                            <button class="btn btn-ghost" type="submit" name="method" value="card">پرداخت کارت به کارت</button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <section class="sh-items">
                <h3 class="sh-h"><?php $icon('bag', 19); ?> اقلام سفارش</h3>
                <?php foreach ($items as $it): ?>
                    <div class="sh-line is-static tone-<?= e($it['tone'] ?? 'slate') ?>">
                        <span class="sh-line-media"><?php if (!empty($it['cover_path'])): ?><img src="/media/shop/<?= e($it['cover_path']) ?>" alt=""><?php else: ?><?php $icon($it['kind'] === 'package' ? 'package' : 'bag', 24); ?><?php endif; ?></span>
                        <div class="sh-line-body">
                            <b class="sh-line-title"><?= e($it['title']) ?></b>
                            <small><?= e(fa((string) $it['qty'])) ?> × <?= e(Shop::money((int) $it['price'])) ?>
                                <?php if ($it['fulfilled_at'] !== null && $it['kind'] === 'package'): ?> · <span class="is-ok">✓ فعال شد</span><?php endif; ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        </div>

        <aside class="sh-summary is-static">
            <h3 class="sh-h"><?php $icon('receipt', 19); ?> صورتحساب</h3>
            <dl class="sh-sum">
                <div><dt>جمع</dt><dd><?= e(Shop::money((int) $order['subtotal'])) ?></dd></div>
                <?php if ((int) $order['discount'] > 0): ?><div class="is-discount"><dt>تخفیف<?= $order['coupon_code'] ? ' (' . e($order['coupon_code']) . ')' : '' ?></dt><dd>− <?= e(Shop::money((int) $order['discount'])) ?></dd></div><?php endif; ?>
                <div class="is-total"><dt>مبلغ نهایی</dt><dd><?= e(Shop::money((int) $order['total'])) ?></dd></div>
            </dl>
            <dl class="sh-meta">
                <div><dt>روش پرداخت</dt><dd><?= e(['gateway' => 'پرداخت آنلاین', 'card' => 'کارت به کارت', 'free' => 'رایگان'][$order['method']]) ?></dd></div>
                <?php if (!empty($order['gateway_ref'])): ?><div><dt>کد پیگیری</dt><dd dir="ltr"><?= e(fa((string) $order['gateway_ref'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($order['paid_at'])): ?><div><dt>زمان پرداخت</dt><dd><?= e(jdate($order['paid_at'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($order['shipping'])): ?><div><dt>ارسال به</dt><dd><?= e(implode('، ', array_filter([$order['shipping']['name'] ?? '', $order['shipping']['city'] ?? '', $order['shipping']['address'] ?? '']))) ?></dd></div><?php endif; ?>
            </dl>
            <?php if ($st === 'paid'): ?>
                <a class="btn btn-primary" href="/student/activate"><?php $icon('package', 16); ?> پکیج‌های من</a>
            <?php endif; ?>
            <?php if ($st === 'pending'): ?>
                <form method="post" action="/shop/orders/<?= e($order['uuid']) ?>/cancel" data-confirm="این سفارش لغو شود؟">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <button class="btn btn-ghost sh-cancel" type="submit">لغو سفارش</button>
                </form>
            <?php endif; ?>
        </aside>
    </div>
</div>
