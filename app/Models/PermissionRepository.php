<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class PermissionRepository extends BaseRepository
{
    /**
     * Effective permissions = role grants, plus per-user allows, minus per-user denies.
     * Super admin is handled by the caller and always receives everything.
     */
    public function effectiveFor(int $userId, int $roleId): array
    {
        $rolePerms = $this->select(
            'SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :role',
            ['role' => $roleId]
        );

        $overrides = $this->select(
            'SELECT p.slug, up.effect FROM user_permissions up JOIN permissions p ON p.id = up.permission_id
             WHERE up.user_id = :user',
            ['user' => $userId]
        );

        $effective = [];
        foreach ($rolePerms as $row) {
            $effective[$row['slug']] = true;
        }
        foreach ($overrides as $row) {
            if ($row['effect'] === 'deny') {
                unset($effective[$row['slug']]);
            } else {
                $effective[$row['slug']] = true;
            }
        }

        return array_keys($effective);
    }

    /** @return array<string,string> slug => effect */
    public function overridesFor(int $userId): array
    {
        $rows = $this->select(
            'SELECT p.slug, up.effect FROM user_permissions up
             JOIN permissions p ON p.id = up.permission_id
             WHERE up.user_id = :user',
            ['user' => $userId]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[$row['slug']] = $row['effect'];
        }
        return $map;
    }

    /**
     * Replaces an admin's permission set in one transaction.
     * Unknown slugs are dropped rather than trusted.
     */
    public function syncUserPermissions(int $userId, array $allowedSlugs, ?int $grantedBy): void
    {
        $valid = array_column($this->all(), 'id', 'slug');

        \HeleXa\Core\Database::transaction(function () use ($userId, $allowedSlugs, $grantedBy, $valid): void {
            $this->execute('DELETE FROM user_permissions WHERE user_id = :user', ['user' => $userId]);
            foreach (array_unique($allowedSlugs) as $slug) {
                if (!isset($valid[$slug])) {
                    continue;
                }
                $this->insert(
                    'INSERT INTO user_permissions (user_id, permission_id, effect, granted_by, created_at)
                     VALUES (:user, :permission, :effect, :by, :now)',
                    [
                        'user'       => $userId,
                        'permission' => (int) $valid[$slug],
                        'effect'     => 'allow',
                        'by'         => $grantedBy,
                        'now'        => $this->now(),
                    ]
                );
            }
        });
    }

    public function all(): array
    {
        return $this->select('SELECT id, slug, name, module FROM permissions ORDER BY module, id');
    }

    public function roleBySlug(string $slug): ?array
    {
        return $this->selectOne('SELECT id, slug, name FROM roles WHERE slug = :slug LIMIT 1', ['slug' => $slug]);
    }
}
