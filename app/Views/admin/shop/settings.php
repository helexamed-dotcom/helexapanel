<?php
/**
 * Payment (ZarinPal, card to card) and the store's look, with a live
 * preview built from the store's own styles.
 *
 * @var string $tab       pay | look
 * @var array  $theme
 * @var array  $card
 * @var string $gateway
 * @var string $merchant
 * @var bool   $sandbox
 * @var string $callback
 * @var array  $tones
 * @var array  $sample    up to three published products for the preview
 */
use HeleXa\Core\View;
use HeleXa\Services\Shop\Shop;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
if ($sample === []) {
    $sample = [
        ['title' => 'پکیج جامع علوم پایه', 'subtitle' => 'درسنامه، بانک سوال و فلش‌کارت', 'price' => 1890000, 'compare_price' => 2490000, 'tone' => 'violet', 'badge' => 'پرفروش', 'cover_path' => null, 'kind' => 'package'],
        ['title' => 'بانک سوال فیزیولوژی', 'subtitle' => '۶۰۰ سوال طبقه‌بندی‌شده', 'price' => 690000, 'compare_price' => null, 'tone' => 'blue', 'badge' => null, 'cover_path' => null, 'kind' => 'package'],
        ['title' => 'جزوه چاپی آناتومی', 'subtitle' => '۲۲۰ صفحه تمام‌رنگی', 'price' => 350000, 'compare_price' => 420000, 'tone' => 'amber', 'badge' => 'جدید', 'cover_path' => null, 'kind' => 'physical'],
    ];
}
?>
<div class="ad-page sa">
    <nav class="ad-tabs">
        <a class="ad-tab<?= $tab === 'pay' ? ' is-on' : '' ?>" href="/admin/shop/settings"><?php $icon('creditcard', 15); ?> پرداخت</a>
        <a class="ad-tab<?= $tab === 'look' ? ' is-on' : '' ?>" href="/admin/shop/settings?tab=look"><?php $icon('palette', 15); ?> ظاهر فروشگاه</a>
    </nav>

    <?php if ($tab === 'pay'): ?>
    <form method="post" action="/admin/shop/settings/payment" class="ad-two">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-blue"><?php $icon('creditcard'); ?></span>
                <div><h3>درگاه پرداخت آنلاین</h3><p>زرین‌پال: پرداخت با همه کارت‌های شتاب؛ سفارش بلافاصله بعد از تأیید درگاه فعال می‌شود.</p></div>
            </header>
            <div class="hx-field">درگاه
                <div class="seg-pick">
                    <label><input type="radio" name="gateway" value="none" <?= $gateway !== 'zarinpal' ? 'checked' : '' ?>><span>خاموش</span></label>
                    <label><input type="radio" name="gateway" value="zarinpal" <?= $gateway === 'zarinpal' ? 'checked' : '' ?>><span>زرین‌پال</span></label>
                </div>
            </div>
            <label class="hx-field">کد پذیرنده (Merchant ID)
                <input class="input" name="merchant" dir="ltr" maxlength="64" value="<?= e($merchant) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" autocomplete="off">
            </label>
            <label class="sa-check"><input type="checkbox" name="sandbox" value="1" <?= $sandbox ? 'checked' : '' ?>> <span>حالت آزمایشی (Sandbox) — پرداخت واقعی انجام نمی‌شود</span></label>
            <p class="sa-hint">نشانی بازگشت درگاه: <code dir="ltr"><?= e(str_replace('?order=…', '', $callback)) ?></code><br>دامنه این نشانی باید همان دامنه‌ای باشد که در پنل زرین‌پال ثبت کرده‌اید (تنظیم <code>app.url</code> در config).</p>
        </section>

        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-teal"><?php $icon('receipt'); ?></span>
                <div><h3>کارت به کارت</h3><p>دانشجو مبلغ را واریز می‌کند و تصویر رسید را می‌فرستد؛ سفارش بعد از تأیید شما در «سفارش‌ها» فعال می‌شود.</p></div>
            </header>
            <label class="sa-check"><input type="checkbox" name="card_enabled" value="1" <?= $card['enabled'] ? 'checked' : '' ?>> <span>کارت به کارت فعال باشد</span></label>
            <div class="ad-form-grid">
                <label class="hx-field">شماره کارت<input class="input" name="card_number" dir="ltr" inputmode="numeric" maxlength="19" value="<?= e(Shop::cardNumber((string) $card['number'])) ?>" placeholder="6037 9912 3456 7890"></label>
                <label class="hx-field">نام صاحب کارت<input class="input" name="card_holder" maxlength="80" value="<?= e($card['holder']) ?>"></label>
                <label class="hx-field">بانک<input class="input" name="card_bank" maxlength="60" value="<?= e($card['bank']) ?>" placeholder="مثلاً بانک ملی"></label>
                <label class="hx-field">شبا (اختیاری)<input class="input" name="card_sheba" dir="ltr" maxlength="34" value="<?= e($card['sheba']) ?>" placeholder="IR…"></label>
                <label class="hx-field is-wide">راهنمای واریز<textarea class="input" name="card_note" rows="3" maxlength="600"><?= e($card['note']) ?></textarea></label>
            </div>
        </section>
        <div class="sa-save-bar"><button class="btn btn-primary" type="submit"><?php $icon('check', 16); ?> ذخیره تنظیمات پرداخت</button></div>
    </form>

    <?php else: ?>
    <form method="post" action="/admin/shop/settings/theme" enctype="multipart/form-data" class="sa-look" data-theme-form>
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <div class="sa-look-controls">
            <section class="ad-card">
                <h3 class="sa-mini-t"><?php $icon('type', 16); ?> متن‌ها</h3>
                <label class="hx-field">عنوان فروشگاه<input class="input" name="title" maxlength="60" value="<?= e($theme['title']) ?>" data-t="title"></label>
                <label class="hx-field">زیرعنوان<input class="input" name="subtitle" maxlength="200" value="<?= e($theme['subtitle']) ?>" data-t="subtitle"></label>
                <label class="hx-field">نوار اعلان بالای فروشگاه <small class="hx-muted">(خالی = نمایش داده نمی‌شود)</small><input class="input" name="notice" maxlength="160" value="<?= e($theme['notice']) ?>" placeholder="مثلاً: ۲۰٪ تخفیف تا آخر هفته با کد YALDA" data-t="notice"></label>
                <label class="hx-field">نشان‌های اعتماد <small class="hx-muted">(هر خط یکی، حداکثر ۵)</small><textarea class="input" name="trust" rows="3" data-t="trust"><?= e(implode("\n", $theme['trust'])) ?></textarea></label>
                <label class="hx-field">متن پایین فروشگاه<textarea class="input" name="footer" rows="2" maxlength="500" data-t="footer"><?= e($theme['footer']) ?></textarea></label>
                <label class="hx-field">واحد پول<input class="input" name="currency" maxlength="12" value="<?= e($theme['currency']) ?>" data-t="currency"></label>
            </section>

            <section class="ad-card">
                <h3 class="sa-mini-t"><?php $icon('palette', 16); ?> رنگ و سبک</h3>
                <div class="hx-field">رنگ اصلی
                    <div class="ad-swatches">
                        <?php foreach ($tones as $t): ?>
                            <label class="ad-swatch tone-<?= e($t) ?>"><input type="radio" name="tone" value="<?= e($t) ?>" <?= $theme['tone'] === $t ? 'checked' : '' ?> data-t="tone"><span></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="hx-field">سربرگ
                    <div class="sa-choices">
                        <?php foreach (['gradient' => 'رنگی', 'image' => 'تصویر', 'minimal' => 'ساده'] as $k => $l): ?>
                            <label class="sa-choice"><input type="radio" name="hero" value="<?= e($k) ?>" <?= $theme['hero'] === $k ? 'checked' : '' ?> data-t="hero"><span class="sa-choice-art is-hero-<?= e($k) ?>"></span><b><?= e($l) ?></b></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="hx-field" data-when-hero="image">تصویر سربرگ
                    <label class="sa-upload"><input type="file" name="banner" accept="image/*" data-banner><?php $icon('upload', 20); ?><span>انتخاب تصویر پهن (مثلاً ۱۶۰۰×۵۰۰)</span></label>
                    <?php if ($theme['banner'] !== ''): ?><label class="sa-check"><input type="checkbox" name="remove_banner" value="1"> <span>حذف تصویر فعلی</span></label><?php endif; ?>
                </div>
                <div class="hx-field">کارت محصول
                    <div class="sa-choices">
                        <?php foreach (['glass' => 'شیشه‌ای', 'solid' => 'ساده', 'outline' => 'خطی'] as $k => $l): ?>
                            <label class="sa-choice"><input type="radio" name="card" value="<?= e($k) ?>" <?= $theme['card'] === $k ? 'checked' : '' ?> data-t="card"><span class="sa-choice-art is-card-<?= e($k) ?>"></span><b><?= e($l) ?></b></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="hx-field">چیدمان
                    <div class="seg-pick">
                        <label><input type="radio" name="layout" value="grid" <?= $theme['layout'] === 'grid' ? 'checked' : '' ?> data-t="layout"><span>شبکه‌ای</span></label>
                        <label><input type="radio" name="layout" value="list" <?= $theme['layout'] === 'list' ? 'checked' : '' ?> data-t="layout"><span>فهرستی</span></label>
                    </div>
                </div>
                <label class="hx-field">ستون‌ها در دسکتاپ: <b data-out="columns"><?= e(fa((string) $theme['columns'])) ?></b>
                    <input type="range" name="columns" min="2" max="4" value="<?= (int) $theme['columns'] ?>" data-t="columns">
                </label>
                <label class="hx-field">گردی گوشه‌ها: <b data-out="radius"><?= e(fa((string) $theme['radius'])) ?></b>
                    <input type="range" name="radius" min="6" max="32" value="<?= (int) $theme['radius'] ?>" data-t="radius">
                </label>
            </section>

            <section class="ad-card">
                <h3 class="sa-mini-t"><?php $icon('sliders', 16); ?> بخش‌ها</h3>
                <?php foreach (['show_search' => 'جستجو', 'show_categories' => 'دسته‌ها (آیکن‌های بالای ویترین)', 'show_compare' => 'قیمت خط‌خورده و درصد تخفیف', 'show_sold' => 'تعداد خرید هر محصول'] as $k => $l): ?>
                    <label class="sa-switch"><input type="checkbox" name="<?= e($k) ?>" value="1" <?= $theme[$k] ? 'checked' : '' ?> data-t="<?= e($k) ?>"><i></i><span><?= e($l) ?></span></label>
                <?php endforeach; ?>
            </section>
            <div class="sa-save-bar"><button class="btn btn-primary" type="submit"><?php $icon('check', 16); ?> ذخیره ظاهر</button> <a class="btn btn-ghost" href="/shop" target="_blank"><?php $icon('eye', 16); ?> دیدن فروشگاه</a></div>
        </div>

        <div class="sa-look-preview">
            <div class="sa-device">
                <div class="sa-device-bar"><i></i><i></i><i></i><span>پیش‌نمایش زنده</span></div>
                <div class="sa-device-screen">
                    <div class="sh sh-card-<?= e($theme['card']) ?> tone-<?= e($theme['tone']) ?>" style="--sh-cols: <?= (int) $theme['columns'] ?>; --sh-r: <?= (int) $theme['radius'] ?>px" data-pv-shop>
                        <div class="sh-notice" data-pv-notice <?= $theme['notice'] === '' ? 'hidden' : '' ?>><?php $icon('gift', 16); ?> <span data-pv-notice-text><?= e($theme['notice']) ?></span></div>
                        <section class="sh-hero is-<?= e($theme['hero']) ?>" data-pv-hero<?= $theme['banner'] !== '' ? ' style="--sh-banner: url(\'/media/shop/' . e($theme['banner']) . '\')"' : '' ?>>
                            <div class="sh-hero-text">
                                <h2 data-pv-title><?= e($theme['title']) ?></h2>
                                <p data-pv-subtitle><?= e($theme['subtitle']) ?></p>
                                <ul class="sh-trust" data-pv-trust><?php foreach ($theme['trust'] as $t): ?><li><?php $icon('check', 14); ?> <?= e($t) ?></li><?php endforeach; ?></ul>
                            </div>
                            <span class="sh-hero-art" aria-hidden="true"><?php $icon('store', 120); ?></span>
                        </section>
                        <div class="sh-tools" data-pv-search <?= $theme['show_search'] ? '' : 'hidden' ?>>
                            <div class="sh-search"><?php $icon('search', 18); ?><input disabled placeholder="جستجو در محصولات…"></div>
                        </div>
                        <nav class="sh-cats" data-pv-cats <?= $theme['show_categories'] ? '' : 'hidden' ?>>
                            <?php foreach ([['apps', 'slate', 'همه'], ['package', 'violet', 'پکیج‌ها'], ['qbank', 'blue', 'بانک سوال'], ['book', 'amber', 'کتاب']] as $i => [$ic, $tn, $l]): ?>
                                <span class="sh-cat<?= $i === 0 ? ' is-on' : '' ?>"><span class="app-ic tone-<?= e($tn) ?>"><?php $icon($ic, 20); ?></span><b><?= e($l) ?></b></span>
                            <?php endforeach; ?>
                        </nav>
                        <div class="sh-grid is-<?= e($theme['layout']) ?>" data-pv-grid>
                            <?php foreach ($sample as $p): $off = Shop::discountPercent($p); ?>
                                <article class="sh-item tone-<?= e($p['tone']) ?>">
                                    <span class="sh-item-media">
                                        <?php if (!empty($p['cover_path'])): ?><img src="/media/shop/<?= e($p['cover_path']) ?>" alt=""><?php else: ?><span class="sh-art"><?php $icon($p['kind'] === 'package' ? 'package' : 'bag', 40); ?></span><?php endif; ?>
                                        <?php if (!empty($p['badge'])): ?><em class="sh-badge"><?= e($p['badge']) ?></em><?php endif; ?>
                                        <?php if ($off > 0): ?><i class="sh-off is-float" data-pv-compare>٪<?= e(fa((string) $off)) ?></i><?php endif; ?>
                                    </span>
                                    <div class="sh-item-body">
                                        <span class="sh-item-title"><?= e($p['title']) ?></span>
                                        <p><?= e((string) $p['subtitle']) ?></p>
                                        <div class="sh-item-foot">
                                            <span class="sh-price">
                                                <?php if ($off > 0): ?><s data-pv-compare><?= e(fa(number_format((int) $p['compare_price']))) ?></s><?php endif; ?>
                                                <strong><?= e(fa(number_format((int) $p['price']))) ?> <span data-pv-currency><?= e($theme['currency']) ?></span></strong>
                                                <small data-pv-sold <?= $theme['show_sold'] ? '' : 'hidden' ?>><?= e(fa('۱۲')) ?> خرید</small>
                                            </span>
                                            <span class="sh-add"><span class="sh-add-plus"><?php $icon('plus', 18); ?></span></span>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <p class="sh-footer" data-pv-footer><?= nl2br(e($theme['footer'])) ?></p>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>
