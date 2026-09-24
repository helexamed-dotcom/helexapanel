<?php
declare(strict_types=1);

namespace HeleXa\Core;

use PDO;
use PDOException;

/**
 * Single PDO connection. Exceptions on, emulation off, no persistent handles.
 * Every query in the application goes through prepared statements.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host    = (string) Config::get('app.database.host', 'localhost');
        $name    = (string) Config::get('app.database.name', '');
        $user    = (string) Config::get('app.database.user', '');
        $pass    = (string) Config::get('app.database.password', '');
        $charset = (string) Config::get('app.database.charset', 'utf8mb4');
        $port    = (int) Config::get('app.database.port', 3306);

        try {
            self::$pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset),
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                    PDO::ATTR_PERSISTENT         => false,
                ]
            );
            // Align the database clock with the application clock. Without this,
            // MySQL's NOW() and PHP's date() disagree by the UTC offset, and any
            // future query mixing the two would silently compute wrong intervals.
            $offset = (new \DateTime('now', new \DateTimeZone((string) Config::get('app.app.timezone', 'Asia/Tehran'))))
                ->format('P');
            self::$pdo->exec("SET time_zone = '" . $offset . "'");
        } catch (PDOException $e) {
            // Credentials and DSN must never reach the browser.
            Logger::critical('Database connection failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('DATABASE_UNAVAILABLE', 0, $e);
        }

        return self::$pdo;
    }

    /** Convenience wrappers. All of them are prepared-statement only. */
    public static function select(string $sql, array $params = []): array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::execute($sql, $params);
        return (int) self::connection()->lastInsertId();
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();

        // Re-entrant: a transaction opened inside another joins it, and any
        // failure still reaches the outer call, which rolls the whole unit back.
        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
