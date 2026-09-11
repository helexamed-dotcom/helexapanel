<?php
/**
 * @var array  $competitions
 * @var ?array $current
 */
$chips = ['active' => 'chip-green', 'scheduled' => 'chip-blue', 'ended' => 'chip-gray', 'cancelled' => 'chip-red'];
$names = ['active' => 'فعال', 'scheduled' => 'زمان‌بندی‌شده', 'ended' => 'پایان‌یافته', 'cancelled' => 'لغو‌شده'];
?>
<div class="card">
    <div class="card-head"><h3 class="card-title" style="margin:0;">رقابت هفتگی</h3></div>

    <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 14px;">
        هم‌زمان فقط یک رقابت می‌تواند فعال باشد. امتیاز هفتگی جدا از امتیاز کل محاسبه می‌شود و
        فقط شامل امتیازهایی است که در بازه همین رقابت کسب شده‌اند.
    </p>

    <?php if ($current !== null): ?>
        <div class="balin-inline-meta">
            <span class="stat-chip chip-green">در جریان</span>
            <strong><?= e($current['title']) ?></strong>
            <span class="leaf-meta">تا <?= e(jdate((string) $current['end_date'])) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($competitions === []): ?>
        <div class="empty">هنوز رقابتی تعریف نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>عنوان</th><th>بازه</th><th>وضعیت</th><th>شرکت‌کننده</th><th>جایزه</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($competitions as $competition): ?>
                    <tr>
                        <td><?= e($competition['title']) ?></td>
                        <td>
                            <?= e(jdate((string) $competition['start_date'])) ?>
                            تا <?= e(jdate((string) $competition['end_date'])) ?>
                        </td>
                        <td>
                            <span class="stat-chip <?= e($chips[$competition['status']] ?? 'chip-gray') ?>">
                                <?= e($names[$competition['status']] ?? $competition['status']) ?>
                            </span>
                        </td>
                        <td><?= e(fa((int) $competition['participant_count'])) ?></td>
                        <td><?= e(fa((int) $competition['reward_count'])) ?></td>
                        <td class="row-actions">
                            <a class="btn btn-primary btn-sm" href="/admin/balin/competitions/<?= e($competition['uuid']) ?>">مدیریت</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">رقابت جدید</h3>
    <form method="post" action="/admin/balin/competitions" class="form-grid">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <label class="field span-2"><span>عنوان *</span><input type="text" name="title" required maxlength="191"></label>
        <label class="field"><span>شروع *</span><input type="datetime-local" name="start_date" required></label>
        <label class="field"><span>پایان *</span><input type="datetime-local" name="end_date" required></label>
        <label class="field span-2"><span>توضیح</span><textarea name="description" rows="2"></textarea></label>
        <label class="field"><span>نمایش جدول</span>
            <select name="leaderboard_visibility">
                <option value="public">عمومی</option>
                <option value="participants">فقط شرکت‌کنندگان</option>
                <option value="admins">فقط مدیران</option>
            </select></label>
        <div class="form-actions span-2"><button class="btn btn-primary" type="submit">ساخت رقابت</button></div>
    </form>
</div>
