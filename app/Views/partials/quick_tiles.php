<?php
/**
 * The dashboard's shortcut tiles.
 *
 * @var int  $fcDue      flashcards due now
 * @var bool $showBalin
 * @var bool $showQbank
 */
$icon = static fn (string $n) => \HeleXa\Core\View::partial('partials.icon', ['name' => $n]);
?>
<div class="quick">
    <a class="quick-tile q-study" href="/student/courses">
        <span class="quick-ico"><?php $icon('book'); ?></span>
        <span><span class="quick-label">مطالعه</span><br><span class="quick-sub">دوره‌ها و پکیج‌ها</span></span>
    </a>
    <a class="quick-tile q-cards" href="/student/flashcards">
        <?php if ($fcDue > 0): ?><span class="quick-badge"><?= e(fa((string) min($fcDue, 999))) ?></span><?php endif; ?>
        <span class="quick-ico"><?php $icon('cards'); ?></span>
        <span><span class="quick-label">فلش‌کارت</span><br>
            <span class="quick-sub"><?= $fcDue > 0 ? e(fa((string) $fcDue)) . ' کارت آماده مرور' : 'مرور روزانه' ?></span></span>
    </a>
    <?php if ($showQbank): ?>
        <a class="quick-tile q-bank" href="/student/qbank">
            <span class="quick-ico"><?php $icon('qbank'); ?></span>
            <span><span class="quick-label">بانک سوال</span><br><span class="quick-sub">تمرین تست</span></span>
        </a>
    <?php endif; ?>
    <?php if ($showBalin): ?>
        <a class="quick-tile q-balin" href="/student/balin">
            <span class="quick-ico"><?php $icon('island'); ?></span>
            <span><span class="quick-label">جزیره بالین</span><br><span class="quick-sub">کیس‌های تعاملی</span></span>
        </a>
    <?php endif; ?>
    <a class="quick-tile q-cal" href="/student/calendar">
        <span class="quick-ico"><?php $icon('calendar'); ?></span>
        <span><span class="quick-label">تقویم</span><br><span class="quick-sub">کلاس‌ها و امتحانات</span></span>
    </a>
</div>