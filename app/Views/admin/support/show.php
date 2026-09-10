<div class="card" style="max-width:760px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <div>
            <h3 class="card-title" style="margin:0;"><?= e($ticket['full_name']) ?></h3>
            <span class="leaf-meta"><?= e($ticket['username']) ?> ·
                <?php $statusLabel = ['open' => 'باز', 'answered' => 'پاسخ‌داده‌شده', 'closed' => 'بسته'][$ticket['status']] ?? $ticket['status']; ?>
                <span class="stat-chip <?= $ticket['status'] === 'open' ? 'chip-amber' : ($ticket['status'] === 'closed' ? 'chip-gray' : 'chip-green') ?>">
                    <?= e($statusLabel) ?>
                </span>
            </span>
        </div>
        <div class="row-actions" style="margin:0;">
            <a class="btn btn-ghost btn-sm" href="/admin/support">بازگشت به فهرست</a>
            <?php if ($ticket['status'] !== 'closed'): ?>
                <form method="post" action="/admin/support/<?= e($ticket['uuid']) ?>/close"
                      data-confirm="این تیکت بسته شود؟" style="margin:0;">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <button class="btn btn-danger btn-sm" type="submit">بستن تیکت</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:18px;">
        <?php foreach ($messages as $message): ?>
            <?php $isAdmin = $message['sender_type'] === 'admin'; ?>
            <div style="align-self: <?= $isAdmin ? 'flex-start' : 'flex-end' ?>; max-width:80%;">
                <div style="font-size:11px; color:var(--ink-3); margin-bottom:3px; text-align: <?= $isAdmin ? 'right' : 'left' ?>;">
                    <?= $isAdmin ? 'پشتیبانی' : e($ticket['full_name']) ?> · <?= e(jdate($message['created_at'])) ?>
                </div>
                <div style="background: <?= $isAdmin ? 'var(--blue-soft, #eef2ff)' : 'var(--canvas)' ?>;
                            border: 1px solid var(--line); border-radius: 12px; padding: 10px 13px; font-size:13.5px; line-height:1.9;">
                    <?php if ($message['body']): ?>
                        <div><?= nl2br(e($message['body'])) ?></div>
                    <?php endif; ?>
                    <?php if ($message['attachment_path']): ?>
                        <a href="/admin/support/<?= e($ticket['uuid']) ?>/attachment/<?= e($message['uuid']) ?>" target="_blank" rel="noopener">
                            <img src="/admin/support/<?= e($ticket['uuid']) ?>/attachment/<?= e($message['uuid']) ?>"
                                 alt="" style="max-width:220px; border-radius:8px; margin-top:6px; display:block;">
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($ticket['status'] !== 'closed'): ?>
        <form method="post" action="/admin/support/<?= e($ticket['uuid']) ?>/reply">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <div class="field">
                <label class="label">پاسخ</label>
                <textarea class="input" name="body" rows="4" maxlength="4000" required
                          placeholder="پاسخ خود را بنویسید…"></textarea>
            </div>
            <button class="btn btn-primary" type="submit" data-lock-on-submit>ارسال پاسخ</button>
        </form>
    <?php else: ?>
        <div class="empty">این تیکت بسته شده و پاسخ جدید نمی‌پذیرد.</div>
    <?php endif; ?>
</div>
