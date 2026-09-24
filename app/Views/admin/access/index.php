<?php
/**
 * The access desk: every student, and what they can open.
 *
 * Each row is a summary and a way in — the real switches live on the
 * student's own access page, so there is one place where access is changed.
 *
 * @var array $students
 * @var array $summaries  keyed by user id
 * @var array $filters
 * @var array $terms
 * @var array $groups
 * @var int   $total
 * @var \HeleXa\Core\Paginator $paginator
 */
$chip = static function (string $label, int $count, string $tone = ''): void { ?>
    <span class="stat-chip <?= e($count > 0 ? ($tone ?: 'chip-green') : 'chip-gray') ?>">
        <?= e($label) ?> <?= e(fa((string) $count)) ?>
    </span>
<?php };
?>
<div class="card">
    <div class="card-head">
        <div>
            <h3 class="card-title" style="margin:0;">دسترسی‌ها (<?= e(fa((string) $total)) ?> دانشجو)</h3>
            <div class="leaf-meta">
                دوره‌ها، پکیج‌ها، درس‌های جزیره بالین، بانک سوال و فلش‌کارت — همه از یک جا.
            </div>
        </div>
        <a class="btn btn-ghost btn-sm" href="/admin/packages">پکیج‌ها</a>
    </div>

    <form method="get" action="/admin/access" class="filters">
        <input class="input" type="search" name="q" placeholder="نام، نام کاربری یا موبایل"
               value="<?= e($filters['search'] ?? '') ?>">
        <select class="input" name="term_id">
            <option value="">همه ترم‌ها</option>
            <?php foreach ($terms as $term): ?>
                <option value="<?= (int) $term['id'] ?>" <?= (int) ($filters['term_id'] ?? 0) === (int) $term['id'] ? 'selected' : '' ?>>
                    <?= e($term['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="input" name="group_id">
            <option value="">همه گروه‌ها</option>
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>" <?= (int) ($filters['group_id'] ?? 0) === (int) $group['id'] ? 'selected' : '' ?>>
                    <?= e($group['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm" type="submit">فیلتر</button>
        <a class="btn btn-ghost btn-sm" href="/admin/access">پاک کردن</a>
    </form>

    <?php if ($students === []): ?>
        <div class="empty">دانشجویی با این شرایط یافت نشد.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>دانشجو</th><th>دوره‌ها</th><th>پکیج‌ها</th><th>جزیره بالین</th>
                    <th>بانک سوال</th><th>فلش‌کارت</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $student):
                    $sum = $summaries[(int) $student['id']] ?? [
                        'courses' => 0, 'packages' => 0, 'balin' => false,
                        'lessons' => 0, 'qbank' => 0, 'flashcards' => 0, 'full' => false,
                    ];
                ?>
                    <tr>
                        <td>
                            <?= e($student['full_name']) ?>
                            <div class="mono" style="color:var(--ink-3); font-size:11.5px;"><?= e($student['username']) ?></div>
                            <?php if ($sum['full']): ?>
                                <div><span class="stat-chip chip-green">پکیج کامل</span></div>
                            <?php endif; ?>
                        </td>
                        <td><?php $chip('دوره', (int) $sum['courses']); ?></td>
                        <td><?php $chip('پکیج', (int) $sum['packages'], 'chip-blue'); ?></td>
                        <td>
                            <?php if (!$sum['balin']): ?>
                                <span class="stat-chip chip-gray">بسته</span>
                            <?php else: ?>
                                <?php $chip('درس', (int) $sum['lessons'], 'chip-amber'); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php $chip('درس', (int) $sum['qbank'], 'chip-blue'); ?></td>
                        <td><?php $chip('درس', (int) $sum['flashcards'], 'chip-blue'); ?></td>
                        <td>
                            <a class="btn btn-primary btn-sm" href="/admin/students/<?= e($student['uuid']) ?>/access">
                                مدیریت دسترسی
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php \HeleXa\Core\View::partial('partials.pagination', ['paginator' => $paginator]); ?>
    <?php endif; ?>
</div>
