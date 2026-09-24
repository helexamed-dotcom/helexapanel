<?php
/**
 * «درس‌های من»: the reading list.
 *
 * @var array $marks
 * @var array $kinds  kind → [icon, label]
 */
$open = array_values(array_filter($marks, static fn ($m) => $m['done_at'] === null));
$done = array_values(array_filter($marks, static fn ($m) => $m['done_at'] !== null));
$today = date('Y-m-d');
?>
<div class="hx-page hx-anim">
    <section class="hx-hero hx-hero-amber">
        <div class="hx-hero-icon" aria-hidden="true">📌</div>
        <div class="hx-hero-text">
            <h2>درس‌های من</h2>
            <p>هر چیزی که باید بخوانی این‌جاست — از دکمه «📌 باید بخونم» در سایت، از تحلیل آزمون‌ها، یا خودت بنویس.</p>
        </div>
        <div class="hx-hero-stat">
            <b><?= e(fa((string) count($open))) ?></b><span>مانده</span>
        </div>
    </section>

    <form method="post" action="/student/study" class="hx-card hx-add-mark">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <input type="hidden" name="kind" value="custom">
        <input class="input" name="title" maxlength="191" placeholder="چه چیزی باید بخوانی؟ مثلاً: فصل ۳ میکروب — استافیلوکوک" required>
        <input class="input" type="date" name="due_date" dir="ltr" title="تا چه روزی (اختیاری)">
        <button class="btn btn-primary" type="submit">افزودن</button>
    </form>

    <?php if ($open === [] && $done === []): ?>
        <section class="hx-empty">
            <div class="hx-empty-art" aria-hidden="true">🎈</div>
            <h3>فهرستت خالی است</h3>
            <p>در بانک سوال، جزیره بالین و کتابخانه دکمه <b>«📌 باید بخونم»</b> را بزن؛ یا بعد از هر آزمون،
               بخش‌های ضعیف را با یک کلیک این‌جا بفرست.</p>
        </section>
    <?php endif; ?>

    <?php if ($open !== []): ?>
        <section class="hx-card">
            <div class="hx-card-head"><h3>📖 باید بخوانم</h3></div>
            <ul class="hx-marks">
                <?php foreach ($open as $m):
                    [$icon, $label] = $kinds[$m['kind']] ?? ['✍️', ''];
                    $late = $m['due_date'] !== null && $m['due_date'] < $today;
                    $due  = $m['due_date'] !== null && $m['due_date'] === $today;
                ?>
                    <li class="hx-mark<?= $late ? ' is-late' : ($due ? ' is-due' : '') ?>">
                        <form method="post" action="/student/study/<?= (int) $m['id'] ?>/toggle" class="hx-inline">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <button class="hx-check" type="submit" title="خواندم" aria-label="علامت خوانده‌شده"></button>
                        </form>
                        <span class="hx-mark-icon" aria-hidden="true"><?= e($icon) ?></span>
                        <span class="hx-mark-main">
                            <?php if ($m['url']): ?>
                                <a href="<?= e($m['url']) ?>"><?= e($m['title']) ?></a>
                            <?php else: ?>
                                <b><?= e($m['title']) ?></b>
                            <?php endif; ?>
                            <small>
                                <?= e($label) ?>
                                <?php if ($m['due_date']): ?>
                                    · <?= $late ? '⏰ عقب افتاده — ' : ($due ? '📅 امروز — ' : 'تا ') ?><?= e(jdate($m['due_date'])) ?>
                                <?php endif; ?>
                                <?php if ($m['note']): ?> · <?= e($m['note']) ?><?php endif; ?>
                            </small>
                        </span>
                        <form method="post" action="/student/study/<?= (int) $m['id'] ?>/delete" class="hx-inline" data-confirm="حذف شود؟">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <button class="hx-mini-btn" type="submit" aria-label="حذف">✕</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($done !== []): ?>
        <details class="hx-card hx-done">
            <summary><h3 style="display:inline">✅ خوانده‌شده (<?= e(fa((string) count($done))) ?>)</h3></summary>
            <ul class="hx-marks">
                <?php foreach ($done as $m): [$icon] = $kinds[$m['kind']] ?? ['✍️']; ?>
                    <li class="hx-mark is-done">
                        <form method="post" action="/student/study/<?= (int) $m['id'] ?>/toggle" class="hx-inline">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <button class="hx-check is-on" type="submit" title="برگرداندن به فهرست" aria-label="برگرداندن"></button>
                        </form>
                        <span class="hx-mark-icon" aria-hidden="true"><?= e($icon) ?></span>
                        <span class="hx-mark-main"><s><?= e($m['title']) ?></s><small><?= e(jdate($m['done_at'])) ?></small></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="/student/study/clear-done" data-confirm="همه موارد خوانده‌شده پاک شوند؟">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button class="btn btn-ghost btn-sm" type="submit">پاک کردن خوانده‌شده‌ها</button>
            </form>
        </details>
    <?php endif; ?>
</div>
