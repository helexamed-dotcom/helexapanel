<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">دوره‌ها</h3>
        <a class="btn btn-primary btn-sm" href="/admin/courses/create">+ افزودن دوره</a>
    </div>

    <?php if ($courses === []): ?>
        <div class="empty">هنوز دوره‌ای ساخته نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>عنوان</th><th>وضعیت</th><th>محتوا</th><th>دانشجو</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($courses as $course): ?>
                    <tr>
                        <td>
                            <?= e($course['title']) ?>
                            <div class="mono" style="color:var(--ink-3); font-size:11.5px;"><?= e($course['slug']) ?></div>
                        </td>
                        <td>
                            <?php $chips = ['published' => 'chip-green', 'draft' => 'chip-gray', 'archived' => 'chip-red']; ?>
                            <span class="stat-chip <?= e($chips[$course['status']] ?? 'chip-gray') ?>">
                                <?= e(['published' => 'منتشرشده', 'draft' => 'پیش‌نویس', 'archived' => 'بایگانی'][$course['status']] ?? $course['status']) ?>
                            </span>
                        </td>
                        <td><?= e(fa((string) $course['content_count'])) ?></td>
                        <td><?= e(fa((string) $course['student_count'])) ?></td>
                        <td class="row-actions">
                            <?php if (can('manage_content')): ?>
                                <a class="btn btn-primary btn-sm" href="/admin/courses/<?= e($course['uuid']) ?>/builder">ساختار و محتوا</a>
                            <?php endif; ?>
                            <a class="btn btn-ghost btn-sm" href="/admin/courses/<?= e($course['uuid']) ?>/students">دانشجویان</a>
                            <a class="btn btn-ghost btn-sm" href="/admin/courses/<?= e($course['uuid']) ?>/edit">ویرایش</a>
                            <form method="post" action="/admin/courses/<?= e($course['uuid']) ?>/delete"
                                  data-confirm="این دوره حذف شود؟ دسترسی دانشجویان بلافاصله قطع می‌شود.">
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
