<?php
/**
 * The game.
 *
 * @var array      $fig
 * @var array      $spots   positions, names, questions, choices (without the answer)
 * @var array|null $best
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$boot = ['uuid' => $fig['uuid'], 'w' => (int) $fig['image_w'], 'h' => (int) $fig['image_h'], 'spots' => $spots];
?>
<div class="fg tone-<?= e($fig['tone']) ?>" data-game>
    <script type="application/json" data-boot nonce="<?= e($cspNonce) ?>"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

    <header class="fg-head">
        <a class="fg-back" href="/student/figures" aria-label="بازگشت"><?php $icon('chevron', 18); ?></a>
        <div class="fg-title"><b><?= e($fig['title']) ?></b><small><?= e(fa((string) count($spots))) ?> ساختار<?= $best ? ' · بهترین ٪' . e(fa((string) $best['best'])) : '' ?></small></div>
        <nav class="fg-modes" role="tablist">
            <button type="button" class="is-on" data-mode="find" role="tab"><?php $icon('target', 16); ?> پیدا کن</button>
            <button type="button" data-mode="ask" role="tab"><?php $icon('info', 16); ?> جواب بده</button>
            <button type="button" data-mode="explore" role="tab"><?php $icon('eye', 16); ?> مرور</button>
        </nav>
    </header>

    <div class="fg-hud" data-hud>
        <span class="fg-progress"><i data-bar></i></span>
        <span class="fg-stat"><b data-n>۰</b>/<span data-total>۰</span></span>
        <span class="fg-stat is-score">⚡ <b data-score>۰</b></span>
        <span class="fg-stat is-streak" data-streak-wrap hidden>🔥 <b data-streak>۰</b></span>
    </div>

    <div class="fg-prompt" data-prompt></div>

    <div class="fg-board">
        <div class="fg-scroll" data-scroll>
            <div class="fg-stage" data-stage>
                <img src="/media/figures/<?= e($fig['image_path']) ?>" alt="<?= e($fig['title']) ?>" data-img draggable="false">
                <div class="fg-layer" data-layer></div>
            </div>
        </div>
        <button class="fg-zoom" type="button" data-zoom title="بزرگ‌نمایی"><?php $icon('search', 18); ?><span data-zoom-label>۱×</span></button>
    </div>

    <section class="fg-panel" data-panel hidden>
        <div class="fg-choices" data-choices></div>
        <div class="fg-feedback" data-feedback hidden>
            <b data-fb-title></b>
            <p data-fb-text></p>
            <a class="mv-lesson" data-fb-lesson hidden><?php $icon('lesson', 18); ?> <span data-fb-lesson-title></span><small>بخوان ←</small></a>
            <button class="btn btn-primary fg-next" type="button" data-next>بعدی ←</button>
        </div>
    </section>

    <div class="fg-end" data-end hidden>
        <div class="fg-end-card">
            <div class="fg-ring" data-ring><b data-end-pct>٪۰</b></div>
            <h3 data-end-title>تمام شد!</h3>
            <p data-end-text></p>
            <div class="fg-missed" data-missed></div>
            <div class="me-row" style="justify-content:center">
                <button class="btn btn-primary" type="button" data-again><?php $icon('refresh', 16); ?> دوباره</button>
                <button class="btn btn-ghost" type="button" data-other>حالت دیگر</button>
                <a class="btn btn-ghost" href="/student/figures">همه شکل‌ها</a>
            </div>
        </div>
    </div>
</div>
