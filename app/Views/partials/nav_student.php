<?php
/**
 * Student navigation.
 *
 * Grouped so the drawer reads as three short lists rather than one long
 * scroll: what you study, what you track, and how you stay in touch.
 */
?>
<div class="nav-group">
    <div class="nav-label"><?= e(t('مطالعه')) ?></div>
    <a class="nav-item<?= active_when($currentPath, '/student') ?>" href="/student" data-tip="<?= e(t('داشبورد')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'home']); ?> <span class="nav-text"><?= e(t('داشبورد')) ?></span>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/student/today') ?>" href="/student/today" data-tip="<?= e(t('امروز من')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'sparkle']); ?> <span class="nav-text"><?= e(t('امروز من')) ?></span>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/student/courses') ?>" href="/student/courses" data-tip="<?= e(t('دوره‌های من')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'book']); ?> <span class="nav-text"><?= e(t('دوره‌های من')) ?></span>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/offline') ?>" href="/offline" data-tip="<?= e(t('محتوای آفلاین من')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'download']); ?> <span class="nav-text"><?= e(t('محتوای آفلاین من')) ?></span>
    </a>
    <?php
    /**
     * The island is in the menu from the moment it exists, even while it is
     * unpublished — clicking it explains what is coming. Only the `disabled`
     * state removes the entry; the server decides what the page then shows.
     */
    if (\HeleXa\Services\Balin\Access::menuVisible()): ?>
        <a class="nav-item<?= active_when($currentPath, '/student/balin') ?>" href="/student/balin" data-tip="<?= e(t('جزیره بالین')) ?>">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'island']); ?>
            <span class="nav-text"><?= e(t('جزیره بالین')) ?></span>
        </a>
    <?php endif; ?>
    <?php if (\HeleXa\Services\QuestionBank\QbAccess::menuVisible()): ?>
        <a class="nav-item<?= active_when($currentPath, '/student/qbank') ?>" href="/student/qbank" data-tip="<?= e(t('بانک سوال')) ?>">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'qbank']); ?>
            <span class="nav-text"><?= e(t('بانک سوال')) ?></span>
        </a>
        <a class="nav-item<?= active_when($currentPath, '/student/my-exams') ?>" href="/student/my-exams" data-tip="<?= e(t('آزمون‌های من')) ?>">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'exam']); ?>
            <span class="nav-text"><?= e(t('آزمون‌های من')) ?></span>
        </a>
    <?php endif; ?>
    <a class="nav-item<?= active_when($currentPath, '/student/study') ?>" href="/student/study" data-tip="<?= e(t('درس‌های من')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'marker']); ?>
        <span class="nav-text"><?= e(t('درس‌های من')) ?></span>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/student/flashcards') ?>" href="/student/flashcards" data-tip="<?= e(t('فلش‌کارت')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'cards']); ?>
        <span class="nav-text"><?= e(t('فلش‌کارت')) ?></span>
    </a>
    <?php if (\HeleXa\Services\Settings::bool('notes_enabled', true)): ?>
    <a class="nav-item<?= active_when($currentPath, '/student/notes') ?>" href="/student/notes" data-tip="<?= e(t('یادداشت‌های من')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'note']); ?>
        <span class="nav-text"><?= e(t('یادداشت‌های من')) ?></span>
    </a>
    <?php endif; ?>
    <a class="nav-item<?= active_when($currentPath, '/student/library') ?>" href="/student/library" data-tip="<?= e(t('کتابخانه')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'folder']); ?>
        <span class="nav-text"><?= e(t('کتابخانه')) ?></span>
    </a>
</div>

<div class="nav-group">
    <div class="nav-label"><?= e(t('برنامه و آزمون')) ?></div>
    <a class="nav-item<?= active_when($currentPath, '/student/schedule') ?>" href="/student/schedule" data-tip="<?= e(t('برنامه هفتگی')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'calendar']); ?> <span class="nav-text"><?= e(t('برنامه هفتگی')) ?></span>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/student/exams') ?>" href="/student/exams" data-tip="<?= e(t('برنامه امتحانات')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'exam']); ?> <span class="nav-text"><?= e(t('برنامه امتحانات')) ?></span>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/student/midterms') ?>" href="/student/midterms" data-tip="<?= e(t('میان‌ترم‌ها')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'clock']); ?> <span class="nav-text"><?= e(t('میان‌ترم‌ها')) ?></span>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/student/calendar') ?>" href="/student/calendar" data-tip="<?= e(t('تقویم درسی')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'layers']); ?> <span class="nav-text"><?= e(t('تقویم درسی')) ?></span>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/student/analytics') ?>" href="/student/analytics" data-tip="<?= e(t('تحلیل عملکرد')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'chart']); ?> <span class="nav-text"><?= e(t('تحلیل عملکرد')) ?></span>
    </a>
</div>

<div class="nav-group">
    <div class="nav-label"><?= e(t('ارتباطات')) ?></div>
    <a class="nav-item<?= active_when($currentPath, '/student/messages') ?>" href="/student/messages" data-tip="<?= e(t('پیام‌ها')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'message']); ?> <span class="nav-text"><?= e(t('پیام‌ها')) ?></span>
        <?php if (($unreadCounts['messages'] ?? 0) > 0): ?>
            <span class="nav-badge"><?= e(fa((string) $unreadCounts['messages'])) ?></span>
        <?php endif; ?>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/student/notifications') ?>" href="/student/notifications" data-tip="<?= e(t('اطلاعیه‌ها')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'bell']); ?> <span class="nav-text"><?= e(t('اطلاعیه‌ها')) ?></span>
        <?php if (($unreadCounts['notifications'] ?? 0) > 0): ?>
            <span class="nav-badge"><?= e(fa((string) $unreadCounts['notifications'])) ?></span>
        <?php endif; ?>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/student/activate') ?>" href="/student/activate" data-tip="<?= e(t('خرید و فعال‌سازی')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'key']); ?> <span class="nav-text"><?= e(t('خرید و فعال‌سازی')) ?></span>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/student/support') ?>" href="/student/support" data-tip="<?= e(t('پشتیبانی')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'support']); ?> <span class="nav-text"><?= e(t('پشتیبانی')) ?></span>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/student/sessions') ?>" href="/student/sessions" data-tip="<?= e(t('نشست‌های من')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'shield']); ?> <span class="nav-text"><?= e(t('نشست‌های من')) ?></span>
    </a>
    <a class="nav-item<?= active_when($currentPath, '/account') ?>" href="/account/profile" data-tip="<?= e(t('پروفایل')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'user']); ?> <span class="nav-text"><?= e(t('پروفایل')) ?></span>
    </a>
</div>
