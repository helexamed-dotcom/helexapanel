<?php
/**
 * @var array  $choices
 * @var bool   $custom
 * @var array  $weekdays
 * @var string $back
 */
?>
<div class="pick-page">
    <section class="card pick-head">
        <div>
            <h2>🗓 درس‌های اخذشده</h2>
            <p class="muted">هر درسی را که برداشته‌اید از همان گروه و ترمی که در آن ثبت‌نام کرده‌اید تیک بزنید.
                فقط کلاس‌های تیک‌خورده در تقویم و داشبورد شما می‌آیند.</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= e($back) ?>">بازگشت</a>
    </section>
    <?php \HeleXa\Core\View::partial('partials.class_picker', [
        'choices'  => $choices,
        'weekdays' => $weekdays,
        'custom'   => $custom,
        'action'   => '/student/schedule/choose',
        'hidden'   => str_starts_with($back, '/account/') ? ['from' => 'profile'] : [],
        'token'    => $csrf_token,
    ]); ?>
</div>