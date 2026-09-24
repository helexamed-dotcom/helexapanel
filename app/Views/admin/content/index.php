<?php
/**
 * Every lesson in the system, across every course.
 *
 * The page this fills was linked from the sidebar but never existed, so an
 * admin looking for one lesson had to remember its course first. The filter
 * row is a GET form on purpose: a filtered view is then a URL that can be
 * bookmarked, shared, and reloaded without a re-POST prompt.
 */
$statusChips  = ['published' => 'chip-green', 'draft' => 'chip-gray', 'hidden' => 'chip-amber'];
$statusLabels = ['published' => 'منتشرشده', 'draft' => 'پیش‌نویس', 'hidden' => 'پنهان'];
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">محتوای آموزشی</h3>
        <span class="leaf-meta"><?= e(fa((string) count($contents))) ?> مورد</span>
    </div>

    <form method="get" action="/admin/content" class="filter-row"
          style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px;">
        <div class="field" style="margin:0; flex:1 1 220px;">
            <label class="label" for="f-q">جستجو</label>
            <input class="input" id="f-q" type="search" name="q" value="<?= e($filters['q']) ?>"
                   placeholder="عنوان محتوا، توضیح یا نام دوره…">
        </div>

        <div class="field" style="margin:0; flex:0 1 200px;">
            <label class="label" for="f-course">دوره</label>
            <select class="input" id="f-course" name="course_id">
                <option value="">همه دوره‌ها</option>
                <?php foreach ($courses as $course): ?>
                    <option value="<?= (int) $course['id'] ?>"
                        <?= (int) $filters['course_id'] === (int) $course['id'] ? 'selected' : '' ?>>
                        <?= e($course['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="margin:0; flex:0 1 160px;">
            <label class="label" for="f-type">نوع</label>
            <select class="input" id="f-type" name="type">
                <option value="">همه انواع</option>
                <?php foreach ($typeLabels as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $filters['type'] === $key ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="margin:0; flex:0 1 150px;">
            <label class="label" for="f-status">وضعیت</label>
            <select class="input" id="f-status" name="status">
                <option value="">همه</option>
                <?php foreach ($statusLabels as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="row-actions" style="margin:0;">
            <button class="btn btn-primary btn-sm" type="submit">اعمال</button>
            <a class="btn btn-ghost btn-sm" href="/admin/content">پاک کردن</a>
        </div>
    </form>

    <?php if ($contents === []): ?>
        <div class="empty">
            <?php if ($filters['q'] !== '' || $filters['course_id'] || $filters['type'] !== '' || $filters['status'] !== ''): ?>
                هیچ محتوایی با این فیلترها پیدا نشد.
            <?php else: ?>
                هنوز محتوایی بارگذاری نشده است. از صفحه یک دوره، بخش «ساختار و محتوا» شروع کنید.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>عنوان</th><th>دوره</th><th>نوع</th><th>وضعیت</th><th>آخرین تغییر</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($contents as $item): ?>
                    <tr>
                        <td>
                            <?= e($item['title']) ?>
                            <?php if (!empty($item['section_title'])): ?>
                                <div class="leaf-meta" style="font-size:11.5px;">
                                    در بخش: <?= e($item['section_title']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="/admin/courses/<?= e($item['course_uuid']) ?>/builder">
                                <?= e($item['course_title']) ?>
                            </a>
                        </td>
                        <td><?= e($typeLabels[$item['content_type']] ?? $item['content_type']) ?></td>
                        <td>
                            <span class="stat-chip <?= e($statusChips[$item['status']] ?? 'chip-gray') ?>">
                                <?= e($statusLabels[$item['status']] ?? $item['status']) ?>
                            </span>
                        </td>
                        <td><?= e(jdate($item['updated_at'] ?? $item['created_at'])) ?></td>
                        <td class="row-actions">
                            <a class="btn btn-ghost btn-sm" href="/content/<?= e($item['uuid']) ?>">پیش‌نمایش</a>
                            <a class="btn btn-primary btn-sm"
                               href="/admin/courses/<?= e($item['course_uuid']) ?>/contents/<?= e($item['uuid']) ?>/edit">ویرایش</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
