<?php
/**
 * Flashcard hub: today's numbers, ready-made courses, personal decks.
 *
 * @var array $courses
 * @var array $decks
 * @var array $deckStats
 * @var array $overview  today, streak, due, mastered
 * @var int   $deckLimit
 */
$totalDue = (int) $overview['due'];
?>
<div class="fc-page">
    <section class="fc-hero">
        <div style="position:relative; z-index:1;">
            <h2>🃏 فلش‌کارت</h2>
            <p>هر روز چند دقیقه مرور کن؛ کارت‌ها درست وقتی برمی‌گردند که در حال فراموش شدن‌اند.</p>
            <div class="fc-pills">
                <span class="fc-pill">⏰ موعد مرور <b><?= e(fa((string) $totalDue)) ?></b></span>
                <span class="fc-pill">✅ امروز <b><?= e(fa((string) $overview['today'])) ?></b></span>
                <span class="fc-pill">🔥 روزهای پیاپی <b><?= e(fa((string) $overview['streak'])) ?></b></span>
                <span class="fc-pill">🏆 تسلط <b><?= e(fa((string) $overview['mastered'])) ?></b></span>
            </div>
        </div>
        <a class="btn btn-light" href="/student/flashcards/study">
            <?= $totalDue > 0 ? 'شروع مرور امروز' : 'شروع مطالعه' ?>
        </a>
    </section>

    <?php if (\HeleXa\Services\Modules::enabled('figures')): ?>
        <a class="fc-figures hx-zoom" href="/student/figures">
            <span class="app-ic tone-amber"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'figure']); ?></span>
            <span><b>بازی با شکل</b><small>ساختار را روی اطلس پیدا کن یا به نقطه‌ای که نشانت می‌دهیم جواب بده</small></span>
            <em>بازی ←</em>
        </a>
    <?php endif; ?>

    <?php if ($courses !== []): ?>
        <section class="fc-page" style="gap:12px;">
            <div class="fc-head"><h3>درس‌های آماده</h3></div>
            <div class="fc-courses">
                <?php foreach ($courses as $course):
                    $cards    = (int) $course['card_count'];
                    $percent  = $cards > 0 ? (int) round((int) $course['mastered_count'] * 100 / $cards) : 0;
                    $seenPct  = $cards > 0 ? (int) round((int) $course['seen_count'] * 100 / $cards) : 0;
                ?>
                    <a class="fc-course fc-c-<?= e($course['color']) ?>" href="/student/flashcards/course/<?= e($course['uuid']) ?>">
                        <?php if ((int) $course['due_count'] > 0): ?>
                            <span class="fc-due"><?= e(fa((string) $course['due_count'])) ?> مرور</span>
                        <?php endif; ?>
                        <div class="fc-course-icon"><?= e($course['icon'] ?: '📘') ?></div>
                        <div>
                            <h4><?= e($course['title']) ?></h4>
                            <small><?= e(fa((string) $course['deck_count'])) ?> جلسه · <?= e(fa((string) $cards)) ?> کارت</small>
                        </div>
                        <div class="fc-course-foot">
                            <div class="fc-course-meta">
                                <span>دیده‌شده <?= e(fa((string) $seenPct)) ?>٪</span>
                                <span>تسلط <?= e(fa((string) $percent)) ?>٪</span>
                            </div>
                            <div class="fc-bar"><i style="width: <?= $seenPct ?>%;"></i></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="fc-page" style="gap:12px;">
        <div class="fc-head">
            <h3>دسته‌های من</h3>
            <span class="fc-hint"><?= e(fa((string) count($decks))) ?> از <?= e(fa((string) $deckLimit)) ?></span>
        </div>

        <div class="fc-decks">
            <?php foreach ($decks as $deck):
                $s     = $deckStats[(int) $deck['id']] ?? ['seen' => 0, 'mastered' => 0, 'due' => 0];
                $count = (int) $deck['card_count'];
                $pct   = $count > 0 ? (int) round($s['seen'] * 100 / $count) : 0;
            ?>
                <a class="fc-deck fc-c-teal" href="/student/flashcards/deck/<?= e($deck['uuid']) ?>">
                    <div class="fc-deck-row">
                        <span class="fc-deck-num">✎</span>
                        <?php if ($s['due'] > 0): ?><span class="fc-due-chip"><?= e(fa((string) $s['due'])) ?> مرور</span><?php endif; ?>
                    </div>
                    <h4><?= e($deck['title']) ?></h4>
                    <span class="fc-deck-meta"><?= e(fa((string) $count)) ?> کارت · دیده‌شده <?= e(fa((string) $pct)) ?>٪</span>
                    <div class="fc-progress"><i style="width: <?= $pct ?>%;"></i></div>
                </a>
            <?php endforeach; ?>

            <?php if (count($decks) < $deckLimit): ?>
                <details class="fc-deck" style="padding:0;">
                    <summary class="fc-new-deck" style="list-style:none;">
                        <span style="font-size:28px;">＋</span>
                        <span>دسته جدید بساز</span>
                    </summary>
                    <form method="post" action="/student/flashcards/decks" style="padding:14px; display:grid; gap:8px;">
                        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                        <input class="input" name="title" maxlength="191" required placeholder="مثلاً لغات فارماکولوژی">
                        <input class="input" name="description" maxlength="500" placeholder="توضیح (اختیاری)">
                        <button class="btn btn-primary btn-sm" type="submit" data-lock-on-submit>ساختن</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>

        <?php if ($decks === [] && $courses === []): ?>
            <div class="fc-panel fc-empty">
                <div class="fc-big">🃏</div>
                <strong>هنوز فلش‌کارتی نداری</strong>
                <p class="fc-hint">یک دسته بساز و کارت‌هایت را یکی‌یکی اضافه کن یا یکجا از اکسل وارد کن.</p>
            </div>
        <?php endif; ?>
    </section>
</div>
