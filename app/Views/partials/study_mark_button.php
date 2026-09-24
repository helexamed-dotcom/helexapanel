<?php
/**
 * «📌 باید بخونم» — adds the thing on this page to «درس‌های من».
 * Posts in place (app.js, [data-study-add]); a plain form without JS.
 *
 * @var string $kind    content | library | qbank_topic | balin_lesson | course
 * @var int    $refId
 * @var string $title
 * @var string $url     where the mark links back to
 */
$userId = (int) (\HeleXa\Services\Auth::id() ?? 0);
$marked = $userId > 0 && (new \HeleXa\Models\StudyMarkRepository())->has($userId, (string) $kind, (int) $refId);
?>
<form method="post" action="/student/study" class="hx-inline" data-study-add>
    <input type="hidden" name="_token" value="<?= e($csrf_token ?? '') ?>">
    <input type="hidden" name="kind" value="<?= e((string) $kind) ?>">
    <input type="hidden" name="ref_id" value="<?= (int) $refId ?>">
    <input type="hidden" name="title" value="<?= e(mb_substr((string) $title, 0, 191)) ?>">
    <input type="hidden" name="url" value="<?= e((string) $url) ?>">
    <input type="hidden" name="back" value="<?= e((string) $url) ?>">
    <button class="hx-mark-btn<?= $marked ? ' is-done' : '' ?>" type="submit" <?= $marked ? 'disabled' : '' ?>>
        <?= $marked ? '✓ در درس‌های من' : '📌 باید بخونم' ?>
    </button>
</form>
