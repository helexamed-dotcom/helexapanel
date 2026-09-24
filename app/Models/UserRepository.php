<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class UserRepository extends BaseRepository
{
    private const COLUMNS = 'u.id, u.uuid, u.role_id, u.username, u.mobile, u.phone_verified_at, u.email,
        u.password_hash, u.must_change_password, u.full_name, u.gender, u.avatar_path, u.major,
        u.university_id, u.major_id, u.term_id, u.group_id, u.status, u.registration_source,
        u.failed_attempts, u.locked_until, u.totp_enabled,
        u.last_login_at, u.created_at, r.slug AS role_slug,
        univ.title AS university_title, mj.title AS major_title';

    /**
     * The same columns minus the password hash, plus a flag saying whether
     * one exists.
     *
     * Admin screens list hundreds of accounts and none of them needs the
     * hash; not selecting it is stronger than remembering never to print it,
     * because a future template cannot leak what was never loaded.
     */
    private const LIST_COLUMNS = 'u.id, u.uuid, u.role_id, u.username, u.mobile, u.phone_verified_at, u.email,
        (u.password_hash IS NOT NULL AND u.password_hash <> \'\') AS has_password,
        u.must_change_password, u.full_name, u.gender, u.avatar_path, u.major,
        u.university_id, u.major_id, u.term_id, u.group_id, u.status, u.registration_source,
        u.failed_attempts, u.locked_until, u.totp_enabled,
        u.last_login_at, u.created_at, r.slug AS role_slug,
        univ.title AS university_title, mj.title AS major_title';

    /** Joined once here so every finder returns the readable academic labels. */
    private const JOINS = 'JOIN roles r ON r.id = u.role_id
        LEFT JOIN universities univ ON univ.id = u.university_id
        LEFT JOIN majors mj ON mj.id = u.major_id';

    /** Login identifier can be either the username or the mobile number. */
    public function findByIdentifier(string $identifier): ?array
    {
        return $this->selectOne(
            'SELECT ' . self::COLUMNS . '
             FROM users u ' . self::JOINS . '
             WHERE (u.username = :username OR u.mobile = :mobile) AND u.deleted_at IS NULL
             LIMIT 1',
            // Native prepared statements cannot reuse one named placeholder twice.
            ['username' => $identifier, 'mobile' => $identifier]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne(
            'SELECT ' . self::COLUMNS . '
             FROM users u ' . self::JOINS . '
             WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1',
            ['id' => $id]
        );
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT ' . self::COLUMNS . '
             FROM users u ' . self::JOINS . '
             WHERE u.uuid = :uuid AND u.deleted_at IS NULL LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * Sign-in by phone number.
     *
     * Deliberately not findByIdentifier(): that one also matches a username,
     * and a username that happens to look like a phone number must never
     * satisfy a flow whose whole premise is that the caller holds the SIM.
     */
    public function findByMobile(string $mobile): ?array
    {
        if ($mobile === '') {
            return null;
        }
        return $this->selectOne(
            'SELECT ' . self::COLUMNS . '
             FROM users u ' . self::JOINS . '
             WHERE u.mobile = :mobile AND u.deleted_at IS NULL LIMIT 1',
            ['mobile' => $mobile]
        );
    }

    public function markPhoneVerified(int $id): void
    {
        $this->execute(
            'UPDATE users SET phone_verified_at = :now, updated_at = :updated_at WHERE id = :id',
            ['now' => $this->now(), 'updated_at' => $this->now(), 'id' => $id]
        );
    }

    /**
     * Clears the password entirely, leaving the account reachable only by a
     * texted code. Distinct from updatePassword(): writing NULL through that
     * method would be a silent way to make every password check pass if one
     * ever forgot to test for NULL first.
     */
    public function clearPassword(int $id): void
    {
        $this->execute(
            'UPDATE users SET password_hash = NULL, password_changed_at = :now,
                    must_change_password = 0, updated_at = :updated_at
             WHERE id = :id',
            ['now' => $this->now(), 'updated_at' => $this->now(), 'id' => $id]
        );
    }

    public function usernameExists(string $username, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT 1 FROM users WHERE username = :u';
        $params = ['u' => $username];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        return $this->selectOne($sql . ' LIMIT 1', $params) !== null;
    }

    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO users
                (uuid, role_id, username, mobile, phone_verified_at, email, password_hash, password_changed_at,
                 must_change_password, full_name, gender, major, university_id, major_id,
                 term_id, group_id, status, registration_source, created_by, created_at)
             VALUES
                (:uuid, :role_id, :username, :mobile, :phone_verified_at, :email, :password_hash, :password_changed_at,
                 :must_change_password, :full_name, :gender, :major, :university_id, :major_id,
                 :term_id, :group_id, :status, :registration_source, :created_by, :created_at)',
            [
                'uuid'                 => $data['uuid'],
                'role_id'              => $data['role_id'],
                'username'             => $data['username'],
                'mobile'               => $data['mobile'] ?? null,
                'phone_verified_at'    => $data['phone_verified_at'] ?? null,
                'email'                => $data['email'] ?? null,
                // Null is a real value here: an account created by a texted
                // code has no password until its owner chooses one.
                'password_hash'        => $data['password_hash'] ?? null,
                'password_changed_at'  => $this->now(),
                'must_change_password' => (int) ($data['must_change_password'] ?? 0),
                'full_name'            => $data['full_name'],
                'gender'               => $data['gender'] ?? null,
                'major'                => $data['major'] ?? null,
                'university_id'        => $data['university_id'] ?? null,
                'major_id'             => $data['major_id'] ?? null,
                'term_id'              => $data['term_id'] ?? null,
                'group_id'             => $data['group_id'] ?? null,
                'status'               => $data['status'] ?? 'active',
                'registration_source'  => $data['registration_source'] ?? 'admin',
                'created_by'           => $data['created_by'] ?? null,
                'created_at'           => $this->now(),
            ]
        );
    }

    public function registerSuccessfulLogin(int $userId, string $ip): void
    {
        $this->execute(
            'UPDATE users
             SET failed_attempts = 0, locked_until = NULL, last_login_at = :login_at,
                 last_login_ip = :ip, updated_at = :updated_at
             WHERE id = :id',
            ['login_at' => $this->now(), 'ip' => $ip, 'updated_at' => $this->now(), 'id' => $userId]
        );
    }

    public function registerFailedLogin(int $userId, int $maxAttempts, int $lockoutSeconds): void
    {
        $this->execute('UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = :id', ['id' => $userId]);

        $attempts = (int) ($this->selectOne('SELECT failed_attempts FROM users WHERE id = :id', ['id' => $userId])['failed_attempts'] ?? 0);
        if ($attempts >= $maxAttempts) {
            $this->execute(
                'UPDATE users SET locked_until = :until, failed_attempts = 0 WHERE id = :id',
                ['until' => date('Y-m-d H:i:s', time() + $lockoutSeconds), 'id' => $userId]
            );
        }
    }

    public function isLocked(array $user): bool
    {
        $until = $user['locked_until'] ?? null;
        return is_string($until) && strtotime($until) > time();
    }

    public function updatePassword(int $userId, string $hash): void
    {
        $this->execute(
            'UPDATE users
             SET password_hash = :hash, password_changed_at = :changed_at,
                 must_change_password = 0, updated_at = :updated_at
             WHERE id = :id',
            ['hash' => $hash, 'changed_at' => $this->now(), 'updated_at' => $this->now(), 'id' => $userId]
        );
    }

    /**
     * Filtered, paginated user list.
     * Every filter value is bound; the only interpolated parts are the
     * whitelisted sort column and the integer LIMIT/OFFSET.
     */
    public function paginate(array $filters, int $perPage, int $offset): array
    {
        [$where, $params] = $this->buildFilters($filters);

        $sortable = ['created_at' => 'u.created_at', 'name' => 'u.full_name', 'last_login' => 'u.last_login_at'];
        $sortKey  = $filters['sort'] ?? 'created_at';
        $orderBy  = $sortable[$sortKey] ?? $sortable['created_at'];
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        // Ids to lift to the top — students with an unresolved suspicious
        // sign-in. Cast to int before they touch the SQL.
        $priority = array_values(array_filter(array_map('intval', $filters['priority_ids'] ?? []), static fn (int $i): bool => $i > 0));
        $lead     = $priority !== [] ? '(u.id IN (' . implode(',', $priority) . ')) DESC, ' : '';

        $rows = $this->select(
            'SELECT ' . self::LIST_COLUMNS . ',
                    t.title AS term_title, g.title AS group_title,
                    (SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id AND s.is_active = 1) AS active_sessions
             FROM users u
             ' . self::JOINS . '
             LEFT JOIN terms t ON t.id = u.term_id
             LEFT JOIN student_groups g ON g.id = u.group_id
             WHERE ' . $where . '
             ORDER BY ' . $lead . $orderBy . ' ' . $direction . '
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        return $rows;
    }

    public function countFiltered(array $filters): int
    {
        [$where, $params] = $this->buildFilters($filters);
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM users u ' . self::JOINS . ' WHERE ' . $where,
            $params
        )['c'] ?? 0);
    }

    /** @return array{0:string,1:array} */
    private function buildFilters(array $filters): array
    {
        $conditions = ['u.deleted_at IS NULL'];
        $params     = [];

        if (!empty($filters['role'])) {
            $conditions[]    = 'r.slug = :role';
            $params['role']  = $filters['role'];
        }
        if (!empty($filters['roles']) && is_array($filters['roles'])) {
            $placeholders = [];
            foreach (array_values($filters['roles']) as $i => $slug) {
                $key                = 'role_' . $i;
                $placeholders[]     = ':' . $key;
                $params[$key]       = $slug;
            }
            $conditions[] = 'r.slug IN (' . implode(', ', $placeholders) . ')';
        }
        if (!empty($filters['status'])) {
            $conditions[]     = 'u.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['term_id'])) {
            $conditions[]      = 'u.term_id = :term_id';
            $params['term_id'] = (int) $filters['term_id'];
        }
        if (!empty($filters['group_id'])) {
            $conditions[]       = 'u.group_id = :group_id';
            $params['group_id'] = (int) $filters['group_id'];
        }
        if (!empty($filters['university_id'])) {
            $conditions[]            = 'u.university_id = :university_id';
            $params['university_id'] = (int) $filters['university_id'];
        }
        if (!empty($filters['major_id'])) {
            $conditions[]        = 'u.major_id = :major_id';
            $params['major_id']  = (int) $filters['major_id'];
        }
        if (!empty($filters['search'])) {
            $conditions[] = '(u.full_name LIKE :s1 OR u.username LIKE :s2 OR u.mobile LIKE :s3)';
            $needle       = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']) . '%';
            $params['s1'] = $needle;
            $params['s2'] = $needle;
            $params['s3'] = $needle;
        }

        return [implode(' AND ', $conditions), $params];
    }

    public function update(int $id, array $data): void
    {
        $this->execute(
            'UPDATE users SET
                full_name = :full_name, username = :username, mobile = :mobile, email = :email,
                gender = :gender, major = :major, university_id = :university_id, major_id = :major_id,
                term_id = :term_id, group_id = :group_id, status = :status,
                updated_at = :updated_at
             WHERE id = :id AND deleted_at IS NULL',
            [
                'full_name'  => $data['full_name'],
                'username'   => $data['username'],
                'mobile'     => $data['mobile'] ?? null,
                'email'      => $data['email'] ?? null,
                'gender'     => $data['gender'] ?? null,
                'major'      => $data['major'] ?? null,
                'university_id' => $data['university_id'] ?? null,
                'major_id'   => $data['major_id'] ?? null,
                'term_id'    => $data['term_id'] ?? null,
                'group_id'   => $data['group_id'] ?? null,
                'status'     => $data['status'],
                'updated_at' => $this->now(),
                'id'         => $id,
            ]
        );
    }

    /**
     * Fields a student may change about themselves.
     * University, major, term and group are deliberately absent: those are
     * enrolment facts an admin sets, not preferences.
     */
    public function updateProfile(int $id, array $data): void
    {
        $this->execute(
            'UPDATE users SET full_name = :full_name, mobile = :mobile, email = :email,
                    gender = :gender, updated_at = :now
             WHERE id = :id AND deleted_at IS NULL',
            [
                'full_name' => $data['full_name'],
                'mobile'    => $data['mobile'] ?? null,
                'email'     => $data['email'] ?? null,
                'gender'    => $data['gender'] ?? null,
                'now'       => $this->now(),
                'id'        => $id,
            ]
        );
    }

    public function setAvatar(int $id, ?string $path): void
    {
        $this->execute(
            'UPDATE users SET avatar_path = :path, updated_at = :now WHERE id = :id',
            ['path' => $path, 'now' => $this->now(), 'id' => $id]
        );
    }

    /** Narrow setters, one field each — safer than reusing updateProfile()
     * for a partial change, since that method writes every field it lists
     * unconditionally and would null out the others if called with only one. */
    public function setGender(int $id, ?string $gender): void
    {
        $this->execute(
            'UPDATE users SET gender = :gender, updated_at = :now WHERE id = :id',
            ['gender' => $gender, 'now' => $this->now(), 'id' => $id]
        );
    }

    public function setMobile(int $id, ?string $mobile): void
    {
        $this->execute(
            'UPDATE users SET mobile = :mobile, updated_at = :now WHERE id = :id',
            ['mobile' => $mobile, 'now' => $this->now(), 'id' => $id]
        );
    }

    public function mobileExists(string $mobile, ?int $exceptId = null): bool
    {
        if ($mobile === '') {
            return false;
        }
        $sql    = 'SELECT 1 FROM users WHERE mobile = :m';
        $params = ['m' => $mobile];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        return $this->selectOne($sql . ' LIMIT 1', $params) !== null;
    }

    /** Students eligible to receive a message or announcement. */
    public function activeStudentIds(?int $termId = null, ?int $groupId = null): array
    {
        $where  = ["r.slug = 'student'", "u.status = 'active'", 'u.deleted_at IS NULL'];
        $params = [];

        if ($termId !== null) {
            $where[]        = 'u.term_id = :term';
            $params['term'] = $termId;
        }
        if ($groupId !== null) {
            $where[]         = 'u.group_id = :group';
            $params['group'] = $groupId;
        }

        return array_map('intval', array_column($this->select(
            'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE ' . implode(' AND ', $where),
            $params
        ), 'id'));
    }

    public function setStatus(int $id, string $status): void
    {
        $this->execute(
            'UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id AND deleted_at IS NULL',
            ['status' => $status, 'updated_at' => $this->now(), 'id' => $id]
        );
    }

    public function markMustChangePassword(int $id, string $hash): void
    {
        $this->execute(
            'UPDATE users SET password_hash = :hash, password_changed_at = :changed_at,
                    must_change_password = 1, failed_attempts = 0, locked_until = NULL, updated_at = :updated_at
             WHERE id = :id',
            ['hash' => $hash, 'changed_at' => $this->now(), 'updated_at' => $this->now(), 'id' => $id]
        );
    }

    public function unlock(int $id): void
    {
        $this->execute(
            'UPDATE users SET failed_attempts = 0, locked_until = NULL, updated_at = :updated_at WHERE id = :id',
            ['updated_at' => $this->now(), 'id' => $id]
        );
    }

    /** Soft delete keeps the audit trail and every foreign key intact. */
    public function softDelete(int $id): void
    {
        $this->execute(
            'UPDATE users SET deleted_at = :now, status = \'inactive\',
                    username = CONCAT(username, \'#deleted\', :suffix),
                    mobile = NULL, updated_at = :updated_at
             WHERE id = :id AND deleted_at IS NULL',
            ['now' => $this->now(), 'suffix' => (string) $id, 'updated_at' => $this->now(), 'id' => $id]
        );
    }

    public function countByRole(string $roleSlug): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.slug = :slug AND u.deleted_at IS NULL',
            ['slug' => $roleSlug]
        )['c'] ?? 0);
    }

    public function countActiveStudents(): int
    {
        return (int) ($this->selectOne(
            "SELECT COUNT(*) AS c FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.slug = 'student' AND u.status = 'active' AND u.deleted_at IS NULL"
        )['c'] ?? 0);
    }
}
