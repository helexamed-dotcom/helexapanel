<div class="card">
    <h3 class="card-title">افزودن رویداد به تقویم</h3>
    <form method="post" action="/admin/calendar" class="filters">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <input class="input" name="title" placeholder="عنوان رویداد" required>
        <select class="input" name="event_type">
            <?php foreach (['event' => 'رویداد', 'holiday' => 'تعطیلی', 'deadline' => 'مهلت', 'reminder' => 'یادآوری', 'custom' => 'سایر'] as $key => $label): ?>
                <option value="<?= e($key) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="input" type="date" name="event_date" dir="ltr" required style="max-width:170px;">
        <select class="input" name="term_id">
            <option value="">همه ترم‌ها</option>
            <?php foreach ($terms as $term): ?>
                <option value="<?= (int) $term['id'] ?>"><?= e($term['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="input" name="group_id">
            <option value="">همه گروه‌ها</option>
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>"><?= e($group['term_title']) ?> — <?= e($group['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="input" name="description" placeholder="توضیح کوتاه">
        <button class="btn btn-primary btn-sm" type="submit">افزودن</button>
    </form>

    <?php if ($events === []): ?>
        <div class="empty">رویدادی ثبت نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>عنوان</th><th>نوع</th><th>تاریخ</th><th>دامنه</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td><?= e($event['title']) ?></td>
                        <td class="mono"><?= e($event['event_type']) ?></td>
                        <td><?= e(jdate($event['event_date'])) ?></td>
                        <td><?= e($event['term_title'] ?? 'همه') ?><?= $event['group_title'] ? ' / ' . e($event['group_title']) : '' ?></td>
                        <td>
                            <form method="post" action="/admin/calendar/<?= (int) $event['id'] ?>/delete" style="margin:0;">
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
