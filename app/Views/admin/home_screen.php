<?php
/**
 * @var array $countdowns
 * @var array $colors
 * @var array $types
 * @var array $catalog
 * @var array $off
 * @var array $defaults
 * @var int   $goal
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$typeTitle = [];
foreach ($types as $t) {
    $typeTitle[(int) $t['id']] = $t['title'];
}
?>
<div class="ad-page">
    <section class="ad-card">
        <header class="ad-card-head">
            <span class="app-ic tone-violet"><?php $icon('hourglass'); ?></span>
            <div>
                <h3>روزشمار داشبورد</h3>
                <p>به جای ساعت، روی داشبورد دانشجو یک شمارش معکوس تا این رویدادها نمایش داده می‌شود. اگر چند مورد باشد، یکی‌یکی می‌چرخند.</p>
            </div>
        </header>

        <form method="post" action="/admin/home-screen/countdowns" class="ad-form-grid">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <label class="hx-field is-wide">عنوان
                <input class="input" name="title" maxlength="80" placeholder="مثلاً: آزمون علوم پایه" required>
            </label>
            <label class="hx-field">تاریخ (شمسی)
                <input class="input" name="date" dir="ltr" placeholder="1405/08/20" required pattern="[0-9۰-۹]{4}[/\-.][0-9۰-۹]{1,2}[/\-.][0-9۰-۹]{1,2}">
            </label>
            <label class="hx-field">ساعت (اختیاری)
                <input class="input" name="time" dir="ltr" placeholder="08:00">
            </label>
            <label class="hx-field is-wide">زیرعنوان (اختیاری)
                <input class="input" name="subtitle" maxlength="140" placeholder="مثلاً: شنبه صبح، سالن اصلی">
            </label>
            <div class="hx-field is-wide">رنگ
                <div class="ad-swatches">
                    <?php foreach ($colors as $key => $label): ?>
                        <label class="ad-swatch tone-<?= e($key) ?>" title="<?= e($label) ?>">
                            <input type="radio" name="color" value="<?= e($key) ?>" <?= $key === 'violet' ? 'checked' : '' ?>><span></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($types !== []): ?>
                <div class="hx-field is-wide">برای چه کسانی؟ <small class="hx-muted">(هیچ‌کدام = همه)</small>
                    <div class="hx-pills">
                        <?php foreach ($types as $t): ?>
                            <label class="hx-pill hx-pill-soft"><input type="checkbox" name="types[]" value="<?= (int) $t['id'] ?>"><span><?= e($t['title']) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="is-wide"><button class="btn btn-primary" type="submit"><?php $icon('plus', 16); ?> افزودن روزشمار</button></div>
        </form>

        <?php if ($countdowns === []): ?>
            <div class="ad-empty">هنوز روزشماری ندارید.</div>
        <?php else: ?>
            <ul class="ad-list">
                <?php foreach ($countdowns as $c):
                    $past = strtotime((string) $c['at']) < time();
                    $days = (int) ceil((strtotime((string) $c['at']) - time()) / 86400); ?>
                    <li class="ad-row<?= $past ? ' is-muted' : '' ?>">
                        <span class="app-ic tone-<?= e($c['color'] ?? 'violet') ?>"><?php $icon('hourglass', 18); ?></span>
                        <span class="ad-row-main">
                            <b><?= e($c['title']) ?></b>
                            <small><?= e(jdate($c['at'])) ?><?= !empty($c['subtitle']) ? ' · ' . e($c['subtitle']) : '' ?>
                                · <?= ($c['types'] ?? []) === [] ? 'همه دانشجویان' : e(implode('، ', array_map(static fn ($id) => $typeTitle[(int) $id] ?? '؟', $c['types']))) ?></small>
                        </span>
                        <span class="ad-pill <?= $past ? '' : 'is-on' ?>"><?= $past ? 'گذشته' : e(fa((string) $days)) . ' روز مانده' ?></span>
                        <form method="post" action="/admin/home-screen/countdowns/<?= e($c['id']) ?>/delete" data-confirm="این روزشمار حذف شود؟">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <button class="ad-icon-btn is-danger" type="submit" aria-label="حذف"><?php $icon('trash', 18); ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="ad-card" id="sections">
        <header class="ad-card-head">
            <span class="app-ic tone-blue"><?php $icon('apps'); ?></span>
            <div>
                <h3>بخش‌های سایت</h3>
                <p>ستون اول: بخش برای همه روشن است یا نه. ستون دوم: دانشجویی که هنوز «نوع دانشجو»ی تأییدشده ندارد چه بخش‌هایی را می‌بیند.
                   بخش‌های هر نوع دانشجو را در <a href="/admin/student-types">انواع دانشجو</a> تعیین کنید.</p>
            </div>
        </header>
        <form method="post" action="/admin/home-screen/sections">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <div class="ad-matrix">
                <div class="ad-matrix-head"><span>بخش</span><span>روشن برای همه</span><span>بدون نوع دانشجو</span></div>
                <?php foreach ($catalog as $key => [$label, $desc, $ic, $tone]): ?>
                    <div class="ad-matrix-row">
                        <span class="ad-matrix-name"><span class="app-ic tone-<?= e($tone) ?>"><?php $icon($ic, 16); ?></span><b><?= e($label) ?></b><small><?= e($desc) ?></small></span>
                        <label class="hx-switch"><input type="checkbox" name="on[]" value="<?= e($key) ?>" <?= in_array($key, $off, true) ? '' : 'checked' ?>><span class="hx-switch-ui"></span></label>
                        <label class="hx-switch"><input type="checkbox" name="default[]" value="<?= e($key) ?>" <?= ($defaults === [] || in_array($key, $defaults, true)) ? 'checked' : '' ?>><span class="hx-switch-ui"></span></label>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="ad-form-grid" style="margin-top:16px">
                <label class="hx-field">هدف مطالعه روزانه (دقیقه)
                    <input class="input" type="number" name="goal" min="10" max="600" value="<?= (int) $goal ?>" dir="ltr">
                </label>
                <label class="hx-switch is-wide">
                    <input type="checkbox" name="type_prompt" value="1" <?= \HeleXa\Services\Settings::bool('student_type_prompt', true) ? 'checked' : '' ?>>
                    <span class="hx-switch-ui"></span>
                    <span>از دانشجوی جدید بپرس چه نوع دانشجویی است<small>کارت انتخاب روی داشبورد نمایش داده می‌شود.</small></span>
                </label>
            </div>
            <button class="btn btn-primary" type="submit" style="margin-top:14px">ذخیره بخش‌ها</button>
        </form>
    </section>
</div>
