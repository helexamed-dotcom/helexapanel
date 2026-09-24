<?php
/**
 * @var array  $rows
 * @var string $status
 * @var int    $pending
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$tabs = ['pending' => 'در انتظار', 'approved' => 'تأییدشده', 'rejected' => 'ردشده'];
?>
<div class="ad-page">
    <section class="ad-card">
        <header class="ad-card-head">
            <span class="app-ic tone-amber"><?php $icon('school'); ?></span>
            <div><h3>درخواست‌های نوع دانشجو</h3><p>با تأیید، بخش‌های آن نوع فوراً برای دانشجو فعال می‌شود و اعلانش برایش می‌رود.</p></div>
            <div class="ad-actions"><a class="btn btn-ghost btn-sm" href="/admin/student-types">انواع دانشجو</a></div>
        </header>
        <nav class="ad-tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <a class="ad-tab<?= $status === $key ? ' is-active' : '' ?>" href="?status=<?= e($key) ?>"><?= e($label) ?><?php if ($key === 'pending' && $pending > 0): ?><b><?= e(fa((string) $pending)) ?></b><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($rows === []): ?>
            <div class="ad-empty">درخواستی در این بخش نیست.</div>
        <?php else: ?>
            <ul class="ad-list">
                <?php foreach ($rows as $r): ?>
                    <li class="ad-row" style="flex-wrap:wrap">
                        <span class="app-ic tone-<?= e($r['color']) ?>"><?php $icon($r['icon'] ?: 'school', 18); ?></span>
                        <span class="ad-row-main">
                            <b><a href="/admin/students/<?= e($r['user_uuid']) ?>/edit"><?= e($r['full_name']) ?></a> ← <?= e($r['type_title']) ?></b>
                            <small dir="auto">@<?= e($r['username']) ?> · <?= e(jdate($r['created_at'])) ?><?= $r['current_title'] ? ' · فعلی: ' . e($r['current_title']) : '' ?><?= $r['note'] ? ' · «' . e($r['note']) . '»' : '' ?>
                                <?= $r['handler_name'] ? ' · توسط ' . e($r['handler_name']) : '' ?><?= $r['admin_note'] ? ' · ' . e($r['admin_note']) : '' ?></small>
                        </span>
                        <?php if ($r['status'] === 'pending'): ?>
                            <form method="post" action="/admin/student-types/requests/<?= (int) $r['id'] ?>" style="display:flex;gap:6px;flex-wrap:wrap">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <input class="input" name="admin_note" placeholder="یادداشت (اختیاری)" style="width:180px;padding:8px 10px">
                                <button class="btn btn-primary btn-sm" name="decision" value="approve" type="submit">تأیید</button>
                                <button class="btn btn-ghost btn-sm" name="decision" value="reject" type="submit">رد</button>
                            </form>
                        <?php else: ?>
                            <span class="ad-pill <?= $r['status'] === 'approved' ? 'is-on' : 'is-bad' ?>"><?= e($tabs[$r['status']]) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
