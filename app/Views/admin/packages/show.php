<?php
/**
 * One package: what is inside it, and who holds it.
 *
 * @var array $package
 * @var array $courses     the courses inside it
 * @var array $available   courses not inside it yet
 * @var array $catalogue   ['qbank_subject'=>[], 'balin_lesson'=>[], 'flashcard_course'=>[]]
 * @var array $chosen      the same keys, each an array of chosen ids
 * @var array $members
 * @var array $students
 */
$isFull  = (int) ($package['is_full_access'] ?? 0) === 1;
$isFree  = (int) ($package['is_free'] ?? 0) === 1;
$kinds   = [
    'qbank_subject'    => ['بانک سوال', 'درس‌های بانک سوال که با این پکیج باز می‌شوند'],
    'balin_lesson'     => ['جزیره بالین', 'درس‌های جزیره که با این پکیج باز می‌شوند'],
    'flashcard_course' => ['فلش‌کارت', 'درس‌های فلش‌کارت که با این پکیج باز می‌شوند'],
];
$itemTotal = array_sum(array_map('count', $chosen));
?>
<div class="card">
    <div class="card-head">
        <div>
            <h3 class="card-title" style="margin:0;">
                <?= e($package['title']) ?>
                <?php if ($isFull): ?><span class="stat-chip chip-green">پکیج کامل</span><?php endif; ?>
                <?php if ($isFree): ?><span class="stat-chip chip-blue">رایگان برای همه</span><?php endif; ?>
            </h3>
            <div class="leaf-meta">
                <?= e(fa((string) count($courses))) ?> دوره ·
                <?= e(fa((string) $itemTotal)) ?> محتوای دیگر ·
                افزودن خودکار: <?= (int) $package['auto_grant_new_courses'] === 1 ? 'روشن' : 'خاموش' ?>
            </div>
        </div>
        <a class="btn btn-ghost btn-sm" href="/admin/packages">بازگشت</a>
    </div>

    <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>" class="filters">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <input class="input" name="title" value="<?= e($package['title']) ?>" required>
        <select class="input" name="status">
            <?php foreach (['published' => 'فعال', 'draft' => 'پیش‌نویس', 'archived' => 'بایگانی'] as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $package['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="input" name="description" placeholder="توضیح" value="<?= e($package['description'] ?? '') ?>">
        <input class="input" type="color" name="color" value="<?= e($package['color'] ?? '#7c6cf3') ?>" style="max-width:70px;">
        <label class="switch-row" style="border:0; padding:0;">
            <input type="checkbox" name="auto_grant_new_courses" value="1"
                <?= (int) $package['auto_grant_new_courses'] === 1 ? 'checked' : '' ?>>
            <span>افزودن خودکار</span>
        </label>
        <label class="switch-row" style="border:0; padding:0;">
            <input type="checkbox" name="is_full_access" value="1" <?= $isFull ? 'checked' : '' ?>>
            <span>پکیج کامل (فول آپشن)</span>
        </label>
        <label class="switch-row" style="border:0; padding:0;">
            <input type="checkbox" name="is_free" value="1" <?= $isFree ? 'checked' : '' ?>>
            <span>رایگان برای همه</span>
        </label>
        <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
    </form>

    <?php $pkgModules = \HeleXa\Services\StudentTypes::decodeModules($package['modules'] ?? '[]'); ?>
    <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>/modules" class="pk-sections" id="sections">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <div class="pk-sections-head">
            <b>بخش‌هایی که این پکیج روشن می‌کند</b>
            <small><?= $isFull
                ? 'پکیج کامل است: همه بخش‌ها و همه محتوا (حتی آنچه بعداً اضافه شود) برای دارندگانش باز است.'
                : 'این بخش‌ها برای دارنده پکیج روشن می‌شوند، حتی اگر نوع دانشجویی‌اش آن‌ها را نداشته باشد. بخشی که در کل سایت خاموش است روشن نمی‌شود.' ?></small>
        </div>
        <div class="pk-sections-grid">
            <?php foreach (\HeleXa\Services\Modules::CATALOG as $key => [$label, , $ic, $tone]): ?>
                <label class="pk-sec<?= $isFull ? ' is-locked' : '' ?>">
                    <input type="checkbox" name="modules[]" value="<?= e($key) ?>" <?= $isFull || in_array($key, $pkgModules, true) ? 'checked' : '' ?> <?= $isFull ? 'disabled' : '' ?>>
                    <span class="app-ic tone-<?= e($tone) ?>"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => $ic, 'size' => 16]); ?></span>
                    <span><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <?php if (!$isFull): ?><button class="btn btn-primary btn-sm" type="submit">ذخیره بخش‌ها</button><?php endif; ?>
    </form>

    <?php if ($isFree): ?>
        <div class="qb-hint" style="margin:10px 0 0; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
            <span>
                این پکیج رایگان است: هر دانشجوی تازه (ثبت‌نام خودش یا توسط مدیر) خودکار آن را می‌گیرد.
                اگر محتوایش را عوض کرده‌اید، این دکمه آن را به همه دانشجویان فعلی هم می‌رساند.
            </span>
            <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>/everyone" style="margin:0;"
                  data-confirm="این پکیج برای همه دانشجویان فعال فعال یا به‌روز شود؟">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button class="btn btn-primary btn-sm" type="submit" data-lock-on-submit>فعال برای همه دانشجویان فعلی</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($isFull): ?>
        <p class="qb-hint" style="margin:10px 0 0;">
            این پکیج همه دوره‌ها، همه درس‌های بانک سوال، همه درس‌های جزیره بالین و همه فلش‌کارت‌ها را باز می‌کند —
            حتی محتوایی که بعداً اضافه شود. لازم نیست چیزی را دستی انتخاب کنید؛ فقط آن را با تاریخ دلخواه
            برای دانشجو فعال کنید.
        </p>
    <?php endif; ?>
</div>

<?php /* ===================================== everything that is not a course */ ?>
<div class="card" id="contents" style="margin-top:16px;">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">محتوای پکیج (به جز دوره‌ها)</h3>
    </div>

    <?php if ($isFull): ?>
        <div class="empty">پکیج کامل همه چیز را شامل می‌شود؛ انتخاب جداگانه لازم نیست.</div>
    <?php else: ?>
        <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>/items">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <?php foreach ($kinds as $type => [$label, $hint]): ?>
                <?php $rows = $catalogue[$type] ?? []; $picked = $chosen[$type] ?? []; ?>
                <h4 style="margin:16px 0 6px; font-size:14px;"><?= e($label) ?></h4>
                <?php if ($rows === []): ?>
                    <div class="empty">محتوایی برای انتخاب نیست (یا این بخش هنوز نصب نشده).</div>
                <?php else: ?>
                    <p class="qb-hint" style="margin:0 0 8px;"><?= e($hint) ?></p>
                    <div class="qb-check-grid">
                        <?php foreach ($rows as $row): ?>
                            <label class="qb-check">
                                <input type="checkbox" name="<?= e($type) ?>[]" value="<?= (int) $row['id'] ?>"
                                       <?= in_array((int) $row['id'], $picked, true) ? 'checked' : '' ?>>
                                <span><?= e(trim((string) ($row['icon'] ?? '') . ' ' . $row['title'])) ?>
                                    <?php if (($row['status'] ?? 'published') !== 'published'): ?><small>منتشر نشده</small><?php endif; ?>
                                    <?php if (isset($row['is_active']) && (int) $row['is_active'] !== 1): ?><small>غیرفعال</small><?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="row-actions" style="margin-top:14px;">
                <button class="btn btn-primary btn-sm" type="submit" data-lock-on-submit>ذخیره محتوای پکیج</button>
            </div>
            <p class="qb-hint" style="margin-top:8px;">
                تغییر این فهرست، دسترسی دانشجویانی که پکیج را قبلاً گرفته‌اند تغییر نمی‌دهد؛
                برای آن‌ها پکیج را دوباره فعال کنید.
            </p>
        </form>
    <?php endif; ?>
</div>

<div class="grid grid-2" style="margin-top:16px;">
    <div class="card">
        <h3 class="card-title">دوره‌های داخل پکیج</h3>

        <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>/courses" class="filters">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <select class="input" name="course_id" required>
                <option value="">انتخاب دوره</option>
                <?php foreach ($available as $course): ?>
                    <option value="<?= (int) $course['id'] ?>"><?= e($course['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm" type="submit">افزودن</button>
        </form>

        <?php if ($courses === []): ?>
            <div class="empty">هنوز دوره‌ای داخل این پکیج نیست.</div>
        <?php else: ?>
            <div class="tree">
                <?php foreach ($courses as $course): ?>
                    <div class="tree-leaf">
                        <span class="leaf-icon">▤</span>
                        <div style="min-width:0; flex:1;">
                            <div class="leaf-title"><?= e($course['title']) ?></div>
                            <div class="leaf-meta"><?= e(jdate($course['added_at'])) ?></div>
                        </div>
                        <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>/courses/<?= (int) $course['id'] ?>/remove"
                              data-confirm="این دوره از پکیج حذف شود؟ دسترسی دانشجویان فعلی تغییر نمی‌کند." style="margin:0;">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 class="card-title">فعال‌سازی برای دانشجو</h3>

        <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>/activate" class="filters">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <select class="input" name="student_uuid" required style="min-width:200px;">
                <option value="">انتخاب دانشجو</option>
                <?php foreach ($students as $student): ?>
                    <option value="<?= e($student['uuid']) ?>"><?= e($student['full_name']) ?> — <?= e($student['username']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="label" style="margin:0;">از</label>
            <input class="input" type="date" name="starts_at" dir="ltr">
            <label class="label" style="margin:0;">تا</label>
            <input class="input" type="date" name="ends_at" dir="ltr">
            <button class="btn btn-primary btn-sm" type="submit">فعال‌سازی</button>
        </form>

        <?php /* A whole group or term at once. */ ?>
        <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>/activate-group" class="filters pkg-group-form"
              data-confirm="این پکیج برای همه دانشجویان فعال این گروه/ترم فعال شود؟">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <strong style="flex-basis:100%; font-size:13px;">👥 فعال‌سازی گروهی</strong>
            <select class="input" name="group_id" style="min-width:160px;">
                <option value="">یک گروه…</option>
                <?php foreach ($groups ?? [] as $g): ?>
                    <option value="<?= (int) $g['id'] ?>"><?= e(($g['term_title'] ?? '') . ' — ' . $g['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <span style="align-self:center; color:var(--ink-3); font-size:12px;">یا</span>
            <select class="input" name="term_id" style="min-width:140px;">
                <option value="">کل یک ترم…</option>
                <?php foreach ($terms ?? [] as $term): ?>
                    <option value="<?= (int) $term['id'] ?>"><?= e($term['title'] . (!empty($term['major_title']) ? ' (' . $term['major_title'] . ')' : '')) ?></option>
                <?php endforeach; ?>
            </select>
            <input class="input" type="date" name="starts_at" dir="ltr" title="از تاریخ">
            <input class="input" type="date" name="ends_at" dir="ltr" title="تا تاریخ">
            <button class="btn btn-primary btn-sm" type="submit" data-lock-on-submit>فعال برای همه</button>
        </form>

        <?php if ($members === []): ?>
            <div class="empty">هنوز برای کسی فعال نشده است.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>دانشجو</th><th>وضعیت</th><th>دوره‌ها</th><th>پایان</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($members as $member): ?>
                        <tr>
                            <td><?= e($member['full_name']) ?><br><span class="mono" style="color:var(--ink-3)"><?= e($member['username']) ?></span></td>
                            <td>
                                <span class="stat-chip <?= $member['status'] === 'active' ? 'chip-green' : 'chip-gray' ?>">
                                    <?= e($member['status']) ?>
                                </span>
                            </td>
                            <td><?= e(fa((string) $member['course_count'])) ?></td>
                            <td><?= e($member['ends_at'] ? jdate($member['ends_at']) : 'بدون محدودیت') ?></td>
                            <td>
                                <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>/members/<?= (int) $member['id'] ?>/status"
                                      style="margin:0;">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="status" value="<?= $member['status'] === 'active' ? 'suspended' : 'active' ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">
                                        <?= $member['status'] === 'active' ? 'تعلیق' : 'فعال‌سازی' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="color:var(--ink-3); font-size:11.5px; margin-top:10px;">
                تعلیق پکیج، دوره‌های داخل آن را هم برای همان دانشجو معلق می‌کند و بلافاصله اثر می‌گذارد.
            </p>
        <?php endif; ?>
    </div>
</div>
