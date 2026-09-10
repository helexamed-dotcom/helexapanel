<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class ContentRepository extends BaseRepository
{
    public function forCourse(int $courseId): array
    {
        return $this->select(
            'SELECT * FROM course_contents
             WHERE course_id = :course AND deleted_at IS NULL
             ORDER BY sort_order, id',
            ['course' => $courseId]
        );
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT cc.*, c.title AS course_title, c.uuid AS course_uuid, c.status AS course_status
             FROM course_contents cc
             JOIN courses c ON c.id = cc.course_id
             WHERE cc.uuid = :uuid AND cc.deleted_at IS NULL AND c.deleted_at IS NULL
             LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne(
            'SELECT * FROM course_contents WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO course_contents
                (uuid, course_id, section_id, title, description, content_type, storage_kind,
                 storage_path, original_filename, checksum, scan_report, byte_size,
                 is_downloadable, is_printable, offline_enabled, status, sort_order, created_by, created_at)
             VALUES
                (:uuid, :course_id, :section_id, :title, :description, :content_type, :storage_kind,
                 :storage_path, :original_filename, :checksum, :scan_report, :byte_size,
                 :is_downloadable, :is_printable, :offline_enabled, :status, :sort_order, :created_by, :now)',
            [
                'uuid'              => $data['uuid'],
                'course_id'         => (int) $data['course_id'],
                'section_id'        => $data['section_id'] ?? null,
                'title'             => $data['title'],
                'description'       => $data['description'] ?? null,
                'content_type'      => $data['content_type'] ?? 'full_notes',
                'storage_kind'      => 'file',
                'storage_path'      => $data['storage_path'],
                'original_filename' => $data['original_filename'] ?? null,
                'checksum'          => $data['checksum'] ?? null,
                'scan_report'       => isset($data['scan_report'])
                    ? json_encode($data['scan_report'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'byte_size'         => (int) ($data['byte_size'] ?? 0),
                'is_downloadable'   => (int) ($data['is_downloadable'] ?? 0),
                'is_printable'      => (int) ($data['is_printable'] ?? 0),
                'offline_enabled'   => (int) ($data['offline_enabled'] ?? 1),
                'status'            => $data['status'] ?? 'draft',
                'sort_order'        => (int) ($data['sort_order'] ?? 0),
                'created_by'        => $data['created_by'] ?? null,
                'now'               => $this->now(),
            ]
        );
    }

    public function updateMeta(int $id, array $data): void
    {
        $this->execute(
            'UPDATE course_contents SET title = :title, description = :description,
                    content_type = :content_type, section_id = :section_id,
                    is_printable = :is_printable, offline_enabled = :offline_enabled,
                    status = :status, updated_at = :now
             WHERE id = :id AND deleted_at IS NULL',
            [
                'title'        => $data['title'],
                'description'  => $data['description'] ?? null,
                'content_type' => $data['content_type'],
                'section_id'   => $data['section_id'] ?? null,
                'is_printable' => (int) ($data['is_printable'] ?? 0),
                'offline_enabled' => (int) ($data['offline_enabled'] ?? 1),
                'status'       => $data['status'],
                'now'          => $this->now(),
                'id'           => $id,
            ]
        );
    }

    public function replaceFile(int $id, array $data): void
    {
        $this->execute(
            'UPDATE course_contents SET storage_path = :path, original_filename = :original,
                    checksum = :checksum, scan_report = :scan, byte_size = :size, updated_at = :now
             WHERE id = :id AND deleted_at IS NULL',
            [
                'path'     => $data['storage_path'],
                'original' => $data['original_filename'],
                'checksum' => $data['checksum'],
                'scan'     => json_encode($data['scan_report'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'size'     => (int) $data['byte_size'],
                'now'      => $this->now(),
                'id'       => $id,
            ]
        );
    }

    public function softDelete(int $id): void
    {
        $this->execute(
            'UPDATE course_contents SET deleted_at = :now WHERE id = :id AND deleted_at IS NULL',
            ['now' => $this->now(), 'id' => $id]
        );
    }

    public function move(int $id, string $direction): void
    {
        $content = $this->findById($id);
        if ($content === null) {
            return;
        }
        $comparison = $direction === 'up' ? '<' : '>';
        $order      = $direction === 'up' ? 'DESC' : 'ASC';

        $neighbour = $this->selectOne(
            'SELECT id, sort_order FROM course_contents
             WHERE course_id = :course AND deleted_at IS NULL
               AND ' . ($content['section_id'] === null ? 'section_id IS NULL' : 'section_id = :section') . '
               AND sort_order ' . $comparison . ' :order
             ORDER BY sort_order ' . $order . ' LIMIT 1',
            $content['section_id'] === null
                ? ['course' => (int) $content['course_id'], 'order' => (int) $content['sort_order']]
                : ['course' => (int) $content['course_id'], 'section' => (int) $content['section_id'], 'order' => (int) $content['sort_order']]
        );

        if ($neighbour === null) {
            return;
        }
        $this->execute('UPDATE course_contents SET sort_order = :o WHERE id = :id',
            ['o' => (int) $neighbour['sort_order'], 'id' => $id]);
        $this->execute('UPDATE course_contents SET sort_order = :o WHERE id = :id',
            ['o' => (int) $content['sort_order'], 'id' => (int) $neighbour['id']]);
    }

    public function nextOrder(int $courseId, ?int $sectionId): int
    {
        $row = $this->selectOne(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 AS next FROM course_contents
             WHERE course_id = :course AND ' . ($sectionId === null ? 'section_id IS NULL' : 'section_id = :section'),
            $sectionId === null ? ['course' => $courseId] : ['course' => $courseId, 'section' => $sectionId]
        );
        return (int) ($row['next'] ?? 1);
    }
}
