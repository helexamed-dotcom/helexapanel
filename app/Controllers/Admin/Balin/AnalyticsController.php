<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\Balin;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\Balin\BalinCheckpointRepository;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Models\Balin\BalinStatsRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\Balin\Mastery;
use HeleXa\Services\Balin\ProfileService;
use HeleXa\Services\Balin\Recalculator;

/**
 * Analytics for the island.
 *
 * Individual student answers are sensitive teaching data, so the per-student
 * view sits behind balin.view_statistics rather than general admin access.
 * The aggregate view carries no individual's record and is the one to reach
 * for when the question is about content rather than a person.
 */
final class AnalyticsController extends Controller
{
    public function __construct(
        private readonly BalinStatsRepository $stats = new BalinStatsRepository(),
        private readonly BalinLessonRepository $lessons = new BalinLessonRepository(),
    ) {
    }

    public function index(Request $request, array $params = []): Response
    {
        $totals = $this->stats->platformTotals();

        return $this->page('layouts.app', 'admin.balin.analytics', [
            'title'     => 'آمار جزیره بالین',
            'totals'    => $totals,
            'accuracy'  => Mastery::accuracy(
                (int) ($totals['correct_answers'] ?? 0),
                (int) ($totals['answers'] ?? 0)
            ),
            'skills'    => $this->stats->skillMasteryAverages(),
            'lessons'   => $this->lessonAnalytics(),
            'exams'     => $this->examAnalytics(),
            'questions' => $this->hardestQuestions(),
            'students'  => $this->topStudents(),
        ]);
    }

    /** One student's full record. */
    public function student(Request $request, array $params = []): Response
    {
        $student = (new UserRepository())->findByUuid((string) ($params['uuid'] ?? ''));

        if ($student === null) {
            throw HttpException::notFound();
        }

        $userId = (int) $student['id'];

        return $this->page('layouts.app', 'admin.balin.student', [
            'title'    => $student['full_name'],
            'student'  => $student,
            'profile'  => (new ProfileService())->forStudent($userId),
            'cps'      => (new Recalculator())->clinicalPerformanceScore($userId),
            'attempts' => $this->attemptHistory($userId),
            'activity' => $this->recentActivity($userId),
        ]);
    }

    // ------------------------------------------------------------- queries

    /** Views, starts, completions and drop-off per lesson. */
    private function lessonAnalytics(): array
    {
        return Database::select(
            "SELECT l.id, l.uuid, l.title, l.icon, l.status,
                    (SELECT COUNT(DISTINCT p.user_id) FROM balin_student_progress p
                      WHERE p.lesson_id = l.id) AS starters,
                    (SELECT COUNT(DISTINCT p.user_id) FROM balin_student_progress p
                      WHERE p.lesson_id = l.id AND p.status = 'completed') AS finishers,
                    (SELECT COUNT(*) FROM balin_stages s WHERE s.lesson_id = l.id AND s.status = 'published') AS stages,
                    (SELECT COALESCE(AVG(m.mastery_percent), 0) FROM balin_student_lesson_mastery m
                      WHERE m.lesson_id = l.id) AS average_mastery,
                    (SELECT COUNT(*) FROM balin_answers a WHERE a.lesson_id = l.id) AS answers,
                    (SELECT COALESCE(SUM(a.is_correct), 0) FROM balin_answers a WHERE a.lesson_id = l.id) AS correct
             FROM balin_lessons l
             ORDER BY l.display_order"
        );
    }

    /** Pass rate and attempts-to-pass for every exam. */
    private function examAnalytics(): array
    {
        $repository = new BalinCheckpointRepository();
        $rows       = [];

        foreach ($this->lessons->all() as $lesson) {
            foreach ($repository->forLesson((int) $lesson['id']) as $exam) {
                $stats = $repository->examStatistics((int) $exam['id']);

                $rows[] = [
                    'exam'         => $exam,
                    'lesson_title' => $lesson['title'],
                    'stats'        => $stats,
                    'pass_rate'    => $stats['attempts'] > 0
                        ? round($stats['passes'] / $stats['attempts'] * 100, 1)
                        : 0.0,
                ];
            }
        }

        return $rows;
    }

    /**
     * The questions students get wrong most often — with enough attempts
     * behind them to mean something, rather than one unlucky answer.
     */
    private function hardestQuestions(): array
    {
        return Database::select(
            "SELECT q.id, q.uuid, q.prompt, q.difficulty, l.title AS lesson_title,
                    COUNT(a.id) AS attempts,
                    COALESCE(SUM(a.is_correct), 0) AS correct,
                    ROUND(COALESCE(SUM(a.is_correct), 0) / COUNT(a.id) * 100, 1) AS accuracy,
                    ROUND(COALESCE(AVG(a.time_spent_ms), 0) / 1000, 1) AS average_seconds
             FROM balin_questions q
             JOIN balin_lessons l ON l.id = q.lesson_id
             JOIN balin_answers a ON a.question_id = q.id
             GROUP BY q.id, q.uuid, q.prompt, q.difficulty, l.title
             HAVING attempts >= 5
             ORDER BY accuracy ASC
             LIMIT 20"
        );
    }

    private function topStudents(): array
    {
        return Database::select(
            "SELECT u.id, u.uuid, u.full_name, s.total_xp, s.cached_level,
                    s.accuracy_percent, s.stages_completed, s.answered_count
             FROM balin_user_stats s
             JOIN users u ON u.id = s.user_id
             WHERE s.total_xp > 0
             ORDER BY s.total_xp DESC
             LIMIT 25"
        );
    }

    private function attemptHistory(int $userId): array
    {
        return Database::select(
            "SELECT a.*, e.title AS exam_title, e.pass_threshold_percent, l.title AS lesson_title
             FROM balin_checkpoint_attempts a
             JOIN balin_checkpoint_exams e ON e.id = a.exam_id
             JOIN balin_lessons l ON l.id = e.lesson_id
             WHERE a.user_id = :user AND a.is_preview = 0
             ORDER BY a.id DESC
             LIMIT 50",
            ['user' => $userId]
        );
    }

    private function recentActivity(int $userId): array
    {
        return Database::select(
            "SELECT x.amount, x.type, x.created_at, x.metadata
             FROM balin_xp_transactions x
             WHERE x.user_id = :user
             ORDER BY x.id DESC
             LIMIT 40",
            ['user' => $userId]
        );
    }
}
