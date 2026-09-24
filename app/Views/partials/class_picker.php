<?php
/**
 * The "which classes have I taken" form, shared by the profile and the
 * stand-alone choice page.
 *
 * @var array  $choices   from StudentSchedule::choices()
 * @var array  $weekdays
 * @var bool   $custom
 * @var string $action    where the form posts
 * @var array  $hidden    extra hidden fields
 * @var string $token     CSRF token
 */
$toMinutes = static fn (string $t): int => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
?>
<form method="post" action="<?= e($action) ?>" class="pick-page" data-pick-form>
    <input type="hidden" name="_token" value="<?= e($token) ?>">
    <?php foreach ($hidden ?? [] as $name => $value): ?>
        <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
    <?php endforeach; ?>

    <?php foreach ($choices as $term): ?>
        <section class="card pick-term">
            <div class="card-head">
                <h3 class="card-title" style="margin:0;"><?= e($term['term_title']) ?></h3>
                <span class="muted" style="font-size:12px;"><?= e(fa((string) count($term['lessons']))) ?> درس</span>
            </div>

            <?php foreach ($term['lessons'] as $lesson): ?>
                <div class="pick-lesson">
                    <div class="pick-lesson-title"><?= e($lesson['title']) ?></div>
                    <div class="pick-options">
                        <?php foreach ($lesson['options'] as $opt):
                            $ids   = array_map(static fn (array $i): int => (int) $i['id'], $opt['items']);
                            $slots = array_map(static fn (array $i): string =>
                                (int) $i['weekday'] . '-' . $toMinutes((string) $i['start_time']) . '-' . $toMinutes((string) $i['end_time']),
                                $opt['items']);
                        ?>
                            <label class="pick-opt">
                                <input type="checkbox" name="units[]" value="<?= e(implode(',', $ids)) ?>"
                                       data-slots="<?= e(implode(';', $slots)) ?>"
                                       data-title="<?= e($term['term_id'] . ':' . $lesson['title']) ?>"
                                       <?= $custom && $opt['checked'] ? 'checked' : '' ?>>
                                <span class="pick-opt-body">
                                    <span class="pick-opt-group">
                                        <?= e($opt['label']) ?>
                                        <?php if ($opt['own']): ?><small class="stat-chip chip-blue">گروه شما</small><?php endif; ?>
                                    </span>
                                    <?php foreach ($opt['items'] as $item): ?>
                                        <span class="pick-slot">
                                            <?= e($weekdays[(int) $item['weekday']] ?? '') ?>
                                            <b dir="ltr"><?= e(fa(substr((string) $item['start_time'], 0, 5))) ?>–<?= e(fa(substr((string) $item['end_time'], 0, 5))) ?></b>
                                            <?php if ($item['teacher']): ?> · <?= e($item['teacher']) ?><?php endif; ?>
                                            <?php if ($item['location']): ?> · <?= e($item['location']) ?><?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                    <span class="pick-clash" hidden>⚠ با کلاس دیگری هم‌زمان است</span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

    <div class="pick-bar">
        <span class="muted" data-pick-count></span>
        <?php if ($custom): ?>
            <button class="btn btn-ghost btn-sm" type="submit" name="reset" value="1" data-pick-reset>پاک کردن انتخاب‌ها</button>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit" data-lock-on-submit>ذخیره درس‌های اخذشده</button>
    </div>
</form>