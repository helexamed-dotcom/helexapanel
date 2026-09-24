<?php
/**
 * @var array $prefs
 * @var array $accents
 * @var array $modes
 * @var array $langs
 */
?>
<form method="post" action="/account/settings" class="prefs-page" data-prefs-form>
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

    <section class="card">
        <h3 class="card-title">🎨 <?= e(t('رنگ اصلی')) ?></h3>
        <p class="muted"><?= e(t('رنگ دلخواهت را انتخاب کن؛ روی همه صفحه‌ها اعمال می‌شود.')) ?></p>
        <div class="accent-grid" role="radiogroup" aria-label="<?= e(t('رنگ اصلی')) ?>">
            <?php foreach ($accents as $key => [$label, $light, $dark]): ?>
                <label class="accent-opt">
                    <input type="radio" name="accent" value="<?= e($key) ?>" <?= $prefs['accent'] === $key ? 'checked' : '' ?> data-accent-pick>
                    <span class="accent-swatch" style="--sw: <?= e($light) ?>; --sw-dark: <?= e($dark) ?>;"></span>
                    <span class="accent-name"><?= e(t($label)) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="prefs-preview">
            <span class="muted"><?= e(t('پیش‌نمایش')) ?>:</span>
            <span class="btn btn-primary btn-sm"><?= e(t('دکمه اصلی')) ?></span>
            <span class="btn btn-ghost btn-sm"><?= e(t('دکمه ثانویه')) ?></span>
            <span class="stat-chip chip-blue">XP</span>
        </div>
    </section>

    <div class="grid grid-2">
        <section class="card">
            <h3 class="card-title">🌗 <?= e(t('حالت نمایش')) ?></h3>
            <div class="seg-pick" role="radiogroup">
                <?php foreach ($modes as $key => $label): ?>
                    <label><input type="radio" name="mode" value="<?= e($key) ?>" <?= $prefs['mode'] === $key ? 'checked' : '' ?> data-mode-pick><span><?= e(t($label)) ?></span></label>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card">
            <h3 class="card-title">🌐 <?= e(t('زبان')) ?></h3>
            <div class="seg-pick" role="radiogroup">
                <?php foreach ($langs as $key => $label): ?>
                    <label><input type="radio" name="lang" value="<?= e($key) ?>" <?= $prefs['lang'] === $key ? 'checked' : '' ?>><span><?= $key === 'en' ? 'English' : 'فارسی' ?></span></label>
                <?php endforeach; ?>
            </div>
            <p class="muted" style="font-size:12px; margin:10px 0 0;"><?= e(t('در نسخه انگلیسی، منوها، نوار پایین و تنظیمات ترجمه شده‌اند؛ متن درس‌ها و سوال‌ها همان‌طور که نوشته شده‌اند نمایش داده می‌شوند.')) ?></p>
        </section>
    </div>

    <div class="row-actions">
        <button class="btn btn-primary" type="submit" data-lock-on-submit><?= e(t('ذخیره')) ?></button>
    </div>
</form>