<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">پکیج‌ها</h3>
        <a class="btn btn-primary btn-sm" href="/admin/packages/create">+ افزودن پکیج</a>
    </div>
    <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 14px;">
        پکیج مجموعه‌ای از دوره‌هاست. با فعال کردن پکیج برای یک دانشجو، همه دوره‌های داخل آن
        یک‌جا برایش فعال می‌شوند و فقط <strong>یک</strong> اطلاعیه دریافت می‌کند، نه یکی به‌ازای هر دوره.
    </p>

    <?php if ($packages === []): ?>
        <div class="empty">هنوز پکیجی ساخته نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>عنوان</th><th>وضعیت</th><th>دوره‌ها</th><th>دانشجویان فعال</th><th>افزودن خودکار</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($packages as $package): ?>
                    <tr>
                        <td>
                            <?= e($package['title']) ?>
                            <?php if ($package['description']): ?>
                                <div class="leaf-meta"><?= e(mb_substr((string) $package['description'], 0, 70, 'UTF-8')) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $chips = ['published' => 'chip-green', 'draft' => 'chip-gray', 'archived' => 'chip-red']; ?>
                            <span class="stat-chip <?= e($chips[$package['status']] ?? 'chip-gray') ?>">
                                <?= e(['published' => 'فعال', 'draft' => 'پیش‌نویس', 'archived' => 'بایگانی'][$package['status']] ?? $package['status']) ?>
                            </span>
                        </td>
                        <td><?= e(fa((string) $package['course_count'])) ?></td>
                        <td><?= e(fa((string) $package['member_count'])) ?></td>
                        <td>
                            <span class="stat-chip <?= (int) $package['auto_grant_new_courses'] === 1 ? 'chip-blue' : 'chip-gray' ?>">
                                <?= (int) $package['auto_grant_new_courses'] === 1 ? 'روشن' : 'خاموش' ?>
                            </span>
                        </td>
                        <td class="row-actions">
                            <a class="btn btn-primary btn-sm" href="/admin/packages/<?= e($package['uuid']) ?>">مدیریت</a>
                            <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>/delete"
                                  data-confirm="این پکیج حذف شود؟ دسترسی دانشجویانی که قبلاً آن را گرفته‌اند دست‌نخورده می‌ماند.">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
