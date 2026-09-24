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
use HeleXa\Controllers\Admin\Balin\TransferController as BalinTransferController;
use HeleXa\Controllers\Admin\CalendarController;
use HeleXa\Controllers\Admin\ContentController;
use HeleXa\Controllers\Admin\LibraryController as AdminLibrary;
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
use HeleXa\Controllers\Admin\StudentAccessController;
use HeleXa\Controllers\Admin\StudentTransferController;
use HeleXa\Controllers\Admin\SecurityFlagController;
use HeleXa\Controllers\Student\SecurityController as StudentSecurity;
use HeleXa\Controllers\Api\MobileController as MobileApi;
use HeleXa\Controllers\Api\SessionController as SessionApi;
use HeleXa\Controllers\Api\SyncController;
use HeleXa\Controllers\AuthController;
use HeleXa\Controllers\ContentViewerController;
use HeleXa\Controllers\HighlightController;
use HeleXa\Controllers\InkController;
use HeleXa\Controllers\HomeController;
use HeleXa\Controllers\PreferencesController;
use HeleXa\Controllers\Student\AnalyticsController as StudentAnalytics;
use HeleXa\Controllers\Student\CourseController as StudentCourses;
use HeleXa\Controllers\Student\DashboardController as StudentDashboard;
use HeleXa\Controllers\Student\InboxController;
use HeleXa\Controllers\Student\BalinController;
use HeleXa\Controllers\Student\BalinExamController;
use HeleXa\Controllers\Student\BalinProfileController;
use HeleXa\Controllers\Student\PlannerController;
use HeleXa\Controllers\Student\SupportController as StudentSupport;
use HeleXa\Controllers\Student\LibraryController as StudentLibrary;
use HeleXa\Controllers\Student\NoteController as StudentNotes;
use HeleXa\Controllers\Student\QuestionBankController as StudentQbank;
use HeleXa\Controllers\Student\FlashcardController as StudentFlashcards;
use HeleXa\Controllers\Admin\Flashcards\FlashcardController as FcAdminController;
use HeleXa\Controllers\Admin\Flashcards\AccessController as FcAccessController;
use HeleXa\Controllers\Admin\QuestionBank\AccessController as QbAccessController;
use HeleXa\Controllers\Admin\QuestionBank\QuestionController as QbQuestionController;
use HeleXa\Controllers\Admin\QuestionBank\TaxonomyController as QbTaxonomyController;
use HeleXa\Controllers\Admin\QuestionBank\TransferController as QbTransferController;
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
// One way in. A forgotten password is reset by an admin from the student's
// own page, which is the only path that does not depend on an outside
// service being reachable.
$router->get('/login',  [AuthController::class, 'showLogin'], [GuestMiddleware::class]);
$router->post('/login', [AuthController::class, 'login'],
    [GuestMiddleware::class, ThrottleMiddleware::class . ':login,30,300']);

// Self-registration: both routes answer 404 until an admin switches it on.
$router->get('/register',  [\HeleXa\Controllers\RegisterController::class, 'show'], [GuestMiddleware::class]);
$router->post('/register', [\HeleXa\Controllers\RegisterController::class, 'register'],
    [GuestMiddleware::class, ThrottleMiddleware::class . ':register,8,3600']);

/* ------------------------------------------------------- authenticated */
$router->post('/logout', [AuthController::class, 'logout'], [AuthenticateMiddleware::class]);

$router->get('/account/password',  [AccountController::class, 'showPasswordForm'], [AuthenticateMiddleware::class]);
$router->post('/account/password', [AccountController::class, 'updatePassword'],
    [AuthenticateMiddleware::class, ThrottleMiddleware::class . ':password,10,600']);

$router->get('/account/profile',        [AccountController::class, 'showProfile'],   [AuthenticateMiddleware::class]);
$router->get('/account/settings',       [PreferencesController::class, 'show'],     [AuthenticateMiddleware::class]);
$router->post('/account/settings',      [PreferencesController::class, 'save'],     [AuthenticateMiddleware::class]);
$router->post('/account/settings/mode', [PreferencesController::class, 'mode'],
    [AuthenticateMiddleware::class, ThrottleMiddleware::class . ':prefs_mode,30,60']);
$router->post('/account/profile',       [AccountController::class, 'updateProfile'], [AuthenticateMiddleware::class]);
$router->post('/account/avatar',        [AccountController::class, 'uploadAvatar'],
    [AuthenticateMiddleware::class, ThrottleMiddleware::class . ':avatar,10,600']);
$router->post('/account/avatar/delete', [AccountController::class, 'removeAvatar'],  [AuthenticateMiddleware::class]);
$router->get('/account/avatar/{uuid}',  [AccountController::class, 'avatar'],        [AuthenticateMiddleware::class]);
$router->get('/account/edit',           [AccountController::class, 'showEdit'],      [AuthenticateMiddleware::class]);
$router->get('/account/sessions',       [StudentSecurity::class, 'sessions'],        [AuthenticateMiddleware::class]);

/* The header's pop-up panels, fetched when opened. */
$router->get('/hub/bell',     [\HeleXa\Controllers\HubController::class, 'bell'],
    [AuthenticateMiddleware::class, ThrottleMiddleware::class . ':hub,240,60']);
$router->get('/hub/activate', [\HeleXa\Controllers\HubController::class, 'activate'],
    [AuthenticateMiddleware::class, RoleMiddleware::class . ':student', ThrottleMiddleware::class . ':hub,240,60']);

/* ------------------------------------------------------------- student */
$router->group('/student', [
    AuthenticateMiddleware::class,
    RoleMiddleware::class . ':student',
    ForcePasswordChangeMiddleware::class,
    \HeleXa\Middleware\ModuleMiddleware::class,
], function (\HeleXa\Core\Router $router): void {
    $router->get('/', [StudentDashboard::class, 'index']);
    $router->get('/courses', [StudentCourses::class, 'index']);
    $router->get('/courses/{uuid}', [StudentCourses::class, 'show']);
    $router->get('/analytics', [StudentAnalytics::class, 'index']);
    $router->get('/schedule',  [PlannerController::class, 'schedule']);
    $router->get('/schedule/choose',  [PlannerController::class, 'choose']);
    $router->post('/schedule/choose', [PlannerController::class, 'saveChoices'],
        [ThrottleMiddleware::class . ':schedule_pick,30,600']);
    $router->get('/exams',     [PlannerController::class, 'exams']);
    $router->get('/midterms',  [PlannerController::class, 'midterms']);
    $router->get('/calendar',  [PlannerController::class, 'calendar']);
    $router->get('/planner',   [PlannerController::class, 'planner']);

    /* ----------------------------------------------- the study suite */
    // «امروز من» and «درس‌های من» live on the home page now; the old
    // addresses redirect there.
    $router->get('/today', [\HeleXa\Controllers\Student\TodayController::class, 'index']);

    // «چه نوع دانشجویی هستی؟»
    $router->post('/type', [\HeleXa\Controllers\Student\StudentTypeController::class, 'request'],
        [ThrottleMiddleware::class . ':student_type,6,3600']);

    // «آزمون‌های من» — the answer route is called once per tick.
    $router->get('/my-exams',                 [\HeleXa\Controllers\Student\MyExamController::class, 'index']);
    $router->post('/my-exams/start',          [\HeleXa\Controllers\Student\MyExamController::class, 'start'],
        [ThrottleMiddleware::class . ':myexam_start,20,600']);
    $router->get('/my-exams/{uuid}',          [\HeleXa\Controllers\Student\MyExamController::class, 'show']);
    $router->post('/my-exams/{uuid}/answer',  [\HeleXa\Controllers\Student\MyExamController::class, 'answer'],
        [ThrottleMiddleware::class . ':myexam_answer,240,60']);
    $router->post('/my-exams/{uuid}/finish',  [\HeleXa\Controllers\Student\MyExamController::class, 'finish']);
    $router->post('/my-exams/{uuid}/delete',  [\HeleXa\Controllers\Student\MyExamController::class, 'destroy']);

    // «درس‌های من»
    $router->get('/study',                    [\HeleXa\Controllers\Student\StudyMarkController::class, 'index']);
    $router->post('/study',                   [\HeleXa\Controllers\Student\StudyMarkController::class, 'store'],
        [ThrottleMiddleware::class . ':study_mark,60,600']);
    $router->post('/study/clear-done',        [\HeleXa\Controllers\Student\StudyMarkController::class, 'clearDone']);
    $router->post('/study/{id}/toggle',       [\HeleXa\Controllers\Student\StudyMarkController::class, 'toggle']);
    $router->post('/study/{id}/delete',       [\HeleXa\Controllers\Student\StudyMarkController::class, 'destroy']);

    // «خرید و فعال‌سازی» — a code is guessed at most a few times.
    $router->get('/activate',  [\HeleXa\Controllers\Student\ActivationController::class, 'show']);
    $router->post('/activate', [\HeleXa\Controllers\Student\ActivationController::class, 'redeem'],
        [ThrottleMiddleware::class . ':activation_code,8,900']);

    $router->get('/notifications',              [InboxController::class, 'notifications']);
    $router->post('/notifications/read-all',    [InboxController::class, 'readAllNotifications']);
    $router->post('/notifications/{id}/read',   [InboxController::class, 'readNotification']);
    $router->get('/messages',                   [InboxController::class, 'messages']);
    $router->get('/messages/{id}',              [InboxController::class, 'showMessage']);

    /* ------------------------------------------------ support desk
       The asking end of the ticket system. The admin queue and both tables
       already existed; the only entry point was the Telegram bot, so it was
       unreachable from the site itself. No ticket id appears in these paths:
       the server resolves the student's one open conversation from the
       session, so there is nothing here to point at someone else's ticket. */
    $router->get('/sessions',      [StudentSecurity::class, 'sessions']);

    $router->get('/support',       [StudentSupport::class, 'index']);
    $router->post('/support',      [StudentSupport::class, 'send'],
        [ThrottleMiddleware::class . ':support_send,20,600']);
    $router->get('/support/attachment/{message}', [StudentSupport::class, 'attachment']);

    /* ------------------------------------------------ 📚 بانک سوال
       Every route re-checks access per request through QbAccess; the درس a
       question belongs to is read from the question, never from the URL. */
    $router->get('/qbank',                  [StudentQbank::class, 'index']);
    $router->get('/qbank/image/{name}',     [StudentQbank::class, 'image'],
        [ThrottleMiddleware::class . ':qbank_img,300,300']);
    $router->post('/qbank/answer/{uuid}',   [StudentQbank::class, 'answer'],
        [ThrottleMiddleware::class . ':qbank_answer,60,60']);
    $router->post('/qbank/mark/{uuid}',     [StudentQbank::class, 'mark'],
        [ThrottleMiddleware::class . ':qbank_mark,60,60']);
    $router->post('/qbank/report/{uuid}',   [StudentQbank::class, 'report'],
        [ThrottleMiddleware::class . ':qbank_report,12,600']);
    $router->get('/qbank/lesson/{uuid}',    [StudentQbank::class, 'lessonNote'],
        [ThrottleMiddleware::class . ':qbank_lesson,120,300']);
    $router->get('/qbank/{uuid}/list',      [StudentQbank::class, 'listing']);
    $router->get('/qbank/{uuid}',           [StudentQbank::class, 'practice']);

    /* ------------------------------------------------ 📝 یادداشت‌ها */
    $noteWrite = [ThrottleMiddleware::class . ':note_write,240,300'];
    $router->get('/notes',                                   [StudentNotes::class, 'index']);
    $router->post('/notes',                                  [StudentNotes::class, 'store'],      $noteWrite);
    $router->get('/notes/{uuid}',                            [StudentNotes::class, 'show']);
    $router->get('/notes/{uuid}/data',                       [StudentNotes::class, 'data']);
    $router->post('/notes/{uuid}/delete',                    [StudentNotes::class, 'destroy']);
    $router->post('/notes/{uuid}/files',                     [StudentNotes::class, 'upload'],
        [ThrottleMiddleware::class . ':note_upload,30,600']);
    $router->get('/notes/{uuid}/files/{file}',               [StudentNotes::class, 'file']);
    $router->get('/notes/{uuid}/files/{file}/ink',           [StudentNotes::class, 'fileInk']);
    $router->post('/notes/{uuid}/files/{file}/ink',          [StudentNotes::class, 'fileInk'],    $noteWrite);
    $router->post('/notes/{uuid}/files/{file}/delete',       [StudentNotes::class, 'deleteFile']);
    $router->post('/notes/{uuid}',                           [StudentNotes::class, 'update'],     $noteWrite);

    /* ------------------------------------------------ 📚 کتابخانه */
    $router->get('/library',                       [StudentLibrary::class, 'index']);
    $router->get('/library/{uuid}',                [StudentLibrary::class, 'show']);
    $router->get('/library/{uuid}/media/{which}',  [StudentLibrary::class, 'media'],
        [ThrottleMiddleware::class . ':library_media,600,300']);

    /* ------------------------------------------------ 🃏 فلش‌کارت
       Access is decided per request by FcAccess: a personal deck only for
       its owner, a course session only while the course is published and
       held. There is no export route, by design. */
    $fcWrite = [ThrottleMiddleware::class . ':fc_write,120,600'];
    $router->get('/flashcards',                      [StudentFlashcards::class, 'index']);
    $router->get('/flashcards/course/{uuid}',        [StudentFlashcards::class, 'course']);
    $router->get('/flashcards/deck/{uuid}',          [StudentFlashcards::class, 'deck']);
    $router->get('/flashcards/study',                [StudentFlashcards::class, 'studyAll']);
    $router->get('/flashcards/study/course/{uuid}',  [StudentFlashcards::class, 'studyCourse']);
    $router->get('/flashcards/study/deck/{uuid}',    [StudentFlashcards::class, 'studyDeck']);
    $router->post('/flashcards/rate/{uuid}',         [StudentFlashcards::class, 'rate'],
        [ThrottleMiddleware::class . ':fc_rate,240,60']);
    $router->post('/flashcards/star/{uuid}',         [StudentFlashcards::class, 'star'],
        [ThrottleMiddleware::class . ':fc_star,120,60']);
    $router->post('/flashcards/decks',               [StudentFlashcards::class, 'storeDeck'],   $fcWrite);
    $router->post('/flashcards/deck/{uuid}',         [StudentFlashcards::class, 'updateDeck'],  $fcWrite);
    $router->post('/flashcards/deck/{uuid}/delete',  [StudentFlashcards::class, 'destroyDeck'], $fcWrite);
    $router->post('/flashcards/deck/{uuid}/reset',   [StudentFlashcards::class, 'resetDeck'],   $fcWrite);
    $router->post('/flashcards/deck/{uuid}/cards',   [StudentFlashcards::class, 'storeCard'],   $fcWrite);
    $router->post('/flashcards/deck/{uuid}/import',  [StudentFlashcards::class, 'import'],
        [ThrottleMiddleware::class . ':fc_import,20,600']);
    $router->post('/flashcards/card/{uuid}',         [StudentFlashcards::class, 'updateCard'],  $fcWrite);
    $router->post('/flashcards/card/{uuid}/delete',  [StudentFlashcards::class, 'destroyCard'], $fcWrite);

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

/* ----------------------------------------------------- the Android app
   Read-only JSON for the mobile client, behind exactly the guards the
   student pages use. It is a second way to read the same data, never a
   second set of rules: no lesson body is served here, because the viewer
   is what issues a per-open token and handing HTML out around it would
   make content cacheable on the device. */
$router->group('/api/mobile', [
    AuthenticateMiddleware::class,
    RoleMiddleware::class . ':student',
    ForcePasswordChangeMiddleware::class,
], function (\HeleXa\Core\Router $router): void {
    $router->get('/me',            [MobileApi::class, 'me']);
    $router->get('/dashboard',     [MobileApi::class, 'dashboard']);
    $router->get('/courses',       [MobileApi::class, 'courses']);
    $router->get('/courses/{uuid}',[MobileApi::class, 'course']);
    $router->get('/schedule',      [MobileApi::class, 'schedule']);
    $router->get('/exams',         [MobileApi::class, 'exams']);
    $router->get('/calendar',      [MobileApi::class, 'calendar']);
    $router->get('/notifications', [MobileApi::class, 'notifications']);
    $router->post('/notifications/{id}/read', [MobileApi::class, 'readNotification']);
});

// Internal JSON API. Session-authenticated like every other route; there is
// no separate token scheme and no second way in. /api/sync only carries study
// time and highlights that were queued during a short loss of connection.
$router->group('/api', [AuthenticateMiddleware::class], function (\HeleXa\Core\Router $router): void {
    $router->get('/session/state', [SessionApi::class, 'state'],
        [ThrottleMiddleware::class . ':session_state,240,60']);

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

    // Handwriting on the lesson and the note pins on it.
    $router->get('/{uuid}/ink',    [InkController::class, 'show']);
    $router->post('/{uuid}/ink',   [InkController::class, 'save'],
        [ThrottleMiddleware::class . ':ink_save,120,60']);
    $router->get('/{uuid}/notes',  [InkController::class, 'notes']);

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
    // JSON export / import of every student (static paths, before {uuid}).
    $router->get('/students/transfer',              [StudentTransferController::class, 'index'],  $students);
    $router->get('/students/export',                [StudentTransferController::class, 'export'],
        array_merge($students, [ThrottleMiddleware::class . ':students_export,10,600']));
    $router->post('/students/import',               [StudentTransferController::class, 'import'],
        array_merge($students, [ThrottleMiddleware::class . ':students_import,10,600']));
    $router->post('/students',                      [StudentController::class, 'store'],         $students);
    $router->get('/students/{uuid}/edit',           [StudentController::class, 'edit'],          $students);
    $router->post('/students/{uuid}',               [StudentController::class, 'update'],        $students);
    $router->post('/students/{uuid}/status',        [StudentController::class, 'setStatus'],     $students);
    $router->post('/students/{uuid}/reset-password',[StudentController::class, 'resetPassword'], $students);
    $router->post('/students/{uuid}/unlock',        [StudentController::class, 'unlock'],        $students);
    $router->post('/students/{uuid}/delete',        [StudentController::class, 'destroy'],       $students);

    /* One page for everything a student can open. The page needs
       manage_students; every write also checks its own module's permission
       inside the controller. */
    $router->get('/access',                                           [StudentAccessController::class, 'index'],         $students);
    $router->get('/students/{uuid}/access',                           [StudentAccessController::class, 'show'],          $students);
    $router->post('/students/{uuid}/access/course',                   [StudentAccessController::class, 'addCourse'],     $students);
    $router->post('/students/{uuid}/access/course/{course}/status',   [StudentAccessController::class, 'courseStatus'],  $students);
    $router->post('/students/{uuid}/access/course/{course}/remove',   [StudentAccessController::class, 'removeCourse'],  $students);
    $router->post('/students/{uuid}/access/package',                  [StudentAccessController::class, 'addPackage'],    $students);
    $router->post('/students/{uuid}/access/package/{activation}/status', [StudentAccessController::class, 'packageStatus'], $students);
    $router->post('/students/{uuid}/access/balin',                    [StudentAccessController::class, 'balin'],         $students);
    $router->post('/students/{uuid}/access/qbank',                    [StudentAccessController::class, 'qbank'],         $students);
    $router->post('/students/{uuid}/access/flashcards',               [StudentAccessController::class, 'flashcards'],    $students);

    /* ----------------------------------------------- student types */
    $stc = \HeleXa\Controllers\Admin\StudentTypeController::class;
    $router->get('/student-types',                  [$stc, 'index'],    $students);
    $router->post('/student-types',                 [$stc, 'save'],     $students);
    $router->get('/student-types/requests',         [$stc, 'requests'], $students);
    $router->post('/student-types/requests/{id}',   [$stc, 'decide'],   $students);
    $router->post('/student-types/{id}/delete',     [$stc, 'destroy'],  $students);
    $router->post('/students/{uuid}/type',          [$stc, 'assign'],   $students);

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
    $router->post('/packages/{uuid}/items',      [PackageController::class, 'saveItems'],  $packages);
    $router->post('/packages/{uuid}/everyone',   [PackageController::class, 'grantEveryone'], $packages);
    $router->post('/packages/{uuid}/courses/{course}/remove', [PackageController::class, 'removeCourse'], $packages);
    $router->post('/packages/{uuid}/activate',   [PackageController::class, 'activate'],   $packages);
    $router->post('/packages/{uuid}/activate-group', [PackageController::class, 'activateGroup'], $packages);
    $router->post('/packages/{uuid}/members/{activation}/status', [PackageController::class, 'setMemberStatus'], $packages);

    /* ------------------------------------------------ activation codes */
    $router->get('/activation-codes',              [\HeleXa\Controllers\Admin\ActivationCodeController::class, 'index'],    $packages);
    $router->post('/activation-codes',             [\HeleXa\Controllers\Admin\ActivationCodeController::class, 'generate'], $packages);
    $router->get('/activation-codes/export',       [\HeleXa\Controllers\Admin\ActivationCodeController::class, 'export'],   $packages);
    $router->post('/activation-codes/{id}/revoke', [\HeleXa\Controllers\Admin\ActivationCodeController::class, 'revoke'],   $packages);

    /* ------------------------------------------- tree & html content */
    $content = [PermissionMiddleware::class . ':manage_content'];

    // The sidebar has linked here since the menu was regrouped, but no route
    // ever answered it — every content page lived under a course id, so the
    // entry 404'd. This is the cross-course index it was always pointing at:
    // every lesson in the system, searchable, with the course it belongs to.
    $router->get('/content', [ContentController::class, 'index'], $content);

    // Content library (video / article / image / file / link).
    $libUpload = array_merge($content, [ThrottleMiddleware::class . ':library_write,60,600']);
    $router->get('/library',                       [AdminLibrary::class, 'index'],   $content);
    $router->get('/library/create',                [AdminLibrary::class, 'create'],  $content);
    $router->post('/library',                      [AdminLibrary::class, 'store'],   $libUpload);
    $router->get('/library/{uuid}/edit',           [AdminLibrary::class, 'edit'],    $content);
    $router->get('/library/{uuid}/media/{which}',  [AdminLibrary::class, 'media'],   $content);
    $router->post('/library/{uuid}/delete',        [AdminLibrary::class, 'destroy'], $content);
    $router->post('/library/{uuid}',               [AdminLibrary::class, 'update'],  $libUpload);

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
    $router->post('/schedule/{id}/items/{item}',    [ScheduleController::class, 'updateItem'],  $schedule);
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

    /* Suspicious sign-ins. Acting on them means warning or suspending a
       student, so the permission is the one that already allows that. */
    $router->get('/security/flags',               [SecurityFlagController::class, 'index'],   $students);
    $router->post('/security/flags/{id}/warn',    [SecurityFlagController::class, 'warn'],    $students);
    $router->post('/security/flags/{id}/suspend', [SecurityFlagController::class, 'suspend'], $students);
    $router->post('/security/flags/{id}/dismiss', [SecurityFlagController::class, 'dismiss'], $students);

    /* ----------------------------------------------- logs & settings */
    $router->get('/logs', [ActivityLogController::class, 'index'],
        [PermissionMiddleware::class . ':view_logs']);

    /* ------------------------------------------------------ backup */
    $router->get('/backup',         [\HeleXa\Controllers\Admin\BackupController::class, 'index'],
        [PermissionMiddleware::class . ':manage_settings']);
    $router->get('/backup/sql',     [\HeleXa\Controllers\Admin\BackupController::class, 'sql'],
        [PermissionMiddleware::class . ':manage_settings', ThrottleMiddleware::class . ':backup_sql,6,600']);
    $router->get('/backup/config',  [\HeleXa\Controllers\Admin\BackupController::class, 'exportConfig'],
        [PermissionMiddleware::class . ':manage_settings']);
    $router->post('/backup/config', [\HeleXa\Controllers\Admin\BackupController::class, 'importConfig'],
        [PermissionMiddleware::class . ':manage_settings', ThrottleMiddleware::class . ':backup_import,10,600']);

    /* «صفحه اصلی دانشجو»: countdowns and which sections are on. */
    $router->get('/home-screen',                         [\HeleXa\Controllers\Admin\HomeScreenController::class, 'index'],
        [PermissionMiddleware::class . ':manage_settings']);
    $router->post('/home-screen/countdowns',             [\HeleXa\Controllers\Admin\HomeScreenController::class, 'addCountdown'],
        [PermissionMiddleware::class . ':manage_settings']);
    $router->post('/home-screen/countdowns/{id}/delete', [\HeleXa\Controllers\Admin\HomeScreenController::class, 'removeCountdown'],
        [PermissionMiddleware::class . ':manage_settings']);
    $router->post('/home-screen/sections',               [\HeleXa\Controllers\Admin\HomeScreenController::class, 'saveSections'],
        [PermissionMiddleware::class . ':manage_settings']);

    $router->get('/settings',  [SettingsController::class, 'index'],
        [PermissionMiddleware::class . ':manage_settings']);
    $router->post('/settings', [SettingsController::class, 'update'],
        [PermissionMiddleware::class . ':manage_settings']);
    $router->post('/settings/brand/{slot}',       [SettingsController::class, 'updateBrandImage'],
        [PermissionMiddleware::class . ':manage_settings', ThrottleMiddleware::class . ':brand_upload,20,300']);
    $router->post('/settings/brand/{slot}/reset', [SettingsController::class, 'resetBrandImage'],
        [PermissionMiddleware::class . ':manage_settings']);

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
    $router->post('/balin/avatars',         [BalinDashboard::class, 'saveAvatars'],
        [PermissionMiddleware::class . ':balin.manage_settings']);
    $router->post('/balin/ranking',         [BalinDashboard::class, 'saveRanking'],
        [PermissionMiddleware::class . ':balin.manage_settings']);

    /* lessons and stages */
    // Whole lessons in and out as JSON (and the AI prompt that writes them).
    $router->get('/balin/transfer',         [BalinTransferController::class, 'index'],  $balinView);
    $router->get('/balin/transfer/export',  [BalinTransferController::class, 'export'], $balinView);
    $router->get('/balin/transfer/sample',  [BalinTransferController::class, 'sample'], $balinView);
    $router->post('/balin/transfer/import', [BalinTransferController::class, 'import'],
        array_merge($balinCreate, [ThrottleMiddleware::class . ':balin_import,20,600']));

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

    /* ------------------------------------------------------ 📚 بانک سوال */
    $qbView     = [PermissionMiddleware::class . ':qbank.view'];
    $qbTaxonomy = [PermissionMiddleware::class . ':qbank.manage_taxonomy'];
    $qbWrite    = [PermissionMiddleware::class . ':qbank.manage_questions'];
    $qbPublish  = [PermissionMiddleware::class . ':qbank.publish'];
    $qbStudents = [PermissionMiddleware::class . ':qbank.manage_students'];

    $router->get('/qbank',           [QbTaxonomyController::class, 'dashboard'],    $qbView);
    $router->post('/qbank/settings', [QbTaxonomyController::class, 'saveSettings'], $qbPublish);

    $router->get('/qbank/subjects',                  [QbTaxonomyController::class, 'subjects'],        $qbView);
    $router->post('/qbank/subjects',                 [QbTaxonomyController::class, 'storeSubject'],    $qbTaxonomy);
    $router->get('/qbank/subjects/{uuid}/children',  [QbTaxonomyController::class, 'subjectChildren'], $qbView);
    $router->get('/qbank/subjects/{uuid}/lesson',    [QbTaxonomyController::class, 'lessonNote'],     $qbView);
    $router->post('/qbank/subjects/{uuid}/lesson',   [QbTaxonomyController::class, 'saveLessonNote'], $qbTaxonomy);
    $router->post('/qbank/subjects/{uuid}',          [QbTaxonomyController::class, 'updateSubject'],   $qbTaxonomy);
    $router->post('/qbank/subjects/{uuid}/delete',   [QbTaxonomyController::class, 'destroySubject'],  $qbTaxonomy);

    $router->get('/qbank/tags',               [QbTaxonomyController::class, 'tags'],       $qbView);
    $router->post('/qbank/tags',              [QbTaxonomyController::class, 'storeTag'],   $qbTaxonomy);
    $router->post('/qbank/tags/{id}',         [QbTaxonomyController::class, 'updateTag'],  $qbTaxonomy);
    $router->post('/qbank/tags/{id}/delete',  [QbTaxonomyController::class, 'destroyTag'], $qbTaxonomy);

    // "create" is declared before "{uuid}" routes: the router takes the first
    // pattern that matches, and would otherwise read "create" as an id.
    $router->get('/qbank/questions',                 [QbQuestionController::class, 'index'],   $qbView);
    $router->get('/qbank/questions/create',          [QbQuestionController::class, 'create'],  $qbWrite);
    $router->post('/qbank/questions',                [QbQuestionController::class, 'store'],
        array_merge($qbWrite, [ThrottleMiddleware::class . ':qbank_write,120,600']));
    // Bulk publish / unpublish / delete. Static path, so it sits before {uuid};
    // the controller checks the permission each action needs.
    $router->post('/qbank/questions/bulk',           [QbQuestionController::class, 'bulk'],
        array_merge($qbView, [ThrottleMiddleware::class . ':qbank_bulk,60,600']));
    $router->get('/qbank/image/{name}',              [QbQuestionController::class, 'image'],   $qbView);
    $router->get('/qbank/questions/{uuid}',          [QbQuestionController::class, 'preview'], $qbView);
    $router->get('/qbank/questions/{uuid}/edit',     [QbQuestionController::class, 'edit'],    $qbWrite);
    $router->post('/qbank/questions/{uuid}',         [QbQuestionController::class, 'update'],
        array_merge($qbWrite, [ThrottleMiddleware::class . ':qbank_write,120,600']));
    $router->post('/qbank/questions/{uuid}/status',  [QbQuestionController::class, 'setStatus'], $qbPublish);
    $router->post('/qbank/questions/{uuid}/delete',  [QbQuestionController::class, 'destroy'],   $qbWrite);

    // JSON import / export — bulk and AI-written questions.
    $router->get('/qbank/transfer',         [QbTransferController::class, 'index'],  $qbView);
    $router->get('/qbank/transfer/sample',  [QbTransferController::class, 'sample'], $qbView);
    $router->get('/qbank/export',           [QbTransferController::class, 'export'],
        array_merge($qbView, [ThrottleMiddleware::class . ':qbank_export,20,600']));
    $router->post('/qbank/import',          [QbTransferController::class, 'import'],
        array_merge($qbWrite, [ThrottleMiddleware::class . ':qbank_import,20,600']));

    $router->get('/qbank/reports',        [\HeleXa\Controllers\Admin\QuestionBank\ReportController::class, 'index'],  $qbView);
    $router->post('/qbank/reports/{id}',  [\HeleXa\Controllers\Admin\QuestionBank\ReportController::class, 'update'], $qbWrite);
    $router->get('/qbank/access',          [QbAccessController::class, 'index'],  $qbStudents);
    $router->get('/qbank/access/{uuid}',   [QbAccessController::class, 'edit'],   $qbStudents);
    $router->post('/qbank/access/{uuid}',  [QbAccessController::class, 'update'], $qbStudents);

    /* ------------------------------------------------------- 🃏 فلش‌کارت */
    $fc         = [PermissionMiddleware::class . ':flashcards.manage'];
    $fcStudents = [PermissionMiddleware::class . ':flashcards.manage_students'];
    $fcImport   = array_merge($fc, [ThrottleMiddleware::class . ':fc_admin_import,60,600']);

    $router->get('/flashcards',                       [FcAdminController::class, 'index'],         $fc);
    $router->post('/flashcards/courses',              [FcAdminController::class, 'storeCourse'],   $fc);
    // access routes before the {uuid} ones they could be mistaken for
    $router->get('/flashcards/access',                [FcAccessController::class, 'index'],        $fcStudents);
    $router->get('/flashcards/access/{uuid}',         [FcAccessController::class, 'edit'],         $fcStudents);
    $router->post('/flashcards/access/{uuid}',        [FcAccessController::class, 'update'],       $fcStudents);
    $router->get('/flashcards/course/{uuid}',         [FcAdminController::class, 'course'],        $fc);
    $router->post('/flashcards/course/{uuid}',        [FcAdminController::class, 'updateCourse'],  $fc);
    $router->post('/flashcards/course/{uuid}/delete', [FcAdminController::class, 'destroyCourse'], $fc);
    $router->post('/flashcards/course/{uuid}/decks',  [FcAdminController::class, 'storeDeck'],     $fc);
    $router->post('/flashcards/course/{uuid}/import', [FcAdminController::class, 'importCourse'],  $fcImport);
    $router->get('/flashcards/deck/{uuid}',           [FcAdminController::class, 'deck'],          $fc);
    $router->post('/flashcards/deck/{uuid}',          [FcAdminController::class, 'updateDeck'],    $fc);
    $router->post('/flashcards/deck/{uuid}/delete',   [FcAdminController::class, 'destroyDeck'],   $fc);
    $router->post('/flashcards/deck/{uuid}/cards',    [FcAdminController::class, 'storeCard'],     $fc);
    $router->post('/flashcards/deck/{uuid}/import',   [FcAdminController::class, 'importDeck'],    $fcImport);
    $router->post('/flashcards/card/{uuid}',          [FcAdminController::class, 'updateCard'],    $fc);
    $router->post('/flashcards/card/{uuid}/delete',   [FcAdminController::class, 'destroyCard'],   $fc);

    /* ----------------------------------------------------------- support */    $support = [PermissionMiddleware::class . ':manage_messages'];
    $router->get('/support',                    [SupportController::class, 'index'],      $support);
    $router->get('/support/{uuid}',              [SupportController::class, 'show'],       $support);
    $router->post('/support/{uuid}/reply',       [SupportController::class, 'reply'],      $support);
    $router->post('/support/{uuid}/close',       [SupportController::class, 'close'],      $support);
    $router->get('/support/{uuid}/attachment/{message}', [SupportController::class, 'attachment'], $support);
});
