<?php
/**
 * «نقشه دسترسی»: sections × (site, no type, each type, each package).
 *
 * @var array      $catalog   Modules::CATALOG
 * @var array      $groups
 * @var list<string> $off     sections off site-wide
 * @var list<string> $default sections of a student with no type
 * @var array      $types
 * @var array      $packages
 * @var array|null $probe     one student's effective access
 * @var string     $query
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 16) => View::partial('partials.icon', ['name' => $n, 'size' => $s]);
$byGroup = [];
foreach ($catalog as $key => $row) {
    $byGroup[$row[5]][$key] = $row;
}
$cell = static function (string $name, string $key, bool $on, bool $locked = false): string {
    return '<td class="am-cell' . ($locked ? ' is-locked' : '') . '"><label><input type="checkbox" name="' . e($name) . '[]" value="' . e($key) . '"'
        . ($on ? ' checked' : '') . ($locked ? ' disabled' : '') . ' data-am-col="' . e($name) . '"><span></span></label></td>';
};
?>
<div class="ad-page am">
    <section class="ad-card">
        <header class="ad-card-head">
            <span class="app-ic tone-violet"><?php $icon('key', 20); ?></span>
            <div>
                <h3>نقشه دسترسی</h3>
                <p>هر بخش برای یک دانشجو روشن است اگر <b>در کل سایت روشن</b> باشد و <b>نوع دانشجویی‌اش</b> یا <b>یکی از پکیج‌هایش</b> آن را روشن کند.
                   پکیج کامل ⭐ همه بخش‌ها و همه محتوا را باز می‌کند؛ حتی چیزهایی که بعداً اضافه می‌کنید.</p>
            </div>
            <div class="ad-actions">
                <a class="btn btn-ghost btn-sm" href="/admin/student-types"><?php $icon('school', 15); ?> انواع دانشجو</a>
                <a class="btn btn-ghost btn-sm" href="/admin/packages"><?php $icon('package', 15); ?> پکیج‌ها</a>
            </div>
        </header>

        <form method="post" action="/admin/access-matrix" class="am-form" data-am>
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <div class="am-scroll">
                <table class="am-table">
                    <thead>
                        <tr>
                            <th class="am-sec-h">بخش</th>
                            <th class="am-col is-site"><span>کل سایت</span><small>کلید اصلی</small></th>
                            <th class="am-col is-default"><span>بدون نوع</span><small>تا تأیید نوع</small></th>
                            <?php foreach ($types as $t): ?>
                                <th class="am-col tone-<?= e($t['color']) ?>"><a href="/admin/student-types?edit=<?= (int) $t['id'] ?>#form"><?= e($t['title']) ?></a>
                                    <small><?= e(fa((string) $t['members'])) ?> دانشجو<?= \HeleXa\Services\AccessProfile::typePackageIds($t) !== [] ? ' · ' . e(fa((string) count(\HeleXa\Services\AccessProfile::typePackageIds($t)))) . ' پکیج' : '' ?></small></th>
                            <?php endforeach; ?>
                            <?php foreach ($packages as $p): ?>
                                <th class="am-col is-pkg<?= (int) $p['is_full_access'] === 1 ? ' is-full' : '' ?>"><a href="/admin/packages/<?= e($p['uuid']) ?>#sections"><?= (int) $p['is_full_access'] === 1 ? '⭐ ' : '📦 ' ?><?= e($p['title']) ?></a>
                                    <small><?= e(fa((string) $p['holders'])) ?> دارنده<?= (int) $p['is_free'] === 1 ? ' · رایگان' : '' ?></small></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <?php foreach ($groups as $g => $gLabel): if (empty($byGroup[$g])) { continue; } ?>
                        <tbody>
                            <tr class="am-group"><th colspan="<?= 3 + count($types) + count($packages) ?>"><?= e($gLabel) ?></th></tr>
                            <?php foreach ($byGroup[$g] as $key => [$label, $desc, $ic, $tone]): ?>
                                <tr>
                                    <th class="am-sec"><span class="app-ic tone-<?= e($tone) ?>"><?php $icon($ic, 15); ?></span><span><b><?= e($label) ?></b><small><?= e($desc) ?></small></span></th>
                                    <?= $cell('site', $key, !in_array($key, $off, true)) ?>
                                    <?= $cell('default', $key, in_array($key, $default, true)) ?>
                                    <?php foreach ($types as $t): ?>
                                        <?= $cell('type_' . (int) $t['id'], $key, in_array($key, $t['modules_list'], true)) ?>
                                    <?php endforeach; ?>
                                    <?php foreach ($packages as $p): $full = (int) $p['is_full_access'] === 1; ?>
                                        <?= $cell('pkg_' . (int) $p['id'], $key, $full || in_array($key, $p['modules_list'], true), $full) ?>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php endforeach; ?>
                    <tfoot>
                        <tr>
                            <th class="am-sec">همه / هیچ</th>
                            <?php foreach (array_merge(['site', 'default'], array_map(static fn ($t) => 'type_' . (int) $t['id'], $types), array_map(static fn ($p) => (int) $p['is_full_access'] === 1 ? '' : 'pkg_' . (int) $p['id'], $packages)) as $col): ?>
                                <td class="am-cell"><?php if ($col !== ''): ?><button type="button" class="am-all" data-am-toggle="<?= e($col) ?>" aria-label="همه یا هیچ">⇅</button><?php endif; ?></td>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="am-save">
                <span class="am-legend"><i class="is-on"></i> روشن <i></i> خاموش <i class="is-locked"></i> پکیج کامل (همیشه روشن)</span>
                <button class="btn btn-primary" type="submit"><?php $icon('check', 16); ?> ذخیره نقشه دسترسی</button>
            </div>
        </form>
    </section>

    <section class="ad-card" id="probe">
        <header class="ad-card-head">
            <span class="app-ic tone-sky"><?php $icon('eye', 20); ?></span>
            <div><h3>این دانشجو چه می‌بیند؟</h3><p>نام کاربری یا شماره موبایل را بنویسید تا بخش‌های روشن و خاموش و دلیل هرکدام را ببینید.</p></div>
        </header>
        <form method="get" action="/admin/access-matrix#probe" class="am-probe-form">
            <input class="input" name="student" value="<?= e($query) ?>" placeholder="مثلاً ali.rezaei یا 0912…" dir="auto">
            <button class="btn btn-ghost" type="submit"><?php $icon('search', 15); ?> بررسی</button>
        </form>
        <?php if ($probe !== null): $u = $probe['user']; ?>
            <div class="am-probe-head">
                <b><?= e($u['full_name']) ?></b>
                <span class="ad-pill is-info"><?= $probe['type'] ? 'نوع: ' . e($probe['type']['title']) : 'بدون نوع' ?></span>
                <?php if ($probe['profile']['full']): ?><span class="ad-pill is-on">⭐ <?= e($probe['profile']['full']['title']) ?></span><?php endif; ?>
                <?php foreach ($probe['profile']['packages'] as $pk): if ($pk['full']) { continue; } ?><span class="ad-pill">📦 <?= e($pk['title']) ?></span><?php endforeach; ?>
                <a class="btn btn-ghost btn-sm" href="/admin/students/<?= e($u['uuid']) ?>/access">دسترسی‌های محتوا ←</a>
            </div>
            <div class="am-probe">
                <?php foreach ($probe['sections'] as $s): ?>
                    <div class="am-probe-row<?= $s['on'] ? ' is-on' : '' ?>">
                        <span class="app-ic tone-<?= e($s['tone']) ?>"><?php $icon($s['icon'], 14); ?></span>
                        <b><?= e($s['label']) ?></b>
                        <em><?= $s['on'] ? 'روشن' : 'خاموش' ?></em>
                        <small><?= e($s['why']) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
