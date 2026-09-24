<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Core\Str;
use HeleXa\Models\AcademicRepository;
use HeleXa\Models\PermissionRepository;
use HeleXa\Models\UserRepository;

/**
 * JSON export / import of every student with their academic placement and
 * access: courses (with dates), Balin island, question bank subjects and
 * flashcard courses.
 *
 * Everything that refers to another record is written by NAME (university,
 * major, term, group, course…) so a file moves between installations whose
 * ids differ. Course uuids are included too and win when they match.
 *
 * Passwords: hashes leave the site only when the admin ticks the box, and are
 * accepted back only in bcrypt/argon2 form. A new account without one gets a
 * temporary password that is shown once, exactly like the manual form.
 */
final class StudentTransfer
{
    public const FORMAT = 'helexa-students';
    public const VERSION = 1;
    private const MAX_ROWS = 20000;

    private AcademicRepository $academic;
    private UserRepository $users;
    private ?int $studentRoleId = null;
    /** @var array<string,array<string,int>> lookup caches by kind */
    private array $maps = [];

    public function __construct()
    {
        $this->academic = new AcademicRepository();
        $this->users    = new UserRepository();
    }

    /* ============================================================ export */

    public function exportTo(bool $withHashes): void
    {
        $db    = Database::connection();
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

        echo "{\n  \"format\": \"" . self::FORMAT . "\",\n  \"version\": " . self::VERSION . ",\n";
        echo '  "exported_at": ' . json_encode(date('c')) . ",\n  \"students\": [";

        $first  = true;
        $lastId = 0;
        do {
            $stmt = $db->prepare(
                "SELECT u.*, un.title AS university_title, m.title AS major_title, g.title AS group_title
                   FROM users u
                   JOIN roles r ON r.id = u.role_id AND r.slug = 'student'
                   LEFT JOIN universities un ON un.id = u.university_id
                   LEFT JOIN majors m ON m.id = u.major_id
                   LEFT JOIN student_groups g ON g.id = u.group_id
                  WHERE u.deleted_at IS NULL AND u.id > :after
                  ORDER BY u.id LIMIT 500"
            );
            $stmt->execute(['after' => $lastId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
            $rel = $this->relations($ids);

            foreach ($rows as $u) {
                $id     = (int) $u['id'];
                $lastId = $id;
                $item = [
                    'id'         => $u['uuid'],
                    'username'   => $u['username'],
                    'full_name'  => $u['full_name'],
                    'mobile'     => $u['mobile'],
                    'email'      => $u['email'],
                    'gender'     => $u['gender'],
                    'status'     => $u['status'],
                    'university' => $u['university_title'],
                    'major'      => $u['major_title'],
                    'major_note' => $u['major'],
                    'terms'      => $rel['terms'][$id] ?? [],
                    'group'      => $u['group_title'],
                    'courses'    => $rel['courses'][$id] ?? [],
                    'balin'      => isset($rel['balin'][$id]),
                    'qbank_subjects'     => $rel['qbank'][$id] ?? [],
                    'flashcard_courses'  => $rel['fc'][$id] ?? [],
                    'created_at' => $u['created_at'],
                    'last_login_at' => $u['last_login_at'] ?? null,
                ];
                if ($withHashes) {
                    $item['password_hash'] = $u['password_hash'];
                }
                echo ($first ? "\n" : ",\n") . '    ' . str_replace("\n", "\n    ", (string) json_encode($item, $flags));
                $first = false;
            }
            flush();
        } while (count($rows) === 500);

        echo ($first ? '' : "\n  ") . "]\n}\n";
    }

    /** @param array<int,int> $ids */
    private function relations(array $ids): array
    {
        $out = ['terms' => [], 'courses' => [], 'balin' => [], 'qbank' => [], 'fc' => []];
        if ($ids === []) {
            return $out;
        }
        $in = implode(',', array_map('intval', $ids));
        $db = Database::connection();

        foreach ($db->query("SELECT us.user_id, t.title FROM user_semesters us JOIN terms t ON t.id = us.term_id WHERE us.user_id IN ($in) ORDER BY t.sort_order, t.id") as $r) {
            $out['terms'][(int) $r['user_id']][] = $r['title'];
        }
        foreach ($db->query("SELECT sc.user_id, c.uuid, c.title, sc.status, sc.starts_at, sc.ends_at
                               FROM student_courses sc JOIN courses c ON c.id = sc.course_id
                              WHERE sc.user_id IN ($in) ORDER BY c.title") as $r) {
            $out['courses'][(int) $r['user_id']][] = [
                'id' => $r['uuid'], 'title' => $r['title'], 'status' => $r['status'],
                'starts_at' => $r['starts_at'], 'ends_at' => $r['ends_at'],
            ];
        }
        foreach ($this->safeQuery("SELECT user_id FROM balin_student_access WHERE is_enabled = 1 AND user_id IN ($in)") as $r) {
            $out['balin'][(int) $r['user_id']] = true;
        }
        foreach ($this->safeQuery("SELECT a.user_id, s.title FROM qb_student_access a JOIN qb_subjects s ON s.id = a.subject_id WHERE a.user_id IN ($in)") as $r) {
            $out['qbank'][(int) $r['user_id']][] = $r['title'];
        }
        foreach ($this->safeQuery("SELECT a.user_id, c.title FROM fc_access a JOIN fc_courses c ON c.id = a.course_id WHERE a.user_id IN ($in)") as $r) {
            $out['fc'][(int) $r['user_id']][] = $r['title'];
        }

        return $out;
    }

    /** Optional modules may not be installed; their absence is not an error. */
    private function safeQuery(string $sql): array
    {
        try {
            return Database::connection()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
    }

    /* ============================================================ import */

    /**
     * @param array{update_existing:bool, sync_access:bool, restore_hashes:bool, dry_run:bool, author:?int} $opts
     * @return array{total:int, created:int, updated:int, skipped:int, errors:array<int,string>, warnings:array<int,string>, passwords:array<int,array{username:string,password:string}>}
     */
    public function import(string $json, array $opts): array
    {
        $report = ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0,
                   'errors' => [], 'warnings' => [], 'passwords' => []];

        $json = preg_replace('/^\xEF\xBB\xBF/', '', trim($json)) ?? '';
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $m) === 1) {
            $json = $m[1];
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('فایل JSON معتبر نیست: ' . $e->getMessage());
        }

        $list = is_array($data) && array_is_list($data) ? $data : ($data['students'] ?? null);
        if (!is_array($list) || $list === []) {
            throw new \RuntimeException('هیچ دانشجویی در فایل پیدا نشد (کلید "students" لازم است).');
        }
        if (count($list) > self::MAX_ROWS) {
            throw new \RuntimeException('حداکثر ' . fa((string) self::MAX_ROWS) . ' دانشجو در هر فایل.');
        }

        $role = (new PermissionRepository())->roleBySlug('student');
        if ($role === null) {
            throw new \RuntimeException('نقش «دانشجو» در سامانه پیدا نشد.');
        }
        $this->studentRoleId = (int) $role['id'];

        foreach (array_values($list) as $i => $row) {
            $n = $i + 1;
            $report['total']++;
            try {
                if (!is_array($row)) {
                    throw new \RuntimeException('ساختار این ردیف یک شیء نیست.');
                }
                $result = $opts['dry_run']
                    ? $this->importOne($row, $opts, $report, $n)
                    : Database::transaction(function () use ($row, $opts, &$report, $n): string {
                        return $this->importOne($row, $opts, $report, $n);
                    });
                $report[$result]++;
            } catch (\Throwable $e) {
                $report['errors'][$n] = $e instanceof \RuntimeException ? $e->getMessage() : 'خطای داخلی هنگام ثبت.';
                if (!$e instanceof \RuntimeException) {
                    error_log('[students import] #' . $n . ': ' . $e->getMessage());
                }
            }
        }

        return $report;
    }

    /** @return string created | updated | skipped */
    private function importOne(array $row, array $opts, array &$report, int $n): string
    {
        $username = trim((string) ($row['username'] ?? ''));
        $fullName = trim((string) ($row['full_name'] ?? $row['name'] ?? ''));
        $mobile   = Jalali::toLatinDigits(trim((string) ($row['mobile'] ?? '')));
        $email    = trim((string) ($row['email'] ?? ''));

        if ($username === '' && preg_match('/^09\d{9}$/', $mobile) === 1) {
            $username = $mobile; // a phone-only row still gets a login name
        }
        if (preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username) !== 1) {
            throw new \RuntimeException('نام کاربری «' . $username . '» معتبر نیست (حروف انگلیسی، عدد، . _ -؛ ۳ تا ۶۴).');
        }
        if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 191) {
            throw new \RuntimeException('نام کامل باید بین ۳ و ۱۹۱ کاراکتر باشد.');
        }
        if ($mobile !== '' && preg_match('/^09\d{9}$/', $mobile) !== 1) {
            $report['warnings'][$n] = 'موبایل «' . $mobile . '» نامعتبر بود و ثبت نشد.';
            $mobile = '';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = '';
        }

        // ----- find the existing account: uuid first, then username
        $existing = null;
        $uuid = is_string($row['id'] ?? null) ? trim($row['id']) : '';
        if ($uuid !== '') {
            $existing = $this->findStudent('uuid', $uuid);
        }
        $existing ??= $this->findStudent('username', $username);

        $other = $this->findAny($username);
        if ($other !== null && ($existing === null || (int) $other['id'] !== (int) $existing['id'])) {
            throw new \RuntimeException('نام کاربری «' . $username . '» متعلق به حساب دیگری است.');
        }
        if ($mobile !== '' && $this->users->mobileExists($mobile, $existing !== null ? (int) $existing['id'] : null)) {
            $report['warnings'][$n] = 'موبایل ' . $mobile . ' برای حساب دیگری ثبت است و نادیده گرفته شد.';
            $mobile = '';
        }
        if ($existing !== null && !$opts['update_existing']) {
            return 'skipped';
        }

        // ----- academic chain, by name
        $universityId = $this->idByTitle('university', $row['university'] ?? null);
        $majorId      = $universityId !== null ? $this->majorId($universityId, $row['major'] ?? null) : null;
        $termIds = [];
        foreach ((array) ($row['terms'] ?? ($row['term'] ?? [])) as $t) {
            $tid = $this->idByTitle('term', $t);
            if ($tid !== null && $this->academic->termBelongsToMajor($tid, $majorId)) {
                $termIds[] = $tid;
            }
        }
        $termIds = array_values(array_unique($termIds));
        $groupId = null;
        if (!empty($row['group'])) {
            foreach ($termIds as $tid) {
                $groupId = $this->groupId($tid, (string) $row['group']);
                if ($groupId !== null) {
                    break;
                }
            }
        }

        $gender = in_array($row['gender'] ?? null, ['male', 'female'], true) ? $row['gender'] : null;
        $status = in_array($row['status'] ?? null, ['active', 'inactive', 'suspended'], true) ? $row['status'] : 'active';

        $data = [
            'full_name'     => $fullName,
            'username'      => $username,
            'mobile'        => $mobile !== '' ? $mobile : null,
            'email'         => $email !== '' ? $email : null,
            'gender'        => $gender,
            'major'         => mb_substr(trim((string) ($row['major_note'] ?? '')), 0, 191) ?: null,
            'university_id' => $universityId,
            'major_id'      => $majorId,
            'term_id'       => $termIds[0] ?? null,
            'group_id'      => $groupId,
            'status'        => $status,
        ];

        // ----- password
        $hash = null;
        $mustChange = 1;
        $given = is_string($row['password_hash'] ?? null) ? $row['password_hash'] : '';
        if ($opts['restore_hashes'] && preg_match('/^\$(2y|2b|argon2id|argon2i)\$/', $given) === 1 && strlen($given) <= 255) {
            $hash = $given;
            $mustChange = 0;
        } elseif (is_string($row['password'] ?? null) && mb_strlen($row['password']) >= 8) {
            $hash = Auth::hashPassword($row['password']);
        }

        if ($opts['dry_run']) {
            return $existing !== null ? 'updated' : 'created';
        }

        $temporary = null;
        if ($existing !== null) {
            $userId = (int) $existing['id'];
            $this->users->update($userId, $data);
            if ($hash !== null) {
                Database::connection()->prepare(
                    'UPDATE users SET password_hash = :h, must_change_password = :m, password_changed_at = NOW() WHERE id = :id'
                )->execute(['h' => $hash, 'm' => $mustChange, 'id' => $userId]);
            }
            $result = 'updated';
        } else {
            if ($hash === null) {
                $temporary = Str::temporaryPassword();
                $hash = Auth::hashPassword($temporary);
            }
            $userId = $this->users->create($data + [
                'uuid'                 => $uuid !== '' && preg_match('/^[0-9a-f-]{36}$/i', $uuid) === 1 && $this->findAnyUuid($uuid) === null ? strtolower($uuid) : Str::uuid4(),
                'role_id'              => $this->studentRoleId,
                'password_hash'        => $hash,
                'must_change_password' => $mustChange,
                'created_by'           => $opts['author'],
            ]);
            $result = 'created';
        }

        $this->academic->syncSemesters($userId, $termIds, $majorId);

        if ($opts['sync_access']) {
            $this->syncAccess($userId, $row, $opts['author'], $report, $n);
        }

        // Listed only once everything for this row has succeeded.
        if ($result === 'created' && $temporary !== null && count($report['passwords']) < 5000) {
            $report['passwords'][] = ['username' => $username, 'password' => $temporary];
        }

        return $result;
    }

    private function syncAccess(int $userId, array $row, ?int $author, array &$report, int $n): void
    {
        $db = Database::connection();
        $missing = [];

        if (is_array($row['courses'] ?? null)) {
            $enrol = new \HeleXa\Models\EnrollmentRepository();
            foreach ($row['courses'] as $c) {
                $c = is_array($c) ? $c : ['title' => (string) $c];
                $courseId = null;
                if (!empty($c['id'])) {
                    $courseId = $this->idByUuid('courses', (string) $c['id']);
                }
                $courseId ??= $this->idByTitle('course', $c['title'] ?? null);
                if ($courseId === null) {
                    $missing[] = (string) ($c['title'] ?? $c['id'] ?? '?');
                    continue;
                }
                $enrol->assign($userId, $courseId, [
                    'status'    => in_array($c['status'] ?? 'active', ['active', 'suspended', 'expired', 'cancelled'], true) ? ($c['status'] ?? 'active') : 'active',
                    'starts_at' => $this->dateOrNull($c['starts_at'] ?? null),
                    'ends_at'   => $this->dateOrNull($c['ends_at'] ?? null),
                ], $author);
            }
        }

        if (array_key_exists('balin', $row)) {
            $balin = new \HeleXa\Models\Balin\BalinAccessRepository();
            if (filter_var($row['balin'], FILTER_VALIDATE_BOOLEAN)) {
                $balin->grant($userId, $author, 'import');
            } elseif ($balin->find($userId) !== null) {
                $balin->revoke($userId, $author, 'import');
            }
        }

        if (is_array($row['qbank_subjects'] ?? null)) {
            $ids = [];
            foreach ($row['qbank_subjects'] as $t) {
                $id = $this->idByTitle('qbank', $t);
                if ($id !== null) {
                    $ids[] = $id;
                } else {
                    $missing[] = (string) $t;
                }
            }
            (new \HeleXa\Models\QuestionBank\QbAccessRepository())->sync($userId, $ids, $author);
        }

        if (is_array($row['flashcard_courses'] ?? null)) {
            $db->prepare('DELETE FROM fc_access WHERE user_id = :u')->execute(['u' => $userId]);
            $ins = $db->prepare('INSERT IGNORE INTO fc_access (user_id, course_id, granted_by, granted_at) VALUES (:u, :c, :b, NOW())');
            foreach ($row['flashcard_courses'] as $t) {
                $id = $this->idByTitle('fc', $t);
                if ($id !== null) {
                    $ins->execute(['u' => $userId, 'c' => $id, 'b' => $author]);
                } else {
                    $missing[] = (string) $t;
                }
            }
        }

        if ($missing !== []) {
            $report['warnings'][$n] = trim(($report['warnings'][$n] ?? '') . ' پیدا نشد: ' . implode('، ', array_slice($missing, 0, 6)));
        }
    }

    /* ----------------------------------------------------------- lookups */

    private function findStudent(string $column, string $value): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT u.* FROM users u JOIN roles r ON r.id = u.role_id AND r.slug = 'student'
              WHERE u.{$column} = :v AND u.deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute(['v' => $value]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function findAny(string $username): ?array
    {
        $stmt = Database::connection()->prepare('SELECT id FROM users WHERE username = :v LIMIT 1');
        $stmt->execute(['v' => $username]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function findAnyUuid(string $uuid): ?array
    {
        $stmt = Database::connection()->prepare('SELECT id FROM users WHERE uuid = :v LIMIT 1');
        $stmt->execute(['v' => $uuid]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function idByUuid(string $table, string $uuid): ?int
    {
        $stmt = Database::connection()->prepare("SELECT id FROM {$table} WHERE uuid = :v LIMIT 1");
        $stmt->execute(['v' => $uuid]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function idByTitle(string $kind, mixed $title): ?int
    {
        if (!is_scalar($title) || trim((string) $title) === '') {
            return null;
        }
        if (!isset($this->maps[$kind])) {
            $sql = [
                'university' => 'SELECT id, title FROM universities',
                'term'       => 'SELECT id, title FROM terms ORDER BY id',
                'course'     => 'SELECT id, title FROM courses WHERE deleted_at IS NULL ORDER BY id',
                'qbank'      => 'SELECT id, title FROM qb_subjects WHERE depth = 1',
                'fc'         => 'SELECT id, title FROM fc_courses',
            ][$kind];
            $this->maps[$kind] = [];
            foreach ($this->safeQuery($sql) as $r) {
                $this->maps[$kind][self::norm((string) $r['title'])] ??= (int) $r['id'];
            }
        }

        return $this->maps[$kind][self::norm((string) $title)] ?? null;
    }

    private function majorId(int $universityId, mixed $title): ?int
    {
        if (!is_scalar($title) || trim((string) $title) === '') {
            return null;
        }
        foreach ($this->academic->majors($universityId) as $m) {
            if (self::norm((string) $m['title']) === self::norm((string) $title)) {
                return (int) $m['id'];
            }
        }
        return null;
    }

    private function groupId(int $termId, string $title): ?int
    {
        foreach ($this->academic->groups($termId) as $g) {
            if (self::norm((string) $g['title']) === self::norm($title)) {
                return (int) $g['id'];
            }
        }
        return null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $ts = strtotime($value);

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    private static function norm(string $s): string
    {
        $s = str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], $s);

        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)) ?? $s);
    }
}
