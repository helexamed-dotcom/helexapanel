<?php
/**
 * 🔔 for a student: three tabs in one panel.
 *
 * @var string     $tab
 * @var array      $notifications
 * @var array      $messages
 * @var array|null $ticket
 * @var array      $thread
 */
$icon = static fn (string $n) => \HeleXa\Core\View::partial('partials.icon', ['name' => $n]);
$unreadN = count(array_filter($notifications, static fn (array $n): bool => (int) $n['is_read'] === 0));
$unreadM = count(array_filter($messages, static fn (array $m): bool => (int) $m['is_read'] === 0));
$ago = static function (?string $at): string {
    $ts = $at ? strtotime($at) : false;
    if ($ts === false) {
        return '';
    }
    $d = time() - $ts;
    return match (true) {
        $d < 60      => 'همین حالا',
        $d < 3600    => fa((string) intdiv($d, 60)) . ' دقیقه پیش',
        $d < 86400   => fa((string) intdiv($d, 3600)) . ' ساعت پیش',
        $d < 7*86400 => fa((string) intdiv($d, 86400)) . ' روز پیش',
        default      => jdate($at),
    };
};
?>
<div class="hub" data-hub-tabs>
    <div class="hub-seg" role="tablist">
        <button type="button" role="tab" data-hub-tab="notifications" aria-selected="<?= $tab === 'notifications' ? 'true' : 'false' ?>">
            <?php $icon('bell'); ?> اعلان‌ها<?php if ($unreadN): ?><i><?= e(fa((string) $unreadN)) ?></i><?php endif; ?>
        </button>
        <button type="button" role="tab" data-hub-tab="messages" aria-selected="<?= $tab === 'messages' ? 'true' : 'false' ?>">
            <?php $icon('message'); ?> پیام‌ها<?php if ($unreadM): ?><i><?= e(fa((string) $unreadM)) ?></i><?php endif; ?>
        </button>
        <button type="button" role="tab" data-hub-tab="support" aria-selected="<?= $tab === 'support' ? 'true' : 'false' ?>">
            <?php $icon('chat'); ?> پشتیبانی
        </button>
    </div>

    <section class="hub-pane" data-hub-pane="notifications" <?= $tab === 'notifications' ? '' : 'hidden' ?>>
        <?php if ($notifications === []): ?>
            <div class="hub-empty"><span class="hub-empty-ic"><?php $icon('bell'); ?></span>اعلانی نداری.</div>
        <?php else: ?>
            <ul class="hub-list">
                <?php foreach ($notifications as $n): ?>
                    <li class="hub-row<?= (int) $n['is_read'] === 0 ? ' is-unread' : '' ?>">
                        <span class="hub-row-ic tone-<?= $n['notif_type'] === 'exam' ? 'red' : ($n['notif_type'] === 'content' ? 'sky' : 'amber') ?>"><?php $icon($n['notif_type'] === 'exam' ? 'exam' : 'bell'); ?></span>
                        <a class="hub-row-text" href="<?= e($n['link_url'] ?: '/student/notifications') ?>"
                           data-hub-read="/student/notifications/<?= (int) $n['id'] ?>/read">
                            <b><?= e($n['title']) ?></b>
                            <small><?= e(mb_strimwidth(strip_tags((string) $n['body']), 0, 90, '…')) ?></small>
                            <em><?= e($ago($n['published_at'])) ?></em>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="hub-foot">
            <?php if ($unreadN > 0): ?>
                <button type="button" class="hub-link" data-hub-post="/student/notifications/read-all">همه را خواندم</button>
            <?php endif; ?>
            <a class="hub-link is-strong" href="/student/notifications">همه اعلان‌ها</a>
        </div>
    </section>

    <section class="hub-pane" data-hub-pane="messages" <?= $tab === 'messages' ? '' : 'hidden' ?>>
        <?php if ($messages === []): ?>
            <div class="hub-empty"><span class="hub-empty-ic"><?php $icon('message'); ?></span>پیامی نداری.</div>
        <?php else: ?>
            <ul class="hub-list">
                <?php foreach ($messages as $m): ?>
                    <li class="hub-row<?= (int) $m['is_read'] === 0 ? ' is-unread' : '' ?>">
                        <span class="hub-row-ic tone-indigo"><?php $icon('message'); ?></span>
                        <a class="hub-row-text" href="/student/messages/<?= (int) $m['id'] ?>">
                            <b><?= e($m['subject']) ?></b>
                            <small><?= e(mb_strimwidth(strip_tags((string) $m['body']), 0, 90, '…')) ?></small>
                            <em><?= e(($m['sender_name'] ?? 'مدیر') . ' · ' . $ago($m['created_at'])) ?></em>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="hub-foot"><a class="hub-link is-strong" href="/student/messages">همه پیام‌ها</a></div>
    </section>

    <section class="hub-pane hub-chat" data-hub-pane="support" <?= $tab === 'support' ? '' : 'hidden' ?>>
        <div class="chat-thread" data-chat-thread>
            <?php if ($thread === []): ?>
                <div class="chat-hello">
                    <span class="hub-empty-ic"><?php $icon('chat'); ?></span>
                    <b>سلام! چطور کمکت کنیم؟</b>
                    <small>سوال یا مشکلت را بنویس؛ پاسخ پشتیبانی همین‌جا و در اعلان‌ها می‌آید.</small>
                </div>
            <?php else: ?>
                <?php foreach ($thread as $msg): $mine = $msg['sender_type'] !== 'admin'; ?>
                    <div class="chat-bubble<?= $mine ? ' is-mine' : '' ?>">
                        <?php if ($msg['body']): ?><p><?= nl2br(e($msg['body'])) ?></p><?php endif; ?>
                        <?php if ($msg['attachment_path']): ?>
                            <a href="/student/support/attachment/<?= e($msg['uuid']) ?>" target="_blank" rel="noopener">
                                <img src="/student/support/attachment/<?= e($msg['uuid']) ?>" alt="تصویر پیوست" loading="lazy">
                            </a>
                        <?php endif; ?>
                        <time><?= e($mine ? $ago($msg['created_at']) : 'پشتیبانی · ' . $ago($msg['created_at'])) ?></time>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <form class="chat-compose" method="post" action="/student/support" enctype="multipart/form-data" data-chat-form>
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <label class="chat-attach" title="پیوست تصویر">
                <?php $icon('attach'); ?>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" hidden data-chat-file>
            </label>
            <textarea name="body" rows="1" maxlength="4000" placeholder="پیام به پشتیبانی…" data-chat-input></textarea>
            <button type="submit" class="chat-send" aria-label="ارسال"><?php $icon('send'); ?></button>
        </form>
        <div class="hub-foot"><a class="hub-link" href="/student/support">باز کردن در صفحه کامل</a></div>
    </section>
</div>
