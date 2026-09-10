<?php
declare(strict_types=1);

namespace HeleXa\Models;

use HeleXa\Core\Database;

abstract class BaseRepository
{
    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    protected function select(string $sql, array $params = []): array
    {
        return Database::select($sql, $params);
    }

    protected function selectOne(string $sql, array $params = []): ?array
    {
        return Database::selectOne($sql, $params);
    }

    protected function execute(string $sql, array $params = []): int
    {
        return Database::execute($sql, $params);
    }

    protected function insert(string $sql, array $params = []): int
    {
        return Database::insert($sql, $params);
    }
}
