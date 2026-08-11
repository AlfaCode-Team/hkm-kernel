<?php
declare(strict_types=1);

namespace Project\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use PDO;
use PDOException;

/**
 * PdoDatabase — project-supplied DatabasePort adapter (PROJECT LAYER).
 *
 * The kernel defines the DatabasePort interface; the project provides this
 * implementation and binds it in bootstrap/app.php via ->withPorts([...]).
 *
 * Defaults to an in-memory SQLite connection so the framework boots with zero
 * external infrastructure. Point DB_DSN / DB_USERNAME / DB_PASSWORD at MySQL or
 * Postgres for production.
 *
 * Swoole note: one instance is created per worker process inside bootstrap/app.php
 * and lives for the worker's lifetime. PDO connections are not shared across
 * workers, so this is safe under OpenSwoole.
 */
final class PdoDatabase implements DatabasePort
{
    private PDO $pdo;

    public function __construct(
        string $dsn = 'sqlite::memory:',
        ?string $username = null,
        ?string $password = null,
    ) {
        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        if ($values === []) {
            return 0;
        }

        $columns = array_keys($values);
        $updateColumns ??= array_values(array_diff($columns, $conflictColumns));
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $quote = static fn(string $i): string => $driver === 'mysql'
            ? '`' . str_replace('`', '', $i) . '`'
            : '"' . str_replace('"', '', $i) . '"';

        $cols = implode(', ', array_map($quote, $columns));
        $binds = implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns));
        $insert = "INSERT INTO {$quote($table)} ({$cols}) VALUES ({$binds})";

        if ($driver === 'mysql') {
            $sql = $updateColumns === []
                ? "{$insert} ON DUPLICATE KEY UPDATE {$quote($conflictColumns[0] ?? $columns[0])} = {$quote($conflictColumns[0] ?? $columns[0])}"
                : "{$insert} ON DUPLICATE KEY UPDATE " . implode(', ', array_map(
                    static fn(string $c): string => "{$quote($c)} = VALUES({$quote($c)})",
                    $updateColumns
                ));
        } else {
            $target = implode(', ', array_map($quote, $conflictColumns));
            $sql = $updateColumns === []
                ? "{$insert} ON CONFLICT ({$target}) DO NOTHING"
                : "{$insert} ON CONFLICT ({$target}) DO UPDATE SET " . implode(', ', array_map(
                    static fn(string $c): string => "{$quote($c)} = EXCLUDED.{$quote($c)}",
                    $updateColumns
                ));
        }

        return $this->execute($sql, $values);
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return $sequence !== null
            ? (string) $this->pdo->lastInsertId($sequence)
            : (string) $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}
