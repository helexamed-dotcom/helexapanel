<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Core\Str;
use HeleXa\Models\Balin\BalinAccessRepository;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Models\EnrollmentRepository;
use HeleXa\Models\Flashcards\FcStudyRepository;
use HeleXa\Models\PackageRepository;
use HeleXa\Models\QuestionBank\QbAccessRepository;

/**
 * Backups, two kinds.
 *
 * 1. The whole database as SQL — every table, every row. This is the real
 *    backup: restored from phpMyAdmin's Import tab, it brings the site back
 *    exactly. It is streamed table by table, so a big database never has to
 *    fit in memory.
 *
 * 2. The configuration as JSON — settings, packages (with everything inside
 *    them), activation codes, and every student's access — keyed by uuid and
 *    username rather than by database id. It can be read back into this site
 *    or into a fresh install that has the same courses and students, which
 *    the SQL file cannot do.
 */
final class Backup
{
    /** Settings that must never travel between installs. */
    private const PRIVATE_SETTINGS = ['app_key', 'install_token', 'cron_token'];

    /* =============================================================== SQL */

    /** Writes the full dump to the output stream. */
    public static function streamSql(): void
    {
        $pdo    = Database::connection();
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM);

        echo "-- HeleXa Med — full database backup\n";
        echo '-- ' . date('Y-m-d H:i:s') . "\n";
        echo "-- Restore: phpMyAdmin → select the database → Import → this file.\n\n";
        echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

        foreach ($tables as [$table]) {
            $name   = str_replace('`', '``', (string) $table);
            $create = $pdo->query('SHOW CREATE TABLE `' . $name . '`')->fetch(\PDO::FETCH_NUM);

            echo "-- ------------------------------------------------------------\n";
            echo "DROP TABLE IF EXISTS `$name`;\n";
            echo $create[1] . ";\n\n";

            $offset = 0;
            $chunk  = 400;
            do {
                $rows = $pdo->query('SELECT * FROM `' . $name . '` LIMIT ' . $chunk . ' OFFSET ' . $offset)->fetchAll(\PDO::FETCH_ASSOC);
                if ($rows !== []) {
                    $columns = '`' . implode('`, `', array_map(static fn ($c) => str_replace('`', '``', (string) $c), array_keys($rows[0]))) . '`';
                    $values  = [];
                    foreach ($rows as $row) {
                        $values[] = '(' . implode(', ', array_map(static function ($v) use ($pdo): string {
                            if ($v === null) {
                                return 'NULL';
                            }
                            return $pdo->quote((string) $v);
                        }, array_values($row))) . ')';
                    }
                    echo "INSERT INTO `$name` ($columns) VALUES\n" . implode(",\n", $values) . ";\n";
                }
                $offset += $chunk;
                if (function_exists('flush')) {
                    @flush();
                }
            } while (count($rows) === $chunk);

            echo "\n";
        }

        echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    }

    /* ============================================================== JSON */

    /** @return array<string,mixed> */
    public static function exportConfig(): array
    {
        $safe = static function (callable $read): array {
            try {
                return $read();
            } catch (\PDOException) {
                return [];
            }
        };

        $settings = [];
        foreach ($safe(static fn () => Database::select('SELECT setting_key, setting_value, value_type FROM settings')) as $row) {
            if (!in_array($row['setting_key'], self::PRIVATE_SETTINGS, true)) {
                $settings[] = ['key' => $row['setting_key'], 'value' => $row['setting_value'], 'type' => $row['value_type']];
            }
        }

        $packages = [];
        foreach ($safe(static fn () => Database::select('SELECT * FROM packages WHERE deleted_at IS NULL ORDER BY sort_order, id')) as $p) {
            $pid = (int) $p['id'];
            $packages[] = [
                'uuid'           => $p['uuid'],
                'title'          => $p['title'],
                'description'    => $p['description'],
                'color'          => $p['color'],
                'status'         => $p['status'],
                'auto_grant'     => (int) $p['auto_grant_new_courses'],
                'is_full_access' => (int) ($p['is_full_access'] ?? 0),
                'is_free'        => (int) ($p['is_free'] ?? 0),
                'sort_order'     => (int) $p['sort_order'],
                'courses'        => array_column($safe(static fn () => Database::select(
                    'SELECT c.uuid FROM package_courses pc JOIN courses c ON c.id = pc.course_id WHERE pc.package_id = :p',
                    ['p' => $pid]
                )), 'uuid'),
                'qbank_subjects' => array_column($safe(static fn () => Database::select(
                    "SELECT s.uuid FROM package_items i JOIN qb_subjects s ON s.id = i.item_id
                     WHERE i.package_id = :p AND i.item_type = 'qbank_subject'", ['p' => $pid]
                )), 'uuid'),
                'balin_lessons'  => array_column($safe(static fn () => Database::select(
                    "SELECT l.uuid FROM package_items i JOIN balin_lessons l ON l.id = i.item_id
                     WHERE i.package_id = :p AND i.item_type = 'balin_lesson'", ['p' => $pid]
                )), 'uuid'),
                'flashcards'     => array_column($safe(static fn () => Database::select(
                    "SELECT c.uuid FROM package_items i JOIN fc_courses c ON c.id = i.item_id
                     WHERE i.package_id = :p AND i.item_type = 'flashcard_course'", ['p' => $pid]
                )), 'uuid'),
            ];
        }

        $codes = $safe(static fn () => Database::select(
            'SELECT c.code, p.uuid AS package, c.duration_days AS days, c.expires_at, c.note,
                    c.created_at, u.username AS redeemed_by, c.redeemed_at, c.revoked_at
             FROM activation_codes c JOIN packages p ON p.id = c.package_id
             LEFT JOIN users u ON u.id = c.redeemed_by ORDER BY c.id'
        ));

        // Access, one entry per student, keyed by username.
        $students = [];
        $add = static function (array $rows, string $field) use (&$students): void {
            foreach ($rows as $row) {
                $name = (string) $row['username'];
                $students[$name] ??= ['username' => $name];
                unset($row['username']);
                $students[$name][$field][] = count($row) === 1 ? reset($row) : $row;
            }
        };
        $add($safe(static fn () => Database::select(
            'SELECT u.username, c.uuid AS course, sc.status, sc.starts_at, sc.ends_at
             FROM student_courses sc JOIN users u ON u.id = sc.user_id JOIN courses c ON c.id = sc.course_id
             WHERE u.deleted_at IS NULL'
        )), 'courses');
        $add($safe(static fn () => Database::select(
            'SELECT u.username, p.uuid AS package, pa.status, pa.starts_at, pa.ends_at
             FROM package_activations pa JOIN users u ON u.id = pa.user_id JOIN packages p ON p.id = pa.package_id
             WHERE u.deleted_at IS NULL'
        )), 'packages');
        $add($safe(static fn () => Database::select(
            'SELECT u.username, s.uuid FROM qb_student_access a
             JOIN users u ON u.id = a.user_id JOIN qb_subjects s ON s.id = a.subject_id WHERE u.deleted_at IS NULL'
        )), 'qbank');
        $add($safe(static fn () => Database::select(
            'SELECT u.username, c.uuid FROM fc_access a
             JOIN users u ON u.id = a.user_id JOIN fc_courses c ON c.id = a.course_id WHERE u.deleted_at IS NULL'
        )), 'flashcards');
        $add($safe(static fn () => Database::select(
            'SELECT u.username, 1 AS enabled FROM balin_student_access a
             JOIN users u ON u.id = a.user_id WHERE a.is_enabled = 1 AND u.deleted_at IS NULL'
        )), 'balin');
        $add($safe(static fn () => Database::select(
            'SELECT u.username, l.uuid FROM balin_lesson_grants g
             JOIN users u ON u.id = g.user_id JOIN balin_lessons l ON l.id = g.lesson_id WHERE u.deleted_at IS NULL'
        )), 'balin_granted');
        $add($safe(static fn () => Database::select(
            'SELECT u.username, l.uuid FROM balin_lesson_blocks b
             JOIN users u ON u.id = b.user_id JOIN balin_lessons l ON l.id = b.lesson_id WHERE u.deleted_at IS NULL'
        )), 'balin_blocked');

        return [
            'format'     => 'helexa-config',
            'version'    => 1,
            'exported'   => date('c'),
            'settings'   => $settings,
            'packages'   => $packages,
            'codes'      => $codes,
            'access'     => array_values($students),
            'balin_lesson_modes' => $safe(static fn () => Database::select('SELECT uuid, access_mode FROM balin_lessons')),
        ];
    }

    /**
     * Reads a configuration file back. Nothing is deleted: what the file has
     * is added or updated, what it does not mention is left alone.
     *
     * @param array{settings?:bool, packages?:bool, codes?:bool, access?:bool} $parts
     * @return array{ok:bool, counts:array<string,int>, warnings:array<int,string>}
     */
    public static function importConfig(array $data, array $parts, ?int $adminId): array
    {
        if (($data['format'] ?? '') !== 'helexa-config') {
            return ['ok' => false, 'counts' => [], 'warnings' => ['این فایل پشتیبان تنظیمات هلکسا نیست.']];
        }

        $counts   = ['settings' => 0, 'packages' => 0, 'codes' => 0, 'students' => 0, 'grants' => 0];
        $warnings = [];

        $idOf = static function (string $table, ?string $uuid): ?int {
            if ($uuid === null || $uuid === '') {
                return null;
            }
            try {
                $row = Database::selectOne('SELECT id FROM ' . $table . ' WHERE uuid = :u LIMIT 1', ['u' => $uuid]);
            } catch (\PDOException) {
                return null;
            }
            return $row === null ? null : (int) $row['id'];
        };
        $date = static fn ($v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $v) === 1 ? $v : null;

        Database::transaction(static function () use ($data, $parts, $adminId, $idOf, $date, &$counts, &$warnings): void {
            /* ---------------------------------------------- settings */
            if (!empty($parts['settings'])) {
                $repo = new \HeleXa\Models\SettingRepository();
                foreach ((array) ($data['settings'] ?? []) as $s) {
                    $key = (string) ($s['key'] ?? '');
                    if ($key === '' || in_array($key, self::PRIVATE_SETTINGS, true) || preg_match('/^[a-z0-9_.]{1,100}$/', $key) !== 1) {
                        continue;
                    }
                    $type = in_array($s['type'] ?? '', ['string', 'int', 'bool', 'json'], true) ? $s['type'] : 'string';
                    $repo->set($key, (string) ($s['value'] ?? ''), $type, $adminId);
                    $counts['settings']++;
                }
                Settings::flush();
            }

            /* ---------------------------------------------- packages */
            $packages = new PackageRepository();
            if (!empty($parts['packages'])) {
                foreach ((array) ($data['packages'] ?? []) as $p) {
                    $fields = [
                        'title'                  => mb_substr((string) ($p['title'] ?? 'پکیج'), 0, 191),
                        'description'            => (string) ($p['description'] ?? ''),
                        'color'                  => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($p['color'] ?? '')) === 1 ? $p['color'] : null,
                        'status'                 => in_array($p['status'] ?? '', ['draft', 'published', 'archived'], true) ? $p['status'] : 'published',
                        'auto_grant_new_courses' => (int) !empty($p['auto_grant']),
                        'is_full_access'         => (int) !empty($p['is_full_access']),
                        'is_free'                => (int) !empty($p['is_free']),
                        'sort_order'             => (int) ($p['sort_order'] ?? 0),
                    ];
                    $existing = isset($p['uuid']) ? $packages->findByUuid((string) $p['uuid']) : null;
                    if ($existing !== null) {
                        $packages->update((int) $existing['id'], $fields);
                        $pid = (int) $existing['id'];
                    } else {
                        $pid = $packages->create($fields + [
                            'uuid' => is_string($p['uuid'] ?? null) && strlen($p['uuid']) === 36 ? $p['uuid'] : Str::uuid4(),
                            'created_by' => $adminId,
                        ]);
                    }
                    foreach ((array) ($p['courses'] ?? []) as $uuid) {
                        $cid = $idOf('courses', (string) $uuid);
                        if ($cid !== null) {
                            $packages->addCourse($pid, $cid);
                        } else {
                            $warnings[] = 'دوره ' . $uuid . ' در این سایت نیست.';
                        }
                    }
                    foreach ([['qbank_subjects', 'qb_subjects', 'qbank_subject'], ['balin_lessons', 'balin_lessons', 'balin_lesson'],
                              ['flashcards', 'fc_courses', 'flashcard_course']] as [$field, $table, $type]) {
                        $ids = [];
                        foreach ((array) ($p[$field] ?? []) as $uuid) {
                            $id = $idOf($table, (string) $uuid);
                            if ($id !== null) {
                                $ids[] = $id;
                            }
                        }
                        if ($ids !== []) {
                            $packages->syncItems($pid, $type, array_merge($packages->itemIds($pid, $type), $ids));
                        }
                    }
                    $counts['packages']++;
                }

                foreach ((array) ($data['balin_lesson_modes'] ?? []) as $m) {
                    if (in_array($m['access_mode'] ?? '', ['open', 'granted'], true) && is_string($m['uuid'] ?? null)) {
                        try {
                            Database::execute('UPDATE balin_lessons SET access_mode = :m WHERE uuid = :u',
                                ['m' => $m['access_mode'], 'u' => $m['uuid']]);
                        } catch (\PDOException) {
                        }
                    }
                }
            }

            /* ---------------------------------------------- codes */
            if (!empty($parts['codes'])) {
                foreach ((array) ($data['codes'] ?? []) as $c) {
                    $pid = $idOf('packages', (string) ($c['package'] ?? ''));
                    if ($pid === null || !is_string($c['code'] ?? null)) {
                        continue;
                    }
                    $user = !empty($c['redeemed_by'])
                        ? Database::selectOne('SELECT id FROM users WHERE username = :u LIMIT 1', ['u' => (string) $c['redeemed_by']])
                        : null;
                    $counts['codes'] += Database::execute(
                        'INSERT IGNORE INTO activation_codes
                            (code, package_id, duration_days, expires_at, note, created_by, created_at, redeemed_by, redeemed_at, revoked_at)
                         VALUES (:code, :p, :d, :e, :n, :a, :c, :rb, :ra, :rv)',
                        [
                            'code' => mb_substr($c['code'], 0, 32), 'p' => $pid,
                            'd' => isset($c['days']) && $c['days'] !== null ? (int) $c['days'] : null,
                            'e' => $date($c['expires_at'] ?? null), 'n' => $c['note'] ?? null, 'a' => $adminId,
                            'c' => $date($c['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
                            'rb' => $user['id'] ?? null, 'ra' => $date($c['redeemed_at'] ?? null), 'rv' => $date($c['revoked_at'] ?? null),
                        ]
                    );
                }
            }

            /* ---------------------------------------------- access */
            if (!empty($parts['access'])) {
                $enrol = new EnrollmentRepository();
                $qb    = new QbAccessRepository();
                $fc    = new FcStudyRepository();
                $isle  = new BalinAccessRepository();
                $less  = new BalinLessonRepository();

                foreach ((array) ($data['access'] ?? []) as $s) {
                    $user = Database::selectOne(
                        'SELECT id FROM users WHERE username = :u AND deleted_at IS NULL LIMIT 1',
                        ['u' => (string) ($s['username'] ?? '')]
                    );
                    if ($user === null) {
                        $warnings[] = 'دانشجو «' . ($s['username'] ?? '?') . '» در این سایت نیست.';
                        continue;
                    }
                    $uid = (int) $user['id'];
                    $counts['students']++;

                    foreach ((array) ($s['courses'] ?? []) as $row) {
                        $cid = $idOf('courses', (string) ($row['course'] ?? ''));
                        if ($cid !== null) {
                            $enrol->assign($uid, $cid, [
                                'status'    => in_array($row['status'] ?? '', ['active', 'suspended', 'expired', 'cancelled'], true) ? $row['status'] : 'active',
                                'starts_at' => $date($row['starts_at'] ?? null),
                                'ends_at'   => $date($row['ends_at'] ?? null),
                            ], $adminId);
                            $counts['grants']++;
                        }
                    }
                    foreach ((array) ($s['packages'] ?? []) as $row) {
                        $pid = $idOf('packages', (string) ($row['package'] ?? ''));
                        if ($pid !== null) {
                            $packages->upsertActivation([
                                'uuid' => Str::uuid4(), 'user_id' => $uid, 'package_id' => $pid,
                                'status' => in_array($row['status'] ?? '', ['active', 'suspended', 'expired', 'cancelled'], true) ? $row['status'] : 'active',
                                'starts_at' => $date($row['starts_at'] ?? null), 'ends_at' => $date($row['ends_at'] ?? null),
                                'course_count' => count($packages->courseIds($pid)), 'activated_by' => $adminId,
                            ]);
                            $counts['grants']++;
                        }
                    }
                    try {
                        foreach ((array) ($s['qbank'] ?? []) as $uuid) {
                            $sid = $idOf('qb_subjects', (string) $uuid);
                            if ($sid !== null && !$qb->has($uid, $sid)) {
                                $qb->grant($uid, $sid, $adminId);
                                $counts['grants']++;
                            }
                        }
                        $fcIds = [];
                        foreach ((array) ($s['flashcards'] ?? []) as $uuid) {
                            $cid = $idOf('fc_courses', (string) $uuid);
                            if ($cid !== null) {
                                $fcIds[] = $cid;
                            }
                        }
                        if ($fcIds !== []) {
                            $fc->syncAccess($uid, array_merge($fc->grantedCourseIds($uid), $fcIds), $adminId);
                            $counts['grants'] += count($fcIds);
                        }
                        if (!empty($s['balin']) && !$isle->isEnabled($uid)) {
                            $isle->grant($uid, $adminId, 'بازگردانی از پشتیبان');
                        }
                        $granted = [];
                        foreach ((array) ($s['balin_granted'] ?? []) as $uuid) {
                            $lid = $idOf('balin_lessons', (string) $uuid);
                            if ($lid !== null) {
                                $granted[] = $lid;
                            }
                        }
                        $less->grant($uid, $granted, $adminId);
                        $blocked = [];
                        foreach ((array) ($s['balin_blocked'] ?? []) as $uuid) {
                            $lid = $idOf('balin_lessons', (string) $uuid);
                            if ($lid !== null) {
                                $blocked[] = $lid;
                            }
                        }
                        if ($blocked !== []) {
                            $less->syncBlocks($uid, array_values(array_unique(array_merge($less->blockedIdsFor($uid), $blocked))), $adminId);
                        }
                    } catch (\PDOException) {
                        // A module missing on this install: its part is skipped.
                    }
                }
            }
        });

        return ['ok' => true, 'counts' => $counts, 'warnings' => array_slice(array_values(array_unique($warnings)), 0, 30)];
    }
}
