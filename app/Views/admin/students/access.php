<?php
/**
 * Everything one student can open.
 *
 * @var array $student
 * @var array $tier
 * @var array $enrollments  all states
 * @var array $courses
 * @var array $activations  all states
 * @var array $packages
 * @var bool  $balinOn
 * @var array $lessons
 * @var array $openLessons  balin lesson ids this student may open
 * @var array $qbSubjects
 * @var array $qbGranted
 * @var array $fcCourses
 * @var array $fcGranted
 */
$base         = '/admin/students/' . $student['uuid'] . '/access';
$statusLabels = ['active' => 'فعال', 'suspended' => 'معلق', 'expired' => 'منقضی', 'cancelled' => 'لغوشده'];
$statusChips  = ['active' => 'chip-green', 'suspended' => 'chip-amber', 'expired' => 'chip-gray', 'cancelled' => 'chip-red'];
$held         = array_map(static fn ($r) => (int) $r['course_id'], $enrollments);
$heldPkg      = array_map(static fn ($r) => (int) $r['package_id'], $activations);
$icon         = static fn (string $n) => \HeleXa\Core\View::partial('partials.icon', ['name' => $n]);
$date         = static fn (?string $v): string => $v ? jdate($v) : '—';

$canCourses  = can('manage_courses');
$canPackages = can('manage_packages');
$canBalin    = can('balin.manage_students');
$canQbank    = can('qbank.manage_students');
$canCards    = can('flashcards.manage_students');

/** Renders a status dropdown that submits on change. */
$statusForm = static function (string $action, string $current) use ($statusLabels, $csrf_token): void { ?>
    <form method="post" action="<?= e($action) ?>" style="margin:0;">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <select class="input" name="status" data-auto-submit aria-label="وضعیت" style="min-width:110px;">
            <?php foreach ($statusLabels as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $current === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-ghost btn-sm" type="submit">اعمال</button></noscript>
    </form>
<?php };
?>
<div class="qb-page">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="avatar avatar-lg"><?php \HeleXa\Core\View::partial('partials.avatar', ['person' => $student]); ?></div>
            <div>
                <h2><?= e($student['full_name']) ?>
                    <span class="tier-badge tier-<?= e($tier['tier']) ?>"><?= e($tier['icon'] . ' ' . $tier['label']) ?></span>
                </h2>
                <p><span class="mono"><?= e($student['username']) ?></span> — همه دسترسی‌های این دانشجو در یک صفحه</p>
            </div>
        </div>
        <div class="qb-hero-actions">
            <a class="btn btn-ghost" href="/admin/students/<?= e($student['uuid']) ?>/edit">ویرایش مشخصات</a>
            <a class="btn btn-ghost" href="/admin/students">فهرست دانشجویان</a>
        </div>
    </section>

    <?php \HeleXa\Core\View::partial('partials.tier_card', ['tier' => $tier, 'self' => false]); ?>

    <?php $sections = \HeleXa\Services\AccessProfile::explain($student); ?>
    <section class="qb-section" id="sections">
        <div class="qb-section-head">
            <h3><?php $icon('grid'); ?> بخش‌های فعال برای این دانشجو</h3>
            <a class="btn btn-ghost btn-sm" href="/admin/access-matrix">نقشه دسترسی ←</a>
        </div>
        <div class="am-probe">
            <?php foreach ($sections as $s): ?>
                <div class="am-probe-row<?= $s['on'] ? ' is-on' : '' ?>">
                    <span class="app-ic tone-<?= e($s['tone']) ?>"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => $s['icon'], 'size' => 14]); ?></span>
                    <b><?= e($s['label']) ?></b>
                    <em><?= $s['on'] ? 'روشن' : 'خاموش' ?></em>
                    <small><?= e($s['why']) ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php /* ========================================================= courses */ ?>
    <section class="qb-section" id="courses">
        <div class="qb-section-head"><h3><?php $icon('book'); ?> دوره‌ها</h3></div>
        <?php if ($enrollments === []): ?>
            <div class="empty">هیچ دوره‌ای برای این دانشجو ثبت نشده است.</div>
        <?php else: ?>
            <div class="qb-list">
                <?php foreach ($enrollments as $row): ?>
                    <div class="qb-row">
                        <div>
                            <div class="qb-row-stem" style="margin:0 0 4px; font-weight:600;"><?= e($row['course_title']) ?></div>
                            <div class="qb-row-meta">
                                <span class="stat-chip <?= e($statusChips[$row['status']] ?? 'chip-gray') ?>"><?= e($statusLabels[$row['status']] ?? $row['status']) ?></span>
                                <?php if ($row['status'] === 'active' && (int) $row['is_live'] !== 1): ?>
                                    <span class="qb-warn">خارج از بازه تاریخ — محتوا باز نمی‌شود</span>
                                <?php endif; ?>
                                <span>از <?= e($date($row['starts_at'])) ?> تا <?= e($date($row['ends_at'])) ?></span>
                                <?php if ($row['course_status'] !== 'published'): ?><span class="qb-warn">دوره منتشر نشده</span><?php endif; ?>
                            </div>
                        </div>
                        <?php if ($canCourses): ?>
                            <div class="qb-row-actions">
                                <?php $statusForm($base . '/course/' . $row['course_uuid'] . '/status', (string) $row['status']); ?>
                                <form method="post" action="<?= e($base . '/course/' . $row['course_uuid'] . '/remove') ?>"
                                      data-confirm="دسترسی این دوره کاملاً حذف شود؟">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($canCourses): ?>
            <form method="post" action="<?= e($base) ?>/course" class="qb-inline-form" style="margin-top:14px;">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <div class="field">
                    <label class="label" for="ac-course">افزودن دوره</label>
                    <select class="input" id="ac-course" name="course" required>
                        <option value="">انتخاب دوره…</option>
                        <?php foreach ($courses as $course): ?>
                            <?php if (!in_array((int) $course['id'], $held, true)): ?>
                                <option value="<?= e($course['uuid']) ?>"><?= e($course['title']) ?><?= $course['status'] !== 'published' ? ' (منتشرنشده)' : '' ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field narrow" style="flex-basis:150px;"><label class="label" for="ac-s">از تاریخ (اختیاری)</label>
                    <input class="input" id="ac-s" type="date" name="starts_at" dir="ltr"></div>
                <div class="field narrow" style="flex-basis:150px;"><label class="label" for="ac-e">تا تاریخ (اختیاری)</label>
                    <input class="input" id="ac-e" type="date" name="ends_at" dir="ltr"></div>
                <button class="btn btn-primary btn-sm" type="submit" data-lock-on-submit>فعال کردن</button>
            </form>
        <?php endif; ?>
    </section>

    <?php /* ======================================================== packages */ ?>
    <section class="qb-section" id="packages">
        <div class="qb-section-head"><h3><?php $icon('package'); ?> پکیج‌ها</h3></div>
        <?php if ($activations === []): ?>
            <div class="empty">هیچ پکیجی برای این دانشجو فعال نشده است.</div>
        <?php else: ?>
            <div class="qb-list">
                <?php foreach ($activations as $row): ?>
                    <div class="qb-row">
                        <div>
                            <div class="qb-row-stem" style="margin:0 0 4px; font-weight:600;"><?= e($row['package_title']) ?></div>
                            <div class="qb-row-meta">
                                <span class="stat-chip <?= e($statusChips[$row['status']] ?? 'chip-gray') ?>"><?= e($statusLabels[$row['status']] ?? $row['status']) ?></span>
                                <?php if ($row['status'] === 'active' && (int) $row['is_live'] !== 1): ?>
                                    <span class="qb-warn">خارج از بازه تاریخ</span>
                                <?php endif; ?>
                                <span><?= e(fa((string) $row['course_count'])) ?> دوره</span>
                                <span>از <?= e($date($row['starts_at'])) ?> تا <?= e($date($row['ends_at'])) ?></span>
                            </div>
                        </div>
                        <?php if ($canPackages): ?>
                            <div class="qb-row-actions">
                                <?php $statusForm($base . '/package/' . (int) $row['id'] . '/status', (string) $row['status']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="qb-hint" style="margin-top:8px;">تغییر وضعیت پکیج روی همه دوره‌های داخل آن هم اعمال می‌شود.</p>
        <?php endif; ?>

        <?php if ($canPackages): ?>
            <form method="post" action="<?= e($base) ?>/package" class="qb-inline-form" style="margin-top:14px;">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <div class="field">
                    <label class="label" for="ap-pkg">افزودن پکیج</label>
                    <select class="input" id="ap-pkg" name="package" required>
                        <option value="">انتخاب پکیج…</option>
                        <?php foreach ($packages as $package): ?>
                            <?php if (!in_array((int) $package['id'], $heldPkg, true)): ?>
                                <option value="<?= e($package['uuid']) ?>"><?= e($package['title']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field narrow" style="flex-basis:150px;"><label class="label" for="ap-s">از تاریخ</label>
                    <input class="input" id="ap-s" type="date" name="starts_at" dir="ltr"></div>
                <div class="field narrow" style="flex-basis:150px;"><label class="label" for="ap-e">تا تاریخ</label>
                    <input class="input" id="ap-e" type="date" name="ends_at" dir="ltr"></div>
                <button class="btn btn-primary btn-sm" type="submit" data-lock-on-submit>فعال کردن پکیج</button>
            </form>
        <?php endif; ?>
    </section>

    <?php /* =========================================================== balin */ ?>
    <form method="post" action="<?= e($base) ?>/balin" class="qb-section" id="balin">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <div class="qb-section-head">
            <h3><?php $icon('island'); ?> جزیره بالین</h3>
            <label class="remember-row" style="margin:0;">
                <input type="checkbox" name="enabled" value="1" <?= $balinOn ? 'checked' : '' ?> <?= $canBalin ? '' : 'disabled' ?>>
                <span><strong>دسترسی به جزیره</strong></span>
            </label>
        </div>
        <?php if ($lessons === []): ?>
            <div class="empty">هنوز درس بالینی ساخته نشده است.</div>
        <?php else: ?>
            <p class="qb-hint" style="margin-bottom:10px;">
                هر درسی که تیک بخورد برای این دانشجو باز می‌شود و هر تیکی که برداشته شود بسته؛ پیشرفتش در هر حال حفظ می‌ماند.
                درس‌های تازه تا وقتی این‌جا (یا با یک پکیج) داده نشوند برای کسی باز نمی‌شوند.
            </p>
            <div class="row-actions" style="margin-bottom:10px; gap:8px;">
                <button class="btn btn-ghost btn-sm" type="button" data-check-all="#balin">همه را انتخاب کن</button>
                <button class="btn btn-ghost btn-sm" type="button" data-check-none="#balin">هیچ‌کدام</button>
            </div>
            <div class="qb-check-grid">
                <?php foreach ($lessons as $lesson): ?>
                    <label class="qb-check">
                        <input type="checkbox" name="lessons[]" value="<?= (int) $lesson['id'] ?>"
                               <?= in_array((int) $lesson['id'], $openLessons, true) ? 'checked' : '' ?> <?= $canBalin ? '' : 'disabled' ?>>
                        <span><?= e($lesson['title']) ?>
                            <?php if ($lesson['status'] !== 'published'): ?><small>منتشر نشده</small><?php endif; ?>
                            <?php if (($lesson['access_mode'] ?? 'open') === 'open'): ?><small>برای همه باز</small><?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($canBalin): ?>
            <div class="row-actions" style="margin-top:12px;"><button class="btn btn-primary btn-sm" type="submit" data-lock-on-submit>ذخیره بالین</button></div>
        <?php endif; ?>
    </form>

    <?php /* ================================================== question bank */ ?>
    <form method="post" action="<?= e($base) ?>/qbank" class="qb-section" id="qbank">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <div class="qb-section-head"><h3><?php $icon('qbank'); ?> بانک سوال</h3></div>
        <?php if ($qbSubjects === []): ?>
            <div class="empty">درسی در بانک سوال نیست (یا مهاجرت بانک سوال هنوز اجرا نشده).</div>
        <?php else: ?>
            <div class="row-actions" style="margin-bottom:10px; gap:8px;">
                <button class="btn btn-ghost btn-sm" type="button" data-check-all="#qbank">همه را انتخاب کن</button>
                <button class="btn btn-ghost btn-sm" type="button" data-check-none="#qbank">هیچ‌کدام</button>
            </div>
            <div class="qb-check-grid">
                <?php foreach ($qbSubjects as $s): ?>
                    <label class="qb-check">
                        <input type="checkbox" name="subjects[]" value="<?= (int) $s['id'] ?>"
                               <?= in_array((int) $s['id'], $qbGranted, true) ? 'checked' : '' ?> <?= $canQbank ? '' : 'disabled' ?>>
                        <span><?= e($s['title']) ?><?php if ((int) $s['is_active'] !== 1): ?><small>غیرفعال</small><?php endif; ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if ($canQbank): ?>
                <div class="row-actions" style="margin-top:12px;"><button class="btn btn-primary btn-sm" type="submit" data-lock-on-submit>ذخیره بانک سوال</button></div>
            <?php endif; ?>
        <?php endif; ?>
    </form>

    <?php /* ===================================================== flashcards */ ?>
    <form method="post" action="<?= e($base) ?>/flashcards" class="qb-section" id="flashcards">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <div class="qb-section-head"><h3><?php $icon('cards'); ?> فلش‌کارت</h3></div>
        <?php if ($fcCourses === []): ?>
            <div class="empty">درس فلش‌کارتی نیست (یا مهاجرت فلش‌کارت هنوز اجرا نشده).</div>
        <?php else: ?>
            <div class="row-actions" style="margin-bottom:10px; gap:8px;">
                <button class="btn btn-ghost btn-sm" type="button" data-check-all="#flashcards">همه را انتخاب کن</button>
                <button class="btn btn-ghost btn-sm" type="button" data-check-none="#flashcards">هیچ‌کدام</button>
            </div>
            <div class="qb-check-grid">
                <?php foreach ($fcCourses as $fc): ?>
                    <label class="qb-check">
                        <input type="checkbox" name="courses[]" value="<?= (int) $fc['id'] ?>"
                               <?= in_array((int) $fc['id'], $fcGranted, true) ? 'checked' : '' ?> <?= $canCards ? '' : 'disabled' ?>>
                        <span><?= e(($fc['icon'] ?: '📘') . ' ' . $fc['title']) ?><?php if ($fc['status'] !== 'published'): ?><small>پیش‌نویس</small><?php endif; ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if ($canCards): ?>
                <div class="row-actions" style="margin-top:12px;"><button class="btn btn-primary btn-sm" type="submit" data-lock-on-submit>ذخیره فلش‌کارت</button></div>
            <?php endif; ?>
        <?php endif; ?>
    </form>
</div>
