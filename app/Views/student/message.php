<div class="card" style="max-width:760px;">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;"><?= e($message['subject']) ?></h3>
        <a class="btn btn-ghost btn-sm" href="/student/messages">بازگشت</a>
    </div>
    <div class="leaf-meta" style="margin-bottom:16px;">
        از <?= e($message['sender_name'] ?? 'سیستم') ?> · <?= e(jdate($message['created_at'])) ?>
    </div>
    <div class="message-body"><?= nl2br(e($message['body'])) ?></div>
</div>
