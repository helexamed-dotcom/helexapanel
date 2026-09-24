<?php
/**
 * @var array $schedules
 * @var array $terms
 * @var array $groups
 */
?>
<div class="card">
    <h3 class="card-title">ساخت برنامه هفتگی</h3>
    <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 14px; line-height:2;">
        هر برنامه به یک ترم تعلق دارد. اگر گروه را خالی بگذارید، برنامه برای کل ترم است؛ اگر گروه مشخص کنید،
        برنامه همان گروه است. دانشجو برنامه <strong>همه گروه‌های</strong> ترم‌هایی که در آن‌ها ثبت شده را می‌بیند و
        از پروفایلش مشخص می‌کند هر درس را از کدام گروه برداشته؛ تا وقتی انتخاب نکرده، برنامه گروه خودش نمایش داده می‌شود.
    </p>
    <form method="post" action="/admin/schedule" class="filters">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <input class="input" name="title" placeholder="عنوان، مثلاً برنامه ترم ۵ — گروه ۲۳" required>
        <select class="input" name="term_id" required>
            <option value="">ترم</option>
            <?php foreach ($terms as $term): ?>
                <option value="<?= (int) $term['id'] ?>"><?= e($term['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="input" name="group_id">
            <option value="">کل ترم</option>
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>"><?= e($group['term_title']) ?> — <?= e($group['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="input" name="academic_year" placeholder="۱۴۰۵-۱۴۰۶" style="max-width:130px;">
        <label class="switch-row" style="border:0; padding:0;">
            <input type="checkbox" name="is_active" value="1" checked><span>فعال</span>
        </label>
        <button class="btn btn-primary btn-sm" type="submit">ساخت برنامه</button>
    </form>

    <?php if ($schedules === []): ?>
        <div class="empty">هنوز برنامه‌ای ساخته نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>عنوان</th><th>دامنه</th><th>سال</th><th>جلسات</th><th>وضعیت</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($schedules as $row): ?>
                    <tr>
                        <td><a href="/admin/schedule/<?= (int) $row['id'] ?>"><?= e($row['title']) ?></a></td>
                        <td><?= e($row['term_title']) ?><?= $row['group_title'] ? ' / ' . e($row['group_title']) : ' / کل ترم' ?></td>
                        <td><?= e($row['academic_year'] ? fa((string) $row['academic_year']) : '—') ?></td>
                        <td><?= e(fa((string) $row['item_count'])) ?></td>
                        <td>
                            <span class="stat-chip <?= (int) $row['is_active'] === 1 ? 'chip-green' : 'chip-gray' ?>">
                                <?= (int) $row['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
                            </span>
                        </td>
                        <td class="row-actions">
                            <a class="btn btn-primary btn-sm" href="/admin/schedule/<?= (int) $row['id'] ?>">ویرایش و جلسات</a>
                            <form method="post" action="/admin/schedule/<?= (int) $row['id'] ?>/delete"
                                  data-confirm="این برنامه و همه جلساتش حذف شود؟ انتخاب دانشجویان از این برنامه هم پاک می‌شود.">
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