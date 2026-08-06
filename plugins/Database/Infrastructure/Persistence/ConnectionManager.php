<?php

declare(strict_types=1);

namespace Plugins\Database\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Database\API\Contracts\DatabaseConfigurationContract;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Database\Exceptions\ConnectionException;

/**
 * ConnectionManager — registry of named database connections.
 *
 * Supports multi-database deployments (e.g. a primary write connection plus a
 * read replica, or a separate analytics warehouse). Connections are created
 * lazily the first time they are resolved and cached for the manager's lifetime.
 *
 * Each adapter is built lazily, so registering a connection that is never used
 * never opens a socket.
 */
final class ConnectionManager implements DatabaseConnectionManagerContract
{
    /** @var array<string, DatabaseConfigurationContract> */
    private array $configs = [];

    /** @var array<string, DatabasePort> */
    private array $resolved = [];

    public function __construct(
        private readonly string $defaultName = 'default',
        private readonly ?LoggerPort $logger = null,
        private readonly bool $logQueries = false,
    ) {}

    public function connection(string $name = 'default'): DatabasePort
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if (!isset($this->configs[$name])) {
            throw ConnectionException::unknownConnection($name);
        }

        return $this->resolved[$name] = new MultiDriverDatabaseAdapter(
            config: $this->configs[$name],
            logger: $this->logger,
            logQueries: $this->logQueries,
        );
    }

    public function default(): DatabasePort
    {
        return $this->connection($this->defaultName);
    }

    public function register(string $name, DatabaseConfigurationContract $config): void
    {
        $this->configs[$name] = $config;
        // Drop any previously resolved adapter so the new config takes effect.
        unset($this->resolved[$name]);
    }

    public function has(string $name): bool
    {
        return isset($this->configs[$name]);
    }

    /**
     * @return list<string>
     */
    public function connections(): array
    {
        return array_keys($this->configs);
    }

    public function close(string $name = 'default'): void
    {
        unset($this->resolved[$name]);
    }

    public function closeAll(): void
    {
        $this->resolved = [];
    }
}
