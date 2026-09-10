<div class="card">
    <div class="card-head">
        <div>
            <h3 class="card-title" style="margin:0;"><?= e($schedule['title']) ?></h3>
            <div style="color:var(--ink-3); font-size:12.5px;">
                <?= e($schedule['term_title']) ?><?= $schedule['group_title'] ? ' / ' . e($schedule['group_title']) : ' / کل ترم' ?>
            </div>
        </div>
        <a class="btn btn-ghost btn-sm" href="/admin/schedule">بازگشت</a>
    </div>

    <form method="post" action="/admin/schedule/<?= (int) $schedule['id'] ?>/items" class="filters">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <select class="input" name="weekday" required style="max-width:120px;">
            <?php foreach ($weekdays as $index => $label): ?>
                <option value="<?= $index ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="input" type="time" name="start_time" dir="ltr" required style="max-width:120px;">
        <input class="input" type="time" name="end_time" dir="ltr" required style="max-width:120px;">
        <input class="input" name="title" id="item-title" placeholder="عنوان جلسه (نام درس)" required>
        <select class="input" name="subject_id" id="item-subject" data-title-source>
            <option value="">بدون انتخاب از فهرست دروس</option>
            <?php foreach ($subjects as $subject): ?>
                <option value="<?= (int) $subject['id'] ?>" data-title="<?= e($subject['title']) ?>">
                    <?= e($subject['title']) ?><?= $subject['major_title'] ? ' — ' . e($subject['major_title']) : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="input" name="course_id" title="اختیاری؛ فقط برای لینک به محتوای همان دوره در سایت">
            <option value="">بدون اتصال به دوره سایت</option>
            <?php foreach ($courses as $course): ?>
                <option value="<?= (int) $course['id'] ?>"><?= e($course['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="input" name="teacher" placeholder="استاد" style="max-width:150px;">
        <input class="input" name="location" placeholder="مکان" style="max-width:130px;">
        <button class="btn btn-primary btn-sm" type="submit">افزودن جلسه</button>
    </form>
    <p style="color:var(--ink-3); font-size:11.5px; margin:-8px 0 14px;">
        «عنوان جلسه» همیشه همان چیزی است که در برنامه نمایش داده می‌شود و کاملاً دستی و آزاد است.
        انتخاب یک درس از فهرست فقط برای پرشدن خودکار عنوان و یکدست ماندن نام‌ها بین جلسات مختلف
        است؛ اگر می‌خواهید فقط چیزی تایپ کنید بدون اینکه به هیچ درس یا دوره‌ای وصل باشد، همین
        دو گزینه را «بدون انتخاب» و «بدون اتصال» بگذارید. دروس از صفحه «ساختار آموزشی» مدیریت می‌شوند.
    </p>

    <div class="week-grid">
        <?php foreach ($weekdays as $index => $label): ?>
            <div class="week-day">
                <div class="week-day-head"><?= e($label) ?></div>
                <?php if (empty($byDay[$index])): ?>
                    <div class="week-empty">—</div>
                <?php else: ?>
                    <?php foreach ($byDay[$index] as $item): ?>
                        <div class="class-card" style="border-right-color: <?= e($item['color'] ?: ($item['subject_color'] ?? '#2563eb')) ?>">
                            <div class="class-time"><?= e(fa(substr((string) $item['start_time'], 0, 5))) ?> — <?= e(fa(substr((string) $item['end_time'], 0, 5))) ?></div>
                            <div class="class-title"><?= e($item['title']) ?></div>
                            <?php if ($item['teacher'] || $item['location']): ?>
                                <div class="class-meta"><?= e(trim(($item['teacher'] ?? '') . ' ' . ($item['location'] ? '· ' . $item['location'] : ''))) ?></div>
                            <?php endif; ?>
                            <form method="post" action="/admin/schedule/<?= (int) $schedule['id'] ?>/items/<?= (int) $item['id'] ?>/delete" style="margin-top:6px;">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
