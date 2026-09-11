<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;
use PDOException;

/**
 * The XP ledger.
 *
 * XP is never a number stored on a student and incremented. It is the sum of
 * append-only rows, each naming what earned it. That is what makes the
 * balance auditable, makes a mistaken award correctable by deleting one row,
 * and makes the leaderboard reconstructible from scratch at any time.
 *
 * Every award carries an idempotency key derived from its cause, so the same
 * cause can be replayed — by a retry, a double-click, or a sync queue
 * draining twice — without paying twice.
 */
final class BalinXpRepository extends BaseRepository
{
    private const DUPLICATE = '23000';

    /**
     * @return array{awarded:bool, id:?int}
     */
    public function award(
        int $userId,
        int $amount,
        string $type,
        ?string $sourceType,
        ?int $sourceId,
        string $idempotencyKey,
        ?int $competitionId = null,
        array $metadata = []
    ): array {
        if ($amount === 0) {
            return ['awarded' => false, 'id' => null];
        }

        try {
            $id = $this->insert(
                'INSERT INTO balin_xp_transactions
                    (user_id, amount, type, source_type, source_id, competition_id, metadata, idempotency_key, created_at)
                 VALUES (:user, :amount, :type, :source_type, :source_id, :competition, :metadata, :idem, :now)',
                [
                    'user'        => $userId,
                    'amount'      => $amount,
                    'type'        => $type,
                    'source_type' => $sourceType,
                    'source_id'   => $sourceId,
                    'competition' => $competitionId,
                    'metadata'    => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
                    'idem'        => $idempotencyKey,
                    'now'         => $this->now(),
                ]
            );

            return ['awarded' => true, 'id' => $id];
        } catch (PDOException $e) {
            if ($e->getCode() !== self::DUPLICATE) {
                throw $e;
            }
            // This exact award already exists. Not an error: the guard worked.
            return ['awarded' => false, 'id' => null];
        }
    }

    public function totalFor(int $userId): int
    {
        return (int) ($this->selectOne(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM balin_xp_transactions WHERE user_id = :user',
            ['user' => $userId]
        )['total'] ?? 0);
    }

    public function recentFor(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return $this->select(
            "SELECT * FROM balin_xp_transactions
             WHERE user_id = :user ORDER BY id DESC LIMIT {$limit}",
            ['user' => $userId]
        );
    }

    /** XP earned inside one competition window, from the ledger. */
    public function totalForCompetition(int $userId, int $competitionId): int
    {
        return (int) ($this->selectOne(
            'SELECT COALESCE(SUM(amount), 0) AS total
             FROM balin_xp_transactions
             WHERE user_id = :user AND competition_id = :competition',
            ['user' => $userId, 'competition' => $competitionId]
        )['total'] ?? 0);
    }

    public function grandTotal(): int
    {
        return (int) ($this->selectOne(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM balin_xp_transactions'
        )['total'] ?? 0);
    }

    /**
     * The overall board, rebuilt from the ledger.
     * @return array<int, array{user_id:int, score:float}>
     */
    public function leaderboardRows(int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));

        return $this->select(
            "SELECT user_id, SUM(amount) AS score
             FROM balin_xp_transactions
             GROUP BY user_id
             HAVING score > 0
             ORDER BY score DESC
             LIMIT {$limit}"
        );
    }

    /** @return array<int, array{user_id:int, score:float}> */
    public function competitionLeaderboardRows(int $competitionId, int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));

        return $this->select(
            "SELECT user_id, SUM(amount) AS score
             FROM balin_xp_transactions
             WHERE competition_id = :competition
             GROUP BY user_id
             HAVING score > 0
             ORDER BY score DESC
             LIMIT {$limit}",
            ['competition' => $competitionId]
        );
    }
}
