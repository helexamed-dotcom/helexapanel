<?php
/**
 * «خرید و فعال‌سازی».
 *
 * @var array $catalogue  packages on offer
 * @var array $held       uuids of packages the student holds now
 * @var array $mine       the student's live packages
 * @var array $used       codes the student has used
 */
?>
<div class="hx-page hx-anim">
    <section class="hx-hero hx-hero-green">
        <div class="hx-hero-icon" aria-hidden="true">🎟️</div>
        <div class="hx-hero-text">
            <h2>خرید و فعال‌سازی</h2>
            <p>کد فعال‌سازی‌ای را که گرفته‌ای وارد کن تا پکیجش فوراً برایت باز شود. هر کد فقط یک بار و فقط برای یک نفر کار می‌کند.</p>
        </div>
    </section>

    <form method="post" action="/student/activate" class="hx-card hx-code-card">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <label class="hx-code-label" for="act-code">کد فعال‌سازی</label>
        <div class="hx-code-row">
            <input class="input hx-code-input" id="act-code" name="code" dir="ltr" maxlength="20"
                   autocapitalize="characters" autocomplete="off" spellcheck="false"
                   placeholder="HLX-XXXX-XXXX" required>
            <button class="btn btn-primary" type="submit" data-lock-on-submit>فعال‌سازی</button>
        </div>
    </form>

    <?php if ($mine !== []): ?>
        <section class="hx-card">
            <div class="hx-card-head"><h3>✅ پکیج‌های فعال من</h3></div>
            <div class="hx-chips">
                <?php foreach ($mine as $p): ?>
                    <span class="hx-chip is-on">
                        <?= e($p['title']) ?>
                        <?php if ($p['ends_at']): ?> · تا <?= e(jdate($p['ends_at'])) ?><?php endif; ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($catalogue !== []): ?>
        <section class="hx-card">
            <div class="hx-card-head">
                <h3>🛍️ پکیج‌ها</h3>
                <span class="hx-muted">برای تهیه کد هر پکیج با پشتیبانی در تماس باش.</span>
            </div>
            <div class="hx-packages">
                <?php foreach ($catalogue as $p): $owned = in_array((string) $p['uuid'], $held, true); ?>
                    <article class="hx-package" style="--pkg: <?= e($p['color'] ?: '#7c6cf3') ?>;">
                        <div class="hx-package-top">
                            <span class="hx-package-dot" aria-hidden="true"></span>
                            <b><?= e($p['title']) ?></b>
                            <?php if ($owned): ?><span class="hx-chip is-on">فعال</span><?php endif; ?>
                        </div>
                        <?php if (!empty($p['description'])): ?><p><?= e($p['description']) ?></p><?php endif; ?>
                        <div class="hx-package-meta">
                            <?php if ((int) $p['course_count'] > 0): ?><span>🎓 <?= e(fa((string) $p['course_count'])) ?> دوره</span><?php endif; ?>
                            <?php if ((int) ($p['item_count'] ?? 0) > 0): ?><span>🧩 <?= e(fa((string) $p['item_count'])) ?> بخش دیگر</span><?php endif; ?>
                        </div>
                        <?php if (!$owned): ?>
                            <a class="btn btn-ghost btn-sm" href="/student/support">درخواست تهیه</a>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($used !== []): ?>
        <section class="hx-card">
            <div class="hx-card-head"><h3>🧾 کدهایی که استفاده کرده‌ام</h3></div>
            <div class="hx-list">
                <?php foreach ($used as $u): ?>
                    <div class="hx-row is-static">
                        <span class="hx-row-main">
                            <b class="mono"><?= e($u['code']) ?></b>
                            <small><?= e($u['package_title']) ?> · <?= e(jdate($u['redeemed_at'])) ?></small>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
