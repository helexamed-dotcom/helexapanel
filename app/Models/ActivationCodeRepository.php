<?php
declare(strict_types=1);

namespace HeleXa\Models;

/**
 * Single-use activation codes. Each one opens one package for the one
 * student who uses it first; after that it is spent.
 */
final class ActivationCodeRepository extends BaseRepository
{
    /** No 0/O, 1/I/L: a code read out over the phone must not be misheard. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * @return array<int,string> the new codes
     */
    public function generate(int $packageId, int $count, ?int $days, ?string $expiresAt, ?string $note, ?int $adminId): array
    {
        $count = max(1, min(500, $count));
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            for ($try = 0; $try < 8; $try++) {
                $code = 'HLX-' . $this->chunk(4) . '-' . $this->chunk(4);
                $added = $this->execute(
                    'INSERT IGNORE INTO activation_codes
                        (code, package_id, duration_days, expires_at, note, created_by, created_at)
                     VALUES (:code, :p, :d, :e, :n, :a, :now)',
                    [
                        'code' => $code, 'p' => $packageId,
                        'd' => $days !== null && $days > 0 ? min(3650, $days) : null,
                        'e' => $expiresAt, 'n' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 191) : null,
                        'a' => $adminId, 'now' => $this->now(),
                    ]
                );
                if ($added > 0) {
                    $codes[] = $code;
                    break;
                }
            }
        }

        return $codes;
    }

    /** @return array<int,array<string,mixed>> */
    public function page(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($filters);
        return $this->select(
            "SELECT c.*, p.title AS package_title, u.full_name AS redeemer_name, u.username AS redeemer_username,
                    u.uuid AS redeemer_uuid
             FROM activation_codes c
             JOIN packages p ON p.id = c.package_id
             LEFT JOIN users u ON u.id = c.redeemed_by
             $where
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT " . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->where($filters);
        return (int) ($this->selectOne("SELECT COUNT(*) AS c FROM activation_codes c $where", $params)['c'] ?? 0);
    }

    public function findByCode(string $code): ?array
    {
        return $this->selectOne(
            'SELECT c.*, p.title AS package_title FROM activation_codes c
             JOIN packages p ON p.id = c.package_id AND p.deleted_at IS NULL
             WHERE c.code = :code LIMIT 1',
            ['code' => $code]
        );
    }

    /**
     * Claims a code for one student, atomically: of two people racing for
     * the same code, exactly one UPDATE matches.
     */
    public function claim(int $id, int $userId): bool
    {
        return $this->execute(
            'UPDATE activation_codes SET redeemed_by = :u, redeemed_at = :now
             WHERE id = :id AND redeemed_by IS NULL AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at >= :now2)',
            ['u' => $userId, 'now' => $this->now(), 'id' => $id, 'now2' => $this->now()]
        ) === 1;
    }

    public function revoke(int $id): void
    {
        $this->execute(
            'UPDATE activation_codes SET revoked_at = :now WHERE id = :id AND redeemed_by IS NULL',
            ['now' => $this->now(), 'id' => $id]
        );
    }

    /** @return array<int,array<string,mixed>> the codes one student used */
    public function redeemedBy(int $userId): array
    {
        try {
            return $this->select(
                'SELECT c.code, c.redeemed_at, c.duration_days, p.title AS package_title
                 FROM activation_codes c JOIN packages p ON p.id = c.package_id
                 WHERE c.redeemed_by = :u ORDER BY c.redeemed_at DESC',
                ['u' => $userId]
            );
        } catch (\PDOException) {
            return [];
        }
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function where(array $filters): array
    {
        $where  = [];
        $params = [];
        if ((int) ($filters['package_id'] ?? 0) > 0) {
            $where[] = 'c.package_id = :pkg';
            $params['pkg'] = (int) $filters['package_id'];
        }
        switch ((string) ($filters['status'] ?? '')) {
            case 'unused':
                $where[] = 'c.redeemed_by IS NULL AND c.revoked_at IS NULL AND (c.expires_at IS NULL OR c.expires_at >= :now)';
                $params['now'] = $this->now();
                break;
            case 'used':
                $where[] = 'c.redeemed_by IS NOT NULL';
                break;
            case 'revoked':
                $where[] = 'c.revoked_at IS NOT NULL';
                break;
            case 'expired':
                $where[] = 'c.redeemed_by IS NULL AND c.revoked_at IS NULL AND c.expires_at < :now';
                $params['now'] = $this->now();
                break;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(c.code LIKE :q1 OR c.note LIKE :q2)';
            $params['q1'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
        }
        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }

    private function chunk(int $length): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }
}
