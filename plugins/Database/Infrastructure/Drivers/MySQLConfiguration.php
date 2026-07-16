<?php

declare(strict_types=1);

namespace Plugins\Database\Infrastructure\Drivers;

use PDO;
use Plugins\Database\API\Contracts\DatabaseConfigurationContract;

/**
 * MySQLConfiguration — MySQL/MariaDB database configuration.
 * Supports both Unix socket and TCP connections.
 */
final readonly class MySQLConfiguration implements DatabaseConfigurationContract
{
    public function __construct(
        private string $host = 'localhost',
        private int $port = 3306,
        private string $database = '',
        private string $username = 'root',
        private string $password = '',
        private string $charset = 'utf8mb4',
        private bool $useSslVerify = false,
        private ?string $sslCa = null,
        private ?string $unixSocket = null,
    ) {}

    public function driver(): string
    {
        return 'mysql';
    }

    public function dsn(): string
    {
        if ($this->unixSocket) {
            return "mysql:unix_socket={$this->unixSocket};dbname={$this->database};charset={$this->charset}";
        }

        return "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset={$this->charset}";
    }

    public function username(): ?string
    {
        return $this->username ?: null;
    }

    public function password(): ?string
    {
        return $this->password ?: null;
    }

    public function pdoOptions(): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // Pin the session to UTC here (not only in initStatements): PDO
            // re-runs INIT_COMMAND on every connect AND auto-reconnect, so
            // CURRENT_TIMESTAMP/NOW() and TIMESTAMP read-back stay UTC even after
            // a dropped connection. '+00:00' is a numeric offset (no tz tables).
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset}, time_zone = '+00:00'",
        ];

        if ($this->sslCa && $this->useSslVerify) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $this->sslCa;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        return $options;
    }

    public function initStatements(): array
    {
        // Charset is already applied via MYSQL_ATTR_INIT_COMMAND; strict mode is
        // enforced here so silent truncation/coercion never reaches production.
        // The session timezone is pinned to UTC ('+00:00' is a numeric offset, so
        // it needs no timezone tables): CURRENT_TIMESTAMP / NOW() and TIMESTAMP
        // read-back are then unambiguously UTC, matching the PHP-side UTC clock.
        return [
            "SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'",
            "SET time_zone = '+00:00'",
        ];
    }

    public function toArray(): array
    {
        return [
            'driver' => $this->driver(),
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'charset' => $this->charset,
            'unix_socket' => $this->unixSocket,
        ];
    }
}
