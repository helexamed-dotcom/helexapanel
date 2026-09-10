<div class="card">
    <h3 class="card-title">صندوق پیام</h3>

    <?php if ($messages === []): ?>
        <div class="empty">پیامی دریافت نکرده‌اید.</div>
    <?php else: ?>
        <div class="tree">
            <?php foreach ($messages as $message): ?>
                <a class="tree-leaf leaf-link<?= (int) $message['is_read'] === 0 ? ' is-unread' : '' ?>"
                   href="/student/messages/<?= (int) $message['id'] ?>">
                    <span class="leaf-icon"><?= (int) $message['is_read'] === 0 ? '✉️' : '📭' ?></span>
                    <div style="min-width:0; flex:1;">
                        <div class="leaf-title"><?= e($message['subject']) ?></div>
                        <div class="leaf-meta">
                            از <?= e($message['sender_name'] ?? 'سیستم') ?> · <?= e(jdate($message['created_at'])) ?>
                        </div>
                    </div>
                    <?php if ((int) $message['is_read'] === 0): ?>
                        <span class="stat-chip chip-blue">خوانده‌نشده</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
