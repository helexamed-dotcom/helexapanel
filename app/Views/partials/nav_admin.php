<?php
/**
 * Admin navigation.
 *
 * Seventeen destinations is too many for one flat list, so they are grouped by
 * the job being done. A group's heading is only printed when the signed-in
 * admin can actually reach something inside it, which is why each group is
 * gated on the same permissions as its own items.
 */
$canStudents = can('manage_students');
$canSchedule = can('manage_schedule');
$canExams    = can('manage_exams');
$canCalendar = can('manage_calendar');
$canMessages = can('manage_messages');
$canSettings = can('manage_settings');

$canBalin    = can('balin.view');

$hasLearning = $canStudents || can('manage_courses') || can('manage_packages') || can('manage_content');
$hasPlanning = $canSchedule || $canExams || $canCalendar;
$hasComms    = can('manage_notifications') || $canMessages;
$hasSecurity = can('view_sessions') || can('view_logs') || can('manage_admins') || $canSettings;
?>
<div class="nav-group">
    <div class="nav-label">مرور کلی</div>
    <a class="nav-item<?= active_when($currentPath, '/admin') ?>" href="/admin" data-tip="داشبورد">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'home']); ?> <span class="nav-text">داشبورد</span>
    </a>
</div>

<?php if ($hasLearning): ?>
    <div class="nav-group">
        <div class="nav-label">آموزش</div>
        <?php if ($canStudents): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/students') ?>" href="/admin/students" data-tip="دانشجویان">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'users']); ?> <span class="nav-text">دانشجویان</span>
            </a>
        <?php endif; ?>
        <?php if (can('manage_courses')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/courses') ?>" href="/admin/courses" data-tip="دوره‌ها">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'book']); ?> <span class="nav-text">دوره‌ها</span>
            </a>
        <?php endif; ?>
        <?php if (can('manage_packages')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/packages') ?>" href="/admin/packages" data-tip="پکیج‌ها">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'package']); ?> <span class="nav-text">پکیج‌ها</span>
            </a>
        <?php endif; ?>
        <?php if (can('manage_content')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/content') ?>" href="/admin/content" data-tip="محتوای آموزشی">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'folder']); ?> <span class="nav-text">محتوای آموزشی</span>
            </a>
        <?php endif; ?>
        <?php if ($canStudents): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/academic') ?>" href="/admin/academic" data-tip="ساختار آموزشی">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'school']); ?> <span class="nav-text">ساختار آموزشی</span>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
/**
 * Balin is a module in its own right, not a page — it has its own content
 * tree, its own exams and its own competition — so it gets a group rather
 * than one more entry under teaching.
 */
if ($canBalin): ?>
    <div class="nav-group">
        <div class="nav-label">جزیره بالین</div>
        <a class="nav-item<?= active_when($currentPath, '/admin/balin') ?>" href="/admin/balin" data-tip="مرور جزیره">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'island']); ?> <span class="nav-text">مرور جزیره</span>
        </a>
        <a class="nav-item<?= active_when($currentPath, '/admin/balin/lessons') ?>" href="/admin/balin/lessons" data-tip="درس‌های بالینی">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'stethoscope']); ?> <span class="nav-text">درس‌های بالینی</span>
        </a>
        <?php if (can('balin.manage_skill_tracks')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/balin/skill-tracks') ?>" href="/admin/balin/skill-tracks" data-tip="مهارت‌های بالینی">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'route']); ?> <span class="nav-text">مهارت‌های بالینی</span>
            </a>
        <?php endif; ?>
        <?php if (can('balin.manage_characters')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/balin/characters') ?>" href="/admin/balin/characters" data-tip="شخصیت‌ها">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'users']); ?> <span class="nav-text">شخصیت‌ها</span>
            </a>
        <?php endif; ?>
        <a class="nav-item<?= active_when($currentPath, '/admin/balin/media') ?>" href="/admin/balin/media" data-tip="کتابخانه رسانه">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'folder']); ?> <span class="nav-text">کتابخانه رسانه</span>
        </a>
        <?php if (can('balin.manage_students')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/balin/access') ?>" href="/admin/balin/access" data-tip="دسترسی دانشجویان">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'key']); ?> <span class="nav-text">دسترسی دانشجویان</span>
            </a>
        <?php endif; ?>
        <?php if (can('balin.manage_competition')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/balin/competitions') ?>" href="/admin/balin/competitions" data-tip="رقابت هفتگی">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'trophy']); ?> <span class="nav-text">رقابت هفتگی</span>
            </a>
        <?php endif; ?>
        <?php if (can('balin.manage_rank_titles')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/balin/rank-tiers') ?>" href="/admin/balin/rank-tiers" data-tip="عنوان سطح‌ها">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'sparkle']); ?> <span class="nav-text">عنوان سطح‌ها</span>
            </a>
        <?php endif; ?>
        <?php if (can('balin.view_statistics')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/balin/analytics') ?>" href="/admin/balin/analytics" data-tip="آمار بالین">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'chart']); ?> <span class="nav-text">آمار بالین</span>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($hasPlanning): ?>
    <div class="nav-group">
        <div class="nav-label">برنامه و آزمون</div>
        <?php if ($canSchedule): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/schedule') ?>" href="/admin/schedule" data-tip="برنامه هفتگی">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'calendar']); ?> <span class="nav-text">برنامه هفتگی</span>
            </a>
        <?php endif; ?>
        <?php if ($canExams): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/exams') ?>" href="/admin/exams" data-tip="امتحانات">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'exam']); ?> <span class="nav-text">امتحانات</span>
            </a>
            <a class="nav-item<?= active_when($currentPath, '/admin/midterms') ?>" href="/admin/midterms" data-tip="میان‌ترم‌ها">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'clock']); ?> <span class="nav-text">میان‌ترم‌ها</span>
            </a>
        <?php endif; ?>
        <?php if ($canCalendar): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/calendar') ?>" href="/admin/calendar" data-tip="تقویم">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'layers']); ?> <span class="nav-text">تقویم</span>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($hasComms): ?>
    <div class="nav-group">
        <div class="nav-label">ارتباطات</div>
        <?php if (can('manage_notifications')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/notifications') ?>" href="/admin/notifications" data-tip="اطلاعیه‌ها">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'bell']); ?> <span class="nav-text">اطلاعیه‌ها</span>
            </a>
        <?php endif; ?>
        <?php if ($canMessages): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/messages') ?>" href="/admin/messages" data-tip="پیام‌ها">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'message']); ?> <span class="nav-text">پیام‌ها</span>
            </a>
            <a class="nav-item<?= active_when($currentPath, '/admin/support') ?>" href="/admin/support" data-tip="پشتیبانی">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'support']); ?> <span class="nav-text">پشتیبانی</span>
                <?php if (($unreadCounts['support_open'] ?? 0) > 0): ?>
                    <span class="nav-badge"><?= e(fa((string) $unreadCounts['support_open'])) ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($hasSecurity): ?>
    <div class="nav-group">
        <div class="nav-label">امنیت و تنظیمات</div>
        <?php if (can('view_sessions')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/sessions') ?>" href="/admin/sessions" data-tip="نشست‌ها">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'clock']); ?> <span class="nav-text">نشست‌ها</span>
            </a>
        <?php endif; ?>
        <?php if (can('view_logs')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/logs') ?>" href="/admin/logs" data-tip="گزارش فعالیت">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'list']); ?> <span class="nav-text">گزارش فعالیت</span>
            </a>
        <?php endif; ?>
        <?php if (can('manage_admins')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/admins') ?>" href="/admin/admins" data-tip="مدیران">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'user']); ?> <span class="nav-text">مدیران</span>
            </a>
        <?php endif; ?>
        <?php if ($canSettings): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/security') ?>" href="/admin/security" data-tip="بازبینی امنیت">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'shield']); ?> <span class="nav-text">بازبینی امنیت</span>
            </a>
            <a class="nav-item<?= active_when($currentPath, '/admin/sms') ?>" href="/admin/sms" data-tip="تنظیمات پیامک">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'message']); ?> <span class="nav-text">تنظیمات پیامک</span>
            </a>
            <a class="nav-item<?= active_when($currentPath, '/admin/settings') ?>" href="/admin/settings" data-tip="تنظیمات">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'settings']); ?> <span class="nav-text">تنظیمات</span>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>
