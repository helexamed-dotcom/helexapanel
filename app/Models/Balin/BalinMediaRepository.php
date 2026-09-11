<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;

/**
 * Uploaded images, audio and video used inside stages.
 *
 * The row records where the bytes went and how they must be described.
 * Private media is served through a controller that checks the session;
 * public media is a plain asset. Which of the two a file is depends on the
 * kind of file it is, decided at upload time by BalinMediaStorage.
 */
final class BalinMediaRepository extends BaseRepository
{
    public function all(?string $kind = null, int $limit = 200): array
    {
        $limit  = max(1, min(500, $limit));
        $filter = '';
        $params = [];

        if ($kind !== null && $kind !== '') {
            $filter = ' WHERE kind = :kind';
            $params['kind'] = $kind;
        }

        return $this->select(
            "SELECT * FROM balin_media {$filter} ORDER BY id DESC LIMIT {$limit}",
            $params
        );
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM balin_media WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne('SELECT * FROM balin_media WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);
    }

    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO balin_media
                (uuid, kind, visibility, storage_path, original_name, mime, byte_size, alt_text,
                 caption, transcript, width_percent, position, allow_download, checksum, uploaded_by, created_at)
             VALUES (:uuid, :kind, :visibility, :path, :original, :mime, :size, :alt,
                     :caption, :transcript, :width, :position, :download, :checksum, :by, :now)',
            [
                'uuid'       => $data['uuid'],
                'kind'       => $data['kind'],
                'visibility' => $data['visibility'] ?? 'private',
                'path'       => $data['storage_path'],
                'original'   => $data['original_name'] ?? null,
                'mime'       => $data['mime'],
                'size'       => $data['byte_size'] ?? 0,
                'alt'        => $data['alt_text'] ?? null,
                'caption'    => $data['caption'] ?? null,
                'transcript' => $data['transcript'] ?? null,
                'width'      => $data['width_percent'] ?? 100,
                'position'   => $data['position'] ?? 'center',
                'download'   => !empty($data['allow_download']) ? 1 : 0,
                'checksum'   => $data['checksum'] ?? null,
                'by'         => $data['uploaded_by'] ?? null,
                'now'        => $this->now(),
            ]
        );
    }

    public function updateMeta(int $id, array $data): int
    {
        return $this->execute(
            'UPDATE balin_media
             SET alt_text = :alt, caption = :caption, transcript = :transcript,
                 width_percent = :width, position = :position, allow_download = :download
             WHERE id = :id',
            [
                'alt'        => $data['alt_text'] ?? null,
                'caption'    => $data['caption'] ?? null,
                'transcript' => $data['transcript'] ?? null,
                'width'      => $data['width_percent'] ?? 100,
                'position'   => $data['position'] ?? 'center',
                'download'   => !empty($data['allow_download']) ? 1 : 0,
                'id'         => $id,
            ]
        );
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM balin_media WHERE id = :id', ['id' => $id]);
    }

    public function usageCount(int $id): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM balin_blocks WHERE media_id = :id',
            ['id' => $id]
        )['c'] ?? 0);
    }
}
