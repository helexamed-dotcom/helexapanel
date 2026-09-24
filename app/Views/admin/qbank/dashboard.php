<?php
/**
 * Question bank overview: counts, the publication switch, and the درس list
 * with how many students hold each.
 *
 * @var string $status
 * @var array  $statuses
 * @var string $comingSoonText
 * @var int    $imageMaxKb
 * @var array  $stats
 * @var array  $roots
 * @var array  $accessCounts
 */
$icon = static fn (string $n) => \HeleXa\Core\View::partial('partials.icon', ['name' => $n]);
$statusChip = ['published' => 'chip-green', 'coming_soon' => 'chip-amber', 'disabled' => 'chip-gray'][$status] ?? 'chip-gray';
?>
<div class="qb-page">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon"><?php $icon('qbank'); ?></div>
            <div>
                <h2>بانک سوال</h2>
                <p>درس‌ها را بساز، سوال‌ها را طبقه‌بندی کن و برای هر دانشجو تعیین کن کدام درس برایش باز باشد.</p>
            </div>
        </div>
        <div class="qb-hero-actions">
            <span class="stat-chip <?= e($statusChip) ?>">وضعیت: <?= e($statuses[$status] ?? $status) ?></span>
            <?php if (can('qbank.manage_questions')): ?>
                <a class="btn btn-primary" href="/admin/qbank/questions/create">+ سوال جدید</a>
            <?php endif; ?>
        </div>
    </section>

    <div class="qb-stats">
        <a class="qb-stat is-blue" href="/admin/qbank/questions">
            <span class="qb-stat-label">همه سوال‌ها</span>
            <span class="qb-stat-value"><?= e(fa((string) $stats['total'])) ?></span>
        </a>
        <a class="qb-stat is-green" href="/admin/qbank/questions?status=published">
            <span class="qb-stat-label">منتشرشده</span>
            <span class="qb-stat-value"><?= e(fa((string) $stats['published'])) ?></span>
        </a>
        <a class="qb-stat is-amber" href="/admin/qbank/questions?status=draft">
            <span class="qb-stat-label">پیش‌نویس</span>
            <span class="qb-stat-value"><?= e(fa((string) $stats['draft'])) ?></span>
        </a>
        <a class="qb-stat" href="/admin/qbank/questions?subject_id=unfiled">
            <span class="qb-stat-label">بدون طبقه‌بندی</span>
            <span class="qb-stat-value"><?= e(fa((string) $stats['unfiled'])) ?></span>
        </a>
        <a class="qb-stat is-purple" href="/admin/qbank/subjects">
            <span class="qb-stat-label">درس‌ها</span>
            <span class="qb-stat-value"><?= e(fa((string) $stats['subjects'])) ?></span>
        </a>
        <a class="qb-stat" href="/admin/qbank/tags">
            <span class="qb-stat-label">برچسب‌ها</span>
            <span class="qb-stat-value"><?= e(fa((string) $stats['tags'])) ?></span>
        </a>
    </div>

    <div class="qb-grid-2">
        <section class="qb-section">
            <div class="qb-section-head">
                <h3><?php $icon('layers'); ?> درس‌ها</h3>
                <a class="btn btn-ghost btn-sm" href="/admin/qbank/subjects">مدیریت ساختار</a>
            </div>
            <?php if ($roots === []): ?>
                <div class="empty">هنوز درسی ساخته نشده. از «دروس و زیردروس» شروع کن — مثلاً «آناتومی».</div>
            <?php else: ?>
                <div class="qb-list">
                    <?php foreach ($roots as $root): ?>
                        <div class="qb-row">
                            <div>
                                <div class="qb-row-stem" style="margin:0;font-weight:600;">
                                    <?= e($root['title']) ?>
                                    <?php if ((int) $root['is_active'] !== 1): ?>
                                        <span class="stat-chip chip-gray">غیرفعال</span>
                                    <?php endif; ?>
                                </div>
                                <div class="qb-row-meta">
                                    <span><?= e(fa((string) ($accessCounts[(int) $root['id']] ?? 0))) ?> دانشجو دسترسی دارند</span>
                                </div>
                            </div>
                            <div class="qb-row-actions">
                                <a class="btn btn-ghost btn-sm" href="/admin/qbank/questions?subject_id=<?= (int) $root['id'] ?>">سوال‌ها</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="qb-section">
            <div class="qb-section-head">
                <h3><?php $icon('settings'); ?> انتشار و تنظیمات</h3>
            </div>
            <?php if (can('qbank.publish')): ?>
                <form method="post" action="/admin/qbank/settings">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <div class="field">
                        <label class="label">وضعیت بانک سوال برای دانشجویان</label>
                        <div class="qb-status-switch" role="radiogroup">
                            <?php foreach ($statuses as $key => $label): ?>
                                <label>
                                    <input type="radio" name="status" value="<?= e($key) ?>" <?= $status === $key ? 'checked' : '' ?>>
                                    <span><?= e($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="qb-hint" style="margin-top:6px;">
                            «به‌زودی»: منو دیده می‌شود ولی محتوا بسته است. «غیرفعال»: از منوی دانشجو حذف می‌شود.
                            حتی در حالت منتشرشده، هر دانشجو فقط درس‌هایی را می‌بیند که برایش فعال کرده‌ای.
                        </p>
                    </div>
                    <div class="field">
                        <label class="label" for="qb-soon">متن صفحه «به‌زودی»</label>
                        <textarea class="input" id="qb-soon" name="coming_soon_text" rows="2" maxlength="500"><?= e($comingSoonText) ?></textarea>
                    </div>
                    <div class="field">
                        <label class="label" for="qb-kb">حداکثر حجم هر تصویر سوال (کیلوبایت)</label>
                        <input class="input" id="qb-kb" type="number" name="image_max_kb" min="256" max="20480" value="<?= (int) $imageMaxKb ?>" dir="ltr">
                    </div>
                    <div class="field">
                        <label class="remember-row"><input type="checkbox" name="xp_enabled" value="1" <?= \HeleXa\Services\QuestionBank\QbXp::enabled() ? 'checked' : '' ?>>
                            <span>امتیاز XP برای پاسخ درست در اولین تلاش (مثل جزیره بالین)</span></label>
                        <div class="qb-grid-3" style="grid-template-columns:repeat(4,minmax(0,1fr));">
                            <?php foreach (\HeleXa\Services\QuestionBank\QbXp::DEFAULTS as $lvl => $def): ?>
                                <div class="field" style="margin:0;">
                                    <label class="label" for="qb-xp-<?= e($lvl) ?>"><?= e(\HeleXa\Models\QuestionBank\QbQuestionRepository::DIFFICULTY_LABELS[$lvl]) ?></label>
                                    <input class="input" id="qb-xp-<?= e($lvl) ?>" type="number" min="0" max="500" name="xp_<?= e($lvl) ?>" value="<?= (int) \HeleXa\Services\QuestionBank\QbXp::amountFor($lvl) ?>" dir="ltr">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button class="btn btn-primary" type="submit" data-lock-on-submit>ذخیره</button>
                </form>
            <?php else: ?>
                <p class="qb-hint">تغییر وضعیت انتشار به دسترسی «انتشار سوالات» نیاز دارد.</p>
            <?php endif; ?>
        </section>
    </div>
</div>
