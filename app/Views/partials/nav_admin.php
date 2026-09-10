<div class="nav-label">مدیریت</div>
<a class="nav-item<?= active_when($currentPath, '/admin') ?>" href="/admin" data-tip="داشبورد">
    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'home']); ?> <span class="nav-text">داشبورد</span>
</a>
<?php if (can('manage_students')): ?>
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
<?php if (can('manage_students')): ?>
    <a class="nav-item<?= active_when($currentPath, '/admin/academic') ?>" href="/admin/academic" data-tip="ساختار آموزشی">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'school']); ?> <span class="nav-text">ساختار آموزشی</span>
    </a>
<?php endif; ?>
<?php if (can('manage_schedule')): ?>
    <a class="nav-item<?= active_when($currentPath, '/admin/schedule') ?>" href="/admin/schedule" data-tip="برنامه هفتگی">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'calendar']); ?> <span class="nav-text">برنامه هفتگی</span>
    </a>
<?php endif; ?>
<?php if (can('manage_exams')): ?>
    <a class="nav-item<?= active_when($currentPath, '/admin/exams') ?>" href="/admin/exams" data-tip="امتحانات">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'exam']); ?> <span class="nav-text">امتحانات</span>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/admin/midterms') ?>" href="/admin/midterms" data-tip="میان‌ترم‌ها">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'exam']); ?> <span class="nav-text">میان‌ترم‌ها</span>
    </a>
<?php endif; ?>
<?php if (can('manage_calendar')): ?>
    <a class="nav-item<?= active_when($currentPath, '/admin/calendar') ?>" href="/admin/calendar" data-tip="تقویم">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'layers']); ?> <span class="nav-text">تقویم</span>
    </a>
<?php endif; ?>
<?php if (can('manage_notifications')): ?>
    <a class="nav-item<?= active_when($currentPath, '/admin/notifications') ?>" href="/admin/notifications" data-tip="اطلاعیه‌ها">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'bell']); ?> <span class="nav-text">اطلاعیه‌ها</span>
    </a>
<?php endif; ?>
<?php if (can('manage_messages')): ?>
    <a class="nav-item<?= active_when($currentPath, '/admin/messages') ?>" href="/admin/messages" data-tip="پیام‌ها">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'message']); ?> <span class="nav-text">پیام‌ها</span>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/admin/support') ?>" href="/admin/support" data-tip="پشتیبانی">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'shield']); ?> <span class="nav-text">پشتیبانی</span>
        <?php if (($unreadCounts['support_open'] ?? 0) > 0): ?>
            <span class="nav-badge"><?= e(fa((string) $unreadCounts['support_open'])) ?></span>
        <?php endif; ?>
    </a>
<?php endif; ?>
<?php if (can('manage_settings')): ?>
    <a class="nav-item<?= active_when($currentPath, '/admin/telegram') ?>" href="/admin/telegram" data-tip="ربات تلگرام">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'message']); ?> <span class="nav-text">ربات تلگرام</span>
    </a>
<?php endif; ?>

<div class="nav-label">امنیت</div>
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
<?php if (can('manage_settings')): ?>
    <a class="nav-item<?= active_when($currentPath, '/admin/security') ?>" href="/admin/security" data-tip="بازبینی امنیت">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'shield']); ?> <span class="nav-text">بازبینی امنیت</span>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/admin/settings') ?>" href="/admin/settings" data-tip="تنظیمات">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'settings']); ?> <span class="nav-text">تنظیمات</span>
    </a>
<?php endif; ?>
