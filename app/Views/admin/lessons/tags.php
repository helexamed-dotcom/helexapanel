<?php
/**
 * @var array $tags
 * @var array $lessons    tag id => count
 * @var array $questions
 * @var array $spots
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$colors = ['chip-gray' => 'خاکستری', 'chip-blue' => 'آبی', 'chip-green' => 'سبز', 'chip-amber' => 'نارنجی', 'chip-red' => 'قرمز', 'chip-purple' => 'بنفش', 'chip-teal' => 'فیروزه‌ای'];
?>
<div class="ad-page">
    <section class="ad-card">
        <header class="ad-card-head">
            <span class="app-ic tone-teal"><?php $icon('tag'); ?></span>
            <div><h3>برچسب‌های مشترک</h3><p>یک برچسب درسنامه‌ها، سوال‌ها و نقطه‌های بازی با شکل را به هم وصل می‌کند؛ دانشجو از هر کدام به بقیه می‌رسد و تحلیل آزمون بر اساس همین برچسب‌ها درسنامه پیشنهاد می‌دهد.</p></div>
        </header>
        <form method="post" action="/admin/lesson-tags" class="ad-form-grid">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <label class="hx-field">عنوان<input class="input" name="title" maxlength="96" required placeholder="مثلاً: چرخه قلبی"></label>
            <label class="hx-field">رنگ<select class="input" name="color"><?php foreach ($colors as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
            <label class="hx-field">ترتیب<input class="input" type="number" name="sort_order" value="0" dir="ltr"></label>
            <div><button class="btn btn-primary" type="submit"><?php $icon('plus', 15); ?> افزودن</button></div>
        </form>
        <?php if ($tags === []): ?>
            <div class="ad-empty">هنوز برچسبی نیست.</div>
        <?php else: ?>
            <ul class="ad-list">
                <?php foreach ($tags as $t): $id = (int) $t['id']; ?>
                    <li class="ad-row<?= (int) $t['is_active'] === 1 ? '' : ' is-muted' ?>" style="flex-wrap:wrap">
                        <form method="post" action="/admin/lesson-tags/<?= $id ?>" style="display:flex;gap:6px;flex:1;flex-wrap:wrap;align-items:center">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <span class="stat-chip <?= e($t['color'] ?: 'chip-gray') ?>"><?= e($t['title']) ?></span>
                            <input class="input" name="title" value="<?= e($t['title']) ?>" maxlength="96" style="width:180px;padding:8px 10px">
                            <select class="input" name="color" style="width:120px;padding:8px"><?php foreach ($colors as $k => $v): ?><option value="<?= e($k) ?>" <?= $t['color'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
                            <input type="hidden" name="sort_order" value="<?= (int) $t['sort_order'] ?>">
                            <label class="hx-switch"><input type="checkbox" name="is_active" value="1" <?= (int) $t['is_active'] === 1 ? 'checked' : '' ?>><span class="hx-switch-ui"></span></label>
                            <button class="btn btn-ghost btn-sm" type="submit">ذخیره</button>
                        </form>
                        <span class="ad-pill is-info">📘 <?= e(fa((string) ($lessons[$id] ?? 0))) ?></span>
                        <span class="ad-pill is-info">❓ <?= e(fa((string) ($questions[$id] ?? 0))) ?></span>
                        <span class="ad-pill is-info">🦴 <?= e(fa((string) ($spots[$id] ?? 0))) ?></span>
                        <form method="post" action="/admin/lesson-tags/<?= $id ?>/delete" data-confirm="این برچسب از همه درسنامه‌ها، سوال‌ها و شکل‌ها برداشته شود؟">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <button class="ad-icon-btn is-danger" type="submit" aria-label="حذف"><?php $icon('trash', 17); ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
