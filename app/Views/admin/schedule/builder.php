<?php
/**
 * One schedule: its details, its sessions by day, and the session form
 * (which edits a session when $editItem is set).
 *
 * @var array      $schedule
 * @var array      $byDay
 * @var array|null $editItem
 * @var array      $pickCounts
 * @var array      $terms
 * @var array      $groups
 * @var array      $weekdays
 * @var array      $courses
 * @var array      $subjects
 */
$base    = '/admin/schedule/' . (int) $schedule['id'];
$editing = $editItem !== null;
$v       = $editItem ?? [];
$hm      = static fn (?string $t): string => $t !== null ? substr($t, 0, 5) : '';
?>
<div class="card">
    <div class="card-head">
        <div>
            <h3 class="card-title" style="margin:0;"><?= e($schedule['title']) ?></h3>
            <div style="color:var(--ink-3); font-size:12.5px;">
                <?= e($schedule['term_title']) ?><?= $schedule['group_title'] ? ' / ' . e($schedule['group_title']) : ' / کل ترم' ?>
                · <?= (int) $schedule['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
            </div>
        </div>
        <a class="btn btn-ghost btn-sm" href="/admin/schedule">بازگشت</a>
    </div>

    <details class="sched-edit">
        <summary>✏️ ویرایش مشخصات برنامه</summary>
        <form method="post" action="<?= e($base) ?>" class="sched-edit-form">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <div class="field"><label class="label" for="se-title">عنوان</label>
                <input class="input" id="se-title" name="title" required maxlength="191" value="<?= e($schedule['title']) ?>"></div>
            <div class="field"><label class="label" for="se-term">ترم</label>
                <select class="input" id="se-term" name="term_id" required>
                    <?php foreach ($terms as $term): ?>
                        <option value="<?= (int) $term['id'] ?>" <?= (int) $term['id'] === (int) $schedule['term_id'] ? 'selected' : '' ?>><?= e($term['title']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="field"><label class="label" for="se-group">گروه</label>
                <select class="input" id="se-group" name="group_id">
                    <option value="">کل ترم</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>" <?= (int) $group['id'] === (int) ($schedule['group_id'] ?? 0) ? 'selected' : '' ?>>
                            <?= e($group['term_title']) ?> — <?= e($group['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="muted">گروه باید زیرمجموعه همان ترم باشد، وگرنه «کل ترم» ذخیره می‌شود.</small></div>
            <div class="field"><label class="label" for="se-year">سال تحصیلی</label>
                <input class="input" id="se-year" name="academic_year" value="<?= e($schedule['academic_year'] ?? '') ?>" placeholder="۱۴۰۵-۱۴۰۶"></div>
            <div class="field"><label class="label" for="se-from">اعتبار از (میلادی، اختیاری)</label>
                <input class="input" id="se-from" type="date" name="effective_from" dir="ltr" value="<?= e($schedule['effective_from'] ?? '') ?>"></div>
            <div class="field"><label class="label" for="se-to">اعتبار تا (میلادی، اختیاری)</label>
                <input class="input" id="se-to" type="date" name="effective_to" dir="ltr" value="<?= e($schedule['effective_to'] ?? '') ?>"></div>
            <label class="switch-row" style="border:0; padding:0;">
                <input type="checkbox" name="is_active" value="1" <?= (int) $schedule['is_active'] === 1 ? 'checked' : '' ?>><span>فعال</span>
            </label>
            <div class="row-actions" style="margin:0;"><button class="btn btn-primary btn-sm" type="submit" data-lock-on-submit>ذخیره مشخصات</button></div>
        </form>
    </details>

    <form method="post" action="<?= e($editing ? $base . '/items/' . (int) $v['id'] : $base . '/items') ?>"
          class="filters sched-item-form<?= $editing ? ' is-editing' : '' ?>" id="item-form">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <?php if ($editing): ?>
            <div class="sched-item-note">✏️ در حال ویرایش «<?= e($v['title']) ?>»</div>
        <?php endif; ?>
        <select class="input" name="weekday" required style="max-width:120px;" aria-label="روز">
            <?php foreach ($weekdays as $index => $label): ?>
                <option value="<?= $index ?>" <?= $editing && (int) $v['weekday'] === $index ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="input" type="time" name="start_time" dir="ltr" required style="max-width:120px;" aria-label="ساعت شروع" value="<?= e($hm($v['start_time'] ?? null)) ?>">
        <input class="input" type="time" name="end_time" dir="ltr" required style="max-width:120px;" aria-label="ساعت پایان" value="<?= e($hm($v['end_time'] ?? null)) ?>">
        <input class="input" name="title" id="item-title" placeholder="عنوان جلسه (نام درس)" required value="<?= e($v['title'] ?? '') ?>">
        <select class="input" name="subject_id" id="item-subject" data-title-source aria-label="درس">
            <option value="">بدون انتخاب از فهرست دروس</option>
            <?php foreach ($subjects as $subject): ?>
                <option value="<?= (int) $subject['id'] ?>" data-title="<?= e($subject['title']) ?>" <?= (int) ($v['subject_id'] ?? 0) === (int) $subject['id'] ? 'selected' : '' ?>>
                    <?= e($subject['title']) ?><?= $subject['major_title'] ? ' — ' . e($subject['major_title']) : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="input" name="course_id" title="اختیاری؛ فقط برای لینک به محتوای همان دوره در سایت" aria-label="دوره">
            <option value="">بدون اتصال به دوره سایت</option>
            <?php foreach ($courses as $course): ?>
                <option value="<?= (int) $course['id'] ?>" <?= (int) ($v['course_id'] ?? 0) === (int) $course['id'] ? 'selected' : '' ?>><?= e($course['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="input" name="teacher" placeholder="استاد" style="max-width:150px;" value="<?= e($v['teacher'] ?? '') ?>">
        <input class="input" name="location" placeholder="مکان" style="max-width:130px;" value="<?= e($v['location'] ?? '') ?>">
        <?php if ($editing): ?>
            <button class="btn btn-primary btn-sm" type="submit" data-lock-on-submit>ذخیره تغییرات</button>
            <a class="btn btn-ghost btn-sm" href="<?= e($base) ?>">انصراف</a>
        <?php else: ?>
            <button class="btn btn-primary btn-sm" type="submit" data-lock-on-submit>افزودن جلسه</button>
        <?php endif; ?>
    </form>
    <p style="color:var(--ink-3); font-size:11.5px; margin:-8px 0 14px;">
        «عنوان جلسه» همان چیزی است که در برنامه نمایش داده می‌شود. برای اینکه دانشجو بتواند یک درس را بین گروه‌ها
        انتخاب کند، نام درس را در برنامه همه گروه‌ها <strong>یکسان</strong> بنویسید (یا از فهرست دروس انتخاب کنید).
        برای ویرایش یک جلسه، روی «ویرایش» زیر همان جلسه بزنید.
    </p>

    <div class="week-grid">
        <?php foreach ($weekdays as $index => $label): ?>
            <div class="week-day">
                <div class="week-day-head"><?= e($label) ?></div>
                <?php if (empty($byDay[$index])): ?>
                    <div class="week-empty">—</div>
                <?php else: ?>
                    <?php foreach ($byDay[$index] as $item): ?>
                        <div class="class-card<?= $editing && (int) $v['id'] === (int) $item['id'] ? ' is-editing' : '' ?>"
                             style="border-right-color: <?= e($item['color'] ?: ($item['subject_color'] ?? '#2563eb')) ?>">
                            <div class="class-time"><?= e(fa(substr((string) $item['start_time'], 0, 5))) ?> — <?= e(fa(substr((string) $item['end_time'], 0, 5))) ?></div>
                            <div class="class-title"><?= e($item['title']) ?></div>
                            <?php if ($item['teacher'] || $item['location']): ?>
                                <div class="class-meta"><?= e(trim(($item['teacher'] ?? '') . ' ' . ($item['location'] ? '· ' . $item['location'] : ''))) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($pickCounts[(int) $item['id']])): ?>
                                <div class="class-meta">👥 <?= e(fa((string) $pickCounts[(int) $item['id']])) ?> دانشجو اخذ کرده‌اند</div>
                            <?php endif; ?>
                            <div class="sched-card-actions">
                                <a class="btn btn-ghost btn-sm" href="<?= e($base) ?>?edit=<?= (int) $item['id'] ?>#item-form">ویرایش</a>
                                <form method="post" action="<?= e($base) ?>/items/<?= (int) $item['id'] ?>/delete" data-confirm="این جلسه حذف شود؟">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>