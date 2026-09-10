<div class="card">
    <div class="row-actions" style="margin-bottom:14px;">
        <?php foreach (['open' => 'باز', 'answered' => 'پاسخ‌داده‌شده', 'closed' => 'بسته'] as $key => $label): ?>
            <a class="btn btn-sm <?= $status === $key ? 'btn-primary' : 'btn-ghost' ?>"
               href="/admin/support?status=<?= e($key) ?>">
                <?= e($label) ?> (<?= e(fa((string) $counts[$key])) ?>)
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($tickets === []): ?>
        <div class="empty">تیکتی در این وضعیت نیست.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>دانشجو</th><th>پیام‌ها</th><th>آخرین فعالیت</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tickets as $ticket): ?>
                    <tr>
                        <td><?= e($ticket['full_name']) ?> <span class="leaf-meta">— <?= e($ticket['username']) ?></span></td>
                        <td><?= e(fa((string) $ticket['message_count'])) ?></td>
                        <td><?= e(jdate($ticket['last_message_at'])) ?></td>
                        <td><a class="btn btn-ghost btn-sm" href="/admin/support/<?= e($ticket['uuid']) ?>">مشاهده</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
