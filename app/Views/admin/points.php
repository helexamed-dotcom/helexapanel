<?php
/**
 * @var array $actions  key => [label, default, icon, tone]
 * @var array $amounts  key => current amount
 * @var array $leagues  key => title / min / color / emoji
 * @var array $board    this week's top ten
 * @var array $posts    recent profile posts
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
?>
<div class="ad-page sa">
    <section class="ad-hero tone-amber">
        <div>
            <h2>امتیاز، لیگ و پست‌ها</h2>
            <p>همه بخش‌های سایت در یک امتیاز جمع می‌شوند: سطح از امتیاز کل می‌آید و لیگ از امتیاز همین هفته (از شنبه). همه این‌ها در پروفایل دانشجو نمایش داده می‌شود.</p>
        </div>
        <div class="ad-quick"><a class="ad-quick-btn" href="/admin/qbank"><?php $icon('qbank', 16); ?> امتیاز سوال‌ها در بانک سوال</a></div>
    </section>

    <form method="post" action="/admin/points" class="ad-two">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-violet"><?php $icon('bolt'); ?></span>
                <div><h3>هر کار چقدر امتیاز دارد؟</h3><p>هر مورد فقط یک بار برای هر دانشجو حساب می‌شود (فلش‌کارت: یک بار در روز برای هر کارت). ۰ یعنی بدون امتیاز.</p></div>
            </header>
            <ul class="ad-list">
                <?php foreach ($actions as $k => [$label, $default, $ic, $tone]): ?>
                    <li class="ad-row">
                        <span class="app-ic tone-<?= e($tone) ?>"><?php $icon($ic, 18); ?></span>
                        <span class="ad-row-main"><b><?= e($label) ?></b><small>پیش‌فرض <?= e(fa((string) $default)) ?></small></span>
                        <input class="input sa-pts" type="number" min="0" max="500" name="points_<?= e($k) ?>" value="<?= (int) $amounts[$k] ?>" aria-label="<?= e($label) ?>">
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-amber"><?php $icon('trophy'); ?></span>
                <div><h3>لیگ‌ها</h3><p>امتیازی که در یک هفته لازم است تا دانشجو به هر لیگ برسد.</p></div>
            </header>
            <ul class="ad-list">
                <?php $first = true; foreach ($leagues as $k => $l): ?>
                    <li class="ad-row">
                        <span class="app-ic tone-<?= e($l['color']) ?>"><span class="pf-emoji" style="font-size:20px;position:relative;z-index:1"><?= e($l['emoji']) ?></span></span>
                        <span class="ad-row-main"><b><?= e($l['title']) ?></b><small><?= $first ? 'همه از این‌جا شروع می‌کنند' : 'از این امتیاز هفتگی به بالا' ?></small></span>
                        <input class="input sa-pts" type="number" min="0" name="league_<?= e($k) ?>" value="<?= (int) $l['min'] ?>" <?= $first ? 'readonly' : '' ?> aria-label="<?= e($l['title']) ?>">
                    </li>
                <?php $first = false; endforeach; ?>
            </ul>
        </section>
        <div class="sa-save-bar"><button class="btn btn-primary" type="submit"><?php $icon('check', 16); ?> ذخیره امتیازها و لیگ‌ها</button></div>
    </form>

    <div class="ad-two">
        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-orange"><?php $icon('crown'); ?></span>
                <div><h3>ده نفر اول این هفته</h3><p>دانشجوهایی که نمایش در رتبه‌بندی را خاموش کرده‌اند این‌جا نیستند.</p></div>
            </header>
            <?php if ($board === []): ?>
                <div class="ad-empty">این هفته هنوز کسی امتیاز نگرفته است.</div>
            <?php else: ?>
                <ul class="ad-list">
                    <?php foreach ($board as $r): ?>
                        <li><a class="ad-row" href="/admin/students/<?= e($r['uuid']) ?>/edit">
                            <span class="sa-rank"><?= e(fa((string) $r['place'])) ?></span>
                            <span class="ad-row-main"><b><?= e((string) $r['full_name']) ?></b><small dir="ltr"><?= e((string) $r['username']) ?></small></span>
                            <span class="ad-pill is-on"><?= e(fa(number_format((int) $r['xp']))) ?> ⚡</span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="ad-card" id="posts">
            <header class="ad-card-head">
                <span class="app-ic tone-pink"><?php $icon('posts'); ?></span>
                <div><h3>پست‌های دانشجوها</h3><p>پستی که پنهان کنید فقط برای نویسنده‌اش باقی می‌ماند.</p></div>
            </header>
            <?php if ($posts === []): ?>
                <div class="ad-empty">هنوز پستی منتشر نشده است.</div>
            <?php else: ?>
                <ul class="ad-list">
                    <?php foreach ($posts as $p): ?>
                        <li class="ad-row<?= $p['hidden_at'] ? ' is-muted' : '' ?>">
                            <?php if ($p['image_path']): ?>
                                <a class="sa-post-thumb" href="/media/posts/<?= e($p['image_path']) ?>" target="_blank"><img src="/media/posts/<?= e($p['image_path']) ?>" alt="" loading="lazy"></a>
                            <?php else: ?>
                                <span class="app-ic tone-<?= e($p['tone']) ?>"><?php $icon('posts', 18); ?></span>
                            <?php endif; ?>
                            <span class="ad-row-main">
                                <b><a href="/u/<?= e($p['user_uuid']) ?>" target="_blank"><?= e($p['full_name']) ?></a></b>
                                <small><?= e(mb_strimwidth((string) ($p['body'] ?? '🖼 تصویر'), 0, 120, '…')) ?> · <?= e(jdate($p['created_at'])) ?> · ♥ <?= e(fa((string) $p['like_count'])) ?></small>
                            </span>
                            <form method="post" action="/admin/points/posts/<?= e($p['uuid']) ?>">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <?php if ($p['hidden_at']): ?>
                                    <button class="btn btn-ghost btn-sm" type="submit" name="action" value="show">نمایش دوباره</button>
                                <?php else: ?>
                                    <button class="ad-icon-btn is-danger" type="submit" name="action" value="hide" title="پنهان کردن"><?php $icon('eye-off', 16); ?></button>
                                <?php endif; ?>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
