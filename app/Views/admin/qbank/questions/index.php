<?php
/**
 * The question list with filters.
 *
 * @var array $questions
 * @var array $tagsByQuestion
 * @var array $filters
 * @var array $tree
 * @var array $tags
 * @var array $difficulties
 * @var int   $page
 * @var int   $pages
 * @var int   $total
 */
$canWrite   = can('qbank.manage_questions');
$canPublish = can('qbank.publish');
$canBulk    = $canWrite || $canPublish;
$indent     = [1 => '', 2 => '— ', 3 => '—— '];
$back       = '/admin/qbank/questions' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$query      = array_filter($filters, static fn ($v) => $v !== '' && $v !== 0 && $v !== null);
?>
<div class="qb-page">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'exam']); ?></div>
            <div>
                <h2>سوالات</h2>
                <p><?= e(fa((string) $total)) ?> سوال با فیلترهای فعلی</p>
            </div>
        </div>
        <?php if ($canWrite): ?>
            <div class="qb-hero-actions">
                <a class="btn btn-ghost" href="/admin/qbank/transfer">ورود / خروج JSON</a>
                <a class="btn btn-primary" href="/admin/qbank/questions/create">+ سوال جدید</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="qb-section">
        <form method="get" action="/admin/qbank/questions" class="qb-filters">
            <div class="field" style="flex:1 1 220px;">
                <label class="label" for="qf-q">جستجو در متن</label>
                <input class="input" id="qf-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="بخشی از صورت سوال یا پاسخ…">
            </div>
            <?php
            /*
             * Three linked selects instead of one long indented list: picking
             * a درس leaves only its own زیردرس‌ها and عنوان‌ها to choose from.
             * The hidden subject_id carries the deepest choice, which is what
             * the server has always filtered on; f1–f3 are the fallback when
             * JavaScript is off.
             */
            $byId = [];
            foreach ($tree as $row) {
                $byId[(int) $row['id']] = $row;
            }
            $picked = [1 => '', 2 => '', 3 => ''];
            $cursor = ctype_digit((string) $filters['subject_id']) ? ($byId[(int) $filters['subject_id']] ?? null) : null;
            while ($cursor !== null) {
                $picked[(int) $cursor['depth']] = (string) $cursor['id'];
                $cursor = $cursor['parent_id'] !== null ? ($byId[(int) $cursor['parent_id']] ?? null) : null;
            }
            if ($filters['subject_id'] === 'unfiled') {
                $picked[1] = 'unfiled';
            }
            $rootOf = static function (array $row) use ($byId): string {
                while ($row['parent_id'] !== null && isset($byId[(int) $row['parent_id']])) {
                    $row = $byId[(int) $row['parent_id']];
                }
                return (string) $row['id'];
            };
            ?>
            <div class="qb-cascade" data-tree-cascade>
                <input type="hidden" name="subject_id" value="<?= e((string) $filters['subject_id']) ?>" data-tree-value>
                <?php foreach ([1 => 'درس', 2 => 'زیردرس', 3 => 'عنوان'] as $depth => $label): ?>
                    <div class="field" style="flex:1 1 150px;">
                        <label class="label" for="qf-l<?= $depth ?>"><?= e($label) ?></label>
                        <select class="input" id="qf-l<?= $depth ?>" name="f<?= $depth ?>" data-level="<?= $depth ?>">
                            <option value="">همه</option>
                            <?php if ($depth === 1): ?>
                                <option value="unfiled" <?= $picked[1] === 'unfiled' ? 'selected' : '' ?>>بدون طبقه‌بندی</option>
                            <?php endif; ?>
                            <?php foreach ($tree as $row): if ((int) $row['depth'] !== $depth) { continue; } ?>
                                <option value="<?= (int) $row['id'] ?>"
                                        data-parent="<?= $row['parent_id'] === null ? '' : (int) $row['parent_id'] ?>"
                                        data-root="<?= e($rootOf($row)) ?>"
                                        <?= $picked[$depth] === (string) $row['id'] ? 'selected' : '' ?>>
                                    <?= e($row['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="field" style="flex:0 1 130px;">
                <label class="label" for="qf-diff">سختی</label>
                <select class="input" id="qf-diff" name="difficulty">
                    <option value="">همه</option>
                    <?php foreach ($difficulties as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filters['difficulty'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex:0 1 140px;">
                <label class="label" for="qf-tag">برچسب</label>
                <select class="input" id="qf-tag" name="tag_id">
                    <option value="">همه</option>
                    <?php foreach ($tags as $tag): ?>
                        <option value="<?= (int) $tag['id'] ?>" <?= (int) $filters['tag_id'] === (int) $tag['id'] ? 'selected' : '' ?>><?= e($tag['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex:0 1 130px;">
                <label class="label" for="qf-status">وضعیت</label>
                <select class="input" id="qf-status" name="status">
                    <option value="">همه</option>
                    <option value="published" <?= $filters['status'] === 'published' ? 'selected' : '' ?>>منتشرشده</option>
                    <option value="draft" <?= $filters['status'] === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
                </select>
            </div>
            <div class="field" style="flex:0 1 110px;">
                <label class="label" for="qf-per">در هر صفحه</label>
                <select class="input" id="qf-per" name="per_page" data-auto-submit>
                    <?php foreach ([30, 100, 300] as $n): ?>
                        <option value="<?= $n ?>" <?= (int) $filters['per_page'] === $n ? 'selected' : '' ?>><?= e(fa((string) $n)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row-actions" style="margin:0;">
                <button class="btn btn-primary btn-sm" type="submit">اعمال</button>
                <a class="btn btn-ghost btn-sm" href="/admin/qbank/questions">پاک کردن</a>
            </div>
        </form>
    </section>

    <?php if ($questions === []): ?>
        <div class="empty">سوالی با این فیلترها پیدا نشد.</div>
    <?php else: ?>
        <?php if ($canBulk): ?>
            <form method="post" action="/admin/qbank/questions/bulk" id="qb-bulk" class="qb-bulk" data-bulk>
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <input type="hidden" name="back" value="<?= e($back) ?>">
                <label class="qb-check qb-bulk-all">
                    <input type="checkbox" data-bulk-all aria-label="انتخاب همه سوالات این صفحه">
                    <span>انتخاب همه</span>
                </label>
                <span class="qb-bulk-count" data-bulk-count aria-live="polite">هیچ سوالی انتخاب نشده</span>
                <div class="qb-bulk-go">
                    <select class="input" name="action" data-bulk-action aria-label="عملیات گروهی">
                        <option value="">عملیات گروهی…</option>
                        <?php if ($canPublish): ?>
                            <option value="publish">انتشار</option>
                            <option value="draft">لغو انتشار (پیش‌نویس)</option>
                        <?php endif; ?>
                        <?php if ($canWrite): ?>
                            <option value="delete">حذف</option>
                        <?php endif; ?>
                    </select>
                    <button class="btn btn-primary btn-sm" type="submit" data-bulk-submit disabled>اعمال</button>
                </div>
            </form>
        <?php endif; ?>
        <div class="qb-list">
            <?php foreach ($questions as $q):
                $path = array_filter([$q['subject_title'], $q['sub_subject_title'], $q['topic_title']]);
                $bad  = (int) $q['correct_count'] !== 1;
            ?>
                <article class="qb-row<?= $canBulk ? ' has-check' : '' ?>">
                    <?php if ($canBulk): ?>
                        <label class="qb-check">
                            <input type="checkbox" name="ids[]" value="<?= e($q['uuid']) ?>" form="qb-bulk" data-bulk-item
                                   aria-label="انتخاب این سوال">
                        </label>
                    <?php endif; ?>
                    <div>
                        <div class="qb-row-stem">
                            <?php if (!empty($q['stem_text'])): ?>
                                <?= e(mb_substr((string) $q['stem_text'], 0, 240)) ?>
                            <?php else: ?>
                                <span class="qb-unfiled">🖼 سوال تصویری</span>
                            <?php endif; ?>
                        </div>
                        <div class="qb-row-meta">
                            <span class="stat-chip <?= $q['status'] === 'published' ? 'chip-green' : 'chip-gray' ?>">
                                <?= $q['status'] === 'published' ? 'منتشرشده' : 'پیش‌نویس' ?>
                            </span>
                            <span class="qb-diff <?= e($q['difficulty']) ?>"><?= e($difficulties[$q['difficulty']] ?? '') ?></span>
                            <?php if ($path !== []): ?>
                                <span class="qb-path"><?php foreach ($path as $part): ?><span><?= e($part) ?></span><?php endforeach; ?></span>
                            <?php else: ?>
                                <span class="qb-unfiled">بدون طبقه‌بندی</span>
                            <?php endif; ?>
                            <span><?= e(fa((string) $q['option_count'])) ?> گزینه</span>
                            <?php if ($bad): ?>
                                <span class="qb-warn">⚠ پاسخ صحیح مشخص نیست</span>
                            <?php endif; ?>
                            <?php if (!empty($q['stem_image'])): ?><span>🖼</span><?php endif; ?>
                            <?php foreach ($tagsByQuestion[(int) $q['id']] ?? [] as $tag): ?>
                                <span class="stat-chip <?= e($tag['color'] ?: 'chip-gray') ?>"><?= e($tag['title']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="qb-row-actions">
                        <a class="btn btn-ghost btn-sm" href="/admin/qbank/questions/<?= e($q['uuid']) ?>">نمایش</a>
                        <?php if ($canWrite): ?>
                            <a class="btn btn-primary btn-sm" href="/admin/qbank/questions/<?= e($q['uuid']) ?>/edit">ویرایش</a>
                        <?php endif; ?>
                        <?php if ($canPublish): ?>
                            <form method="post" action="/admin/qbank/questions/<?= e($q['uuid']) ?>/status">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <input type="hidden" name="back" value="<?= e($back) ?>">
                                <input type="hidden" name="status" value="<?= $q['status'] === 'published' ? 'draft' : 'published' ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">
                                    <?= $q['status'] === 'published' ? 'لغو انتشار' : 'انتشار' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canWrite): ?>
                            <form method="post" action="/admin/qbank/questions/<?= e($q['uuid']) ?>/delete" data-confirm="این سوال حذف شود؟">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <input type="hidden" name="back" value="<?= e($back) ?>">
                                <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="صفحه‌بندی">
                <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
                    <a class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-ghost' ?>"
                       href="/admin/qbank/questions?<?= e(http_build_query($query + ['page' => $p])) ?>"><?= e(fa((string) $p)) ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
