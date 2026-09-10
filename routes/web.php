<?php
declare(strict_types=1);

/**
 * Route table. Middleware is declared here, not inside controllers,
 * so no page can accidentally ship without its guards.
 *
 * @var \HeleXa\Core\Router $router
 */

use HeleXa\Controllers\AccountController;
use HeleXa\Controllers\Admin\AcademicController;
use HeleXa\Controllers\Admin\CalendarController;
use HeleXa\Controllers\Admin\ContentController;
use HeleXa\Controllers\Admin\CourseController;
use HeleXa\Controllers\Admin\ActivityLogController;
use HeleXa\Controllers\Admin\AdminUserController;
use HeleXa\Controllers\Admin\DashboardController as AdminDashboard;
use HeleXa\Controllers\Admin\ExamController;
use HeleXa\Controllers\Admin\MessageController;
use HeleXa\Controllers\Admin\NotificationController;
use HeleXa\Controllers\Admin\PackageController;
use HeleXa\Controllers\Admin\ScheduleController;
use HeleXa\Controllers\Admin\SecurityController;
use HeleXa\Controllers\Admin\SessionController;
use HeleXa\Controllers\Admin\SettingsController;
use HeleXa\Controllers\Admin\SupportController;
use HeleXa\Controllers\Admin\StudentController;
use HeleXa\Controllers\Api\OfflineController as OfflineApi;
use HeleXa\Controllers\Api\SessionController as SessionApi;
use HeleXa\Controllers\Api\SyncController;
use HeleXa\Controllers\AuthController;
use HeleXa\Controllers\ContentViewerController;
use HeleXa\Controllers\HighlightController;
use HeleXa\Controllers\HomeController;
use HeleXa\Controllers\OfflineController;
use HeleXa\Controllers\Student\AnalyticsController as StudentAnalytics;
use HeleXa\Controllers\Student\CourseController as StudentCourses;
use HeleXa\Controllers\Student\DashboardController as StudentDashboard;
use HeleXa\Controllers\Student\InboxController;
use HeleXa\Controllers\Student\PlannerController;
use HeleXa\Middleware\AuthenticateMiddleware;
use HeleXa\Middleware\CsrfMiddleware;
use HeleXa\Middleware\ForcePasswordChangeMiddleware;
use HeleXa\Middleware\GuestMiddleware;
use HeleXa\Middleware\PermissionMiddleware;
use HeleXa\Middleware\PostSizeMiddleware;
use HeleXa\Middleware\RoleMiddleware;
use HeleXa\Middleware\ThrottleMiddleware;

$router->globalMiddleware([PostSizeMiddleware::class, CsrfMiddleware::class]);

$router->get('/', [HomeController::class, 'index']);

/* ---------------------------------------------------------------- guest */
$router->get('/login',  [AuthController::class, 'showLogin'], [GuestMiddleware::class]);
$router->post('/login', [AuthController::class, 'login'],
    [GuestMiddleware::class, ThrottleMiddleware::class . ':login,30,300']);

/* ------------------------------------------------------- authenticated */
$router->post('/logout', [AuthController::class, 'logout'], [AuthenticateMiddleware::class]);

$router->get('/account/password',  [AccountController::class, 'showPasswordForm'], [AuthenticateMiddleware::class]);
$router->post('/account/password', [AccountController::class, 'updatePassword'],
    [AuthenticateMiddleware::class, ThrottleMiddleware::class . ':password,10,600']);

$router->get('/account/profile',        [AccountController::class, 'showProfile'],   [AuthenticateMiddleware::class]);
$router->post('/account/profile',       [AccountController::class, 'updateProfile'], [AuthenticateMiddleware::class]);
$router->post('/account/avatar',        [AccountController::class, 'uploadAvatar'],
    [AuthenticateMiddleware::class, ThrottleMiddleware::class . ':avatar,10,600']);
$router->post('/account/avatar/delete', [AccountController::class, 'removeAvatar'],  [AuthenticateMiddleware::class]);
$router->get('/account/avatar/{uuid}',  [AccountController::class, 'avatar'],        [AuthenticateMiddleware::class]);

/* ------------------------------------------------------------- student */
$router->group('/student', [
    AuthenticateMiddleware::class,
    RoleMiddleware::class . ':student',
    ForcePasswordChangeMiddleware::class,
], function (\HeleXa\Core\Router $router): void {
    $router->get('/', [StudentDashboard::class, 'index']);
    $router->get('/courses', [StudentCourses::class, 'index']);
    $router->get('/courses/{uuid}', [StudentCourses::class, 'show']);
    $router->get('/analytics', [StudentAnalytics::class, 'index']);
    $router->get('/schedule',  [PlannerController::class, 'schedule']);
    $router->get('/exams',     [PlannerController::class, 'exams']);
    $router->get('/midterms',  [PlannerController::class, 'midterms']);
    $router->get('/calendar',  [PlannerController::class, 'calendar']);

    $router->get('/notifications',              [InboxController::class, 'notifications']);
    $router->post('/notifications/read-all',    [InboxController::class, 'readAllNotifications']);
    $router->post('/notifications/{id}/read',   [InboxController::class, 'readNotification']);
    $router->get('/messages',                   [InboxController::class, 'messages']);
    $router->get('/messages/{id}',              [InboxController::class, 'showMessage']);
});

/* ---------------------------------------------------- PWA / offline */
// The offline shell holds no user data, which is what makes it safe for the
// service worker to cache a single copy of it.
$router->get('/offline', [OfflineController::class, 'index'], [AuthenticateMiddleware::class]);

// Internal JSON API. Session-authenticated like every other route; there is
// no separate token scheme and no second way in.
$router->group('/api', [AuthenticateMiddleware::class], function (\HeleXa\Core\Router $router): void {
    $router->get('/session/state', [SessionApi::class, 'state'],
        [ThrottleMiddleware::class . ':session_state,240,60']);

    $router->get('/offline/catalogue',      [OfflineApi::class, 'catalogue'],
        [ThrottleMiddleware::class . ':offline_cat,60,300']);
    $router->get('/offline/manifest/{uuid}', [OfflineApi::class, 'manifest'],
        [ThrottleMiddleware::class . ':offline_pkg,120,3600']);
    $router->post('/offline/verify',        [OfflineApi::class, 'verify'],
        [ThrottleMiddleware::class . ':offline_verify,60,300']);

    $router->post('/sync/study',  [SyncController::class, 'study'],
        [ThrottleMiddleware::class . ':sync_study,60,300']);
    $router->post('/sync/status', [SyncController::class, 'status'],
        [ThrottleMiddleware::class . ':sync_status,60,300']);
    $router->post('/sync/highlights', [SyncController::class, 'highlights'],
        [ThrottleMiddleware::class . ':sync_hl,60,300']);
});

/* ------------------------------------------------- private content */
// Role-agnostic: ContentAccess decides for students and previewing admins alike.
$router->group('/content', [
    AuthenticateMiddleware::class,
    ForcePasswordChangeMiddleware::class,
], function (\HeleXa\Core\Router $router): void {
    $router->get('/{uuid}',        [ContentViewerController::class, 'show']);
    $router->get('/{uuid}/stream', [ContentViewerController::class, 'stream'],
        [ThrottleMiddleware::class . ':stream,60,300']);
    $router->post('/{uuid}/state', [ContentViewerController::class, 'saveState'],
        [ThrottleMiddleware::class . ':state,120,60']);
    $router->post('/{uuid}/status',[ContentViewerController::class, 'setStatus'],
        [ThrottleMiddleware::class . ':status,60,60']);
    $router->post('/{uuid}/beat',  [ContentViewerController::class, 'heartbeat'],
        [ThrottleMiddleware::class . ':beat,120,60']);
    $router->post('/{uuid}/end',   [ContentViewerController::class, 'endStudy']);

    $router->get('/{uuid}/highlights',  [HighlightController::class, 'index'],
        [ThrottleMiddleware::class . ':hl_read,120,300']);
    $router->post('/{uuid}/highlights', [HighlightController::class, 'store'],
        [ThrottleMiddleware::class . ':hl_write,200,300']);
    $router->post('/{uuid}/highlights/{highlight}/delete',  [HighlightController::class, 'destroy'],
        [ThrottleMiddleware::class . ':hl_write,200,300']);
    $router->post('/{uuid}/highlights/{highlight}/color',   [HighlightController::class, 'recolor'],
        [ThrottleMiddleware::class . ':hl_write,200,300']);
});

/* --------------------------------------------------------------- admin */
$router->group('/admin', [
    AuthenticateMiddleware::class,
    RoleMiddleware::class . ':admin,super_admin',
    ForcePasswordChangeMiddleware::class,
], function (\HeleXa\Core\Router $router): void {
    $router->get('/', [AdminDashboard::class, 'index']);

    $router->get('/sessions', [SessionController::class, 'index'],
        [PermissionMiddleware::class . ':view_sessions']);

    $router->get('/sessions/user/{uuid}', [SessionController::class, 'forUser'],
        [PermissionMiddleware::class . ':view_sessions']);

    $router->post('/sessions/{id}/terminate', [SessionController::class, 'terminate'],
        [PermissionMiddleware::class . ':terminate_sessions']);

    $router->post('/sessions/user/{uuid}/terminate-all', [SessionController::class, 'terminateAllForUser'],
        [PermissionMiddleware::class . ':terminate_sessions']);

    /* ------------------------------------------------------- students */
    $students = [PermissionMiddleware::class . ':manage_students'];
    $router->get('/students',                       [StudentController::class, 'index'],         $students);
    $router->get('/students/create',                [StudentController::class, 'create'],        $students);
    $router->post('/students',                      [StudentController::class, 'store'],         $students);
    $router->get('/students/{uuid}/edit',           [StudentController::class, 'edit'],          $students);
    $router->post('/students/{uuid}',               [StudentController::class, 'update'],        $students);
    $router->post('/students/{uuid}/status',        [StudentController::class, 'setStatus'],     $students);
    $router->post('/students/{uuid}/reset-password',[StudentController::class, 'resetPassword'], $students);
    $router->post('/students/{uuid}/unlock',        [StudentController::class, 'unlock'],        $students);
    $router->post('/students/{uuid}/delete',        [StudentController::class, 'destroy'],       $students);

    /* --------------------------------------------------- terms/groups */
    $router->get('/academic',                        [AcademicController::class, 'index'],        $students);
    $router->post('/academic/universities',              [AcademicController::class, 'storeUniversity'],   $students);
    $router->post('/academic/universities/{id}/toggle',  [AcademicController::class, 'toggleUniversity'],  $students);
    $router->post('/academic/universities/{id}/delete',  [AcademicController::class, 'destroyUniversity'], $students);
    $router->post('/academic/majors',                    [AcademicController::class, 'storeMajor'],        $students);
    $router->post('/academic/majors/{id}/toggle',        [AcademicController::class, 'toggleMajor'],       $students);
    $router->post('/academic/majors/{id}/delete',        [AcademicController::class, 'destroyMajor'],      $students);
    $router->post('/academic/subjects',                  [AcademicController::class, 'storeSubject'],      $students);
    $router->post('/academic/subjects/{id}/toggle',       [AcademicController::class, 'toggleSubject'],     $students);
    $router->post('/academic/subjects/{id}/delete',       [AcademicController::class, 'destroySubject'],    $students);
    $router->post('/academic/terms',                 [AcademicController::class, 'storeTerm'],    $students);
    $router->post('/academic/terms/{id}/delete',     [AcademicController::class, 'destroyTerm'],  $students);
    $router->post('/academic/groups',                [AcademicController::class, 'storeGroup'],   $students);
    $router->post('/academic/groups/{id}/delete',    [AcademicController::class, 'destroyGroup'], $students);

    /* --------------------------------------------------------- admins */
    $admins = [PermissionMiddleware::class . ':manage_admins'];
    $router->get('/admins',                        [AdminUserController::class, 'index'],         $admins);
    $router->get('/admins/create',                 [AdminUserController::class, 'create'],        $admins);
    $router->post('/admins',                       [AdminUserController::class, 'store'],         $admins);
    $router->get('/admins/{uuid}/edit',            [AdminUserController::class, 'edit'],          $admins);
    $router->post('/admins/{uuid}',                [AdminUserController::class, 'update'],        $admins);
    $router->post('/admins/{uuid}/reset-password', [AdminUserController::class, 'resetPassword'], $admins);
    $router->post('/admins/{uuid}/delete',         [AdminUserController::class, 'destroy'],       $admins);

    /* -------------------------------------------------------- courses */
    $courses = [PermissionMiddleware::class . ':manage_courses'];
    $router->get('/courses',                     [CourseController::class, 'index'],          $courses);
    $router->get('/courses/create',              [CourseController::class, 'create'],         $courses);
    $router->post('/courses',                    [CourseController::class, 'store'],          $courses);
    $router->get('/courses/{uuid}/edit',         [CourseController::class, 'edit'],           $courses);
    $router->post('/courses/{uuid}',             [CourseController::class, 'update'],         $courses);
    $router->post('/courses/{uuid}/delete',      [CourseController::class, 'destroy'],        $courses);
    $router->post('/courses/{uuid}/icon',        [CourseController::class, 'updateIcon'],
        array_merge($courses, [ThrottleMiddleware::class . ':course_icon,20,300']));
    $router->post('/courses/{uuid}/icon/reset',  [CourseController::class, 'resetIcon'],       $courses);
    $router->get('/courses/{uuid}/students',     [CourseController::class, 'students'],       $courses);
    $router->post('/courses/{uuid}/students',    [CourseController::class, 'assignStudent'],  $courses);
    $router->post('/courses/{uuid}/students/{student}/remove', [CourseController::class, 'removeStudent'], $courses);

    /* ------------------------------------------------------- packages */
    $packages = [PermissionMiddleware::class . ':manage_packages'];
    $router->get('/packages',                    [PackageController::class, 'index'],     $packages);
    $router->get('/packages/create',             [PackageController::class, 'create'],    $packages);
    $router->post('/packages',                   [PackageController::class, 'store'],     $packages);
    $router->get('/packages/{uuid}',             [PackageController::class, 'show'],      $packages);
    $router->post('/packages/{uuid}',            [PackageController::class, 'update'],    $packages);
    $router->post('/packages/{uuid}/delete',     [PackageController::class, 'destroy'],   $packages);
    $router->post('/packages/{uuid}/courses',    [PackageController::class, 'addCourse'],  $packages);
    $router->post('/packages/{uuid}/courses/{course}/remove', [PackageController::class, 'removeCourse'], $packages);
    $router->post('/packages/{uuid}/activate',   [PackageController::class, 'activate'],   $packages);
    $router->post('/packages/{uuid}/members/{activation}/status', [PackageController::class, 'setMemberStatus'], $packages);

    /* ------------------------------------------- tree & html content */
    $content = [PermissionMiddleware::class . ':manage_content'];
    $router->get('/courses/{uuid}/builder',                 [CourseController::class, 'builder'],       $content);
    $router->post('/courses/{uuid}/sections',               [ContentController::class, 'storeSection'], $content);
    $router->post('/courses/{uuid}/sections/{id}',          [ContentController::class, 'updateSection'],$content);
    $router->post('/courses/{uuid}/sections/{id}/delete',   [ContentController::class, 'destroySection'],$content);
    $router->post('/courses/{uuid}/sections/{id}/move',     [ContentController::class, 'moveSection'],  $content);

    $router->get('/courses/{uuid}/contents/create',           [ContentController::class, 'createContent'],  $content);
    $router->post('/courses/{uuid}/contents',                 [ContentController::class, 'storeContent'],   $content);
    $router->get('/courses/{uuid}/contents/{content}/edit',   [ContentController::class, 'editContent'],    $content);
    $router->post('/courses/{uuid}/contents/{content}',       [ContentController::class, 'updateContent'],  $content);
    $router->post('/courses/{uuid}/contents/{content}/delete',[ContentController::class, 'destroyContent'], $content);
    $router->post('/courses/{uuid}/contents/{content}/move',  [ContentController::class, 'moveContent'],    $content);

    /* ----------------------------------------------- weekly schedule */
    $schedule = [PermissionMiddleware::class . ':manage_schedule'];
    $router->get('/schedule',                       [ScheduleController::class, 'index'],       $schedule);
    $router->post('/schedule',                      [ScheduleController::class, 'store'],       $schedule);
    $router->get('/schedule/{id}',                  [ScheduleController::class, 'show'],        $schedule);
    $router->post('/schedule/{id}',                 [ScheduleController::class, 'update'],      $schedule);
    $router->post('/schedule/{id}/delete',          [ScheduleController::class, 'destroy'],     $schedule);
    $router->post('/schedule/{id}/items',           [ScheduleController::class, 'storeItem'],   $schedule);
    $router->post('/schedule/{id}/items/{item}/delete', [ScheduleController::class, 'destroyItem'], $schedule);

    /* ------------------------------------------ exams and midterms */
    $exams = [PermissionMiddleware::class . ':manage_exams'];
    $router->get('/exams',              [ExamController::class, 'index'],     $exams);
    $router->get('/midterms',           [ExamController::class, 'midterms'],  $exams);
    $router->post('/exams',             [ExamController::class, 'store'],     $exams);
    $router->post('/exams/{id}',        [ExamController::class, 'update'],    $exams);
    $router->post('/exams/{id}/delete', [ExamController::class, 'destroy'],   $exams);

    /* ---------------------------------------------------- calendar */
    $calendar = [PermissionMiddleware::class . ':manage_calendar'];
    $router->get('/calendar',               [CalendarController::class, 'index'],   $calendar);
    $router->post('/calendar',              [CalendarController::class, 'store'],   $calendar);
    $router->post('/calendar/{id}/delete',  [CalendarController::class, 'destroy'], $calendar);

    /* ------------------------------------ notifications & messages */
    $router->get('/notifications',              [NotificationController::class, 'index'],
        [PermissionMiddleware::class . ':manage_notifications']);
    $router->post('/notifications',             [NotificationController::class, 'store'],
        [PermissionMiddleware::class . ':manage_notifications']);
    $router->post('/notifications/{id}/delete', [NotificationController::class, 'destroy'],
        [PermissionMiddleware::class . ':manage_notifications']);

    $messages = [PermissionMiddleware::class . ':manage_messages'];
    $router->get('/messages',                [MessageController::class, 'index'],   $messages);
    $router->post('/messages',               [MessageController::class, 'store'],
        array_merge($messages, [ThrottleMiddleware::class . ':send,30,600']));
    $router->get('/messages/{id}',           [MessageController::class, 'show'],    $messages);
    $router->post('/messages/{id}/delete',   [MessageController::class, 'destroy'], $messages);

    /* ------------------------------------------- security review */
    $router->get('/security',          [SecurityController::class, 'index'],
        [PermissionMiddleware::class . ':manage_settings']);
    $router->post('/security/cleanup', [SecurityController::class, 'cleanup'],
        [PermissionMiddleware::class . ':manage_settings']);

    /* ----------------------------------------------- logs & settings */
    $router->get('/logs', [ActivityLogController::class, 'index'],
        [PermissionMiddleware::class . ':view_logs']);

    $router->get('/settings',  [SettingsController::class, 'index'],
        [PermissionMiddleware::class . ':manage_settings']);
    $router->post('/settings', [SettingsController::class, 'update'],
        [PermissionMiddleware::class . ':manage_settings']);
    $router->post('/settings/brand/{slot}',       [SettingsController::class, 'updateBrandImage'],
        [PermissionMiddleware::class . ':manage_settings', ThrottleMiddleware::class . ':brand_upload,20,300']);
    $router->post('/settings/brand/{slot}/reset', [SettingsController::class, 'resetBrandImage'],
        [PermissionMiddleware::class . ':manage_settings']);

    /* ----------------------------------------------------------- support */
    $support = [PermissionMiddleware::class . ':manage_messages'];
    $router->get('/support',                    [SupportController::class, 'index'],      $support);
    $router->get('/support/{uuid}',              [SupportController::class, 'show'],       $support);
    $router->post('/support/{uuid}/reply',       [SupportController::class, 'reply'],      $support);
    $router->post('/support/{uuid}/close',       [SupportController::class, 'close'],      $support);
    $router->get('/support/{uuid}/attachment/{message}', [SupportController::class, 'attachment'], $support);
});
