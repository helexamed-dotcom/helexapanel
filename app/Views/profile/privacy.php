<?php
/**
 * «حریم خصوصی و نمایش»: who sees what on the profile, and whether the
 * student appears in the rankings.
 *
 * @var array $settings
 * @var array $sections
 * @var array $tones
 * @var array $requests  pending follow requests
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
?>
<?php View::partial('partials.profile_tabs', ['currentPath' => $currentPath, 'currentUser' => $currentUser]); ?>

<div class="pf-settings">
    <?php if ($requests !== []): ?>
        <section class="pf-set-card">
            <h3><?php $icon('users', 18); ?> درخواست‌های دنبال کردن <em><?= e(fa((string) count($requests))) ?></em></h3>
            <ul class="pf-people">
                <?php foreach ($requests as $r): ?>
                    <li>
                        <a class="pf-person" href="/u/<?= e($r['handle'] ?: $r['uuid']) ?>">
                            <span class="pf-avatar is-sm"><?php View::partial('partials.avatar', ['person' => $r]); ?></span>
                            <span><b><?= e($r['full_name']) ?></b><?php if ($r['handle']): ?><small dir="ltr">@<?= e($r['handle']) ?></small><?php endif; ?></span>
                        </a>
                        <form method="post" action="/profile/requests/<?= e($r['uuid']) ?>">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <button class="pf-btn is-primary is-sm" type="submit" name="decision" value="accept">قبول</button>
                            <button class="pf-btn is-sm" type="submit" name="decision" value="decline">رد</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <form method="post" action="/account/privacy" class="pf-set-form">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

        <section class="pf-set-card">
            <h3><?php $icon('user', 18); ?> معرفی</h3>
            <label class="hx-field">نام کاربری پروفایل
                <span class="pf-handle" dir="ltr"><span>@</span><input class="input" name="handle" dir="ltr" maxlength="30" value="<?= e((string) $settings['handle']) ?>" placeholder="sara.med" autocomplete="off"></span>
                <small class="hx-muted">نشانی پروفایلت: <span dir="ltr">/u/<?= e($settings['handle'] ?: 'نام-کاربری') ?></span></small>
            </label>
            <label class="hx-field">بیو<textarea class="input" name="bio" rows="3" maxlength="300" placeholder="مثلاً: ورودی ۱۴۰۲ · عاشق فیزیولوژی 🫀"><?= e((string) $settings['bio']) ?></textarea></label>
            <div class="hx-field">رنگ پروفایل
                <div class="ad-swatches pf-swatches">
                    <?php foreach ($tones as $t): ?>
                        <label class="ad-swatch tone-<?= e($t) ?>"><input type="radio" name="tone" value="<?= e($t) ?>" <?= $settings['tone'] === $t ? 'checked' : '' ?>><span></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="pf-set-card">
            <h3><?php $icon('shield', 18); ?> حریم خصوصی</h3>
            <label class="pf-switch">
                <span><b>حساب خصوصی</b><small>فقط کسانی که درخواستشان را قبول کنی پست‌ها و آمارت را می‌بینند.</small></span>
                <input type="checkbox" name="is_private" value="1" <?= $settings['is_private'] ? 'checked' : '' ?>><i></i>
            </label>
            <label class="pf-switch">
                <span><b>نمایش در رتبه‌بندی</b><small>اگر خاموش باشد، نامت در جدول لیگ و رتبه‌های کل دیده نمی‌شود؛ امتیازت همچنان حساب می‌شود.</small></span>
                <input type="checkbox" name="show_in_leaderboard" value="1" <?= $settings['show_in_leaderboard'] ? 'checked' : '' ?>><i></i>
            </label>
        </section>

        <section class="pf-set-card">
            <h3><?php $icon('eye', 18); ?> دیگران در پروفایلت چه ببینند؟</h3>
            <?php foreach ($sections as $k => $label): ?>
                <label class="pf-switch">
                    <span><b><?= e($label) ?></b></span>
                    <input type="checkbox" name="visible[]" value="<?= e($k) ?>" <?= $settings['visibility'][$k] ? 'checked' : '' ?>><i></i>
                </label>
            <?php endforeach; ?>
        </section>

        <div class="pf-set-save"><button class="btn btn-primary" type="submit"><?php $icon('check', 16); ?> ذخیره</button><a class="btn btn-ghost" href="/account/profile">دیدن پروفایل</a></div>
    </form>
</div>
