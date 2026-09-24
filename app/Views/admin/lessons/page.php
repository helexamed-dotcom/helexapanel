<?php
/**
 * One page of a درسنامه: its title, زیردرس, text and tags.
 *
 * @var array      $lesson
 * @var array|null $pageRow
 * @var array      $outline
 * @var int        $sectionId
 * @var list<int>  $tagIds
 * @var array      $allTags
 * @var array|null $prev
 * @var array|null $next
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$base = '/admin/lessons/' . $lesson['uuid'];
$action = $pageRow === null ? $base . '/pages' : $base . '/pages/' . $pageRow['uuid'];
?>
<form class="le" method="post" action="<?= e($action) ?>" data-lesson-form data-upload="/admin/lessons/media"
      data-draft-key="lesson-page-<?= e($pageRow['uuid'] ?? ('new-' . $lesson['uuid'] . '-' . $sectionId)) ?>">
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <input type="hidden" name="body_html" data-le-output>

    <div class="le-main">
        <nav class="lo-crumbs">
            <a href="/admin/lessons">درسنامه‌ها</a>
            <a href="<?= e($base . '/edit#outline') ?>"><?= e($lesson['title']) ?></a>
            <span><?= $pageRow === null ? 'صفحه تازه' : e($pageRow['title']) ?></span>
        </nav>
        <input class="le-title" name="title" maxlength="191" value="<?= e($pageRow['title'] ?? '') ?>" placeholder="عنوان صفحه — مثلاً «ساختار دیواره سلولی»" required>
        <?php View::partial('partials.rich_editor', ['html' => (string) ($pageRow['body_html'] ?? ''), 'placeholder' => 'متن این صفحه را بنویسید یا از ورد بچسبانید…']); ?>
        <?php if ($prev || $next): ?>
            <nav class="lo-pager">
                <?php if ($prev): ?><a href="<?= e($base . '/pages/' . $prev['uuid'] . '/edit') ?>">→ <?= e($prev['title']) ?></a><?php else: ?><span></span><?php endif; ?>
                <?php if ($next): ?><a href="<?= e($base . '/pages/' . $next['uuid'] . '/edit') ?>"><?= e($next['title']) ?> ←</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>

    <aside class="le-side">
        <section class="ad-card">
            <label class="hx-field">زیردرس
                <select class="input" name="section_id" required>
                    <?php foreach ($outline as $i => $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $sectionId ? 'selected' : '' ?>><?= e(fa((string) ($i + 1))) ?>. <?= e($s['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="le-actions">
                <button class="btn btn-primary" type="submit" name="then" value="back"><?php $icon('check', 16); ?> ذخیره</button>
                <button class="btn btn-ghost" type="submit" name="then" value="stay">ذخیره و ادامه</button>
                <button class="btn btn-ghost" type="submit" name="then" value="new"><?php $icon('plus', 15); ?> ذخیره و صفحه بعد</button>
            </div>
            <?php if ($pageRow !== null): ?>
                <a class="hx-link" href="/student/lessons/<?= e($lesson['uuid']) ?>/p/<?= e($pageRow['uuid']) ?>?preview=1" target="_blank">پیش‌نمایش دانشجو ←</a>
            <?php endif; ?>
        </section>

        <section class="ad-card">
            <?php View::partial('partials.tag_picker', ['allTags' => $allTags, 'selected' => $tagIds]); ?>
        </section>

        <section class="ad-card lo-mini-outline">
            <b>فهرست «<?= e($lesson['title']) ?>»</b>
            <?php foreach ($outline as $i => $s): ?>
                <div class="lo-mini-sec"><?= e(fa((string) ($i + 1))) ?>. <?= e($s['title']) ?></div>
                <?php foreach ($s['pages'] as $p): ?>
                    <a class="lo-mini-page<?= $pageRow && (int) $p['id'] === (int) $pageRow['id'] ? ' is-on' : '' ?>" href="<?= e($base . '/pages/' . $p['uuid'] . '/edit') ?>"><?= e($p['title']) ?></a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </section>
    </aside>
</form>
