<?php
$typeLabel = [
    'content' => 'محتوا', 'schedule' => 'برنامه', 'exam' => 'امتحان',
    'course' => 'دوره', 'message' => 'پیام', 'system' => 'سیستم',
];
$typeChip = [
    'content' => 'chip-teal', 'schedule' => 'chip-blue', 'exam' => 'chip-red',
    'course' => 'chip-purple', 'message' => 'chip-blue', 'system' => 'chip-gray',
];
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">اطلاعیه‌ها</h3>
        <?php if ($notifications !== []): ?>
            <form method="post" action="/student/notifications/read-all" style="margin:0;">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button class="btn btn-ghost btn-sm" type="submit">همه را خوانده‌شده کن</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($notifications === []): ?>
        <div class="empty">اطلاعیه‌ای برای شما وجود ندارد.</div>
    <?php else: ?>
        <div class="tree">
            <?php foreach ($notifications as $item): ?>
                <div class="tree-leaf<?= (int) $item['is_read'] === 0 ? ' is-unread' : '' ?>">
                    <span class="leaf-icon"><?= (int) $item['is_read'] === 0 ? '🔵' : '⚪' ?></span>
                    <div style="min-width:0; flex:1;">
                        <div class="leaf-title"><?= e($item['title']) ?></div>
                        <?php if ($item['body']): ?>
                            <div class="leaf-body"><?= nl2br(e($item['body'])) ?></div>
                        <?php endif; ?>
                        <div class="leaf-meta"><?= e(jdate($item['published_at'])) ?></div>
                        <?php if ($item['link_url']): ?>
                            <a class="class-link" href="<?= e($item['link_url']) ?>">مشاهده ←</a>
                        <?php endif; ?>
                    </div>
                    <span class="stat-chip <?= e($typeChip[$item['notif_type']] ?? 'chip-gray') ?>">
                        <?= e($typeLabel[$item['notif_type']] ?? $item['notif_type']) ?>
                    </span>
                    <?php if ((int) $item['is_read'] === 0): ?>
                        <form method="post" action="/student/notifications/<?= (int) $item['id'] ?>/read" style="margin:0;">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <button class="btn btn-ghost btn-sm" type="submit">خواندم</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
