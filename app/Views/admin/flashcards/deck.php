<?php
/**
 * One session of a course: its cards, add, edit, import.
 *
 * @var array $deck
 * @var array $cards
 * @var int   $page
 * @var int   $pages
 * @var int   $offset
 * @var int   $maxRows
 * @var list<int> $tagIds
 * @var array $allTags
 */
$here = '/admin/flashcards/deck/' . $deck['uuid'];
$back = $here . ($page > 1 ? '?page=' . $page : '');
?>
<div class="fc-page">
    <section class="fc-hero fc-c-<?= e($deck['course_color'] ?? 'blue') ?>"
             style="background: radial-gradient(80% 120% at 100% 0%, rgba(255,255,255,.22), transparent 60%), linear-gradient(135deg, var(--fc-a), var(--fc-b));">
        <div style="position:relative; z-index:1;">
            <p style="margin-bottom:4px;"><?= e($deck['course_title']) ?></p>
            <h2><?= e($deck['title']) ?></h2>
            <div class="fc-pills"><span class="fc-pill">🃏 <b><?= e(fa((string) $deck['card_count'])) ?></b> کارت</span></div>
        </div>
        <a class="btn btn-ghost" href="/admin/flashcards/course/<?= e($deck['course_uuid']) ?>" style="position:relative; z-index:1;">بازگشت به درس</a>
    </section>

    <form method="post" action="<?= e($here) ?>/tags" class="fc-panel fc-tags-panel">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <?php \HeleXa\Core\View::partial('partials.tag_picker', ['allTags' => $allTags, 'selected' => $tagIds,
            'hint' => 'مرور کارت‌های این جلسه در تحلیل عملکرد زیر همین برچسب‌ها حساب می‌شود؛ همان برچسب‌های درسنامه و بانک سوال.']); ?>
        <button class="btn btn-primary btn-sm" type="submit">ذخیره برچسب‌ها</button>
    </form>

    <div class="fc-grid-2">
        <form method="post" action="<?= e($here) ?>/cards" class="fc-panel" id="add">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <div class="fc-head" style="margin-bottom:10px;"><h3>＋ کارت جدید</h3></div>
            <div class="field"><label class="label" for="a-front">روی کارت</label>
                <textarea class="input" id="a-front" name="front" rows="2" maxlength="2000" required dir="auto" data-fc-autofocus></textarea></div>
            <div class="field"><label class="label" for="a-back">پشت کارت</label>
                <textarea class="input" id="a-back" name="back" rows="2" maxlength="2000" required dir="auto"></textarea></div>
            <div class="field"><label class="label" for="a-hint">راهنما (اختیاری)</label>
                <input class="input" id="a-hint" name="hint" maxlength="500" dir="auto"></div>
            <button class="btn btn-primary" type="submit" data-lock-on-submit>افزودن کارت</button>
        </form>

        <?php \HeleXa\Core\View::partial('partials.fc_import', [
            'action' => $here . '/import', 'maxRows' => $maxRows, 'withSession' => false,
        ]); ?>
    </div>

    <section class="fc-panel">
        <div class="fc-head" style="margin-bottom:12px;">
            <h3>کارت‌ها</h3>
            <?php if (count($cards) > 8): ?>
                <input class="input" type="search" placeholder="جستجو در این صفحه…" data-fc-filter style="max-width:240px;">
            <?php endif; ?>
        </div>

        <?php if ($cards === []): ?>
            <div class="fc-empty"><div class="fc-big">📭</div><span>این جلسه هنوز کارتی ندارد.</span></div>
        <?php else: ?>
            <div class="fc-cards">
                <?php foreach ($cards as $i => $card): ?>
                    <details class="fc-card-row" data-fc-item>
                        <summary class="fc-card-sum">
                            <span class="fc-card-num"><?= e(fa((string) ($offset + $i + 1))) ?></span>
                            <span class="fc-card-front" dir="auto"><?= e($card['front']) ?></span>
                            <span class="fc-card-back" dir="auto"><?= e($card['back']) ?></span>
                            <span class="fc-stage review">ویرایش</span>
                        </summary>
                        <div class="fc-card-body">
                            <form method="post" action="/admin/flashcards/card/<?= e($card['uuid']) ?>" class="fc-grid-2">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <input type="hidden" name="return_to" value="<?= e($back) ?>">
                                <textarea class="input" name="front" rows="2" maxlength="2000" required dir="auto" aria-label="روی کارت"><?= e($card['front']) ?></textarea>
                                <textarea class="input" name="back" rows="2" maxlength="2000" required dir="auto" aria-label="پشت کارت"><?= e($card['back']) ?></textarea>
                                <input class="input" name="hint" maxlength="500" value="<?= e($card['hint'] ?? '') ?>" placeholder="راهنما" dir="auto" aria-label="راهنما">
                                <div class="fc-inline">
                                    <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
                                    <button class="btn btn-danger btn-sm" type="submit"
                                            formaction="/admin/flashcards/card/<?= e($card['uuid']) ?>/delete"
                                            data-fc-confirm="این کارت حذف شود؟ پیشرفت دانشجویان روی آن هم پاک می‌شود.">حذف</button>
                                </div>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>

            <?php if ($pages > 1): ?>
                <nav class="pager" aria-label="صفحه‌بندی">
                    <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
                        <a class="pager-item<?= $p === $page ? ' is-current' : '' ?>" href="<?= e($here) ?>?page=<?= $p ?>"><?= e(fa((string) $p)) ?></a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <details class="fc-panel">
        <summary style="cursor:pointer; font-weight:600;">⚙ تنظیمات جلسه</summary>
        <form method="post" action="<?= e($here) ?>" class="fc-inline" style="margin-top:12px;">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <div class="field"><label class="label">عنوان</label>
                <input class="input" name="title" value="<?= e($deck['title']) ?>" maxlength="191" required></div>
            <div class="field"><label class="label">توضیح</label>
                <input class="input" name="description" value="<?= e($deck['description'] ?? '') ?>" maxlength="500"></div>
            <div class="field narrow"><label class="label">ترتیب</label>
                <input class="input" type="number" name="sort_order" value="<?= (int) $deck['sort_order'] ?>" dir="ltr"></div>
            <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
            <button class="btn btn-danger btn-sm" type="submit" formaction="<?= e($here) ?>/delete"
                    data-fc-confirm="این جلسه با همه کارت‌هایش حذف شود؟">حذف جلسه</button>
        </form>
    </details>
</div>
