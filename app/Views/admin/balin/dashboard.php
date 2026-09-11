<?php
/**
 * The island's control room.
 *
 * The publication switch is the first thing on the page because it is the
 * only control here that changes what students can see.
 *
 * @var string $status
 * @var array  $totals
 * @var int    $accessible
 * @var array  $log
 * @var array  $settings
 */
use HeleXa\Services\Balin\BalinSettings;

$states = [
    BalinSettings::STATUS_COMING_SOON => ['به‌زودی', 'منو دیده می‌شود، محتوا بسته است و صفحه «به‌زودی» نمایش داده می‌شود.'],
    BalinSettings::STATUS_PUBLISHED   => ['منتشرشده', 'دانشجویانی که دسترسی دارند محتوای واقعی را می‌بینند.'],
    BalinSettings::STATUS_MAINTENANCE => ['در حال به‌روزرسانی', 'برای همه بسته است، حتی کسانی که دسترسی دارند.'],
    BalinSettings::STATUS_DISABLED    => ['غیرفعال', 'گزینه از منوی دانشجو هم حذف می‌شود.'],
];
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">وضعیت انتشار جزیره بالین</h3>
        <span class="stat-chip <?= $status === 'published' ? 'chip-green' : ($status === 'disabled' ? 'chip-red' : 'chip-blue') ?>">
            <?= e($states[$status][0]) ?>
        </span>
    </div>

    <p style="color:var(--ink-3); font-size:12.5px; margin:-6px 0 14px;">
        این تصمیم سمت سرور بررسی می‌شود، نه فقط با پنهان کردن لینک. دانشجویی که آدرس را مستقیم باز کند
        همان چیزی را می‌بیند که از منو می‌بیند.
    </p>

    <?php if (can('balin.publish')): ?>
        <form method="post" action="/admin/balin/status" class="balin-status-form">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

            <div class="balin-status-options">
                <?php foreach ($states as $value => [$label, $help]): ?>
                    <label class="balin-status-option<?= $status === $value ? ' is-current' : '' ?>">
                        <input type="radio" name="status" value="<?= e($value) ?>"
                               <?= $status === $value ? 'checked' : '' ?>>
                        <span>
                            <strong><?= e($label) ?></strong>
                            <span class="leaf-meta"><?= e($help) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <label class="field">
                <span>یادداشت (اختیاری)</span>
                <input type="text" name="note" maxlength="255" placeholder="دلیل این تغییر">
            </label>

            <button class="btn btn-primary" type="submit"
                    data-confirm="وضعیت انتشار جزیره بالین تغییر کند؟">ثبت وضعیت</button>
        </form>
    <?php else: ?>
        <div class="empty">برای تغییر وضعیت انتشار به دسترسی balin.publish نیاز داری.</div>
    <?php endif; ?>
</div>

<div class="stat-grid">
    <div class="stat-card"><span class="stat-value"><?= e(fa((int) ($totals['lessons'] ?? 0))) ?></span><span class="stat-label">درس منتشرشده</span></div>
    <div class="stat-card"><span class="stat-value"><?= e(fa((int) ($totals['stages'] ?? 0))) ?></span><span class="stat-label">مرحله</span></div>
    <div class="stat-card"><span class="stat-value"><?= e(fa((int) ($totals['questions'] ?? 0))) ?></span><span class="stat-label">سؤال</span></div>
    <div class="stat-card"><span class="stat-value"><?= e(fa((int) ($totals['exams'] ?? 0))) ?></span><span class="stat-label">آزمون</span></div>
    <div class="stat-card"><span class="stat-value"><?= e(fa($accessible)) ?></span><span class="stat-label">دانشجوی دارای دسترسی</span></div>
    <div class="stat-card"><span class="stat-value"><?= e(fa((int) ($totals['active_students'] ?? 0))) ?></span><span class="stat-label">دانشجوی فعال</span></div>
    <div class="stat-card"><span class="stat-value"><?= e(fa((int) ($totals['completed_stages'] ?? 0))) ?></span><span class="stat-label">مرحله تکمیل‌شده</span></div>
    <div class="stat-card"><span class="stat-value"><?= e(fa((int) ($totals['total_xp'] ?? 0))) ?></span><span class="stat-label">مجموع امتیاز</span></div>
</div>

<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">میان‌برها</h3>
    </div>
    <div class="balin-shortcuts">
        <a class="btn btn-ghost btn-sm" href="/admin/balin/lessons">درس‌های بالینی</a>
        <?php if (can('balin.manage_skill_tracks')): ?>
            <a class="btn btn-ghost btn-sm" href="/admin/balin/skill-tracks">مهارت‌های بالینی</a>
        <?php endif; ?>
        <?php if (can('balin.manage_characters')): ?>
            <a class="btn btn-ghost btn-sm" href="/admin/balin/characters">شخصیت‌ها</a>
        <?php endif; ?>
        <?php if (can('balin.manage_students')): ?>
            <a class="btn btn-ghost btn-sm" href="/admin/balin/access">دسترسی دانشجویان</a>
        <?php endif; ?>
        <?php if (can('balin.view_statistics')): ?>
            <a class="btn btn-ghost btn-sm" href="/admin/balin/analytics">آمار</a>
        <?php endif; ?>
        <form method="post" action="/admin/balin/leaderboard/rebuild" style="display:inline">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <button class="btn btn-ghost btn-sm" type="submit">بازسازی جدول رتبه‌بندی</button>
        </form>
    </div>
</div>

<?php if (can('balin.manage_settings')): ?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">تنظیمات</h3>
    </div>

    <form method="post" action="/admin/balin/settings" class="form-grid">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

        <label class="field span-2">
            <span>متن صفحه «به‌زودی»</span>
            <textarea name="coming_soon_text" rows="2"><?= e($settings['coming_soon_text']) ?></textarea>
        </label>

        <label class="field span-2">
            <span>متن حالت به‌روزرسانی</span>
            <textarea name="maintenance_text" rows="2"><?= e($settings['maintenance_text']) ?></textarea>
        </label>

        <label class="field">
            <span>برآورد بازگشت (اختیاری)</span>
            <input type="text" name="maintenance_eta" value="<?= e($settings['maintenance_eta']) ?>"
                   placeholder="مثلاً: فردا ساعت ۱۰">
        </label>

        <label class="field">
            <span>منطقه زمانی مؤسسه</span>
            <select name="timezone">
                <?php foreach (['Asia/Tehran', 'UTC', 'Asia/Dubai', 'Europe/Istanbul'] as $zone): ?>
                    <option value="<?= e($zone) ?>" <?= $settings['timezone'] === $zone ? 'selected' : '' ?>>
                        <?= e($zone) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>مبنای محاسبه استمرار روزانه، مأموریت‌ها و فاصله بین تلاش‌های آزمون.</small>
        </label>

        <label class="field">
            <span>امتیاز پاسخ صحیح</span>
            <input type="number" name="xp_per_correct" min="0" max="1000"
                   value="<?= (int) $settings['xp_per_correct'] ?>">
        </label>

        <label class="field">
            <span>کسر امتیاز با استفاده از راهنما (٪)</span>
            <input type="number" name="hint_penalty" min="0" max="100"
                   value="<?= (int) $settings['hint_penalty'] ?>">
        </label>

        <fieldset class="field span-2">
            <legend>وزن سختی در محاسبه تسلط</legend>
            <div class="balin-weight-row">
                <?php foreach (['easy' => 'آسان', 'medium' => 'متوسط', 'hard' => 'دشوار', 'expert' => 'تخصصی'] as $key => $label): ?>
                    <label><?= e($label) ?>
                        <input type="number" name="weight_<?= e($key) ?>" min="1" max="20"
                               value="<?= (int) ($settings['weights'][$key] ?? 1) ?>">
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <label class="field">
            <span>رفتار سطوح بالاتر از آخرین رتبه</span>
            <select name="rank_overflow">
                <option value="repeat_last" <?= $settings['rank_overflow'] === 'repeat_last' ? 'selected' : '' ?>>
                    تکرار آخرین رتبه با شماره قدم افزایشی
                </option>
                <option value="admin_defined" <?= $settings['rank_overflow'] === 'admin_defined' ? 'selected' : '' ?>>
                    نمایش عدد خام سطح (رتبه‌ها را خودم اضافه می‌کنم)
                </option>
            </select>
        </label>

        <label class="field">
            <span>وقتی رقابتی در جریان نیست</span>
            <select name="no_competition">
                <option value="empty" <?= $settings['no_competition'] === 'empty' ? 'selected' : '' ?>>جدول خالی نمایش داده شود</option>
                <option value="last_ended" <?= $settings['no_competition'] === 'last_ended' ? 'selected' : '' ?>>آخرین رقابت پایان‌یافته نمایش داده شود</option>
            </select>
        </label>

        <div class="form-actions span-2">
            <button class="btn btn-primary" type="submit">ذخیره تنظیمات</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">تاریخچه تغییر وضعیت</h3>
    </div>

    <?php if ($log === []): ?>
        <div class="empty">هنوز وضعیت انتشار تغییر نکرده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>از</th><th>به</th><th>مدیر</th><th>یادداشت</th><th>زمان</th></tr></thead>
                <tbody>
                <?php foreach ($log as $entry): ?>
                    <tr>
                        <td><?= e($states[$entry['from_status']][0] ?? $entry['from_status']) ?></td>
                        <td><?= e($states[$entry['to_status']][0] ?? $entry['to_status']) ?></td>
                        <td><?= e($entry['admin_name'] ?? '—') ?></td>
                        <td><?= e($entry['note'] ?? '—') ?></td>
                        <td><?= e(jdate($entry['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
