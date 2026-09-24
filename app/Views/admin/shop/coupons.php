<?php
/**
 * Discount codes.
 *
 * @var array      $rows
 * @var array|null $editing
 * @var array      $products
 */
use HeleXa\Core\View;
use HeleXa\Services\Shop\Shop;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$c = $editing ?? ['id' => 0, 'code' => '', 'kind' => 'percent', 'amount' => '', 'max_discount' => null, 'min_total' => null, 'starts_at' => null,
    'ends_at' => null, 'usage_limit' => null, 'per_user_limit' => 1, 'product_ids' => null, 'status' => 'active', 'note' => ''];
$picked = $c['product_ids'] !== null ? array_map('intval', (array) json_decode((string) $c['product_ids'], true)) : [];
$jd = static fn (?string $d) => $d === null ? '' : \HeleXa\Services\Jalali::date((int) strtotime($d));
$now = date('Y-m-d H:i:s');
?>
<div class="ad-page sa">
    <section class="ad-card">
        <header class="ad-card-head">
            <span class="app-ic tone-pink"><?php $icon('percent'); ?></span>
            <div><h3><?= $editing ? 'ویرایش کد «' . e($c['code']) . '»' : 'کد تخفیف تازه' ?></h3><p>دانشجو کد را در سبد خرید وارد می‌کند. کدهای استفاده‌شده در سفارش‌های پرداخت‌شده یا در انتظار بررسی شمرده می‌شوند.</p></div>
            <?php if ($editing): ?><a class="btn btn-ghost" href="/admin/shop/coupons">انصراف</a><?php endif; ?>
        </header>
        <form class="ad-form-grid sa-coupon-form" method="post" action="/admin/shop/coupons">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <label class="hx-field">کد
                <span class="sa-code-in">
                    <input class="input" name="code" maxlength="40" dir="ltr" value="<?= e($c['code']) ?>" placeholder="مثلاً KONKUR20" data-code>
                    <button class="ad-icon-btn" type="button" title="ساخت کد تصادفی" data-gen><?php $icon('refresh', 16); ?></button>
                </span>
            </label>
            <div class="hx-field">نوع
                <div class="seg-pick">
                    <label><input type="radio" name="kind" value="percent" <?= $c['kind'] === 'percent' ? 'checked' : '' ?>><span>درصد</span></label>
                    <label><input type="radio" name="kind" value="fixed" <?= $c['kind'] === 'fixed' ? 'checked' : '' ?>><span>مبلغ ثابت</span></label>
                </div>
            </div>
            <label class="hx-field">مقدار <small class="hx-muted">(درصد یا تومان)</small><input class="input" name="amount" inputmode="numeric" dir="ltr" value="<?= e((string) $c['amount']) ?>" required></label>
            <label class="hx-field">سقف تخفیف (تومان)<input class="input" name="max_discount" inputmode="numeric" dir="ltr" value="<?= e((string) ($c['max_discount'] ?? '')) ?>" placeholder="بدون سقف"></label>
            <label class="hx-field">حداقل مبلغ سبد<input class="input" name="min_total" inputmode="numeric" dir="ltr" value="<?= e((string) ($c['min_total'] ?? '')) ?>" placeholder="ندارد"></label>
            <label class="hx-field">از تاریخ (شمسی)<input class="input" name="starts_at" dir="ltr" value="<?= e($jd($c['starts_at'])) ?>" placeholder="1405/07/01"></label>
            <label class="hx-field">تا تاریخ (شمسی)<input class="input" name="ends_at" dir="ltr" value="<?= e($jd($c['ends_at'])) ?>" placeholder="1405/07/30"></label>
            <label class="hx-field">کل دفعات مجاز<input class="input" name="usage_limit" inputmode="numeric" dir="ltr" value="<?= e((string) ($c['usage_limit'] ?? '')) ?>" placeholder="نامحدود"></label>
            <label class="hx-field">برای هر دانشجو<input class="input" name="per_user_limit" inputmode="numeric" dir="ltr" value="<?= e((string) ($c['per_user_limit'] ?? '')) ?>" placeholder="نامحدود"></label>
            <div class="hx-field">وضعیت
                <div class="seg-pick">
                    <label><input type="radio" name="status" value="active" <?= $c['status'] === 'active' ? 'checked' : '' ?>><span>فعال</span></label>
                    <label><input type="radio" name="status" value="disabled" <?= $c['status'] === 'disabled' ? 'checked' : '' ?>><span>غیرفعال</span></label>
                </div>
            </div>
            <label class="hx-field is-wide">یادداشت داخلی<input class="input" name="note" maxlength="255" value="<?= e((string) ($c['note'] ?? '')) ?>" placeholder="مثلاً: کمپین شب یلدا"></label>
            <?php if ($products !== []): ?>
                <div class="hx-field is-wide">فقط برای این محصولات <small class="hx-muted">(هیچ‌کدام = همه محصولات)</small>
                    <div class="hx-pills">
                        <?php foreach ($products as $p): ?>
                            <label class="hx-pill hx-pill-soft"><input type="checkbox" name="products[]" value="<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $picked, true) ? 'checked' : '' ?>><span><?= e($p['title']) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="is-wide"><button class="btn btn-primary" type="submit"><?php $icon('check', 16); ?> <?= $editing ? 'ذخیره تغییرات' : 'ساخت کد' ?></button></div>
        </form>
    </section>

    <section class="ad-card">
        <header class="ad-card-head"><span class="app-ic tone-slate"><?php $icon('list'); ?></span><div><h3>همه کدها</h3></div></header>
        <?php if ($rows === []): ?>
            <div class="ad-empty">هنوز کد تخفیفی نساخته‌اید.</div>
        <?php else: ?>
            <div class="sa-coupons">
                <?php foreach ($rows as $r):
                    $expired = $r['ends_at'] !== null && $r['ends_at'] < $now;
                    $full = $r['usage_limit'] !== null && (int) $r['used'] >= (int) $r['usage_limit'];
                    $live = $r['status'] === 'active' && !$expired && !$full; ?>
                    <article class="sa-ticket<?= $live ? '' : ' is-off' ?>">
                        <div class="sa-ticket-amt">
                            <b><?= $r['kind'] === 'percent' ? '٪' . e(fa((string) $r['amount'])) : e(fa(number_format((int) $r['amount']))) ?></b>
                            <small><?= $r['kind'] === 'percent' ? 'تخفیف' : 'تومان' ?></small>
                        </div>
                        <div class="sa-ticket-body">
                            <code dir="ltr"><?= e($r['code']) ?></code>
                            <small>
                                <?= e(fa((string) $r['used'])) ?><?= $r['usage_limit'] !== null ? ' از ' . e(fa((string) $r['usage_limit'])) : '' ?> استفاده
                                <?= $r['max_discount'] !== null ? ' · سقف ' . e(Shop::money((int) $r['max_discount'])) : '' ?>
                                <?= $r['min_total'] !== null ? ' · حداقل ' . e(Shop::money((int) $r['min_total'])) : '' ?>
                                <?= $r['ends_at'] !== null ? ' · تا ' . e($jd($r['ends_at'])) : '' ?>
                                <?= $r['product_ids'] !== null ? ' · محصولات خاص' : '' ?>
                            </small>
                            <?php if (!empty($r['note'])): ?><small class="hx-muted"><?= e($r['note']) ?></small><?php endif; ?>
                        </div>
                        <span class="ad-pill <?= $live ? 'is-on' : '' ?>"><?= $live ? 'فعال' : ($expired ? 'منقضی' : ($full ? 'تمام‌شده' : 'غیرفعال')) ?></span>
                        <a class="ad-icon-btn" href="/admin/shop/coupons?edit=<?= (int) $r['id'] ?>" title="ویرایش"><?php $icon('pen', 16); ?></a>
                        <form method="post" action="/admin/shop/coupons/<?= (int) $r['id'] ?>/delete" data-confirm="کد «<?= e($r['code']) ?>» حذف شود؟">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <button class="ad-icon-btn is-danger" type="submit"><?php $icon('trash', 16); ?></button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<script nonce="<?= e($cspNonce) ?>">
(function () {
    var gen = document.querySelector('[data-gen]');
    if (!gen) { return; }
    gen.addEventListener('click', function () {
        var abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', s = '', a = new Uint8Array(8);
        crypto.getRandomValues(a);
        a.forEach(function (n) { s += abc[n % abc.length]; });
        document.querySelector('[data-code]').value = s;
    });
})();
</script>
