<?php
/**
 * @var array  $rows
 * @var string $status
 * @var array  $statuses
 * @var array  $counts
 * @var bool   $available
 * @var bool   $enabled
 * @var int    $threshold
 */
$chips = ['open' => 'chip-red', 'warned' => 'chip-amber', 'suspended' => 'chip-gray', 'dismissed' => 'chip-green'];
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">⚠ کاربران مشکوک</h3>
        <a class="btn btn-ghost btn-sm" href="/admin/sessions">همه نشست‌ها</a>
    </div>

    <?php if (!$available): ?>
        <div class="alert alert-error"><div>مهاجرت <span class="mono">2026_09_18_ip_watch.sql</span> هنوز اجرا نشده است.</div></div>
    <?php else: ?>
        <p class="leaf-meta" style="margin:-4px 0 14px; line-height:2;">
            دانشجویی که در یک روز از <strong><?= e(fa((string) $threshold)) ?> شبکه یا بیشتر</strong> وارد شده باشد اینجا می‌آید.
            آدرس‌های یک اپراتور موبایل یک شبکه حساب می‌شوند، پس جابه‌جایی میان وای‌فای خانه و اینترنت همراه
            به‌تنها مورد مشکوک نیست. هیچ اقدامی خودکار انجام نمی‌شود؛ تصمیم با شماست.
            <?php if (!$enabled): ?><br><strong>تشخیص در تنظیمات خاموش است.</strong><?php endif; ?>
        </p>

        <div class="row-actions" style="margin-bottom:14px; justify-content:flex-start;">
            <?php foreach ($statuses + ['all' => 'همه'] as $key => $label): ?>
                <a class="btn btn-sm <?= $status === $key ? 'btn-primary' : 'btn-ghost' ?>" href="/admin/security/flags?status=<?= e($key) ?>">
                    <?= e($label) ?><?= isset($counts[$key]) ? ' (' . e(fa((string) $counts[$key])) . ')' : '' ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($rows === []): ?>
            <div class="empty">موردی در این فهرست نیست.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>دانشجو</th><th>روز</th><th>شبکه / ورود</th><th>آدرس‌ها</th><th>وضعیت</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $ips = json_decode((string) ($row['ip_list'] ?? '[]'), true) ?: [];
                    ?>
                        <tr class="<?= $row['status'] === 'open' ? 'is-flagged' : '' ?>">
                            <td>
                                <a href="/admin/students/<?= e($row['user_uuid']) ?>/access"><?= e($row['full_name']) ?></a>
                                <div class="leaf-meta mono"><?= e($row['username']) ?></div>
                                <?php if ((int) $row['flag_total'] > 1): ?>
                                    <div class="leaf-meta"><?= e(fa((string) $row['flag_total'])) ?> روز مشکوک تا امروز</div>
                                <?php endif; ?>
                                <?php if ($row['user_status'] !== 'active'): ?><span class="stat-chip chip-gray">حساب غیرفعال</span><?php endif; ?>
                            </td>
                            <td><?= e(jdate($row['flag_day'] . ' 00:00:00')) ?></td>
                            <td><strong><?= e(fa((string) $row['networks'])) ?></strong> شبکه · <?= e(fa((string) $row['sign_ins'])) ?> ورود</td>
                            <td>
                                <details>
                                    <summary class="leaf-meta" style="cursor:pointer;"><?= e(fa((string) count($ips))) ?> آدرس</summary>
                                    <?php foreach ($ips as $ip): ?>
                                        <div class="mono" dir="ltr" style="font-size:12px;"><?= e((string) $ip) ?></div>
                                    <?php endforeach; ?>
                                </details>
                                <a class="leaf-meta" href="/admin/sessions/user/<?= e($row['user_uuid']) ?>">تاریخچه نشست‌ها</a>
                            </td>
                            <td>
                                <span class="stat-chip <?= e($chips[$row['status']] ?? 'chip-gray') ?>"><?= e($statuses[$row['status']] ?? $row['status']) ?></span>
                                <?php if (!empty($row['reviewer_name'])): ?>
                                    <div class="leaf-meta"><?= e($row['reviewer_name']) ?> · <?= e(jdate($row['reviewed_at'])) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($row['note'])): ?><div class="leaf-meta"><?= e($row['note']) ?></div><?php endif; ?>
                            </td>
                            <td>
                                <?php if (in_array($row['status'], ['open', 'warned'], true)): ?>
                                    <form method="post" action="/admin/security/flags/<?= (int) $row['id'] ?>/warn" style="display:flex; gap:6px; margin:0 0 6px;">
                                        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                        <input class="input" name="note" maxlength="255" placeholder="توضیح برای دانشجو (اختیاری)" style="min-width:150px;">
                                        <button class="btn btn-ghost btn-sm" type="submit"><?= $row['status'] === 'warned' ? 'اخطار دوباره' : 'اخطار' ?></button>
                                    </form>
                                    <div class="row-actions" style="margin:0;">
                                        <form method="post" action="/admin/security/flags/<?= (int) $row['id'] ?>/suspend"
                                              data-confirm="حساب «<?= e($row['full_name']) ?>» تعلیق و همه نشست‌هایش بسته شود؟">
                                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                            <button class="btn btn-danger btn-sm" type="submit">تعلیق حساب</button>
                                        </form>
                                        <form method="post" action="/admin/security/flags/<?= (int) $row['id'] ?>/dismiss">
                                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                            <button class="btn btn-ghost btn-sm" type="submit">مشکلی نیست</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
