<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class ActivityLogRepository extends BaseRepository
{
    public function write(array $data): void
    {
        $this->insert(
            'INSERT INTO activity_logs (user_id, action, target_type, target_id, ip_address, user_agent, metadata, severity, created_at)
             VALUES (:user_id, :action, :target_type, :target_id, :ip, :ua, :metadata, :severity, :now)',
            [
                'user_id'     => $data['user_id'] ?? null,
                'action'      => mb_substr((string) $data['action'], 0, 96),
                'target_type' => $data['target_type'] ?? null,
                'target_id'   => $data['target_id'] ?? null,
                'ip'          => $data['ip'] ?? null,
                'ua'          => isset($data['user_agent']) ? mb_substr((string) $data['user_agent'], 0, 500) : null,
                'metadata'    => isset($data['metadata']) && $data['metadata'] !== []
                    ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE)
                    : null,
                'severity'    => $data['severity'] ?? 'info',
                'now'         => $this->now(),
            ]
        );
    }

    /** @return array{0:string,1:array} */
    private function buildFilters(array $filters): array
    {
        $conditions = ['1 = 1'];
        $params     = [];

        if (!empty($filters['action'])) {
            $conditions[]     = 'l.action = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['severity'])) {
            $conditions[]       = 'l.severity = :severity';
            $params['severity'] = $filters['severity'];
        }
        if (!empty($filters['user'])) {
            $conditions[]   = '(u.username LIKE :u1 OR u.full_name LIKE :u2)';
            $needle         = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['user']) . '%';
            $params['u1']   = $needle;
            $params['u2']   = $needle;
        }
        if (!empty($filters['from'])) {
            $conditions[]   = 'l.created_at >= :from';
            $params['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $conditions[] = 'l.created_at <= :to';
            $params['to'] = $filters['to'];
        }

        return [implode(' AND ', $conditions), $params];
    }

    public function paginate(array $filters, int $perPage, int $offset): array
    {
        [$where, $params] = $this->buildFilters($filters);
        return $this->select(
            'SELECT l.*, u.full_name, u.username
             FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
             WHERE ' . $where . '
             ORDER BY l.id DESC
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
    }

    public function countFiltered(array $filters): int
    {
        [$where, $params] = $this->buildFilters($filters);
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE ' . $where,
            $params
        )['c'] ?? 0);
    }

    public function distinctActions(): array
    {
        return array_column(
            $this->select('SELECT DISTINCT action FROM activity_logs ORDER BY action LIMIT 200'),
            'action'
        );
    }

    public function recent(int $limit = 20): array
    {
        $limit = max(1, min($limit, 200));
        return $this->select(
            'SELECT l.*, u.full_name, u.username
             FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
             ORDER BY l.id DESC LIMIT ' . $limit
        );
    }
}
