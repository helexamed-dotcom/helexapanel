<?php
/**
 * One competition: its window, its standings, and its rewards.
 *
 * @var array $competition
 * @var array $rewards
 * @var array $standings
 */
$chips = ['active' => 'chip-green', 'scheduled' => 'chip-blue', 'ended' => 'chip-gray', 'cancelled' => 'chip-red'];
$names = ['active' => 'فعال', 'scheduled' => 'زمان‌بندی‌شده', 'ended' => 'پایان‌یافته', 'cancelled' => 'لغو‌شده'];
$rewardStates = ['draft' => 'پیش‌نویس', 'assigned' => 'تخصیص‌یافته', 'delivered' => 'تحویل‌شده', 'cancelled' => 'لغو‌شده'];
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">🏆 <?= e($competition['title']) ?></h3>
        <a class="btn btn-ghost btn-sm" href="/admin/balin/competitions">بازگشت</a>
    </div>

    <div class="balin-inline-meta">
        <span class="stat-chip <?= e($chips[$competition['status']] ?? 'chip-gray') ?>">
            <?= e($names[$competition['status']] ?? $competition['status']) ?>
        </span>
        <span class="leaf-meta">
            <?= e(jdate((string) $competition['start_date'])) ?> تا <?= e(jdate((string) $competition['end_date'])) ?>
        </span>
    </div>

    <form method="post" action="/admin/balin/competitions/<?= e($competition['uuid']) ?>" class="form-grid">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <label class="field span-2"><span>عنوان *</span>
            <input type="text" name="title" required maxlength="191" value="<?= e($competition['title']) ?>"></label>
        <label class="field"><span>شروع *</span>
            <input type="datetime-local" name="start_date" required
                   value="<?= e(date('Y-m-d\TH:i', strtotime((string) $competition['start_date']))) ?>"></label>
        <label class="field"><span>پایان *</span>
            <input type="datetime-local" name="end_date" required
                   value="<?= e(date('Y-m-d\TH:i', strtotime((string) $competition['end_date']))) ?>"></label>
        <label class="field span-2"><span>توضیح</span>
            <textarea name="description" rows="2"><?= e((string) $competition['description']) ?></textarea></label>
        <label class="field"><span>نمایش جدول</span>
            <select name="leaderboard_visibility">
                <?php foreach (['public' => 'عمومی', 'participants' => 'فقط شرکت‌کنندگان', 'admins' => 'فقط مدیران'] as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $competition['leaderboard_visibility'] === $key ? 'selected' : '' ?>>
                        <?= e($label) ?></option>
                <?php endforeach; ?>
            </select></label>
        <div class="form-actions span-2"><button class="btn btn-primary btn-sm" type="submit">ذخیره</button></div>
    </form>

    <div class="balin-shortcuts">
        <?php foreach (['active' => 'فعال‌سازی', 'ended' => 'پایان دادن', 'cancelled' => 'لغو'] as $status => $label): ?>
            <?php if ($competition['status'] === $status) { continue; } ?>
            <form method="post" action="/admin/balin/competitions/<?= e($competition['uuid']) ?>/status">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <input type="hidden" name="status" value="<?= e($status) ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= e($label) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <h3 class="card-title">جدول هفتگی</h3>
    <?php if ($standings['rows'] === []): ?>
        <div class="empty">هنوز امتیازی در این رقابت ثبت نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>رتبه</th><th>دانشجو</th><th>امتیاز هفتگی</th><th>شناسه</th></tr></thead>
                <tbody>
                <?php foreach ($standings['rows'] as $row): ?>
                    <tr>
                        <td><?= e(fa((int) $row['rank_position'])) ?></td>
                        <td><?= e($row['full_name']) ?></td>
                        <td><?= e(fa(round((float) $row['score']))) ?></td>
                        <td class="leaf-meta"><?= e(fa((int) $row['user_id'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if (can('balin.manage_rewards')): ?>
<div class="card">
    <h3 class="card-title">جوایز</h3>

    <?php if ($rewards === []): ?>
        <div class="empty">جایزه‌ای تعریف نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>عنوان</th><th>رتبه</th><th>برنده</th><th>وضعیت</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rewards as $reward): ?>
                    <tr>
                        <td><?= e($reward['title']) ?></td>
                        <td><?= $reward['rank_position'] === null ? '—' : e(fa((int) $reward['rank_position'])) ?></td>
                        <td><?= e($reward['winner_name'] ?? '—') ?></td>
                        <td><?= e($rewardStates[$reward['status']] ?? $reward['status']) ?></td>
                        <td class="row-actions">
                            <details>
                                <summary class="btn btn-ghost btn-sm">تخصیص</summary>
                                <form method="post" action="/admin/balin/competitions/<?= e($competition['uuid']) ?>/rewards/assign"
                                      class="form-grid">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="reward_id" value="<?= (int) $reward['id'] ?>">
                                    <label class="field"><span>شناسه کاربر برنده</span>
                                        <input type="number" name="winner_user_id" min="1"
                                               value="<?= $reward['winner_user_id'] === null ? '' : (int) $reward['winner_user_id'] ?>"></label>
                                    <label class="field"><span>وضعیت</span>
                                        <select name="status">
                                            <?php foreach ($rewardStates as $key => $label): ?>
                                                <option value="<?= e($key) ?>" <?= $reward['status'] === $key ? 'selected' : '' ?>>
                                                    <?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select></label>
                                    <label class="field span-2"><span>یادداشت</span>
                                        <input type="text" name="admin_note" maxlength="255" value="<?= e((string) $reward['admin_note']) ?>"></label>
                                    <div class="form-actions span-2">
                                        <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
                                    </div>
                                </form>
                                <form method="post" action="/admin/balin/competitions/<?= e($competition['uuid']) ?>/rewards/delete"
                                      data-confirm="این جایزه حذف شود؟">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="reward_id" value="<?= (int) $reward['id'] ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/balin/competitions/<?= e($competition['uuid']) ?>/rewards" class="form-grid balin-inline-form">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <label class="field"><span>عنوان جایزه *</span><input type="text" name="title" required maxlength="191"></label>
        <label class="field"><span>رتبه</span><input type="number" name="rank_position" min="1" max="100"></label>
        <label class="field span-2"><span>توضیح</span><input type="text" name="description" maxlength="255"></label>
        <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">افزودن جایزه</button></div>
    </form>
</div>
<?php endif; ?>

<?php if (can('balin.manage_competition')): ?>
    <div class="card danger-zone">
        <h3 class="card-title">حذف رقابت</h3>
        <form method="post" action="/admin/balin/competitions/<?= e($competition['uuid']) ?>/delete"
              data-confirm="این رقابت و امتیازهای هفتگی آن حذف شود؟ امتیاز کل دانشجویان دست‌نخورده می‌ماند.">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <button class="btn btn-danger btn-sm" type="submit">حذف رقابت</button>
        </form>
    </div>
<?php endif; ?>
