<div class="nav-label">منوی اصلی</div>
<a class="nav-item<?= active_when($currentPath, '/student') ?>" href="/student" data-tip="داشبورد">
    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'home']); ?> <span class="nav-text">داشبورد</span>
</a>
<a class="nav-item<?= active_when($currentPath, '/student/courses') ?>" href="/student/courses" data-tip="دوره‌های من">
    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'book']); ?> <span class="nav-text">دوره‌های من</span>
</a>
<a class="nav-item<?= active_when($currentPath, '/student/schedule') ?>" href="/student/schedule" data-tip="برنامه هفتگی">
    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'calendar']); ?> <span class="nav-text">برنامه هفتگی</span>
</a>
<a class="nav-item<?= active_when($currentPath, '/student/exams') ?>" href="/student/exams" data-tip="برنامه امتحانات">
    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'exam']); ?> <span class="nav-text">برنامه امتحانات</span>
</a>
<a class="nav-item<?= active_when($currentPath, '/student/midterms') ?>" href="/student/midterms" data-tip="میان‌ترم‌ها">
    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'exam']); ?> <span class="nav-text">میان‌ترم‌ها</span>
</a>
<a class="nav-item<?= active_when($currentPath, '/student/analytics') ?>" href="/student/analytics" data-tip="تحلیل عملکرد">
    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'chart']); ?> <span class="nav-text">تحلیل عملکرد</span>
</a>
<a class="nav-item<?= active_when($currentPath, '/offline') ?>" href="/offline" data-tip="محتوای آفلاین من">
    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'download']); ?> <span class="nav-text">محتوای آفلاین من</span>
</a>
<a class="nav-item<?= active_when($currentPath, '/student/calendar') ?>" href="/student/calendar" data-tip="تقویم درسی">
    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'layers']); ?> <span class="nav-text">تقویم درسی</span>
</a>

<div class="nav-label">ارتباطات</div>
<a class="nav-item<?= active_when($currentPath, '/student/messages') ?>" href="/student/messages" data-tip="پیام‌ها">
    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'message']); ?> <span class="nav-text">پیام‌ها</span>
    <?php if (($unreadCounts['messages'] ?? 0) > 0): ?>
        <span class="nav-badge"><?= e(fa((string) $unreadCounts['messages'])) ?></span>
    <?php endif; ?>
</a>
<a class="nav-item<?= active_when($currentPath, '/student/notifications') ?>" href="/student/notifications" data-tip="اطلاعیه‌ها">
    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'bell']); ?> <span class="nav-text">اطلاعیه‌ها</span>
    <?php if (($unreadCounts['notifications'] ?? 0) > 0): ?>
        <span class="nav-badge"><?= e(fa((string) $unreadCounts['notifications'])) ?></span>
    <?php endif; ?>
</a>
<a class="nav-item<?= active_when($currentPath, '/account') ?>" href="/account/profile" data-tip="پروفایل">
    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'user']); ?> <span class="nav-text">پروفایل</span>
</a>
