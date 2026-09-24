<?php
/**
 * One deck. A personal deck is fully editable; a course session is read-only
 * and shows only the fronts, so the page is a study map rather than a way to
 * copy the course out.
 *
 * @var array $deck
 * @var bool  $editable
 * @var array $cards
 * @var array $stages   card uuid → stage/starred
 * @var array $stats
 * @var int   $maxRows
 */
$count      = count($cards);
$stageLabel = ['new' => 'جدید', 'learning' => 'در حال یادگیری', 'review' => 'مرور', 'mastered' => 'تسلط'];
$color      = $deck['course_color'] ?? 'teal';
$backUrl    = $deck['course_uuid'] ? '/student/flashcards/course/' . $deck['course_uuid'] : '/student/flashcards';
$studyUrl   = '/student/flashcards/study/deck/' . $deck['uuid'];
$starred    = count(array_filter($stages, static fn ($s) => $s['starred']));
?>
<div class="fc-page">
    <section class="fc-hero fc-c-<?= e($color) ?>"
             style="background: radial-gradient(80% 120% at 100% 0%, rgba(255,255,255,.22), transparent 60%), linear-gradient(135deg, var(--fc-a), var(--fc-b));">
        <div style="position:relative; z-index:1;">
            <?php if (!empty($deck['course_title'])): ?>
                <p style="margin-bottom:4px;"><?= e($deck['course_title']) ?></p>
            <?php endif; ?>
            <h2><?= e($deck['title']) ?></h2>
            <?php if (!empty($deck['description'])): ?><p><?= e($deck['description']) ?></p><?php endif; ?>
            <div class="fc-pills">
                <span class="fc-pill">🃏 <b><?= e(fa((string) $count)) ?></b> کارت</span>
                <span class="fc-pill">👀 دیده‌شده <b><?= e(fa((string) $stats['seen'])) ?></b></span>
                <span class="fc-pill">⏰ موعد <b><?= e(fa((string) $stats['due'])) ?></b></span>
                <span class="fc-pill">🏆 تسلط <b><?= e(fa((string) $stats['mastered'])) ?></b></span>
            </div>
        </div>
        <div style="display:grid; gap:8px; position:relative; z-index:1;">
            <?php if ($count > 0): ?>
                <a class="btn btn-light" href="<?= e($studyUrl) ?>">▶ شروع مطالعه</a>
            <?php endif; ?>
            <a class="btn btn-ghost" href="<?= e($backUrl) ?>">بازگشت</a>
        </div>
    </section>

    <?php if ($count > 0): ?>
        <div class="fc-panel">
            <div class="fc-head">
                <h3>حالت‌های مطالعه</h3>
            </div>
            <div class="fc-inline" style="margin-top:10px;">
                <a class="btn btn-ghost btn-sm" href="<?= e($studyUrl) ?>">⏰ موعددارها + کارت جدید</a>
                <a class="btn btn-ghost btn-sm" href="<?= e($studyUrl) ?>?mode=all">📚 همه کارت‌ها به ترتیب</a>
                <a class="btn btn-ghost btn-sm" href="<?= e($studyUrl) ?>?mode=all&amp;shuffle=1">🔀 همه به‌صورت تصادفی</a>
                <?php if ($starred > 0): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e($studyUrl) ?>?mode=starred">⭐ ستاره‌دارها (<?= e(fa((string) $starred)) ?>)</a>
                <?php endif; ?>
                <a class="btn btn-ghost btn-sm" href="<?= e($studyUrl) ?>?mode=hard">🧠 کارت‌های سخت</a>
                <?php if ($stats['seen'] > 0): ?>
                    <form method="post" action="/student/flashcards/deck/<?= e($deck['uuid']) ?>/reset"
                          data-confirm="پیشرفت شما در این دسته از صفر شروع شود؟" style="margin:0;">
                        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                        <button class="btn btn-ghost btn-sm" type="submit">↺ شروع دوباره</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($editable): ?>
        <div class="fc-grid-2">
            <form method="post" action="/student/flashcards/deck/<?= e($deck['uuid']) ?>/cards" class="fc-panel" id="add">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <div class="fc-head" style="margin-bottom:10px;"><h3>＋ کارت جدید</h3></div>
                <div class="field">
                    <label class="label" for="fc-front">روی کارت</label>
                    <textarea class="input" id="fc-front" name="front" rows="2" maxlength="2000" required dir="auto" data-fc-autofocus></textarea>
                </div>
                <div class="field">
                    <label class="label" for="fc-back">پشت کارت</label>
                    <textarea class="input" id="fc-back" name="back" rows="2" maxlength="2000" required dir="auto"></textarea>
                </div>
                <div class="field">
                    <label class="label" for="fc-hint">راهنما (اختیاری)</label>
                    <input class="input" id="fc-hint" name="hint" maxlength="500" dir="auto">
                </div>
                <button class="btn btn-primary" type="submit" data-lock-on-submit>افزودن کارت</button>
            </form>

            <?php \HeleXa\Core\View::partial('partials.fc_import', [
                'action' => '/student/flashcards/deck/' . $deck['uuid'] . '/import', 'maxRows' => $maxRows, 'withSession' => false,
            ]); ?>
        </div>
    <?php endif; ?>

    <section class="fc-panel">
        <div class="fc-head" style="margin-bottom:12px;">
            <h3>کارت‌ها</h3>
            <?php if ($count > 8): ?>
                <input class="input" type="search" placeholder="جستجو در کارت‌ها…" data-fc-filter style="max-width:240px;">
            <?php endif; ?>
        </div>

        <?php if ($cards === []): ?>
            <div class="fc-empty"><div class="fc-big">📭</div><span>این دسته هنوز کارتی ندارد.</span></div>
        <?php else: ?>
            <div class="fc-cards" data-fc-list>
                <?php foreach ($cards as $i => $card):
                    $st    = $stages[$card['uuid']] ?? ['stage' => 'new', 'starred' => false];
                ?>
                    <?php /* A course card has nothing to open, so it is a plain row;
                             inline handlers are not an option under the site's CSP. */ ?>
                    <<?= $editable ? 'details' : 'div' ?> class="fc-card-row<?= $editable ? '' : ' is-static' ?>" data-fc-item>
                        <<?= $editable ? 'summary' : 'div' ?> class="fc-card-sum">
                            <span class="fc-card-num"><?= e(fa((string) ($i + 1))) ?></span>
                            <span class="fc-card-front" dir="auto"><?= $st['starred'] ? '⭐ ' : '' ?><?= e($card['front']) ?></span>
                            <span class="fc-card-back" dir="auto"><?= $editable ? e($card['back']) : '' ?></span>
                            <span class="fc-stage <?= e($st['stage']) ?>"><?= e($stageLabel[$st['stage']] ?? '') ?></span>
                        </<?= $editable ? 'summary' : 'div' ?>>
                        <?php if ($editable): ?>
                            <div class="fc-card-body">
                                <form method="post" action="/student/flashcards/card/<?= e($card['uuid']) ?>" class="fc-grid-2">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <textarea class="input" name="front" rows="2" maxlength="2000" required dir="auto" aria-label="روی کارت"><?= e($card['front']) ?></textarea>
                                    <textarea class="input" name="back" rows="2" maxlength="2000" required dir="auto" aria-label="پشت کارت"><?= e($card['back']) ?></textarea>
                                    <input class="input" name="hint" maxlength="500" value="<?= e($card['hint'] ?? '') ?>" placeholder="راهنما" dir="auto" aria-label="راهنما">
                                    <div class="fc-inline">
                                        <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
                                        <button class="btn btn-danger btn-sm" type="submit"
                                                formaction="/student/flashcards/card/<?= e($card['uuid']) ?>/delete"
                                                data-fc-confirm="این کارت حذف شود؟">حذف</button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </<?= $editable ? 'details' : 'div' ?>>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($editable): ?>
        <details class="fc-panel">
            <summary style="cursor:pointer; font-weight:600;">⚙ تنظیمات دسته</summary>
            <form method="post" action="/student/flashcards/deck/<?= e($deck['uuid']) ?>" class="fc-inline" style="margin-top:12px;">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <div class="field"><label class="label">عنوان</label><input class="input" name="title" value="<?= e($deck['title']) ?>" maxlength="191" required></div>
                <div class="field"><label class="label">توضیح</label><input class="input" name="description" value="<?= e($deck['description'] ?? '') ?>" maxlength="500"></div>
                <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
            </form>
            <form method="post" action="/student/flashcards/deck/<?= e($deck['uuid']) ?>/delete"
                  data-confirm="این دسته با همه کارت‌هایش حذف شود؟ این کار برگشت‌پذیر نیست." style="margin-top:12px;">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button class="btn btn-danger btn-sm" type="submit">حذف دسته</button>
            </form>
        </details>
    <?php endif; ?>
</div>
