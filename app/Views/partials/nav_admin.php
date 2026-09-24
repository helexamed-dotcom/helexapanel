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
$hasSecurity = can('view_sessions') || can('view_logs') || can('manage_admins') || $canSettings || $canStudents;
$openFlags   = $canStudents ? \HeleXa\Services\IpWatch::openCount() : 0;
?>
<div class="nav-group">
    <div class="nav-label"><?= e(t('مرور کلی')) ?></div>
    <a class="nav-item<?= active_when($currentPath, '/admin') ?>" href="/admin" data-tip="<?= e(t('داشبورد')) ?>">
        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'home']); ?> <span class="nav-text"><?= e(t('داشبورد')) ?></span>
    </a>
</div>

<?php if ($hasLearning): ?>
    <div class="nav-group">
        <div class="nav-label"><?= e(t('آموزش')) ?></div>
        <?php if ($canStudents): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/students') ?>" href="/admin/students" data-tip="<?= e(t('دانشجویان')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'users']); ?> <span class="nav-text"><?= e(t('دانشجویان')) ?></span>
            </a>
        <?php endif; ?>
        <?php if ($canStudents): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/access') ?>" href="/admin/access" data-tip="<?= e(t('دسترسی‌ها')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'key']); ?> <span class="nav-text"><?= e(t('دسترسی‌ها')) ?></span>
            </a>
        <?php endif; ?>
        <?php if (can('manage_courses')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/courses') ?>" href="/admin/courses" data-tip="<?= e(t('دوره‌ها')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'book']); ?> <span class="nav-text"><?= e(t('دوره‌ها')) ?></span>
            </a>
        <?php endif; ?>
        <?php if (can('manage_packages')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/packages') ?>" href="/admin/packages" data-tip="<?= e(t('پکیج‌ها')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'package']); ?> <span class="nav-text"><?= e(t('پکیج‌ها')) ?></span>
            </a>
            <a class="nav-item<?= active_when($currentPath, '/admin/activation-codes') ?>" href="/admin/activation-codes" data-tip="<?= e(t('کدهای فعال‌سازی')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'key']); ?> <span class="nav-text"><?= e(t('کدهای فعال‌سازی')) ?></span>
            </a>
        <?php endif; ?>
        <?php if (can('manage_content')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/library') ?>" href="/admin/library" data-tip="<?= e(t('محتوای آموزشی')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'folder']); ?> <span class="nav-text"><?= e(t('محتوای آموزشی')) ?></span>
            </a>
        <?php endif; ?>
        <?php if ($canStudents): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/academic') ?>" href="/admin/academic" data-tip="<?= e(t('ساختار آموزشی')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'school']); ?> <span class="nav-text"><?= e(t('ساختار آموزشی')) ?></span>
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
        <div class="nav-label"><?= e(t('جزیره بالین')) ?></div>
        <a class="nav-item<?= active_when($currentPath, '/admin/balin') ?>" href="/admin/balin" data-tip="<?= e(t('مرور جزیره')) ?>">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'island']); ?> <span class="nav-text"><?= e(t('مرور جزیره')) ?></span>
        </a>
        <a class="nav-item<?= active_when($currentPath, '/admin/balin/lessons') ?>" href="/admin/balin/lessons" data-tip="<?= e(t('درس‌های بالینی')) ?>">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'stethoscope']); ?> <span class="nav-text"><?= e(t('درس‌های بالینی')) ?></span>
        </a>
        <?php if (can('balin.manage_skill_tracks')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/balin/skill-tracks') ?>" href="/admin/balin/skill-tracks" data-tip="<?= e(t('مهارت‌های بالینی')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'route']); ?> <span class="nav-text"><?= e(t('مهارت‌های بالینی')) ?></span>
            </a>
        <?php endif; ?>
        <?php if (can('balin.manage_characters')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/balin/characters') ?>" href="/admin/balin/characters" data-tip="<?= e(t('شخصیت‌ها')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'users']); ?> <span class="nav-text"><?= e(t('شخصیت‌ها')) ?></span>
            </a>
        <?php endif; ?>
        <a class="nav-item<?= active_when($currentPath, '/admin/balin/transfer') ?>" href="/admin/balin/transfer" data-tip="<?= e(t('ورود و خروج JSON')) ?>">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'download']); ?> <span class="nav-text"><?= e(t('ورود و خروج JSON')) ?></span>
        </a>
        <a class="nav-item<?= active_when($currentPath, '/admin/balin/media') ?>" href="/admin/balin/media" data-tip="<?= e(t('کتابخانه رسانه')) ?>">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'folder']); ?> <span class="nav-text"><?= e(t('کتابخانه رسانه')) ?></span>
        </a>
        <?php if (can('balin.manage_students')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/balin/access') ?>" href="/admin/balin/access" data-tip="<?= e(t('دسترسی دانشجویان')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'key']); ?> <span class="nav-text"><?= e(t('دسترسی دانشجویان')) ?></span>
            </a>
        <?php endif; ?>
        <?php if (can('balin.manage_competition')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/balin/competitions') ?>" href="/admin/balin/competitions" data-tip="<?= e(t('رقابت هفتگی')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'trophy']); ?> <span class="nav-text"><?= e(t('رقابت هفتگی')) ?></span>
            </a>
        <?php endif; ?>
        <?php if (can('balin.manage_rank_titles')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/balin/rank-tiers') ?>" href="/admin/balin/rank-tiers" data-tip="<?= e(t('عنوان سطح‌ها')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'sparkle']); ?> <span class="nav-text"><?= e(t('عنوان سطح‌ها')) ?></span>
            </a>
        <?php endif; ?>
        <?php if (can('balin.view_statistics')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/balin/analytics') ?>" href="/admin/balin/analytics" data-tip="<?= e(t('آمار بالین')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'chart']); ?> <span class="nav-text"><?= e(t('آمار بالین')) ?></span>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
/**
 * The question bank is its own group for the same reason Balin is: it has a
 * syllabus, a tag set, a question list and an access list, and four entries
 * under «آموزش» would bury them.
 */
if (can('qbank.view')): ?>
    <div class="nav-group">
        <div class="nav-label"><?= e(t('بانک سوال')) ?></div>
        <a class="nav-item<?= $currentPath === '/admin/qbank' ? ' is-active' : '' ?>" href="/admin/qbank" data-tip="<?= e(t('مرور بانک سوال')) ?>">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'qbank']); ?> <span class="nav-text"><?= e(t('مرور بانک سوال')) ?></span>
        </a>
        <a class="nav-item<?= active_when($currentPath, '/admin/qbank/questions') ?>" href="/admin/qbank/questions" data-tip="<?= e(t('سوالات')) ?>">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'exam']); ?> <span class="nav-text"><?= e(t('سوالات')) ?></span>
        </a>
        <a class="nav-item<?= active_when($currentPath, '/admin/qbank/subjects') ?>" href="/admin/qbank/subjects" data-tip="<?= e(t('دروس و زیردروس')) ?>">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'layers']); ?> <span class="nav-text"><?= e(t('دروس و زیردروس')) ?></span>
        </a>
        <a class="nav-item<?= active_when($currentPath, '/admin/qbank/tags') ?>" href="/admin/qbank/tags" data-tip="<?= e(t('برچسب‌ها')) ?>">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'tag']); ?> <span class="nav-text"><?= e(t('برچسب‌ها')) ?></span>
        </a>
        <a class="nav-item<?= active_when($currentPath, '/admin/qbank/transfer') ?>" href="/admin/qbank/transfer" data-tip="<?= e(t('ورود و خروج JSON')) ?>">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'download']); ?> <span class="nav-text"><?= e(t('ورود و خروج JSON')) ?></span>
        </a>
        <?php $openReports = (new \HeleXa\Models\QuestionBank\QbReportRepository())->countOpen(); ?>
        <a class="nav-item<?= active_when($currentPath, '/admin/qbank/reports') ?>" href="/admin/qbank/reports" data-tip="<?= e(t('گزارشات اشکال')) ?>">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'message']); ?> <span class="nav-text"><?= e(t('گزارشات اشکال')) ?></span>
            <?php if ($openReports > 0): ?>
                <span class="nav-badge"><?= e(fa((string) $openReports)) ?></span>
            <?php endif; ?>
        </a>
        <?php if (can('qbank.manage_students')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/qbank/access') ?>" href="/admin/qbank/access" data-tip="<?= e(t('دسترسی دانشجویان')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'key']); ?> <span class="nav-text"><?= e(t('دسترسی دانشجویان')) ?></span>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (can('flashcards.manage') || can('flashcards.manage_students')): ?>
    <div class="nav-group">
        <div class="nav-label"><?= e(t('فلش‌کارت')) ?></div>
        <?php if (can('flashcards.manage')): ?>
            <a class="nav-item<?= $currentPath === '/admin/flashcards' || str_starts_with($currentPath, '/admin/flashcards/course') || str_starts_with($currentPath, '/admin/flashcards/deck') ? ' is-active' : '' ?>"
               href="/admin/flashcards" data-tip="<?= e(t('درس‌های فلش‌کارت')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'cards']); ?> <span class="nav-text"><?= e(t('درس‌های فلش‌کارت')) ?></span>
            </a>
        <?php endif; ?>
        <?php if (can('flashcards.manage_students')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/flashcards/access') ?>" href="/admin/flashcards/access" data-tip="<?= e(t('دسترسی فلش‌کارت')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'key']); ?> <span class="nav-text"><?= e(t('دسترسی دانشجویان')) ?></span>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($hasPlanning): ?>
    <div class="nav-group">
        <div class="nav-label"><?= e(t('برنامه و آزمون')) ?></div>
        <?php if ($canSchedule): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/schedule') ?>" href="/admin/schedule" data-tip="<?= e(t('برنامه هفتگی')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'calendar']); ?> <span class="nav-text"><?= e(t('برنامه هفتگی')) ?></span>
            </a>
        <?php endif; ?>
        <?php if ($canExams): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/exams') ?>" href="/admin/exams" data-tip="<?= e(t('امتحانات')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'exam']); ?> <span class="nav-text"><?= e(t('امتحانات')) ?></span>
            </a>
            <a class="nav-item<?= active_when($currentPath, '/admin/midterms') ?>" href="/admin/midterms" data-tip="<?= e(t('میان‌ترم‌ها')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'clock']); ?> <span class="nav-text"><?= e(t('میان‌ترم‌ها')) ?></span>
            </a>
        <?php endif; ?>
        <?php if ($canCalendar): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/calendar') ?>" href="/admin/calendar" data-tip="<?= e(t('تقویم')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'layers']); ?> <span class="nav-text"><?= e(t('تقویم')) ?></span>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($hasComms): ?>
    <div class="nav-group">
        <div class="nav-label"><?= e(t('ارتباطات')) ?></div>
        <?php if (can('manage_notifications')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/notifications') ?>" href="/admin/notifications" data-tip="<?= e(t('اطلاعیه‌ها')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'bell']); ?> <span class="nav-text"><?= e(t('اطلاعیه‌ها')) ?></span>
            </a>
        <?php endif; ?>
        <?php if ($canMessages): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/messages') ?>" href="/admin/messages" data-tip="<?= e(t('پیام‌ها')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'message']); ?> <span class="nav-text"><?= e(t('پیام‌ها')) ?></span>
            </a>
            <a class="nav-item<?= active_when($currentPath, '/admin/support') ?>" href="/admin/support" data-tip="<?= e(t('پشتیبانی')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'support']); ?> <span class="nav-text"><?= e(t('پشتیبانی')) ?></span>
                <?php if (($unreadCounts['support_open'] ?? 0) > 0): ?>
                    <span class="nav-badge"><?= e(fa((string) $unreadCounts['support_open'])) ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($hasSecurity): ?>
    <div class="nav-group">
        <div class="nav-label"><?= e(t('امنیت و تنظیمات')) ?></div>
        <?php if (can('view_sessions')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/sessions') ?>" href="/admin/sessions" data-tip="<?= e(t('نشست‌ها')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'clock']); ?> <span class="nav-text"><?= e(t('نشست‌ها')) ?></span>
            </a>
        <?php endif; ?>
        <?php if ($canStudents): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/security/flags') ?>" href="/admin/security/flags" data-tip="<?= e(t('کاربران مشکوک')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'shield']); ?> <span class="nav-text"><?= e(t('کاربران مشکوک')) ?></span>
                <?php if ($openFlags > 0): ?>
                    <span class="nav-badge"><?= e(fa((string) $openFlags)) ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
        <?php if (can('view_logs')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/logs') ?>" href="/admin/logs" data-tip="<?= e(t('گزارش فعالیت')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'list']); ?> <span class="nav-text"><?= e(t('گزارش فعالیت')) ?></span>
            </a>
        <?php endif; ?>
        <?php if (can('manage_admins')): ?>
            <a class="nav-item<?= active_when($currentPath, '/admin/admins') ?>" href="/admin/admins" data-tip="<?= e(t('مدیران')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'user']); ?> <span class="nav-text"><?= e(t('مدیران')) ?></span>
            </a>
        <?php endif; ?>
        <?php if ($canSettings): ?>
            <a class="nav-item<?= $currentPath === '/admin/security' ? ' is-active' : '' ?>" href="/admin/security" data-tip="<?= e(t('بازبینی امنیت')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'shield']); ?> <span class="nav-text"><?= e(t('بازبینی امنیت')) ?></span>
            </a>
            <a class="nav-item<?= active_when($currentPath, '/admin/settings') ?>" href="/admin/settings" data-tip="<?= e(t('تنظیمات')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'settings']); ?> <span class="nav-text"><?= e(t('تنظیمات')) ?></span>
            </a>
            <a class="nav-item<?= active_when($currentPath, '/admin/backup') ?>" href="/admin/backup" data-tip="<?= e(t('پشتیبان‌گیری')) ?>">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'download']); ?> <span class="nav-text"><?= e(t('پشتیبان‌گیری')) ?></span>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>
