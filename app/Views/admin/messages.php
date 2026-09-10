<div class="card">
    <h3 class="card-title">ارسال پیام</h3>
    <form method="post" action="/admin/messages">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <div class="form-grid">
            <div class="field">
                <label class="label">گیرنده</label>
                <select class="input" name="target">
                    <option value="user">یک دانشجو</option>
                    <option value="group">یک گروه</option>
                    <option value="term">یک ترم</option>
                    <option value="all">همه دانشجویان</option>
                </select>
            </div>
            <div class="field">
                <label class="label">دانشجو</label>
                <select class="input" name="student_uuid">
                    <option value="">—</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= e($student['uuid']) ?>"><?= e($student['full_name']) ?> — <?= e($student['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label">ترم</label>
                <select class="input" name="term_id">
                    <option value="">—</option>
                    <?php foreach ($terms as $term): ?>
                        <option value="<?= (int) $term['id'] ?>"><?= e($term['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label">گروه</label>
                <select class="input" name="group_id">
                    <option value="">—</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>"><?= e($group['term_title']) ?> — <?= e($group['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field">
            <label class="label">موضوع</label>
            <input class="input" name="subject" required>
        </div>
        <div class="field">
            <label class="label">متن پیام</label>
            <textarea class="input" name="body" rows="5" maxlength="5000" required></textarea>
            <div style="color:var(--ink-3); font-size:11.5px; margin-top:4px;">
                متن به‌صورت ساده ذخیره و هنگام نمایش کاملاً escape می‌شود؛ HTML داخل پیام اجرا نمی‌شود.
            </div>
        </div>
        <button class="btn btn-primary" type="submit">ارسال</button>
    </form>
</div>

<div class="card" style="margin-top:16px;">
    <h3 class="card-title">پیام‌های ارسال‌شده</h3>
    <?php if ($messages === []): ?>
        <div class="empty">پیامی ارسال نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>موضوع</th><th>فرستنده</th><th>گیرندگان</th><th>خوانده‌شده</th><th>تاریخ</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($messages as $message): ?>
                    <tr>
                        <td><?= e($message['subject']) ?></td>
                        <td><?= e($message['sender_name'] ?? '—') ?></td>
                        <td><?= e(fa((string) $message['recipients'])) ?></td>
                        <td><?= e(fa((string) $message['read_count'])) ?></td>
                        <td><?= e(jdate($message['created_at'])) ?></td>
                        <td class="row-actions">
                            <a class="btn btn-ghost btn-sm" href="/admin/messages/<?= (int) $message['id'] ?>">گیرندگان</a>
                            <form method="post" action="/admin/messages/<?= (int) $message['id'] ?>/delete"
                                  data-confirm="این پیام برای همه گیرندگان حذف شود؟">
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
