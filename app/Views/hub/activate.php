<?php
/**
 * 🔑 — enter a code, or go buy one.
 *
 * @var array $held
 * @var int   $heldAll
 * @var bool  $shopOn
 */
$icon = static fn (string $n) => \HeleXa\Core\View::partial('partials.icon', ['name' => $n]);
?>
<div class="hub">
    <div class="hub-hero tone-orange">
        <span class="hub-hero-ic"><?php $icon('key'); ?></span>
        <div>
            <b>فعال‌سازی پکیج</b>
            <small>کد فعال‌سازی را وارد کن تا پکیج بلافاصله برایت باز شود.</small>
        </div>
    </div>

    <form class="hub-code" method="post" action="/student/activate" data-activate-form>
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <input class="input" name="code" dir="ltr" autocomplete="off" spellcheck="false" maxlength="40"
               placeholder="XXXX-XXXX-XXXX" aria-label="کد فعال‌سازی" required>
        <button type="submit" class="btn btn-primary">فعال کن</button>
        <p class="hub-code-msg" data-activate-msg role="status" aria-live="polite"></p>
    </form>

    <?php if ($shopOn): ?>
        <a class="hub-buy" href="/shop">
            <span class="hub-row-ic tone-green"><?php $icon('bag'); ?></span>
            <span><b>کد نداری؟ خرید پکیج</b><small>پرداخت آنلاین یا کارت به کارت</small></span>
            <span class="hub-buy-go"><?php $icon('chevron'); ?></span>
        </a>
    <?php endif; ?>

    <?php if ($held !== []): ?>
        <div class="hub-sub">پکیج‌های فعال من</div>
        <ul class="hub-list is-compact">
            <?php foreach ($held as $p): ?>
                <li class="hub-row">
                    <span class="hub-row-ic tone-violet"><?php $icon('package'); ?></span>
                    <span class="hub-row-text">
                        <b><?= e($p['title']) ?></b>
                        <small><?= !empty($p['ends_at']) ? 'تا ' . e(jdate($p['ends_at'])) : 'بدون تاریخ پایان' ?></small>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <div class="hub-foot">
        <a class="hub-link is-strong" href="/student/activate"><?= $heldAll > 0 ? 'همه پکیج‌ها و کدهای من' : 'صفحه خرید و فعال‌سازی' ?></a>
    </div>
</div>
