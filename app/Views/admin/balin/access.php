<?php
/**
 * Student access to the island.
 *
 * @var array  $students
 * @var string $search
 * @var int    $page
 * @var int    $pages
 * @var int    $total
 * @var int    $enabled
 */
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">دسترسی دانشجویان</h3>
        <span class="stat-chip chip-blue"><?= e(fa($enabled)) ?> نفر دسترسی دارند</span>
    </div>

    <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 14px;">
        پیش‌فرض برای هر دانشجو <strong>بدون دسترسی</strong> است. بستن دسترسی، پیشرفت و امتیاز
        دانشجو را پاک نمی‌کند — فقط ورود را می‌بندد و او را از جدول عمومی برمی‌دارد.
    </p>

    <form method="get" action="/admin/balin/access" class="balin-filter">
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="نام، نام کاربری یا موبایل">
        <button class="btn btn-ghost btn-sm" type="submit">جست‌وجو</button>
    </form>

    <?php if ($students === []): ?>
        <div class="empty">دانشجویی پیدا نشد.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>دانشجو</th><th>وضعیت حساب</th><th>دسترسی بالین</th><th>امتیاز</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($students as $student):
                    $hasAccess = (int) $student['has_access'] === 1;
                ?>
                    <tr>
                        <td>
                            <?= e($student['full_name']) ?>
                            <div class="leaf-meta"><?= e($student['username']) ?></div>
                        </td>
                        <td><?= e($student['status'] === 'active' ? 'فعال' : $student['status']) ?></td>
                        <td>
                            <span class="stat-chip <?= $hasAccess ? 'chip-green' : 'chip-gray' ?>">
                                <?= $hasAccess ? 'باز' : 'بسته' ?>
                            </span>
                            <?php if ($hasAccess && $student['granted_at']): ?>
                                <div class="leaf-meta"><?= e(jdate($student['granted_at'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e(fa((int) $student['total_xp'])) ?></td>
                        <td class="row-actions">
                            <form method="post" action="/admin/balin/access/<?= e($student['uuid']) ?>">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <input type="hidden" name="enable" value="<?= $hasAccess ? '0' : '1' ?>">
                                <button class="btn <?= $hasAccess ? 'btn-danger' : 'btn-primary' ?> btn-sm" type="submit">
                                    <?= $hasAccess ? 'بستن دسترسی' : 'باز کردن دسترسی' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="صفحه‌بندی">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <a class="pager-link<?= $p === $page ? ' is-active' : '' ?>"
                       href="/admin/balin/access?<?= e(http_build_query(['q' => $search, 'page' => $p])) ?>">
                        <?= e(fa($p)) ?>
                    </a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">دسترسی گروهی</h3>
    <p style="color:var(--ink-3); font-size:12.5px;">
        دسترسی همه <?= e(fa($total)) ?> دانشجو را یک‌جا باز می‌کند. این کار در گزارش فعالیت ثبت می‌شود.
    </p>
    <form method="post" action="/admin/balin/access/grant-all"
          data-confirm="دسترسی همه دانشجویان به جزیره بالین باز شود؟">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <button class="btn btn-primary btn-sm" type="submit">باز کردن برای همه</button>
    </form>
</div>
