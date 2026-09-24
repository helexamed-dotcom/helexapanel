<?php
/**
 * The student's support conversation.
 *
 * Mirrors the admin thread view so the two ends of the same ticket read the
 * same way, with the sides swapped: here the student's own messages sit on
 * the near edge and support's answers on the far one.
 */
?>
<div class="card" style="max-width:760px;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; flex-wrap:wrap;">
        <div>
            <h3 class="card-title" style="margin:0;">گفتگو با پشتیبانی</h3>
            <span class="leaf-meta">
                <?php if ($ticket === null): ?>
                    گفتگوی بازی نداری
                <?php else: ?>
                    <?php $statusLabel = ['open' => 'در انتظار پاسخ', 'answered' => 'پاسخ داده شد'][$ticket['status']] ?? $ticket['status']; ?>
                    <span class="stat-chip <?= $ticket['status'] === 'open' ? 'chip-amber' : 'chip-green' ?>">
                        <?= e($statusLabel) ?>
                    </span>
                <?php endif; ?>
            </span>
        </div>
    </div>

    <?php if ($ticket === null): ?>
        <div class="empty" style="margin-bottom:18px;">
            سوالی داری یا مشکلی پیش آمده؟ پیام خود را بنویس — پشتیبانی همین‌جا پاسخ می‌دهد.
        </div>
    <?php else: ?>
        <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:18px;">
            <?php foreach ($messages as $message): ?>
                <?php $fromSupport = $message['sender_type'] === 'admin'; ?>
                <div style="align-self: <?= $fromSupport ? 'flex-start' : 'flex-end' ?>; max-width:80%;">
                    <div style="font-size:11px; color:var(--ink-3); margin-bottom:3px; text-align: <?= $fromSupport ? 'right' : 'left' ?>;">
                        <?= $fromSupport ? 'پشتیبانی' : 'شما' ?> · <?= e(jdate($message['created_at'])) ?>
                    </div>
                    <div style="background: <?= $fromSupport ? 'var(--blue-soft, #eef2ff)' : 'var(--canvas)' ?>;
                                border: 1px solid var(--line); border-radius: 12px; padding: 10px 13px; font-size:13.5px; line-height:1.9;">
                        <?php if ($message['body']): ?>
                            <div><?= nl2br(e($message['body'])) ?></div>
                        <?php endif; ?>
                        <?php if ($message['attachment_path']): ?>
                            <a href="/student/support/attachment/<?= e($message['uuid']) ?>" target="_blank" rel="noopener">
                                <img src="/student/support/attachment/<?= e($message['uuid']) ?>"
                                     alt="تصویر ضمیمه" style="max-width:220px; border-radius:8px; margin-top:6px; display:block;">
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/student/support" enctype="multipart/form-data">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <div class="field">
            <label class="label" for="support-body">پیام شما</label>
            <textarea class="input" id="support-body" name="body" rows="4"
                      maxlength="<?= (int) $maxBody ?>"
                      placeholder="<?= $ticket === null ? 'مشکل یا سوالت را بنویس…' : 'ادامه گفتگو…' ?>"></textarea>
        </div>
        <div class="field">
            <label class="label" for="support-photo">تصویر (اختیاری)</label>
            <input class="input" id="support-photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp">
            <span class="leaf-meta">JPG، PNG یا WEBP — حداکثر ۳ مگابایت</span>
        </div>
        <button class="btn btn-primary" type="submit" data-lock-on-submit>ارسال پیام</button>
    </form>
</div>
