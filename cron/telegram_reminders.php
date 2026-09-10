<?php
declare(strict_types=1);

/**
 * HeleXa Med — Telegram reminders.
 *
 * Opportunistic piggyback on web traffic (see TelegramQueue::maybeDrain) is
 * good enough for delivering things that already happened, like a course
 * activation. It cannot deliver something that must fire AT a specific clock
 * time regardless of whether anyone is browsing the site — a reminder for
 * tomorrow, or for an exam two days out, needs a real cron entry.
 *
 * Usage (DirectAdmin → Cron Jobs, or any crontab):
 *
 *   0 22 * * *  php /home/USER/domains/YOURDOMAIN/cron/telegram_reminders.php schedule
 *   0  9 * * *  php /home/USER/domains/YOURDOMAIN/cron/telegram_reminders.php exams
 *
 * "schedule" sends each student tomorrow's class list, once, at 22:00.
 * "exams" checks every published exam and reminds students exactly two days
 * and exactly one day before it, once each. Running "all" does both in one
 * call if a single daily cron entry is preferred over two.
 *
 * Idempotent by design: every reminder is created through
 * NotificationService::publish() with a deterministic idempotency_key, the
 * same mechanism a duplicated course-activation request already relies on.
 * Running this script twice in the same day sends nothing twice.
 */

require dirname(__DIR__) . '/bootstrap/bootstrap.php';

use HeleXa\Core\Database;
use HeleXa\Core\Logger;
use HeleXa\Models\AcademicRepository;
use HeleXa\Models\ScheduleRepository;
use HeleXa\Services\Jalali;
use HeleXa\Services\NotificationService;
use HeleXa\Services\Settings;
use HeleXa\Services\Telegram\TelegramNotificationChannel;
use HeleXa\Services\Telegram\TelegramQueue;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

if (!HELEXA_INSTALLED) {
    fwrite(STDERR, "Not installed yet.\n");
    exit(1);
}

TelegramNotificationChannel::register();

if (!Settings::bool('telegram_bot_enabled', false)) {
    echo "Telegram bot is disabled — nothing to do.\n";
    exit(0);
}

$mode = $argv[1] ?? 'all';
if (!in_array($mode, ['schedule', 'exams', 'all'], true)) {
    fwrite(STDERR, "usage: php cron/telegram_reminders.php [schedule|exams|all]\n");
    exit(1);
}

$sent = 0;

/* ========================================================= schedule digest
   Every linked, schedule-notifying student gets tomorrow's class list, once
   a day, regardless of when in the day the cron actually fires — the
   idempotency key is the calendar date, not the run time. */
if ($mode === 'schedule' || $mode === 'all') {
    $tomorrow      = strtotime('+1 day');
    $tomorrowLabel = Jalali::WEEKDAYS[Jalali::weekdayIndex($tomorrow)];
    $dateKey       = date('Y-m-d', $tomorrow);

    $schedules  = new ScheduleRepository();
    $academic   = new AcademicRepository();
    $linked     = Database::select(
        "SELECT u.id AS user_id, u.term_id, u.group_id, ta.chat_id
         FROM telegram_accounts ta
         JOIN users u ON u.id = ta.user_id
         WHERE ta.unlinked_at IS NULL AND ta.notify_schedule = 1
           AND u.status = 'active' AND u.deleted_at IS NULL"
    );

    foreach ($linked as $row) {
        $userId = (int) $row['user_id'];

        // Mirrors AcademicScope::termIds() without a second round-trip to
        // fetch the full user row — this query already carries term_id.
        $termIds = $academic->semestersOf($userId);
        if ($termIds === [] && $row['term_id'] !== null) {
            $termIds = [(int) $row['term_id']];
        }
        if ($termIds === []) {
            continue;
        }
        $groupId = $row['group_id'] !== null ? (int) $row['group_id'] : null;

        $items = [];
        foreach ($schedules->forStudentTerms($termIds, $groupId) as $entry) {
            if ($entry['schedule'] === null) {
                continue;
            }
            $items = array_merge(
                $items,
                $schedules->itemsForWeekday((int) $entry['schedule']['id'], Jalali::weekdayIndex($tomorrow))
            );
        }
        if ($items === []) {
            continue;
        }

        // Plain text on purpose: this body is stored in notifications.body
        // and TelegramQueue::drainDue() HTML-escapes it before sending
        // (correct — a course-activation body has no markup either), so any
        // <b> written here would arrive at the student as literal "&lt;b&gt;".
        $lines = ['🌙 یادآوری برنامه فردا (' . $tomorrowLabel . ")"];
        foreach ($items as $item) {
            $lines[] = sprintf(
                "🕘 %s — %s\n%s%s",
                fa(substr((string) $item['start_time'], 0, 5)),
                fa(substr((string) $item['end_time'], 0, 5)),
                $item['title'],
                $item['location'] ? "\n📍 " . $item['location'] : ''
            );
        }

        $result = NotificationService::publish([
            'title'           => 'یادآوری برنامه فردا',
            'body'            => implode("\n\n", $lines),
            'notif_type'      => 'schedule',
            'idempotency_key' => "schedule_reminder:{$userId}:{$dateKey}",
            'audience'        => 'user',
            'user_id'         => $userId,
            'link_url'        => '/student/schedule',
            'expires_at'      => null,
            'created_by'      => null,
        ]);
        if ($result['created']) {
            $sent++;
        }
    }
    echo "Schedule reminders created: {$sent}\n";
}

/* ============================================================ exam reminders
   T-2 and T-1 are two independent idempotency keys per exam per student, so
   a student who is still enrolled through both windows gets exactly two
   reminders for the same exam, never more, regardless of how many times the
   cron fires on either of those days. */
if ($mode === 'exams' || $mode === 'all') {
    $examSent = 0;
    $today    = date('Y-m-d');

    foreach ([2 => 'دو روز دیگر', 1 => 'فردا'] as $daysAhead => $label) {
        $targetDate = date('Y-m-d', strtotime("+{$daysAhead} day"));

        $exams = Database::select(
            "SELECT * FROM exams
             WHERE is_published = 1 AND exam_date = :date",
            ['date' => $targetDate]
        );

        foreach ($exams as $exam) {
            // Matches the same eligibility rule the bot's own exam listing
            // uses (AcademicScope): a student's primary term_id, or any
            // secondary term picked up through multi-term enrolment.
            $students = Database::select(
                "SELECT DISTINCT u.id AS user_id FROM users u
                 LEFT JOIN user_semesters us ON us.user_id = u.id AND us.term_id = :term1
                 WHERE u.status = 'active' AND u.deleted_at IS NULL
                   AND (u.term_id = :term2 OR us.term_id IS NOT NULL)
                   AND (:group_id1 IS NULL OR u.group_id = :group_id2)",
                [
                    'term1'     => $exam['term_id'],
                    'term2'     => $exam['term_id'],
                    'group_id1' => $exam['group_id'],
                    'group_id2' => $exam['group_id'],
                ]
            );

            $kindLabel = ['final' => 'پایان‌ترم', 'midterm' => 'میان‌ترم', 'quiz' => 'کوییز', 'practical' => 'عملی', 'other' => 'سایر'];
            // Plain text — see the note above the schedule digest for why.
            $body = sprintf(
                "⏰ %s (%s)\nتا %s باقی مانده.\n📅 %s%s",
                $exam['title'],
                $kindLabel[$exam['exam_kind']] ?? $exam['exam_kind'],
                $label,
                Jalali::date((int) strtotime((string) $exam['exam_date'])),
                $exam['start_time'] ? ' ساعت ' . fa(substr((string) $exam['start_time'], 0, 5)) : ''
            );

            foreach ($students as $student) {
                $userId = (int) $student['user_id'];
                $result = NotificationService::publish([
                    'title'           => 'یادآوری امتحان',
                    'body'            => $body,
                    'notif_type'      => 'exam',
                    'idempotency_key' => "exam_reminder:{$exam['id']}:{$userId}:t{$daysAhead}",
                    'audience'        => 'user',
                    'user_id'         => $userId,
                    'link_url'        => '/student/exams',
                    'expires_at'      => null,
                    'created_by'      => null,
                ]);
                if ($result['created']) {
                    $examSent++;
                }
            }
        }
    }
    echo "Exam reminders created: {$examSent}\n";
}

/* Deliveries were only queued above; this actually sends them. Run in the
   same process so the cron log shows real delivery counts, not just how
   many were queued. */
$drained = TelegramQueue::drainDue(200);
echo "Telegram queue drained: sent={$drained['sent']} failed={$drained['failed']}\n";

Logger::info('Telegram reminders run', ['mode' => $mode, 'drained' => $drained]);
