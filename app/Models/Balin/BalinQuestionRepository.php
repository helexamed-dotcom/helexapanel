<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Core\Database;
use HeleXa\Models\BaseRepository;

/**
 * Questions, their options, and the skill tracks they are tagged with.
 *
 * A question belongs to a lesson always and to a stage sometimes: a question
 * written only for a checkpoint exam pool has no stage. Options live with
 * the question and are replaced wholesale on save, which is simpler and
 * safer than diffing them and cannot leave a stale correct answer behind.
 */
final class BalinQuestionRepository extends BaseRepository
{
    public function forLesson(int $lessonId): array
    {
        return $this->select(
            'SELECT q.*, s.title AS stage_title,
                    (SELECT COUNT(*) FROM balin_question_options o WHERE o.question_id = q.id) AS option_count,
                    (SELECT COUNT(*) FROM balin_answers a WHERE a.question_id = q.id) AS answer_count
             FROM balin_questions q
             LEFT JOIN balin_stages s ON s.id = q.stage_id
             WHERE q.lesson_id = :lesson
             ORDER BY q.id DESC',
            ['lesson' => $lessonId]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM balin_questions WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne('SELECT * FROM balin_questions WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);
    }

    /** @return array<int, array<string,mixed>> */
    public function options(int $questionId): array
    {
        return $this->select(
            'SELECT * FROM balin_question_options WHERE question_id = :q ORDER BY display_order, id',
            ['q' => $questionId]
        );
    }

    /**
     * The options a student is allowed to see: the same rows without the
     * is_correct column, so the answer is never in the HTML.
     */
    public function optionsForStudent(int $questionId): array
    {
        return $this->select(
            'SELECT id, label, body, display_order FROM balin_question_options
             WHERE question_id = :q ORDER BY display_order, id',
            ['q' => $questionId]
        );
    }

    public function correctOptionId(int $questionId): ?int
    {
        $row = $this->selectOne(
            'SELECT id FROM balin_question_options WHERE question_id = :q AND is_correct = 1 LIMIT 1',
            ['q' => $questionId]
        );
        return $row === null ? null : (int) $row['id'];
    }

    /** @return array<int,int> skill track ids */
    public function skillTrackIds(int $questionId): array
    {
        return array_map(
            'intval',
            array_column(
                $this->select(
                    'SELECT skill_track_id FROM balin_question_skill_tags WHERE question_id = :q',
                    ['q' => $questionId]
                ),
                'skill_track_id'
            )
        );
    }

    /**
     * Creates the question, its options and its tags in one transaction, so a
     * question can never exist without the options that make it answerable.
     *
     * @param array<int, array{label:string, body:string, is_correct:bool}> $options
     * @param array<int,int> $skillTrackIds
     */
    public function createWithOptions(array $data, array $options, array $skillTrackIds): int
    {
        return (int) Database::transaction(function () use ($data, $options, $skillTrackIds): int {
            $id = $this->insert(
                'INSERT INTO balin_questions
                    (uuid, lesson_id, stage_id, skill_id, prompt, explanation, hint, difficulty,
                     xp_reward, is_required, is_final_case_step, status, created_at)
                 VALUES (:uuid, :lesson, :stage, :skill, :prompt, :explanation, :hint, :difficulty,
                         :xp, :required, :final, :status, :now)',
                [
                    'uuid'        => $data['uuid'],
                    'lesson'      => $data['lesson_id'],
                    'stage'       => $data['stage_id'] ?? null,
                    'skill'       => $data['skill_id'] ?? null,
                    'prompt'      => $data['prompt'],
                    'explanation' => $data['explanation'] ?? null,
                    'hint'        => $data['hint'] ?? null,
                    'difficulty'  => $data['difficulty'] ?? 'medium',
                    'xp'          => $data['xp_reward'] ?? 10,
                    'required'    => !empty($data['is_required']) ? 1 : 0,
                    'final'       => !empty($data['is_final_case_step']) ? 1 : 0,
                    'status'      => $data['status'] ?? 'published',
                    'now'         => $this->now(),
                ]
            );

            $this->replaceOptions($id, $options);
            $this->replaceSkillTags($id, $skillTrackIds);

            return $id;
        });
    }

    public function updateWithOptions(int $id, array $data, array $options, array $skillTrackIds, int $expectedVersion): bool
    {
        return (bool) Database::transaction(function () use ($id, $data, $options, $skillTrackIds, $expectedVersion): bool {
            $changed = $this->execute(
                'UPDATE balin_questions
                 SET stage_id = :stage, skill_id = :skill, prompt = :prompt, explanation = :explanation,
                     hint = :hint, difficulty = :difficulty, xp_reward = :xp, is_required = :required,
                     is_final_case_step = :final, status = :status,
                     version = version + 1, updated_at = :now
                 WHERE id = :id AND version = :version',
                [
                    'stage'       => $data['stage_id'] ?? null,
                    'skill'       => $data['skill_id'] ?? null,
                    'prompt'      => $data['prompt'],
                    'explanation' => $data['explanation'] ?? null,
                    'hint'        => $data['hint'] ?? null,
                    'difficulty'  => $data['difficulty'] ?? 'medium',
                    'xp'          => $data['xp_reward'] ?? 10,
                    'required'    => !empty($data['is_required']) ? 1 : 0,
                    'final'       => !empty($data['is_final_case_step']) ? 1 : 0,
                    'status'      => $data['status'] ?? 'published',
                    'id'          => $id,
                    'version'     => $expectedVersion,
                    'now'         => $this->now(),
                ]
            );

            if ($changed === 0) {
                return false;   // someone else saved first
            }

            $this->replaceOptions($id, $options);
            $this->replaceSkillTags($id, $skillTrackIds);

            return true;
        });
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM balin_questions WHERE id = :id', ['id' => $id]);
    }

    private function replaceOptions(int $questionId, array $options): void
    {
        $this->execute('DELETE FROM balin_question_options WHERE question_id = :q', ['q' => $questionId]);

        $order = 1;
        foreach ($options as $option) {
            $body = trim((string) ($option['body'] ?? ''));
            if ($body === '') {
                continue;
            }
            $this->insert(
                'INSERT INTO balin_question_options (question_id, label, body, is_correct, display_order)
                 VALUES (:q, :label, :body, :correct, :order)',
                [
                    'q'       => $questionId,
                    'label'   => (string) ($option['label'] ?? chr(64 + $order)),
                    'body'    => $body,
                    'correct' => !empty($option['is_correct']) ? 1 : 0,
                    'order'   => $order,
                ]
            );
            $order++;
        }
    }

    private function replaceSkillTags(int $questionId, array $skillTrackIds): void
    {
        $this->execute('DELETE FROM balin_question_skill_tags WHERE question_id = :q', ['q' => $questionId]);

        foreach (array_unique(array_map('intval', $skillTrackIds)) as $trackId) {
            if ($trackId <= 0) {
                continue;
            }
            $this->execute(
                'INSERT IGNORE INTO balin_question_skill_tags (question_id, skill_track_id) VALUES (:q, :t)',
                ['q' => $questionId, 't' => $trackId]
            );
        }
    }

    /**
     * Published questions tagged with a track, for a random exam pool.
     * Ordered randomly here so two attempts do not receive the same paper.
     *
     * @return array<int, array<string,mixed>>
     */
    public function pooledForTrack(int $trackId, int $limit, ?int $lessonId = null): array
    {
        $limit  = max(1, min(100, $limit));
        $params = ['track' => $trackId];
        $scope  = '';

        if ($lessonId !== null) {
            $scope = ' AND q.lesson_id = :lesson';
            $params['lesson'] = $lessonId;
        }

        return $this->select(
            "SELECT q.* FROM balin_questions q
             JOIN balin_question_skill_tags t ON t.question_id = q.id AND t.skill_track_id = :track
             WHERE q.status = 'published'{$scope}
             ORDER BY RAND()
             LIMIT {$limit}",
            $params
        );
    }

    public function countPooledForTrack(int $trackId, ?int $lessonId = null): int
    {
        $params = ['track' => $trackId];
        $scope  = '';
        if ($lessonId !== null) {
            $scope = ' AND q.lesson_id = :lesson';
            $params['lesson'] = $lessonId;
        }

        return (int) ($this->selectOne(
            "SELECT COUNT(*) AS c FROM balin_questions q
             JOIN balin_question_skill_tags t ON t.question_id = q.id AND t.skill_track_id = :track
             WHERE q.status = 'published'{$scope}",
            $params
        )['c'] ?? 0);
    }

    /** @param array<int,int> $ids */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT * FROM balin_questions WHERE id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byId[(int) $row['id']] = $row;
        }

        // Returned in the order asked for, so an exam keeps its question order.
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
        return $ordered;
    }
}
