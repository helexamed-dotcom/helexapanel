<?php
declare(strict_types=1);

namespace HeleXa\Models;

use HeleXa\Core\Database;
use HeleXa\Core\Str;

/**
 * The student profile: the owner's choices, posts and likes, and who
 * follows whom.
 */
final class ProfileRepository extends BaseRepository
{
    /** What others may see, each switchable by the owner. */
    public const SECTIONS = [
        'level'   => 'سطح و امتیاز کل',
        'league'  => 'لیگ این هفته',
        'streak'  => 'روزهای پیاپی',
        'stats'   => 'آمار مطالعه (سوال، درسنامه، فلش‌کارت)',
        'badges'  => 'نشان‌ها و دستاوردها',
        'posts'   => 'پست‌ها',
    ];
    public const TONES = ['indigo', 'violet', 'blue', 'sky', 'teal', 'green', 'amber', 'orange', 'rose', 'pink', 'red', 'slate'];

    public static function ready(): bool
    {
        try {
            Database::selectOne('SELECT 1 FROM user_profiles LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /* ============================================================ profile */

    /** The owner's settings, with defaults for anyone who never saved any. */
    public function settings(int $userId): array
    {
        $row = $this->selectOne('SELECT * FROM user_profiles WHERE user_id = :u', ['u' => $userId]);
        $vis = $row !== null && $row['visibility'] !== null ? (json_decode((string) $row['visibility'], true) ?: []) : [];
        $all = [];
        foreach (array_keys(self::SECTIONS) as $k) {
            $all[$k] = array_key_exists($k, $vis) ? (bool) $vis[$k] : true;
        }
        return [
            'handle'              => $row['handle'] ?? null,
            'bio'                 => $row['bio'] ?? '',
            'tone'                => $row['tone'] ?? 'indigo',
            'is_private'          => (int) ($row['is_private'] ?? 0) === 1,
            'show_in_leaderboard' => (int) ($row['show_in_leaderboard'] ?? 1) === 1,
            'visibility'          => $all,
            'exists'              => $row !== null,
        ];
    }

    public function save(int $userId, array $s): void
    {
        $this->execute(
            'INSERT INTO user_profiles (user_id, handle, bio, tone, is_private, show_in_leaderboard, visibility, created_at, updated_at)
             VALUES (:u, :h, :b, :t, :p, :l, :v, :now, :now2)
             ON DUPLICATE KEY UPDATE handle = VALUES(handle), bio = VALUES(bio), tone = VALUES(tone), is_private = VALUES(is_private),
                 show_in_leaderboard = VALUES(show_in_leaderboard), visibility = VALUES(visibility), updated_at = VALUES(updated_at)',
            [
                'u' => $userId, 'h' => $s['handle'], 'b' => $s['bio'], 't' => $s['tone'], 'p' => $s['is_private'] ? 1 : 0,
                'l' => $s['show_in_leaderboard'] ? 1 : 0, 'v' => json_encode($s['visibility']), 'now' => $this->now(), 'now2' => $this->now(),
            ]
        );
    }

    public function handleTaken(string $handle, int $exceptUserId): bool
    {
        return $this->selectOne('SELECT user_id FROM user_profiles WHERE handle = :h AND user_id <> :u', ['h' => $handle, 'u' => $exceptUserId]) !== null;
    }

    /** A student as the profile shows them: account, profile choices, role. */
    public function person(string $key): ?array
    {
        $byHandle = preg_match('/^[a-z0-9_.]{3,30}$/', $key) === 1 && !preg_match('/^[0-9a-f]{8}-/', $key);
        $row = $this->selectOne(
            "SELECT u.id, u.uuid, u.full_name, u.username, u.avatar_path, u.gender, u.created_at, r.slug AS role_slug
             FROM users u JOIN roles r ON r.id = u.role_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.deleted_at IS NULL AND u.status = 'active' AND " . ($byHandle ? 'p.handle = :k' : 'u.uuid = :k') . ' LIMIT 1',
            ['k' => $key]
        );
        return $row;
    }

    public function personById(int $userId): ?array
    {
        return $this->selectOne(
            'SELECT u.id, u.uuid, u.full_name, u.username, u.avatar_path, u.gender, u.created_at, r.slug AS role_slug
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :u',
            ['u' => $userId]
        );
    }

    /** Students by name or handle, for «پیدا کردن دوستان». */
    public function search(string $q, int $viewerId, int $limit = 30): array
    {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        return $this->select(
            "SELECT u.id, u.uuid, u.full_name, u.avatar_path, u.gender, p.handle, p.tone, p.is_private,
                    (SELECT status FROM profile_follows f WHERE f.follower_id = :v AND f.followee_id = u.id) AS follow_status
             FROM users u JOIN roles r ON r.id = u.role_id AND r.slug = 'student'
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.deleted_at IS NULL AND u.status = 'active' AND u.id <> :v2
               AND (u.full_name LIKE :q1 OR p.handle LIKE :q2)
             ORDER BY u.full_name LIMIT " . max(1, min(60, $limit)),
            ['v' => $viewerId, 'v2' => $viewerId, 'q1' => $like, 'q2' => $like]
        );
    }

    /* ============================================================ follows */

    public function followStatus(int $follower, int $followee): ?string
    {
        $r = $this->selectOne('SELECT status FROM profile_follows WHERE follower_id = :a AND followee_id = :b', ['a' => $follower, 'b' => $followee]);
        return $r['status'] ?? null;
    }

    public function follow(int $follower, int $followee, bool $needsApproval): string
    {
        $status = $needsApproval ? 'pending' : 'accepted';
        $this->execute(
            'INSERT IGNORE INTO profile_follows (follower_id, followee_id, status, created_at) VALUES (:a, :b, :s, :now)',
            ['a' => $follower, 'b' => $followee, 's' => $status, 'now' => $this->now()]
        );
        return $this->followStatus($follower, $followee) ?? $status;
    }

    public function unfollow(int $follower, int $followee): void
    {
        $this->execute('DELETE FROM profile_follows WHERE follower_id = :a AND followee_id = :b', ['a' => $follower, 'b' => $followee]);
    }

    public function accept(int $follower, int $followee): bool
    {
        return $this->execute("UPDATE profile_follows SET status = 'accepted' WHERE follower_id = :a AND followee_id = :b AND status = 'pending'",
            ['a' => $follower, 'b' => $followee]) === 1;
    }

    /** @return array{followers:int, following:int, pending:int} */
    public function counts(int $userId): array
    {
        $r = $this->selectOne(
            "SELECT (SELECT COUNT(*) FROM profile_follows WHERE followee_id = :a AND status = 'accepted') AS followers,
                    (SELECT COUNT(*) FROM profile_follows WHERE follower_id = :b AND status = 'accepted') AS following,
                    (SELECT COUNT(*) FROM profile_follows WHERE followee_id = :c AND status = 'pending') AS pending",
            ['a' => $userId, 'b' => $userId, 'c' => $userId]
        ) ?? [];
        return ['followers' => (int) ($r['followers'] ?? 0), 'following' => (int) ($r['following'] ?? 0), 'pending' => (int) ($r['pending'] ?? 0)];
    }

    public function requests(int $userId): array
    {
        return $this->select(
            "SELECT u.id, u.uuid, u.full_name, u.avatar_path, u.gender, p.handle, f.created_at FROM profile_follows f
             JOIN users u ON u.id = f.follower_id LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE f.followee_id = :u AND f.status = 'pending' ORDER BY f.created_at DESC LIMIT 50",
            ['u' => $userId]
        );
    }

    /** @param 'followers'|'following' $which */
    public function people(int $userId, string $which): array
    {
        [$join, $where] = $which === 'followers' ? ['f.follower_id', 'f.followee_id'] : ['f.followee_id', 'f.follower_id'];
        return $this->select(
            "SELECT u.id, u.uuid, u.full_name, u.avatar_path, u.gender, p.handle, p.tone FROM profile_follows f
             JOIN users u ON u.id = {$join} AND u.deleted_at IS NULL LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE {$where} = :u AND f.status = 'accepted' ORDER BY f.created_at DESC LIMIT 200",
            ['u' => $userId]
        );
    }

    /** @return list<int> ids the viewer follows (accepted) */
    public function followingIds(int $userId): array
    {
        return array_map('intval', array_column(
            $this->select("SELECT followee_id FROM profile_follows WHERE follower_id = :u AND status = 'accepted'", ['u' => $userId]),
            'followee_id'
        ));
    }

    /* ============================================================== posts */

    /**
     * @param list<string> $audiences which audiences the viewer may see
     */
    public function posts(int $ownerId, array $audiences, int $viewerId, int $limit = 60): array
    {
        if ($audiences === []) {
            return [];
        }
        $in = implode(',', array_map(static fn (string $a): string => Database::connection()->quote($a), $audiences));
        return $this->select(
            "SELECT p.*, EXISTS (SELECT 1 FROM profile_post_likes l WHERE l.post_id = p.id AND l.user_id = :v) AS liked
             FROM profile_posts p WHERE p.user_id = :u AND p.deleted_at IS NULL AND p.hidden_at IS NULL AND p.audience IN ({$in})
             ORDER BY p.id DESC LIMIT " . max(1, min(200, $limit)),
            ['u' => $ownerId, 'v' => $viewerId]
        );
    }

    /** The home feed: the viewer's own posts and those of people they follow. */
    public function feed(int $viewerId, int $limit = 40): array
    {
        $ids = array_merge([$viewerId], $this->followingIds($viewerId));
        $list = implode(',', array_map('intval', $ids));
        return $this->select(
            "SELECT p.*, u.uuid AS user_uuid, u.full_name, u.avatar_path, u.gender, pr.handle,
                    EXISTS (SELECT 1 FROM profile_post_likes l WHERE l.post_id = p.id AND l.user_id = :v) AS liked
             FROM profile_posts p JOIN users u ON u.id = p.user_id LEFT JOIN user_profiles pr ON pr.user_id = u.id
             WHERE p.deleted_at IS NULL AND p.hidden_at IS NULL AND p.user_id IN ({$list})
               AND (p.user_id = :v2 OR p.audience IN ('everyone','followers'))
             ORDER BY p.id DESC LIMIT " . max(1, min(100, $limit)),
            ['v' => $viewerId, 'v2' => $viewerId]
        );
    }

    public function postCount(int $userId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM profile_posts WHERE user_id = :u AND deleted_at IS NULL AND hidden_at IS NULL', ['u' => $userId]);
    }

    public function createPost(int $userId, ?string $body, ?string $image, string $tone, string $audience): array
    {
        $uuid = Str::uuid4();
        $this->insert(
            'INSERT INTO profile_posts (uuid, user_id, body, image_path, tone, audience, created_at) VALUES (:uuid, :u, :b, :i, :t, :a, :now)',
            ['uuid' => $uuid, 'u' => $userId, 'b' => $body, 'i' => $image, 't' => $tone, 'a' => $audience, 'now' => $this->now()]
        );
        return $this->findPost($uuid) ?? [];
    }

    public function findPost(string $uuid): ?array
    {
        return $this->selectOne('SELECT * FROM profile_posts WHERE uuid = :u AND deleted_at IS NULL', ['u' => $uuid]);
    }

    public function deletePost(int $id): void
    {
        $this->execute('UPDATE profile_posts SET deleted_at = :now WHERE id = :id', ['id' => $id, 'now' => $this->now()]);
    }

    public function setHidden(int $id, ?int $by): void
    {
        $this->execute('UPDATE profile_posts SET hidden_at = :h, hidden_by = :b WHERE id = :id',
            ['h' => $by === null ? null : $this->now(), 'b' => $by, 'id' => $id]);
    }

    /** @return array{liked:bool, count:int} */
    public function toggleLike(int $postId, int $userId): array
    {
        return Database::transaction(function () use ($postId, $userId): array {
            $gone = $this->execute('DELETE FROM profile_post_likes WHERE post_id = :p AND user_id = :u', ['p' => $postId, 'u' => $userId]);
            if ($gone === 0) {
                $this->execute('INSERT IGNORE INTO profile_post_likes (post_id, user_id, created_at) VALUES (:p, :u, :now)',
                    ['p' => $postId, 'u' => $userId, 'now' => $this->now()]);
            }
            $this->execute('UPDATE profile_posts SET like_count = (SELECT COUNT(*) FROM profile_post_likes WHERE post_id = :p) WHERE id = :p2',
                ['p' => $postId, 'p2' => $postId]);
            $count = (int) Database::scalar('SELECT like_count FROM profile_posts WHERE id = :p', ['p' => $postId]);
            return ['liked' => $gone === 0, 'count' => $count];
        });
    }

    /** For the admin: recent posts from everyone, hidden ones included. */
    public function recentAll(int $limit = 60): array
    {
        return $this->select(
            'SELECT p.*, u.full_name, u.uuid AS user_uuid FROM profile_posts p JOIN users u ON u.id = p.user_id
             WHERE p.deleted_at IS NULL ORDER BY p.id DESC LIMIT ' . max(1, min(200, $limit))
        );
    }

    /* ============================================================== stats */

    /** Study numbers for the profile, each optional. */
    public function studyStats(int $userId): array
    {
        $one = static function (string $sql) use ($userId): int {
            try {
                return (int) Database::scalar($sql, ['u' => $userId]);
            } catch (\PDOException) {
                return 0;
            }
        };
        return [
            'questions' => $one('SELECT COUNT(*) FROM qb_attempts WHERE user_id = :u'),
            'correct'   => $one('SELECT COUNT(*) FROM qb_attempts WHERE user_id = :u AND is_correct = 1'),
            'lessons'   => $one('SELECT COUNT(*) FROM lesson_reads WHERE user_id = :u AND read_at IS NOT NULL'),
            'cards'     => $one('SELECT COUNT(*) FROM fc_review_log WHERE user_id = :u'),
            'exams'     => $one("SELECT COUNT(*) FROM qb_my_exams WHERE user_id = :u AND status = 'finished'"),
        ];
    }
}
