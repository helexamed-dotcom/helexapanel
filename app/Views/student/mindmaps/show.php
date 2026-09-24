<?php
/**
 * The mind map viewer.
 *
 * @var array $map
 * @var array $tree     the tree
 * @var array $lessons  uuid => title (published)
 * @var bool  $done
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$boot = ['uuid' => $map['uuid'], 'root' => $tree, 'theme' => $map['theme'], 'layout' => $map['layout'], 'lessons' => $lessons];
?>
<div class="mv" data-viewer-app data-done-url="/student/mindmaps/<?= e($map['uuid']) ?>/done">
    <script type="application/json" data-boot nonce="<?= e($cspNonce) ?>"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <div data-canvas style="position:absolute;inset:0"></div>

    <div class="mv-top">
        <div class="mv-title">
            <a href="/student/mindmaps" aria-label="بازگشت"><?php $icon('chevron', 18); ?></a>
            <span style="display:grid"><b><?= e($map['title']) ?></b><small><?= e(fa((string) $map['node_count'])) ?> موضوع</small></span>
        </div>
        <div class="mv-tools">
            <label class="mv-search"><?php $icon('search', 16); ?><input type="search" data-search placeholder="جستجو در نقشه…"></label>
            <button class="mv-btn" type="button" data-act="expand" title="باز کردن همه"><?php $icon('expand', 16); ?></button>
            <button class="mv-btn" type="button" data-act="collapse" title="بستن همه"><?php $icon('collapse', 16); ?></button>
            <button class="mv-btn<?= $done ? ' is-done' : '' ?>" type="button" data-act="done"><?php $icon('check', 16); ?> <span data-done-text><?= $done ? 'مرور شد' : 'مرور کردم' ?></span></button>
        </div>
    </div>

    <div class="mm-zoom">
        <button type="button" data-act="zin" title="بزرگ‌نمایی">+</button>
        <small data-zoom>۱۰۰٪</small>
        <button type="button" data-act="zout" title="کوچک‌نمایی">−</button>
        <button type="button" data-act="fit" title="نمایش کامل"><?php $icon('expand-full', 16); ?></button>
    </div>

    <article class="mv-card" data-card hidden>
        <button class="mv-x" type="button" data-card-close aria-label="بستن"><?php $icon('close', 16); ?></button>
        <h4 data-card-title></h4>
        <img alt="" data-card-img hidden>
        <p data-card-note hidden></p>
        <a class="mv-lesson" data-card-lesson hidden><?php $icon('lesson', 18); ?> <span data-card-lesson-title></span><small>بخوان ←</small></a>
    </article>
</div>
