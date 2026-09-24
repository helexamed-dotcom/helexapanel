<?php
/**
 * «گزارشات»: question-error reports from students.
 *
 * @var array  $reports
 * @var string $status
 * @var array  $counts
 * @var array  $reasons
 * @var array  $statuses
 * @var \HeleXa\Core\Paginator $paginator
 */
$canWrite = can('qbank.manage_questions');
$tabs = ['open' => 'باز', 'resolved' => 'رسیدگی‌شده', 'dismissed' => 'ردشده', 'all' => 'همه'];
$chip = ['open' => 'chip-amber', 'resolved' => 'chip-green', 'dismissed' => 'chip-gray'];
$back = '/admin/qbank/reports?status=' . rawurlencode($status);
?>
<div class="qb-page">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon" aria-hidden="true">⚠️</div>
            <div>
                <h2>گزارشات اشکال سوال</h2>
                <p><?= e(fa((string) $counts['open'])) ?> گزارش باز · دانشجویان از دکمه «گزارش اشکال» زیر هر سوال می‌فرستند.</p>
            </div>
        </div>
    </section>

    <nav class="qb-tabs hx-pills" aria-label="وضعیت">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="hx-pill-btn<?= $status === $key ? ' is-on' : '' ?>" href="/admin/qbank/reports?status=<?= e($key) ?>">
                <?= e($label) ?> <b><?= e(fa((string) ($counts[$key] ?? 0))) ?></b>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($reports === []): ?>
        <div class="hx-empty">
            <div class="hx-empty-art" aria-hidden="true">🎉</div>
            <h3>گزارشی در این بخش نیست</h3>
        </div>
    <?php else: ?>
        <div class="hx-report-list">
            <?php foreach ($reports as $r): ?>
                <article class="hx-report is-<?= e($r['status']) ?>">
                    <header class="hx-report-head">
                        <span class="stat-chip <?= e($chip[$r['status']] ?? 'chip-gray') ?>"><?= e($statuses[$r['status']] ?? $r['status']) ?></span>
                        <span class="hx-report-reason"><?= e($reasons[$r['reason']] ?? $r['reason']) ?></span>
                        <?php if ((int) $r['open_on_question'] > 1): ?>
                            <span class="stat-chip chip-red"><?= e(fa((string) $r['open_on_question'])) ?> گزارش باز روی این سوال</span>
                        <?php endif; ?>
                        <span class="hx-muted" style="margin-inline-start:auto;"><?= e(jdate($r['created_at'])) ?></span>
                    </header>

                    <div class="hx-report-q">
                        <small><?= e($r['subject_title'] ?? 'بدون درس') ?></small>
                        <p><?= e(mb_substr((string) ($r['stem_text'] ?? '(سوال تصویری)'), 0, 260)) ?></p>
                    </div>

                    <?php if (!empty($r['body'])): ?>
                        <blockquote class="hx-report-body">«<?= nl2br(e($r['body'])) ?>»</blockquote>
                    <?php endif; ?>

                    <div class="hx-report-meta">
                        👤 <?= e($r['reporter_name'] ?? '—') ?> <span class="mono"><?= e($r['reporter_username'] ?? '') ?></span>
                        <?php if ($r['handler_name']): ?> · رسیدگی: <?= e($r['handler_name']) ?><?php endif; ?>
                        <?php if ($r['admin_note']): ?> · یادداشت: <?= e($r['admin_note']) ?><?php endif; ?>
                    </div>

                    <div class="hx-report-actions">
                        <a class="btn btn-ghost btn-sm" href="/admin/qbank/questions/<?= e($r['question_uuid']) ?>">پیش‌نمایش</a>
                        <?php if ($canWrite): ?>
                            <a class="btn btn-primary btn-sm" href="/admin/qbank/questions/<?= e($r['question_uuid']) ?>/edit">ویرایش سوال</a>
                            <form method="post" action="/admin/qbank/reports/<?= (int) $r['id'] ?>" class="hx-report-form">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <input type="hidden" name="back" value="<?= e($back) ?>">
                                <input class="input" name="admin_note" placeholder="یادداشت (اختیاری)" value="<?= e($r['admin_note'] ?? '') ?>">
                                <?php if ($r['status'] === 'open'): ?>
                                    <label class="remember-row" style="margin:0;">
                                        <input type="checkbox" name="all_on_question" value="1" checked>
                                        <span>همه گزارش‌های این سوال</span>
                                    </label>
                                    <button class="btn btn-primary btn-sm" name="status" value="resolved" type="submit">✓ رسیدگی شد</button>
                                    <button class="btn btn-ghost btn-sm" name="status" value="dismissed" type="submit">رد گزارش</button>
                                <?php else: ?>
                                    <button class="btn btn-ghost btn-sm" name="status" value="open" type="submit">بازگشایی</button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php \HeleXa\Core\View::partial('partials.pagination', ['paginator' => $paginator]); ?>
    <?php endif; ?>
</div>
