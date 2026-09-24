<?php
declare(strict_types=1);

namespace HeleXa\Models\Flashcards;

use HeleXa\Core\Database;
use HeleXa\Core\Str;
use HeleXa\Models\BaseRepository;

/**
 * Courses, decks and cards — what exists, independent of who is studying it.
 */
final class FcCatalogRepository extends BaseRepository
{
    public const COLORS = ['blue', 'purple', 'teal', 'green', 'orange', 'pink', 'red', 'indigo'];

    /* =========================================================== courses */

    /** @return array<int,array<string,mixed>> */
    public function courses(bool $publishedOnly = false): array
    {
        return $this->select(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM fc_decks d WHERE d.course_id = c.id) AS deck_count,
                    (SELECT COUNT(*) FROM fc_cards k JOIN fc_decks d ON d.id = k.deck_id WHERE d.course_id = c.id) AS card_count,
                    (SELECT COUNT(*) FROM fc_access a WHERE a.course_id = c.id) AS student_count
             FROM fc_courses c'
            . ($publishedOnly ? " WHERE c.status = 'published'" : '')
            . ' ORDER BY c.sort_order, c.title'
        );
    }

    public function courseByUuid(string $uuid): ?array
    {
        return $this->selectOne('SELECT * FROM fc_courses WHERE uuid = :u LIMIT 1', ['u' => $uuid]);
    }

    public function courseById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM fc_courses WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function saveCourse(?int $id, array $data, ?int $authorId): int
    {
        $title = trim((string) $data['title']);
        if ($title === '') {
            throw new \RuntimeException('عنوان درس نمی‌تواند خالی باشد.');
        }

        $dupe = $this->selectOne(
            'SELECT id FROM fc_courses WHERE title = :t' . ($id !== null ? ' AND id <> :id' : '') . ' LIMIT 1',
            $id !== null ? ['t' => $title, 'id' => $id] : ['t' => $title]
        );
        if ($dupe !== null) {
            throw new \RuntimeException('درسی با این عنوان از قبل هست.');
        }

        $params = [
            'title'       => $title,
            'description' => ($data['description'] ?? '') !== '' ? mb_substr((string) $data['description'], 0, 500) : null,
            'color'       => in_array($data['color'] ?? '', self::COLORS, true) ? $data['color'] : 'blue',
            'icon'        => self::cleanIcon((string) ($data['icon'] ?? '')),
            'sort_order'  => (int) ($data['sort_order'] ?? 0),
            'status'      => ($data['status'] ?? '') === 'published' ? 'published' : 'draft',
            'now'         => $this->now(),
        ];

        if ($id === null) {
            return $this->insert(
                'INSERT INTO fc_courses (uuid, title, description, color, icon, sort_order, status, created_by, created_at)
                 VALUES (:uuid, :title, :description, :color, :icon, :sort_order, :status, :author, :now)',
                $params + ['uuid' => Str::uuid4(), 'author' => $authorId]
            );
        }

        $this->execute(
            'UPDATE fc_courses SET title = :title, description = :description, color = :color, icon = :icon,
                    sort_order = :sort_order, status = :status, updated_at = :now
              WHERE id = :id',
            $params + ['id' => $id]
        );

        return $id;
    }

    public function deleteCourse(int $id): void
    {
        // Sessions, cards, progress and grants all go by cascade.
        $this->execute('DELETE FROM fc_courses WHERE id = :id', ['id' => $id]);
    }

    /* ============================================================= decks */

    public function deckByUuid(string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT d.*, c.title AS course_title, c.uuid AS course_uuid, c.status AS course_status, c.color AS course_color,
                    (SELECT COUNT(*) FROM fc_cards k WHERE k.deck_id = d.id) AS card_count
             FROM fc_decks d LEFT JOIN fc_courses c ON c.id = d.course_id
             WHERE d.uuid = :u LIMIT 1',
            ['u' => $uuid]
        );
    }

    public function deckById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM fc_decks WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function decksOfCourse(int $courseId): array
    {
        return $this->select(
            'SELECT d.*, (SELECT COUNT(*) FROM fc_cards k WHERE k.deck_id = d.id) AS card_count
             FROM fc_decks d WHERE d.course_id = :c ORDER BY d.sort_order, d.id',
            ['c' => $courseId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function decksOfOwner(int $userId): array
    {
        return $this->select(
            'SELECT d.*, (SELECT COUNT(*) FROM fc_cards k WHERE k.deck_id = d.id) AS card_count
             FROM fc_decks d WHERE d.owner_id = :u ORDER BY d.sort_order, d.created_at DESC',
            ['u' => $userId]
        );
    }

    /**
     * Creates a deck under exactly one parent — a course or an owner.
     * This is the single place the "one of the two" rule is enforced.
     */
    public function createDeck(?int $courseId, ?int $ownerId, string $title, string $description = '', ?int $sortOrder = null): int
    {
        if (($courseId === null) === ($ownerId === null)) {
            throw new \LogicException('A deck belongs to a course or to a student, never both or neither.');
        }

        $title = trim($title);
        if ($title === '') {
            throw new \RuntimeException('عنوان نمی‌تواند خالی باشد.');
        }

        if ($sortOrder === null) {
            $row = $courseId !== null
                ? $this->selectOne('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM fc_decks WHERE course_id = :p', ['p' => $courseId])
                : $this->selectOne('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM fc_decks WHERE owner_id = :p', ['p' => $ownerId]);
            $sortOrder = (int) ($row['n'] ?? 1);
        }

        return $this->insert(
            'INSERT INTO fc_decks (uuid, course_id, owner_id, title, description, sort_order, created_at)
             VALUES (:uuid, :course, :owner, :title, :description, :sort, :now)',
            [
                'uuid'        => Str::uuid4(),
                'course'      => $courseId,
                'owner'       => $ownerId,
                'title'       => mb_substr($title, 0, 191),
                'description' => $description !== '' ? mb_substr($description, 0, 500) : null,
                'sort'        => $sortOrder,
                'now'         => $this->now(),
            ]
        );
    }

    public function updateDeck(int $id, string $title, string $description, int $sortOrder): void
    {
        $title = trim($title);
        if ($title === '') {
            throw new \RuntimeException('عنوان نمی‌تواند خالی باشد.');
        }

        $this->execute(
            'UPDATE fc_decks SET title = :title, description = :description, sort_order = :sort, updated_at = :now WHERE id = :id',
            [
                'title'       => mb_substr($title, 0, 191),
                'description' => $description !== '' ? mb_substr($description, 0, 500) : null,
                'sort'        => $sortOrder,
                'now'         => $this->now(),
                'id'          => $id,
            ]
        );
    }

    public function deleteDeck(int $id): void
    {
        $this->execute('DELETE FROM fc_decks WHERE id = :id', ['id' => $id]);
    }

    /** Finds a session of a course by title, creating it if needed — for the course-level import. */
    public function sessionNamed(int $courseId, string $title): int
    {
        $row = $this->selectOne(
            'SELECT id FROM fc_decks WHERE course_id = :c AND title = :t LIMIT 1',
            ['c' => $courseId, 't' => mb_substr(trim($title), 0, 191)]
        );

        return $row !== null ? (int) $row['id'] : $this->createDeck($courseId, null, $title);
    }

    public function countOwnerDecks(int $userId): int
    {
        return (int) ($this->selectOne('SELECT COUNT(*) AS c FROM fc_decks WHERE owner_id = :u', ['u' => $userId])['c'] ?? 0);
    }

    public function countOwnerCards(int $userId): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM fc_cards k JOIN fc_decks d ON d.id = k.deck_id WHERE d.owner_id = :u',
            ['u' => $userId]
        )['c'] ?? 0);
    }

    /* ============================================================= cards */

    /** @return array<int,array<string,mixed>> */
    public function cardsOfDeck(int $deckId, int $limit = 1000, int $offset = 0): array
    {
        return $this->select(
            'SELECT * FROM fc_cards WHERE deck_id = :d ORDER BY sort_order, id
             LIMIT ' . max(1, min($limit, 5000)) . ' OFFSET ' . max(0, $offset),
            ['d' => $deckId]
        );
    }

    public function cardByUuid(string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT k.*, d.uuid AS deck_uuid, d.course_id, d.owner_id
             FROM fc_cards k JOIN fc_decks d ON d.id = k.deck_id
             WHERE k.uuid = :u LIMIT 1',
            ['u' => $uuid]
        );
    }

    public function addCard(int $deckId, string $front, string $back, ?string $hint): string
    {
        [$front, $back, $hint] = self::validateCard($front, $back, $hint);
        $uuid = Str::uuid4();
        $next = $this->selectOne('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM fc_cards WHERE deck_id = :d', ['d' => $deckId]);

        $this->insert(
            'INSERT INTO fc_cards (uuid, deck_id, front, back, hint, sort_order, created_at)
             VALUES (:uuid, :deck, :front, :back, :hint, :sort, :now)',
            ['uuid' => $uuid, 'deck' => $deckId, 'front' => $front, 'back' => $back, 'hint' => $hint,
             'sort' => (int) ($next['n'] ?? 1), 'now' => $this->now()]
        );
        $this->touchDeck($deckId);

        return $uuid;
    }

    public function updateCard(int $id, string $front, string $back, ?string $hint): void
    {
        [$front, $back, $hint] = self::validateCard($front, $back, $hint);

        // Progress is keyed on the card id, so a corrected typo keeps every
        // student's schedule for this card.
        $this->execute(
            'UPDATE fc_cards SET front = :front, back = :back, hint = :hint, updated_at = :now WHERE id = :id',
            ['front' => $front, 'back' => $back, 'hint' => $hint, 'now' => $this->now(), 'id' => $id]
        );
    }

    public function deleteCard(int $id): void
    {
        $this->execute('DELETE FROM fc_cards WHERE id = :id', ['id' => $id]);
    }

    /**
     * Inserts many cards in one transaction.
     *
     * $resolveDeck maps a row to the deck it belongs in — a fixed deck for a
     * normal import, or a session looked up by name for a course import.
     *
     * @param array<int,array{front:string,back:string,hint:?string,session:?string}> $cards
     * @param callable(array):int $resolveDeck
     * @return int cards inserted
     */
    public function bulkInsert(array $cards, callable $resolveDeck): int
    {
        return (int) Database::transaction(function () use ($cards, $resolveDeck): int {
            $orders = [];
            $count  = 0;
            $now    = $this->now();

            foreach ($cards as $card) {
                $deckId = $resolveDeck($card);

                if (!isset($orders[$deckId])) {
                    $row = $this->selectOne('SELECT COALESCE(MAX(sort_order), 0) AS n FROM fc_cards WHERE deck_id = :d', ['d' => $deckId]);
                    $orders[$deckId] = (int) ($row['n'] ?? 0);
                }

                $this->insert(
                    'INSERT INTO fc_cards (uuid, deck_id, front, back, hint, sort_order, created_at)
                     VALUES (:uuid, :deck, :front, :back, :hint, :sort, :now)',
                    ['uuid' => Str::uuid4(), 'deck' => $deckId, 'front' => $card['front'], 'back' => $card['back'],
                     'hint' => $card['hint'], 'sort' => ++$orders[$deckId], 'now' => $now]
                );
                $count++;
            }

            foreach (array_keys($orders) as $deckId) {
                $this->touchDeck($deckId);
            }

            return $count;
        });
    }

    /* ----------------------------------------------------------- helpers */

    private function touchDeck(int $deckId): void
    {
        $this->execute('UPDATE fc_decks SET updated_at = :now WHERE id = :id', ['now' => $this->now(), 'id' => $deckId]);
    }

    /** @return array{0:string,1:string,2:?string} */
    private static function validateCard(string $front, string $back, ?string $hint): array
    {
        $front = trim($front);
        $back  = trim($back);
        $hint  = $hint === null ? null : trim($hint);

        if ($front === '' || $back === '') {
            throw new \RuntimeException('روی کارت و پشت کارت هر دو لازم‌اند.');
        }

        return [mb_substr($front, 0, 2000), mb_substr($back, 0, 2000), $hint === '' || $hint === null ? null : mb_substr($hint, 0, 500)];
    }

    /** One emoji or a couple of characters — never markup. */
    private static function cleanIcon(string $icon): ?string
    {
        $icon = trim(strip_tags($icon));
        return $icon === '' ? null : mb_substr($icon, 0, 4);
    }
}
