<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class SettingRepository extends BaseRepository
{
    public function all(): array
    {
        return $this->select('SELECT setting_key, setting_value, value_type FROM settings');
    }

    public function set(string $key, string $value, string $type, ?int $userId = null): void
    {
        $this->execute(
            'INSERT INTO settings (setting_key, setting_value, value_type, updated_by, updated_at)
             VALUES (:k, :v, :t, :u, :now)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type),
                                     updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            ['k' => $key, 'v' => $value, 't' => $type, 'u' => $userId, 'now' => $this->now()]
        );
    }
}
