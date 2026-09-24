<?php
/**
 * One course: its settings, its sessions, and a whole-course import.
 *
 * @var array $course
 * @var array $decks
 * @var array $colors
 * @var int   $maxRows
 */
$colorLabels = ['blue' => 'آبی', 'purple' => 'بنفش', 'teal' => 'فیروزه‌ای', 'green' => 'سبز',
                'orange' => 'نارنجی', 'pink' => 'صورتی', 'red' => 'قرمز', 'indigo' => 'نیلی'];
$here        = '/admin/flashcards/course/' . $course['uuid'];
$totalCards  = array_sum(array_map(static fn ($d) => (int) $d['card_count'], $decks));
?>
<div class="fc-page">
    <section class="fc-hero fc-c-<?= e($course['color']) ?>"
             style="background: radial-gradient(80% 120% at 100% 0%, rgba(255,255,255,.22), transparent 60%), linear-gradient(135deg, var(--fc-a), var(--fc-b));">
        <div style="position:relative; z-index:1;">
            <h2><?= e($course['icon'] ?: '📘') ?> <?= e($course['title']) ?></h2>
            <div class="fc-pills">
                <span class="fc-pill"><?= $course['status'] === 'published' ? '🟢 منتشرشده' : '⚪ پیش‌نویس' ?></span>
                <span class="fc-pill">📚 <b><?= e(fa((string) count($decks))) ?></b> جلسه</span>
                <span class="fc-pill">🃏 <b><?= e(fa((string) $totalCards)) ?></b> کارت</span>
            </div>
        </div>
        <a class="btn btn-ghost" href="/admin/flashcards" style="position:relative; z-index:1;">همه درس‌ها</a>
    </section>

    <div class="fc-grid-2">
        <form method="post" action="<?= e($here) ?>" class="fc-panel">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <div class="fc-head" style="margin-bottom:10px;"><h3>⚙ مشخصات درس</h3></div>
            <div class="fc-inline">
                <div class="field"><label class="label">عنوان</label>
                    <input class="input" name="title" value="<?= e($course['title']) ?>" maxlength="191" required></div>
                <div class="field narrow"><label class="label">آیکون</label>
                    <input class="input" name="icon" value="<?= e($course['icon'] ?? '') ?>" maxlength="4"></div>
            </div>
            <div class="field" style="margin-top:10px;"><label class="label">توضیح</label>
                <input class="input" name="description" value="<?= e($course['description'] ?? '') ?>" maxlength="500"></div>
            <div class="fc-inline">
                <div class="field"><label class="label">رنگ</label>
                    <select class="input" name="color">
                        <?php foreach ($colors as $color): ?>
                            <option value="<?= e($color) ?>" <?= $course['color'] === $color ? 'selected' : '' ?>><?= e($colorLabels[$color] ?? $color) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="field narrow"><label class="label">ترتیب</label>
                    <input class="input" type="number" name="sort_order" value="<?= (int) $course['sort_order'] ?>" dir="ltr"></div>
                <div class="field"><label class="label">وضعیت</label>
                    <select class="input" name="status">
                        <option value="draft" <?= $course['status'] === 'draft' ? 'selected' : '' ?>>پیش‌نویس (برای دانشجو پنهان)</option>
                        <option value="published" <?= $course['status'] === 'published' ? 'selected' : '' ?>>منتشرشده</option>
                    </select></div>
            </div>
            <div class="fc-inline" style="margin-top:12px; justify-content:space-between;">
                <button class="btn btn-primary" type="submit">ذخیره</button>
                <button class="btn btn-danger btn-sm" type="submit" formaction="<?= e($here) ?>/delete"
                        data-fc-confirm="این درس با همه جلسه‌ها، کارت‌ها و پیشرفت دانشجویان حذف شود؟ برگشت‌پذیر نیست.">حذف درس</button>
            </div>
        </form>

        <?php \HeleXa\Core\View::partial('partials.fc_import', [
            'action' => $here . '/import', 'maxRows' => $maxRows, 'withSession' => true,
        ]); ?>
    </div>

    <section class="fc-panel">
        <div class="fc-head" style="margin-bottom:12px;"><h3>جلسه‌ها</h3></div>

        <form method="post" action="<?= e($here) ?>/decks" class="fc-inline" style="margin-bottom:16px;">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <div class="field"><label class="label" for="d-title">جلسه جدید</label>
                <input class="input" id="d-title" name="title" maxlength="191" required
                       placeholder="جلسه <?= e(fa((string) (count($decks) + 1))) ?>" value="جلسه <?= e(fa((string) (count($decks) + 1))) ?>"></div>
            <div class="field"><label class="label" for="d-desc">توضیح</label>
                <input class="input" id="d-desc" name="description" maxlength="500"></div>
            <button class="btn btn-primary" type="submit" data-lock-on-submit>＋ افزودن جلسه</button>
        </form>

        <?php if ($decks === []): ?>
            <div class="fc-empty"><div class="fc-big">🗂️</div><span>هنوز جلسه‌ای نیست. یکی بساز یا کل درس را از اکسل وارد کن.</span></div>
        <?php else: ?>
            <div class="fc-decks">
                <?php foreach ($decks as $i => $deck): ?>
                    <a class="fc-deck fc-c-<?= e($course['color']) ?>" href="/admin/flashcards/deck/<?= e($deck['uuid']) ?>">
                        <div class="fc-deck-row">
                            <span class="fc-deck-num"><?= e(fa((string) ($i + 1))) ?></span>
                            <span class="fc-deck-meta">ترتیب <?= e(fa((string) $deck['sort_order'])) ?></span>
                        </div>
                        <h4><?= e($deck['title']) ?></h4>
                        <span class="fc-deck-meta"><?= e(fa((string) $deck['card_count'])) ?> کارت</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
