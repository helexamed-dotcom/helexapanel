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
use HeleXa\Controllers\Admin\Balin\AccessController as BalinAccessController;
use HeleXa\Controllers\Admin\Balin\AnalyticsController as BalinAnalyticsController;
use HeleXa\Controllers\Admin\Balin\CatalogController as BalinCatalogController;
use HeleXa\Controllers\Admin\Balin\CheckpointController as BalinCheckpointController;
use HeleXa\Controllers\Admin\Balin\CompetitionController as BalinCompetitionController;
use HeleXa\Controllers\Admin\Balin\DashboardController as BalinDashboard;
use HeleXa\Controllers\Admin\Balin\LessonController as BalinLessonController;
use HeleXa\Controllers\Admin\Balin\QuestionController as BalinQuestionController;
use HeleXa\Controllers\Admin\Balin\StageController as BalinStageController;
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
use HeleXa\Controllers\Admin\SmsController;
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
use HeleXa\Controllers\Student\BalinController;
use HeleXa\Controllers\Student\BalinExamController;
use HeleXa\Controllers\Student\BalinProfileController;
use HeleXa\Controllers\Student\PlannerController;
use HeleXa\Middleware\AuthenticateMiddleware;
use HeleXa\Middleware\BalinAccessMiddleware;
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

/* ------------------------------------------------- sign-in by texted code
   Every send endpoint costs real money per call, so the route-level throttle
   is deliberately tighter than the login form's. It is a blunt ceiling on top
   of the per-number and per-address limits the OTP service enforces itself:
   this one stops a flood before it reaches the database at all. */
$router->post('/auth/request-otp', [AuthController::class, 'requestOtp'],
    [GuestMiddleware::class, ThrottleMiddleware::class . ':otp_request,10,600']);
$router->post('/auth/verify-otp',  [AuthController::class, 'verifyOtp'],
    [GuestMiddleware::class, ThrottleMiddleware::class . ':otp_verify,30,600']);

/* ------------------------------------------------------ forgotten password */
$router->get('/forgot-password',     [AuthController::class, 'showForgotPassword'], [GuestMiddleware::class]);
$router->post('/auth/forgot-password', [AuthController::class, 'forgotPassword'],
    [GuestMiddleware::class, ThrottleMiddleware::class . ':otp_request,10,600']);
$router->post('/auth/verify-reset',    [AuthController::class, 'verifyResetOtp'],
    [GuestMiddleware::class, ThrottleMiddleware::class . ':otp_verify,30,600']);
$router->post('/auth/reset-password',  [AuthController::class, 'resetPassword'],
    [GuestMiddleware::class, ThrottleMiddleware::class . ':password_reset,10,600']);

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

    /* ------------------------------------------------- 🏝️ جزیره بالین */
    // BalinAccessMiddleware answers the two publication questions once, for
    // every route below, so none of them can be reached before the island is
    // published and this student has been given access. While it is closed
    // the same middleware renders the Coming Soon page in place of content.
    $balin = [BalinAccessMiddleware::class];

    $router->get('/balin',                        [BalinController::class, 'index'],      $balin);
    $router->get('/balin/lesson/{uuid}',          [BalinController::class, 'lesson'],     $balin);
    $router->get('/balin/stage/{uuid}',           [BalinController::class, 'stage'],      $balin);
    $router->post('/balin/stage/{uuid}/answer',   [BalinController::class, 'answer'],
        array_merge($balin, [ThrottleMiddleware::class . ':balin_answer,20,60']));
    $router->post('/balin/stage/{uuid}/complete', [BalinController::class, 'complete'],   $balin);

    $router->get('/balin/exam/{uuid}',            [BalinExamController::class, 'show'],    $balin);
    $router->post('/balin/exam/{uuid}/start',     [BalinExamController::class, 'start'],
        array_merge($balin, [ThrottleMiddleware::class . ':balin_exam_start,10,60']));
    $router->get('/balin/exam/{uuid}/attempt',    [BalinExamController::class, 'attempt'], $balin);
    $router->post('/balin/exam/{uuid}/submit',    [BalinExamController::class, 'submit'],
        array_merge($balin, [ThrottleMiddleware::class . ':balin_exam_submit,20,60']));
    $router->get('/balin/exam/{uuid}/result',     [BalinExamController::class, 'result'],  $balin);

    $router->get('/balin/profile',     [BalinProfileController::class, 'profile'],     $balin);
    $router->get('/balin/leaderboard', [BalinProfileController::class, 'leaderboard'], $balin);
    $router->get('/balin/media/{uuid}',[BalinProfileController::class, 'media'],       $balin);
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

    /* ------------------------------------------------- SMS gateway & OTP
       Behind the same permission as the other settings. The test endpoint is
       throttled separately because, unlike saving a form, each call spends
       real credit on the operator's SMS account. */
    $router->get('/sms',       [SmsController::class, 'index'],
        [PermissionMiddleware::class . ':manage_settings']);
    $router->post('/sms',      [SmsController::class, 'update'],
        [PermissionMiddleware::class . ':manage_settings']);
    $router->post('/sms/test', [SmsController::class, 'test'],
        [PermissionMiddleware::class . ':manage_settings', ThrottleMiddleware::class . ':sms_test,10,600']);

    /* -------------------------------------------------- 🏝️ جزیره بالین */
    // Each capability carries its own permission, so an admin can be given
    // the ability to write cases without also being able to publish them or
    // read individual students' answers.
    $balinView    = [PermissionMiddleware::class . ':balin.view'];
    $balinEdit    = [PermissionMiddleware::class . ':balin.edit'];
    $balinCreate  = [PermissionMiddleware::class . ':balin.create'];
    $balinDelete  = [PermissionMiddleware::class . ':balin.delete'];
    $balinPublish = [PermissionMiddleware::class . ':balin.publish'];
    $balinStats   = [PermissionMiddleware::class . ':balin.view_statistics'];

    $router->get('/balin',                  [BalinDashboard::class, 'index'],         $balinView);
    $router->post('/balin/status',          [BalinDashboard::class, 'setStatus'],     $balinPublish);
    $router->post('/balin/settings',        [BalinDashboard::class, 'saveSettings'],
        [PermissionMiddleware::class . ':balin.manage_settings']);
    $router->post('/balin/leaderboard/rebuild', [BalinDashboard::class, 'rebuildBoards'], $balinView);

    /* lessons and stages */
    $router->get('/balin/lessons',                 [BalinLessonController::class, 'index'],      $balinView);
    $router->get('/balin/lessons/create',          [BalinLessonController::class, 'create'],     $balinCreate);
    $router->post('/balin/lessons',                [BalinLessonController::class, 'store'],      $balinCreate);
    $router->get('/balin/lessons/{uuid}',          [BalinLessonController::class, 'show'],       $balinView);
    $router->get('/balin/lessons/{uuid}/edit',     [BalinLessonController::class, 'edit'],       $balinEdit);
    $router->post('/balin/lessons/{uuid}',         [BalinLessonController::class, 'update'],     $balinEdit);
    $router->post('/balin/lessons/{uuid}/status',  [BalinLessonController::class, 'setStatus'],  $balinPublish);
    $router->post('/balin/lessons/{uuid}/delete',  [BalinLessonController::class, 'destroy'],    $balinDelete);
    $router->post('/balin/lessons/{uuid}/stages',  [BalinLessonController::class, 'storeStage'], $balinCreate);
    $router->post('/balin/stages/{uuid}/move',     [BalinLessonController::class, 'moveStage'],  $balinEdit);

    /* stage builder */
    $router->get('/balin/stages/{uuid}',                   [BalinStageController::class, 'show'],       $balinView);
    $router->post('/balin/stages/{uuid}',                  [BalinStageController::class, 'update'],     $balinEdit);
    $router->post('/balin/stages/{uuid}/status',           [BalinStageController::class, 'setStatus'],  $balinPublish);
    $router->post('/balin/stages/{uuid}/delete',           [BalinStageController::class, 'destroy'],    $balinDelete);
    $router->post('/balin/stages/{uuid}/rebalance',        [BalinStageController::class, 'rebalance'],  $balinEdit);
    $router->post('/balin/stages/{uuid}/blocks',           [BalinStageController::class, 'storeBlock'], $balinCreate);
    $router->post('/balin/blocks/{block}',                 [BalinStageController::class, 'updateBlock'],    $balinEdit);
    $router->post('/balin/blocks/{block}/move',            [BalinStageController::class, 'moveBlock'],      $balinEdit);
    $router->post('/balin/blocks/{block}/duplicate',       [BalinStageController::class, 'duplicateBlock'], $balinCreate);
    $router->post('/balin/blocks/{block}/delete',          [BalinStageController::class, 'destroyBlock'],   $balinDelete);

    /* questions */
    $balinQuestions = [PermissionMiddleware::class . ':balin.manage_questions'];
    $router->get('/balin/lessons/{uuid}/questions/create', [BalinQuestionController::class, 'create'],  $balinQuestions);
    $router->post('/balin/lessons/{uuid}/questions',       [BalinQuestionController::class, 'store'],   $balinQuestions);
    $router->get('/balin/questions/{uuid}/edit',           [BalinQuestionController::class, 'edit'],    $balinQuestions);
    $router->post('/balin/questions/{uuid}',               [BalinQuestionController::class, 'update'],  $balinQuestions);
    $router->post('/balin/questions/{uuid}/delete',        [BalinQuestionController::class, 'destroy'], $balinQuestions);

    /* checkpoint exams */
    $balinExams = [PermissionMiddleware::class . ':balin.manage_checkpoint_exams'];
    $router->get('/balin/lessons/{uuid}/exams/create', [BalinCheckpointController::class, 'create'], $balinExams);
    $router->post('/balin/lessons/{uuid}/exams',       [BalinCheckpointController::class, 'store'],  $balinExams);
    $router->get('/balin/exams/{uuid}',                [BalinCheckpointController::class, 'show'],   $balinExams);
    $router->post('/balin/exams/{uuid}',               [BalinCheckpointController::class, 'update'], $balinExams);
    $router->post('/balin/exams/{uuid}/status',        [BalinCheckpointController::class, 'setStatus'], $balinPublish);
    $router->post('/balin/exams/{uuid}/delete',        [BalinCheckpointController::class, 'destroy'],   $balinDelete);
    $router->post('/balin/exams/{uuid}/questions/attach', [BalinCheckpointController::class, 'attachQuestion'], $balinExams);
    $router->post('/balin/exams/{uuid}/questions/detach', [BalinCheckpointController::class, 'detachQuestion'], $balinExams);
    $router->post('/balin/exams/{uuid}/attempts/reset',   [BalinCheckpointController::class, 'resetAttempts'],  $balinExams);

    /* characters, skills, tiers, media */
    $balinCharacters = [PermissionMiddleware::class . ':balin.manage_characters'];
    $router->get('/balin/characters',                [BalinCatalogController::class, 'characters'],        $balinCharacters);
    $router->post('/balin/characters',               [BalinCatalogController::class, 'storeCharacter'],    $balinCharacters);
    $router->post('/balin/characters/{uuid}',        [BalinCatalogController::class, 'updateCharacter'],   $balinCharacters);
    $router->post('/balin/characters/{uuid}/delete', [BalinCatalogController::class, 'destroyCharacter'],  $balinCharacters);

    $balinTracks = [PermissionMiddleware::class . ':balin.manage_skill_tracks'];
    $router->get('/balin/skill-tracks',             [BalinCatalogController::class, 'skillTracks'],       $balinTracks);
    $router->post('/balin/skill-tracks',            [BalinCatalogController::class, 'storeSkillTrack'],   $balinTracks);
    $router->post('/balin/skill-tracks/{id}',       [BalinCatalogController::class, 'updateSkillTrack'],  $balinTracks);
    $router->post('/balin/skill-tracks/{id}/delete',[BalinCatalogController::class, 'destroySkillTrack'], $balinTracks);

    $balinTiers = [PermissionMiddleware::class . ':balin.manage_rank_titles'];
    $router->get('/balin/rank-tiers',              [BalinCatalogController::class, 'rankTiers'],       $balinTiers);
    $router->post('/balin/rank-tiers',             [BalinCatalogController::class, 'storeRankTier'],   $balinTiers);
    $router->post('/balin/rank-tiers/{id}',        [BalinCatalogController::class, 'updateRankTier'],  $balinTiers);
    $router->post('/balin/rank-tiers/{id}/delete', [BalinCatalogController::class, 'destroyRankTier'], $balinTiers);

    $router->get('/balin/media',                 [BalinCatalogController::class, 'media'],        $balinView);
    $router->post('/balin/media',                [BalinCatalogController::class, 'uploadMedia'],
        array_merge($balinCreate, [ThrottleMiddleware::class . ':balin_media,30,300']));
    $router->post('/balin/media/{uuid}',         [BalinCatalogController::class, 'updateMedia'],  $balinEdit);
    $router->post('/balin/media/{uuid}/delete',  [BalinCatalogController::class, 'destroyMedia'], $balinDelete);

    /* student access */
    $balinStudents = [PermissionMiddleware::class . ':balin.manage_students'];
    $router->get('/balin/access',            [BalinAccessController::class, 'index'],    $balinStudents);
    // Declared before the {uuid} route: the router takes the first pattern
    // that matches, and "grant-all" would otherwise be read as a student id.
    $router->post('/balin/access/grant-all', [BalinAccessController::class, 'grantAll'], $balinStudents);
    $router->post('/balin/access/{uuid}',    [BalinAccessController::class, 'toggle'],   $balinStudents);

    /* competitions and rewards */
    $balinCompetition = [PermissionMiddleware::class . ':balin.manage_competition'];
    $router->get('/balin/competitions',                 [BalinCompetitionController::class, 'index'],     $balinCompetition);
    $router->post('/balin/competitions',                [BalinCompetitionController::class, 'store'],     $balinCompetition);
    $router->get('/balin/competitions/{uuid}',          [BalinCompetitionController::class, 'show'],      $balinCompetition);
    $router->post('/balin/competitions/{uuid}',         [BalinCompetitionController::class, 'update'],    $balinCompetition);
    $router->post('/balin/competitions/{uuid}/status',  [BalinCompetitionController::class, 'setStatus'], $balinCompetition);
    $router->post('/balin/competitions/{uuid}/delete',  [BalinCompetitionController::class, 'destroy'],   $balinCompetition);
    $router->post('/balin/competitions/{uuid}/rewards',        [BalinCompetitionController::class, 'storeReward'],
        [PermissionMiddleware::class . ':balin.manage_rewards']);
    $router->post('/balin/competitions/{uuid}/rewards/assign', [BalinCompetitionController::class, 'assignReward'],
        [PermissionMiddleware::class . ':balin.manage_rewards']);
    $router->post('/balin/competitions/{uuid}/rewards/delete', [BalinCompetitionController::class, 'destroyReward'],
        [PermissionMiddleware::class . ':balin.manage_rewards']);

    /* analytics — individual records need their own permission */
    $router->get('/balin/analytics',                [BalinAnalyticsController::class, 'index'],   $balinStats);
    $router->get('/balin/analytics/student/{uuid}', [BalinAnalyticsController::class, 'student'], $balinStats);

    /* ----------------------------------------------------------- support */
    $support = [PermissionMiddleware::class . ':manage_messages'];
    $router->get('/support',                    [SupportController::class, 'index'],      $support);
    $router->get('/support/{uuid}',              [SupportController::class, 'show'],       $support);
    $router->post('/support/{uuid}/reply',       [SupportController::class, 'reply'],      $support);
    $router->post('/support/{uuid}/close',       [SupportController::class, 'close'],      $support);
    $router->get('/support/{uuid}/attachment/{message}', [SupportController::class, 'attachment'], $support);
});
