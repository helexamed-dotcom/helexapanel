<?php
/**
 * @var array      $types
 * @var array|null $editing
 * @var array      $catalog
 * @var array      $colors
 * @var int        $pending
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$f = $editing ?? ['id' => 0, 'title' => '', 'description' => '', 'color' => 'blue', 'icon' => 'school',
    'modules_list' => array_keys($catalog), 'requires_approval' => 1, 'is_active' => 1, 'sort_order' => count($types) + 1];
$iconChoices = ['school', 'lesson', 'stethoscope', 'target', 'trophy', 'book', 'star', 'crown', 'medal', 'figure', 'qbank', 'users'];
?>
<div class="ad-page">
    <section class="ad-card">
        <header class="ad-card-head">
            <span class="app-ic tone-violet"><?php $icon('school'); ?></span>
            <div>
                <h3>انواع دانشجو</h3>
                <p>هر دانشجو بعد از ثبت‌نام یکی از این نوع‌ها را درخواست می‌کند. برای هر نوع تعیین کنید چه بخش‌هایی از سایت برایش روشن باشد —
                   مثلاً «علوم پایه» به برنامه کلاسی و تقویم نیاز ندارد.</p>
            </div>
            <div class="ad-actions">
                <a class="btn btn-ghost btn-sm" href="/admin/student-types/requests">درخواست‌ها<?php if ($pending > 0): ?> <span class="adn-dot" style="display:inline-grid"><?= e(fa((string) $pending)) ?></span><?php endif; ?></a>
            </div>
        </header>

        <?php if ($types === []): ?>
            <div class="ad-empty">نوعی تعریف نشده است.</div>
        <?php else: ?>
            <ul class="ad-list">
                <?php foreach ($types as $t): ?>
                    <li class="ad-row<?= (int) $t['is_active'] === 1 ? '' : ' is-muted' ?>">
                        <span class="app-ic tone-<?= e($t['color']) ?>"><?php $icon($t['icon'] ?: 'school', 18); ?></span>
                        <span class="ad-row-main">
                            <b><?= e($t['title']) ?></b>
                            <small><?= e(fa((string) count($t['modules_list']))) ?> بخش روشن ·
                                <?= e(implode('، ', array_map(static fn ($k) => $catalog[$k][0] ?? $k, array_slice($t['modules_list'], 0, 6)))) ?><?= count($t['modules_list']) > 6 ? '…' : '' ?></small>
                        </span>
                        <span class="ad-pill is-info"><?= e(fa((string) $t['members'])) ?> دانشجو</span>
                        <span class="ad-pill <?= (int) $t['requires_approval'] === 1 ? 'is-warn' : 'is-on' ?>"><?= (int) $t['requires_approval'] === 1 ? 'نیاز به تأیید' : 'خودکار' ?></span>
                        <a class="ad-icon-btn" href="/admin/student-types?edit=<?= (int) $t['id'] ?>#form" aria-label="ویرایش"><?php $icon('pencil', 17); ?></a>
                        <form method="post" action="/admin/student-types/<?= (int) $t['id'] ?>/delete" data-confirm="این نوع حذف شود؟ دانشجویانش بدون نوع می‌شوند.">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <button class="ad-icon-btn is-danger" type="submit" aria-label="حذف"><?php $icon('trash', 17); ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="ad-card" id="form">
        <header class="ad-card-head">
            <span class="app-ic tone-<?= e($f['color']) ?>"><?php $icon($editing ? 'pencil' : 'plus'); ?></span>
            <div><h3><?= $editing ? 'ویرایش «' . e($f['title']) . '»' : 'نوع جدید' ?></h3></div>
            <?php if ($editing): ?><div class="ad-actions"><a class="btn btn-ghost btn-sm" href="/admin/student-types">انصراف</a></div><?php endif; ?>
        </header>
        <form method="post" action="/admin/student-types">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <div class="ad-form-grid">
                <label class="hx-field">عنوان<input class="input" name="title" maxlength="128" value="<?= e($f['title']) ?>" required></label>
                <label class="hx-field">ترتیب<input class="input" type="number" name="sort_order" min="0" max="999" value="<?= (int) $f['sort_order'] ?>" dir="ltr"></label>
                <label class="hx-field is-wide">توضیح کوتاه<input class="input" name="description" maxlength="500" value="<?= e($f['description'] ?? '') ?>"></label>
                <div class="hx-field">رنگ
                    <div class="ad-swatches">
                        <?php foreach ($colors as $c): ?>
                            <label class="ad-swatch tone-<?= e($c) ?>"><input type="radio" name="color" value="<?= e($c) ?>" <?= $f['color'] === $c ? 'checked' : '' ?>><span></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="hx-field">آیکون
                    <div class="ad-swatches">
                        <?php foreach ($iconChoices as $ic): ?>
                            <label class="ad-swatch tone-slate" title="<?= e($ic) ?>"><input type="radio" name="icon" value="<?= e($ic) ?>" <?= $f['icon'] === $ic ? 'checked' : '' ?>><span style="display:grid;place-items:center;color:#fff"><?php $icon($ic, 18); ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <label class="hx-switch"><input type="checkbox" name="requires_approval" value="1" <?= (int) $f['requires_approval'] === 1 ? 'checked' : '' ?>><span class="hx-switch-ui"></span><span>نیاز به تأیید مدیر<small>خاموش: با انتخاب دانشجو فوراً فعال می‌شود.</small></span></label>
                <label class="hx-switch"><input type="checkbox" name="is_active" value="1" <?= (int) $f['is_active'] === 1 ? 'checked' : '' ?>><span class="hx-switch-ui"></span><span>قابل انتخاب<small>خاموش: در فهرست دانشجو دیده نمی‌شود.</small></span></label>
            </div>
            <div class="hx-label">بخش‌های روشن برای این نوع</div>
            <div class="ad-modules">
                <?php foreach ($catalog as $key => [$label, $desc, $ic, $tone]): ?>
                    <label class="ad-module ad-module-pick">
                        <input type="checkbox" name="modules[]" value="<?= e($key) ?>" <?= in_array($key, $f['modules_list'], true) ? 'checked' : '' ?>>
                        <span class="app-ic tone-<?= e($tone) ?>"><?php $icon($ic); ?></span>
                        <b><?= e($label) ?></b>
                        <small><?= e($desc) ?></small>
                    </label>
                <?php endforeach; ?>
            </div>
            <button class="btn btn-primary" type="submit" style="margin-top:16px"><?= $editing ? 'ذخیره تغییرات' : 'ساخت نوع' ?></button>
        </form>
    </section>
</div>
