<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Api;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\CalendarRepository;
use HeleXa\Models\ContentRepository;
use HeleXa\Models\ContentStatusRepository;
use HeleXa\Models\EnrollmentRepository;
use HeleXa\Models\ExamRepository;
use HeleXa\Models\MessageRepository;
use HeleXa\Models\NotificationRepository;
use HeleXa\Models\ScheduleRepository;
use HeleXa\Models\SectionRepository;
use HeleXa\Services\AcademicScope;
use HeleXa\Services\Auth;
use HeleXa\Services\ContentAccess;
use HeleXa\Services\Jalali;

/**
 * JSON for the Android app.
 *
 * Read-only, and deliberately thin: every figure it returns is fetched through
 * the same repositories the web pages use, so the app cannot drift into
 * showing a different number than the site for the same student. Nothing here
 * decides who may see what — the route table puts this behind the same
 * authentication and role middleware as the student pages, and the repositories
 * scope by the signed-in user.
 *
 * Two rules shape every method:
 *
 *  - No lesson bodies. The app renders content through the existing viewer,
 *    which issues a short-lived single-use token per document; handing the
 *    HTML out here would route around that and make the content cacheable on
 *    the device, which is the one thing the whole viewer exists to prevent.
 *
 *  - Dates go out twice. `date` is the Gregorian value the client can sort and
 *    compare; `date_label` is the Jalali string a student reads. Converting on
 *    the device would mean shipping a second calendar implementation and having
 *    it disagree with the website's.
 */
final class MobileController extends Controller
{
    /** Who is signed in, and what is waiting for them. */
    public function me(Request $request, array $params = []): Response
    {
        $user = Auth::user();

        return $this->json([
            'ok'   => true,
            'user' => [
                'uuid'            => (string) $user['uuid'],
                'full_name'       => (string) $user['full_name'],
                'username'        => (string) $user['username'],
                'mobile'          => $user['mobile'] !== null ? (string) $user['mobile'] : null,
                'phone_verified'  => !empty($user['phone_verified_at']),
                // Whether a password exists, never the hash and never a hint of it.
                'has_password'    => is_string($user['password_hash'] ?? null) && $user['password_hash'] !== '',
                'university'      => $user['university_title'] ?? null,
                'major'           => $user['major_title'] ?? ($user['major'] ?? null),
                'avatar_url'      => '/account/avatar/' . $user['uuid'],
                'joined_at'       => (string) $user['created_at'],
                'joined_label'    => jdate($user['created_at']),
            ],
            'unread' => [
                'notifications' => (new NotificationRepository())->unreadCount((int) $user['id']),
                'messages'      => (new MessageRepository())->unreadCount((int) $user['id']),
            ],
        ]);
    }

    /** The home screen: today's classes, the next exams, and the courses list. */
    public function dashboard(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $userId  = (int) $user['id'];
        $termIds = AcademicScope::termIds($user);
        $groupId = $user['group_id'] !== null ? (int) $user['group_id'] : null;

        // 0 = شنبه, matching the schedule table's own convention.
        $weekday = Jalali::weekdayIndex(time());

        $today = [];
        foreach ((new ScheduleRepository())->forStudentTerms($termIds, $groupId) as $entry) {
            foreach ((new ScheduleRepository())->items((int) $entry['schedule']['id']) as $item) {
                if ((int) $item['weekday'] !== $weekday) {
                    continue;
                }
                $today[] = $this->scheduleItem($item);
            }
        }
        usort($today, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        $exams = array_map(
            fn (array $e): array => $this->exam($e),
            array_slice((new ExamRepository())->forStudentTerms($termIds, $groupId, null, true), 0, 5)
        );

        return $this->json([
            'ok'        => true,
            'today'     => $today,
            'exams'     => $exams,
            'courses'   => array_map(
                fn (array $c): array => $this->courseSummary($c),
                (new EnrollmentRepository())->coursesForStudent($userId)
            ),
            'unread'    => [
                'notifications' => (new NotificationRepository())->unreadCount($userId),
                'messages'      => (new MessageRepository())->unreadCount($userId),
            ],
        ]);
    }

    public function courses(Request $request, array $params = []): Response
    {
        return $this->json([
            'ok'      => true,
            'courses' => array_map(
                fn (array $c): array => $this->courseSummary($c),
                (new EnrollmentRepository())->coursesForStudent((int) Auth::id())
            ),
        ]);
    }

    /**
     * One course, as a tree of sections and the lessons inside them.
     *
     * Access is re-checked here rather than assumed from the list: a course a
     * student could see yesterday may have been unassigned since, and the list
     * they are holding could be that old.
     */
    public function course(Request $request, array $params = []): Response
    {
        $user = Auth::user();

        // Throws notFound for a course this student is not enrolled on, which
        // is deliberate: "exists but not yours" and "does not exist" must look
        // identical from outside, or the app becomes a way to enumerate the
        // catalogue.
        $course   = ContentAccess::authorizeCourse($user, (string) ($params['uuid'] ?? ''));
        $courseId = (int) $course['id'];
        $statuses = (new ContentStatusRepository())->forCourse((int) $user['id'], $courseId);
        $byId     = [];
        foreach ($statuses as $row) {
            $byId[(int) $row['content_id']] = $row;
        }

        $contents = [];
        foreach ((new ContentRepository())->forCourse($courseId) as $item) {
            if (($item['status'] ?? '') !== 'published') {
                continue;
            }
            $status = $byId[(int) $item['id']] ?? null;

            $contents[] = [
                'uuid'         => (string) $item['uuid'],
                'section_id'   => $item['section_id'] !== null ? (int) $item['section_id'] : null,
                'title'        => (string) $item['title'],
                'type'         => (string) $item['content_type'],
                'minutes'      => $item['estimated_minutes'] !== null ? (int) $item['estimated_minutes'] : null,
                'status'       => $status['status'] ?? 'unread',
                'seconds_read' => (int) ($status['total_seconds'] ?? 0),
                // The address of the viewer, not of the document. The viewer is
                // what mints the per-open token; a direct file path would have
                // no token to mint.
                'viewer_path'  => '/content/' . $item['uuid'],
            ];
        }

        $sections = array_map(static fn (array $s): array => [
            'id'        => (int) $s['id'],
            'parent_id' => $s['parent_id'] !== null ? (int) $s['parent_id'] : null,
            'title'     => (string) $s['title'],
            'type'      => (string) $s['section_type'],
        ], array_filter(
            (new SectionRepository())->forCourse($courseId),
            static fn (array $s): bool => ($s['status'] ?? '') === 'published'
        ));

        return $this->json([
            'ok'       => true,
            'course'   => $this->courseSummary($course),
            'sections' => array_values($sections),
            'contents' => $contents,
        ]);
    }

    /** The whole week, grouped by day. */
    public function schedule(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $termIds = AcademicScope::termIds($user);
        $groupId = $user['group_id'] !== null ? (int) $user['group_id'] : null;

        $repository = new ScheduleRepository();
        $days       = array_fill(0, 7, []);

        foreach ($repository->forStudentTerms($termIds, $groupId) as $entry) {
            foreach ($repository->items((int) $entry['schedule']['id']) as $item) {
                $weekday = (int) $item['weekday'];
                if ($weekday < 0 || $weekday > 6) {
                    continue;
                }
                $days[$weekday][] = $this->scheduleItem($item);
            }
        }

        foreach ($days as &$items) {
            usort($items, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
        }
        unset($items);

        return $this->json([
            'ok'      => true,
            'weekdays' => ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'],
            'days'    => $days,
        ]);
    }

    /** Finals or midterms, whichever the client asks for. */
    public function exams(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $termIds = AcademicScope::termIds($user);
        $groupId = $user['group_id'] !== null ? (int) $user['group_id'] : null;

        $kind = $request->string('kind', 'final');
        if (!in_array($kind, ['final', 'midterm', 'quiz', 'practical', 'other'], true)) {
            $kind = 'final';
        }

        return $this->json([
            'ok'    => true,
            'kind'  => $kind,
            'exams' => array_map(
                fn (array $e): array => $this->exam($e),
                (new ExamRepository())->forStudentTerms($termIds, $groupId, $kind, false)
            ),
        ]);
    }

    /** One Jalali month of exams and events. */
    public function calendar(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $termIds = AcademicScope::termIds($user);
        $groupId = $user['group_id'] !== null ? (int) $user['group_id'] : null;

        [$currentYear, $currentMonth] = Jalali::currentMonth();

        $year  = $request->int('year', $currentYear);
        $month = $request->int('month', $currentMonth);

        // Clamp rather than trust, exactly as the web calendar does: a crafted
        // ?month=99 would otherwise walk off the end of the grid.
        if ($month < 1 || $month > 12 || $year < 1300 || $year > 1500) {
            [$year, $month] = [$currentYear, $currentMonth];
        }

        // The grid's first and last day, so the range covers the leading and
        // trailing days of adjacent months that the month view also shows.
        $grid = Jalali::monthGrid($year, $month);
        $from = (string) $grid['days'][0]['date'];
        $to   = (string) $grid['days'][count($grid['days']) - 1]['date'];

        $events = array_map(static fn (array $e): array => [
            'title'      => (string) $e['title'],
            'type'       => (string) $e['event_type'],
            'date'       => (string) $e['event_date'],
            'date_label' => jdate($e['event_date']),
        ], (new CalendarRepository())->forStudentTerms((int) $user['id'], $termIds, $groupId, $from, $to));

        $exams = array_map(
            fn (array $e): array => $this->exam($e),
            (new ExamRepository())->betweenDatesForTerms($termIds, $groupId, $from, $to)
        );

        return $this->json([
            'ok'     => true,
            'year'   => $year,
            'month'  => $month,
            'events' => $events,
            'exams'  => $exams,
        ]);
    }

    public function notifications(Request $request, array $params = []): Response
    {
        $rows = (new NotificationRepository())->forUser((int) Auth::id(), 50);

        return $this->json([
            'ok'            => true,
            'notifications' => array_map(static fn (array $n): array => [
                'id'         => (int) $n['id'],
                'title'      => (string) $n['title'],
                'body'       => (string) ($n['body'] ?? ''),
                'type'       => (string) ($n['notif_type'] ?? 'system'),
                'read'       => !empty($n['read_at']),
                'sent_at'    => (string) $n['created_at'],
                'sent_label' => jdate($n['created_at']),
            ], $rows),
        ]);
    }

    public function readNotification(Request $request, array $params = []): Response
    {
        (new NotificationRepository())->markRead((int) Auth::id(), (int) ($params['id'] ?? 0));

        return $this->json(['ok' => true]);
    }

    /* ------------------------------------------------------------ shaping */

    private function courseSummary(array $course): array
    {
        return [
            'uuid'        => (string) $course['uuid'],
            'title'       => (string) $course['title'],
            'description' => $course['description'] !== null ? (string) $course['description'] : null,
            'color'       => $course['color'] !== null ? (string) $course['color'] : null,
        ];
    }

    private function scheduleItem(array $item): array
    {
        return [
            'title'    => (string) $item['title'],
            // Trimmed to HH:MM: the seconds are always zero and only make the
            // client decide how to hide them.
            'start'    => substr((string) $item['start_time'], 0, 5),
            'end'      => substr((string) $item['end_time'], 0, 5),
            'teacher'  => $item['teacher'] !== null ? (string) $item['teacher'] : null,
            'location' => $item['location'] !== null ? (string) $item['location'] : null,
            'color'    => $item['color'] !== null ? (string) $item['color'] : null,
        ];
    }

    private function exam(array $exam): array
    {
        return [
            'title'      => (string) $exam['title'],
            'kind'       => (string) $exam['exam_kind'],
            'date'       => (string) $exam['exam_date'],
            'date_label' => jdate($exam['exam_date']),
            'start'      => $exam['start_time'] !== null ? substr((string) $exam['start_time'], 0, 5) : null,
            'location'   => $exam['location'] !== null ? (string) $exam['location'] : null,
        ];
    }
}
