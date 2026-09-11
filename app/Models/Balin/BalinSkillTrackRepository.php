<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;

/**
 * Cross-lesson clinical skills: history taking, physical exam, and so on.
 *
 * These are not the same thing as a lesson's own sub-skills (ECG under
 * cardiology). A track follows a student across every lesson, which is the
 * only way to answer "is this student any good at taking a history" rather
 * than "is this student any good at cardiology".
 */
final class BalinSkillTrackRepository extends BaseRepository
{
    public function all(bool $activeOnly = false): array
    {
        $filter = $activeOnly ? ' WHERE is_active = 1' : '';

        return $this->select(
            "SELECT t.*,
                    (SELECT COUNT(*) FROM balin_question_skill_tags g WHERE g.skill_track_id = t.id) AS tagged_questions
             FROM balin_skill_tracks t {$filter}
             ORDER BY t.display_order, t.id"
        );
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM balin_skill_tracks WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->selectOne('SELECT * FROM balin_skill_tracks WHERE slug = :slug LIMIT 1', ['slug' => $slug]);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM balin_skill_tracks WHERE slug = :slug';
        $params = ['slug' => $slug];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        return $this->selectOne($sql . ' LIMIT 1', $params) !== null;
    }

    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO balin_skill_tracks
                (uuid, name, name_en, slug, icon, color, description, category, display_order,
                 min_questions_for_reliable_mastery, badge_thresholds, is_active, created_at)
             VALUES (:uuid, :name, :name_en, :slug, :icon, :color, :description, :category, :order,
                     :minimum, :thresholds, :active, :now)',
            [
                'uuid'        => $data['uuid'],
                'name'        => $data['name'],
                'name_en'     => $data['name_en'] ?? null,
                'slug'        => $data['slug'],
                'icon'        => $data['icon'] ?? null,
                'color'       => $data['color'] ?? null,
                'description' => $data['description'] ?? null,
                'category'    => $data['category'] ?? 'clinical_reasoning',
                'order'       => $data['display_order'] ?? 100,
                'minimum'     => $data['min_questions_for_reliable_mastery'] ?? 10,
                'thresholds'  => json_encode($data['badge_thresholds'] ?? ['bronze' => 10, 'silver' => 30, 'gold' => 75, 'platinum' => 150]),
                'active'      => !empty($data['is_active']) ? 1 : 0,
                'now'         => $this->now(),
            ]
        );
    }

    public function update(int $id, array $data, int $expectedVersion): bool
    {
        return $this->execute(
            'UPDATE balin_skill_tracks
             SET name = :name, name_en = :name_en, slug = :slug, icon = :icon, color = :color,
                 description = :description, category = :category, display_order = :order,
                 min_questions_for_reliable_mastery = :minimum, badge_thresholds = :thresholds,
                 is_active = :active, version = version + 1, updated_at = :now
             WHERE id = :id AND version = :version',
            [
                'name'        => $data['name'],
                'name_en'     => $data['name_en'] ?? null,
                'slug'        => $data['slug'],
                'icon'        => $data['icon'] ?? null,
                'color'       => $data['color'] ?? null,
                'description' => $data['description'] ?? null,
                'category'    => $data['category'] ?? 'clinical_reasoning',
                'order'       => $data['display_order'] ?? 100,
                'minimum'     => $data['min_questions_for_reliable_mastery'] ?? 10,
                'thresholds'  => json_encode($data['badge_thresholds'] ?? []),
                'active'      => !empty($data['is_active']) ? 1 : 0,
                'id'          => $id,
                'version'     => $expectedVersion,
                'now'         => $this->now(),
            ]
        ) > 0;
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM balin_skill_tracks WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,int> decoded thresholds with sane defaults */
    public function thresholds(array $track): array
    {
        $decoded = json_decode((string) ($track['badge_thresholds'] ?? ''), true);
        if (!is_array($decoded) || $decoded === []) {
            return ['bronze' => 10, 'silver' => 30, 'gold' => 75, 'platinum' => 150];
        }
        return array_map('intval', $decoded);
    }

    public const CATEGORIES = [
        'clinical_reasoning' => 'استدلال بالینی',
        'procedural'         => 'مهارت عملی',
        'communication'      => 'ارتباط',
        'documentation'      => 'مستندسازی',
        'professionalism'    => 'اخلاق حرفه‌ای',
    ];
}
