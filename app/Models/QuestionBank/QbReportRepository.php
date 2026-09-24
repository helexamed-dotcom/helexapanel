<?php
declare(strict_types=1);

namespace HeleXa\Models\QuestionBank;

use HeleXa\Models\BaseRepository;

/**
 * «گزارش اشکال»: students telling the admin a question is wrong.
 *
 * One open report per student per question: reporting the same question
 * again while the first is still open updates it rather than stacking a
 * second copy in the admin's queue.
 */
final class QbReportRepository extends BaseRepository
{
    public const REASONS = [
        'wrong_key'  => 'کلید (پاسخ صحیح) اشتباه است',
        'wrong_text' => 'غلط تایپی یا متن اشتباه',
        'unclear'    => 'سوال یا گزینه‌ها مبهم است',
        'image'      => 'تصویر مشکل دارد',
        'duplicate'  => 'سوال تکراری است',
        'other'      => 'سایر',
    ];

    public const STATUSES = [
        'open'      => 'باز',
        'resolved'  => 'رسیدگی شد',
        'dismissed' => 'رد شد',
    ];

    public function submit(int $userId, int $questionId, string $reason, string $body): void
    {
        $reason = array_key_exists($reason, self::REASONS) ? $reason : 'other';
        $body   = mb_substr(trim($body), 0, 2000);

        $open = $this->selectOne(
            "SELECT id FROM qb_reports WHERE user_id = :u AND question_id = :q AND status = 'open' LIMIT 1",
            ['u' => $userId, 'q' => $questionId]
        );

        if ($open !== null) {
            $this->execute(
                'UPDATE qb_reports SET reason = :r, body = :b, created_at = :now WHERE id = :id',
                ['r' => $reason, 'b' => $body !== '' ? $body : null, 'now' => $this->now(), 'id' => (int) $open['id']]
            );
            return;
        }

        $this->insert(
            'INSERT INTO qb_reports (question_id, user_id, reason, body, status, created_at)
             VALUES (:q, :u, :r, :b, \'open\', :now)',
            ['q' => $questionId, 'u' => $userId, 'r' => $reason, 'b' => $body !== '' ? $body : null, 'now' => $this->now()]
        );
    }

    public function countOpen(): int
    {
        try {
            return (int) ($this->selectOne("SELECT COUNT(*) AS c FROM qb_reports WHERE status = 'open'")['c'] ?? 0);
        } catch (\PDOException) {
            return 0;   // the table arrives with a migration
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function page(string $status, int $limit, int $offset): array
    {
        $where  = '';
        $params = [];
        if (array_key_exists($status, self::STATUSES)) {
            $where = 'WHERE r.status = :status';
            $params['status'] = $status;
        }

        return $this->select(
            "SELECT r.*, q.uuid AS question_uuid, q.stem_text, q.status AS question_status,
                    s.title AS subject_title, u.full_name AS reporter_name, u.username AS reporter_username,
                    h.full_name AS handler_name,
                    (SELECT COUNT(*) FROM qb_reports x WHERE x.question_id = r.question_id AND x.status = 'open') AS open_on_question
             FROM qb_reports r
             JOIN qb_questions q ON q.id = r.question_id
             LEFT JOIN qb_subjects s ON s.id = q.subject_id
             LEFT JOIN users u ON u.id = r.user_id
             LEFT JOIN users h ON h.id = r.handled_by
             $where
             ORDER BY r.status = 'open' DESC, r.created_at DESC
             LIMIT " . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    public function count(string $status): int
    {
        if (array_key_exists($status, self::STATUSES)) {
            return (int) ($this->selectOne('SELECT COUNT(*) AS c FROM qb_reports WHERE status = :s', ['s' => $status])['c'] ?? 0);
        }
        return (int) ($this->selectOne('SELECT COUNT(*) AS c FROM qb_reports')['c'] ?? 0);
    }

    public function find(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM qb_reports WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function setStatus(int $id, string $status, ?string $note, ?int $adminId): void
    {
        if (!array_key_exists($status, self::STATUSES)) {
            return;
        }
        $this->execute(
            'UPDATE qb_reports SET status = :s, admin_note = :n, handled_by = :a, handled_at = :now WHERE id = :id',
            [
                's'   => $status,
                'n'   => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 2000) : null,
                'a'   => $status === 'open' ? null : $adminId,
                'now' => $status === 'open' ? null : $this->now(),
                'id'  => $id,
            ]
        );
    }

    /** Closes every open report on one question at once — fixing it answers them all. */
    public function resolveQuestion(int $questionId, ?string $note, ?int $adminId): int
    {
        return $this->execute(
            "UPDATE qb_reports SET status = 'resolved', admin_note = :n, handled_by = :a, handled_at = :now
             WHERE question_id = :q AND status = 'open'",
            ['n' => $note !== null && trim($note) !== '' ? trim($note) : null, 'a' => $adminId,
             'now' => $this->now(), 'q' => $questionId]
        );
    }
}
