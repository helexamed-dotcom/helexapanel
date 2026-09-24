<?php
/**
 * A درسنامه: its settings, and its فهرست — زیردرس‌ها, each with its pages.
 *
 * @var array|null $lesson
 * @var array      $outline   زیردرس‌ها with their pages (and each page's tags)
 * @var array      $subjects
 * @var array      $packages
 * @var array      $colors
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$l = $lesson ?? ['uuid' => '', 'title' => '', 'summary' => '', 'subject_id' => null, 'package_id' => null, 'color' => 'indigo',
    'cover_path' => null, 'status' => 'draft', 'sort_order' => 0, 'reading_minutes' => 0];
$action = $lesson === null ? '/admin/lessons' : '/admin/lessons/' . $l['uuid'];
$base = '/admin/lessons/' . $l['uuid'];
$pageCount = array_sum(array_map(static fn ($s) => count($s['pages']), $outline));
$tok = '<input type="hidden" name="_token" value="' . e($csrf_token) . '">';
?>
<div class="lo tone-<?= e($l['color']) ?>">
    <div class="lo-main">
        <section class="lo-head">
            <span class="app-ic"><?php $icon('lesson', 22); ?></span>
            <div class="lo-head-text">
                <input class="lo-title" form="lesson-meta" name="title" maxlength="191" value="<?= e($l['title']) ?>" placeholder="نام درسنامه — مثلاً باکتری‌شناسی" required>
                <input class="lo-summary" form="lesson-meta" name="summary" maxlength="500" value="<?= e($l['summary'] ?? '') ?>" placeholder="خلاصه یک‌خطی (روی کارت درسنامه)">
            </div>
            <?php if ($lesson !== null): ?>
                <div class="lo-stats"><b><?= e(fa((string) count($outline))) ?></b><small>زیردرس</small></div>
                <div class="lo-stats"><b><?= e(fa((string) $pageCount)) ?></b><small>صفحه</small></div>
                <div class="lo-stats"><b><?= e(fa((string) $l['reading_minutes'])) ?></b><small>دقیقه</small></div>
            <?php endif; ?>
        </section>

        <?php if ($lesson === null): ?>
            <section class="ad-card lo-start">
                <header class="ad-card-head">
                    <span class="app-ic tone-violet"><?php $icon('list'); ?></span>
                    <div><h3>فهرست (زیردرس‌ها)</h3><p>هر خط یک زیردرس. بعد از ذخیره، داخل هر زیردرس صفحه می‌سازید؛ هر صفحه عنوان، متن و برچسب خودش را دارد.</p></div>
                </header>
                <textarea class="input" form="lesson-meta" name="sections" rows="7" placeholder="کلیات باکتری‌شناسی&#10;کوکسی‌های گرم مثبت&#10;باسیل‌های گرم منفی&#10;مایکوباکتریوم‌ها"></textarea>
            </section>
        <?php else: ?>
            <section class="lo-outline" id="outline">
                <?php if ($outline === []): ?>
                    <div class="ad-empty">هنوز زیردرسی نیست. پایین اولین زیردرس (فصل) را بسازید؛ مثلاً «کلیات».</div>
                <?php endif; ?>
                <?php foreach ($outline as $si => $s): ?>
                    <article class="lo-sec" id="s<?= (int) $s['id'] ?>" style="--i: <?= min($si, 10) ?>">
                        <header class="lo-sec-head">
                            <span class="lo-num"><?= e(fa((string) ($si + 1))) ?></span>
                            <form class="lo-rename" method="post" action="<?= e($base . '/sections/' . $s['id']) ?>">
                                <?= $tok ?>
                                <input name="title" value="<?= e($s['title']) ?>" maxlength="191" aria-label="نام زیردرس" data-autosave-blur>
                            </form>
                            <small><?= e(fa((string) count($s['pages']))) ?> صفحه</small>
                            <div class="lo-tools">
                                <?php foreach ([-1 => ['↑', 'بالاتر'], 1 => ['↓', 'پایین‌تر']] as $dir => [$ch, $lab]): ?>
                                    <form method="post" action="<?= e($base . '/sections/' . $s['id'] . '/move') ?>"><?= $tok ?><input type="hidden" name="dir" value="<?= $dir ?>">
                                        <button class="ad-icon-btn" type="submit" aria-label="<?= $lab ?>" title="<?= $lab ?>"><?= $ch ?></button></form>
                                <?php endforeach; ?>
                                <form method="post" action="<?= e($base . '/sections/' . $s['id'] . '/delete') ?>" data-confirm="زیردرس «<?= e($s['title']) ?>» با همه صفحه‌هایش حذف شود؟"><?= $tok ?>
                                    <button class="ad-icon-btn is-danger" type="submit" aria-label="حذف زیردرس"><?php $icon('trash', 16); ?></button></form>
                            </div>
                        </header>

                        <ol class="lo-pages">
                            <?php foreach ($s['pages'] as $pi => $p): ?>
                                <li class="lo-page">
                                    <span class="lo-pnum"><?= e(fa((string) ($pi + 1))) ?></span>
                                    <a class="lo-page-main" href="<?= e($base . '/pages/' . $p['uuid'] . '/edit') ?>">
                                        <b><?= e($p['title']) ?></b>
                                        <span class="lo-page-tags">
                                            <?php foreach ($p['tags'] as $t): ?><span class="stat-chip <?= e($t['color'] ?: 'chip-gray') ?>">#<?= e($t['title']) ?></span><?php endforeach; ?>
                                            <?php if ($p['tags'] === []): ?><em class="lo-notag">بدون برچسب — در تحلیل دیده نمی‌شود</em><?php endif; ?>
                                        </span>
                                    </a>
                                    <small class="lo-min"><?= e(fa((string) $p['reading_minutes'])) ?> د</small>
                                    <div class="lo-tools">
                                        <?php foreach ([-1 => '↑', 1 => '↓'] as $dir => $ch): ?>
                                            <form method="post" action="<?= e($base . '/pages/' . $p['uuid'] . '/move') ?>"><?= $tok ?><input type="hidden" name="dir" value="<?= $dir ?>">
                                                <button class="ad-icon-btn" type="submit" aria-label="جابه‌جایی"><?= $ch ?></button></form>
                                        <?php endforeach; ?>
                                        <a class="ad-icon-btn" href="<?= e($base . '/pages/' . $p['uuid'] . '/edit') ?>" aria-label="ویرایش"><?php $icon('pencil', 16); ?></a>
                                        <form method="post" action="<?= e($base . '/pages/' . $p['uuid'] . '/delete') ?>" data-confirm="صفحه «<?= e($p['title']) ?>» حذف شود؟"><?= $tok ?>
                                            <button class="ad-icon-btn is-danger" type="submit" aria-label="حذف صفحه"><?php $icon('trash', 16); ?></button></form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                        <a class="lo-add-page" href="<?= e($base . '/pages/new?section=' . $s['id']) ?>"><?php $icon('plus', 16); ?> صفحه تازه در «<?= e($s['title']) ?>»</a>
                    </article>
                <?php endforeach; ?>

                <form class="lo-add-sec" method="post" action="<?= e($base . '/sections') ?>">
                    <?= $tok ?>
                    <span class="app-ic tone-violet"><?php $icon('plus', 18); ?></span>
                    <textarea name="title" rows="1" maxlength="4000" placeholder="زیردرس تازه… (چند خط = چند زیردرس)" required data-grow></textarea>
                    <button class="btn btn-primary btn-sm" type="submit">افزودن زیردرس</button>
                </form>
            </section>
        <?php endif; ?>
    </div>

    <form class="lo-side" id="lesson-meta" method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
        <?= $tok ?>
        <section class="ad-card">
            <div class="hx-field">وضعیت
                <div class="seg-pick">
                    <label><input type="radio" name="status" value="draft" <?= $l['status'] !== 'published' ? 'checked' : '' ?>><span>پیش‌نویس</span></label>
                    <label><input type="radio" name="status" value="published" <?= $l['status'] === 'published' ? 'checked' : '' ?>><span>منتشر</span></label>
                </div>
            </div>
            <div class="le-actions">
                <button class="btn btn-primary" type="submit" name="stay" value="1"><?php $icon('check', 16); ?> <?= $lesson === null ? 'ساختن و رفتن به فهرست' : 'ذخیره تنظیمات' ?></button>
            </div>
            <?php if ($lesson !== null): ?>
                <a class="hx-link" href="/student/lessons/<?= e($l['uuid']) ?>?preview=1" target="_blank">پیش‌نمایش دانشجو ←</a>
            <?php endif; ?>
        </section>

        <section class="ad-card">
            <label class="hx-field">درس / زیردرس در بانک سوال
                <select class="input" name="subject_id">
                    <option value="0">— بدون درس —</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) ($l['subject_id'] ?? 0) === $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="lo-hint">برچسب‌ها روی هر <b>صفحه</b> گذاشته می‌شوند؛ برچسب‌های درسنامه خودکار از صفحه‌هایش جمع می‌شود.</p>
        </section>

        <section class="ad-card">
            <div class="hx-field">رنگ
                <div class="ad-swatches">
                    <?php foreach ($colors as $c): ?>
                        <label class="ad-swatch tone-<?= e($c) ?>"><input type="radio" name="color" value="<?= e($c) ?>" <?= $l['color'] === $c ? 'checked' : '' ?>><span></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <label class="hx-field" style="margin-top:12px">تصویر جلد (اختیاری)
                <input class="input" type="file" name="cover" accept="image/*">
            </label>
            <?php if (!empty($l['cover_path'])): ?>
                <div class="le-cover"><img src="/media/lessons/<?= e($l['cover_path']) ?>" alt=""><label class="hx-switch"><input type="checkbox" name="remove_cover" value="1"><span class="hx-switch-ui"></span><span>حذف جلد</span></label></div>
            <?php endif; ?>
            <label class="hx-field" style="margin-top:12px">فقط برای دارندگان پکیج
                <select class="input" name="package_id">
                    <option value="0">همه دانشجویان</option>
                    <?php foreach ($packages as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) ($l['package_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="hx-field" style="margin-top:12px">ترتیب نمایش<input class="input" type="number" name="sort_order" value="<?= (int) $l['sort_order'] ?>" dir="ltr"></label>
        </section>
    </form>
</div>
