<?php

declare(strict_types=1);

namespace Plugins\Database;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Database\API\Contracts\DatabaseConfigurationContract;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Database\Infrastructure\Drivers\DatabaseConfigurationFactory;
use Plugins\Database\Infrastructure\Persistence\ConnectionManager;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;
use Plugins\Database\Infrastructure\Persistence\PooledDatabaseAdapter;
use Plugins\Database\Infrastructure\Pool\ConnectionPool;
use Plugins\Database\Infrastructure\Pool\PoolConfiguration;

/**
 * Provider — registers the Database module with multi-driver support.
 *
 * Solves: database.management
 *
 * Supported drivers: MySQL/MariaDB, PostgreSQL, SQLite (file or in-memory),
 * SQL Server.
 *
 * Responsibilities (wiring only — no business logic):
 *   • Build the active DatabaseConfigurationContract from DB_* env vars via the
 *     DatabaseConfigurationFactory.
 *   • Bind the kernel DatabasePort to a lazily-connecting MultiDriverDatabaseAdapter.
 *   • Expose a ConnectionManager for multi-database (read-replica / warehouse) setups.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'database.management';
    }

    public function requires(): array
    {
        return [];
    }

    public function exposes(): array
    {
        return [
            DatabasePort::class,
            DatabaseConfigurationContract::class,
            DatabaseConnectionManagerContract::class,
        ];
    }

    public function register(ModuleContainer $container): void
    {
        $logQueries = $this->boolEnv('DB_ENABLE_QUERY_LOG');

        // Active connection configuration, resolved once from the environment.
        $container->singleton(DatabaseConfigurationContract::class, static fn (): DatabaseConfigurationContract =>
            (new DatabaseConfigurationFactory())->fromEnvironment()
        );

        if ($this->boolEnv('DB_POOL_ENABLED')) {
            $this->registerPooledPort($container, $logQueries);
        } else {
            // Kernel port — repositories depend on this interface only.
            $container->bind(DatabasePort::class, static fn ($c): DatabasePort =>
                new MultiDriverDatabaseAdapter(
                    config: $c->make(DatabaseConfigurationContract::class),
                    logger: self::optionalLogger($c),
                    logQueries: $logQueries,
                )
            );
        }

        // Multi-connection registry; the default connection mirrors DatabasePort.
        $container->singleton(DatabaseConnectionManagerContract::class, static function ($c) use ($logQueries): DatabaseConnectionManagerContract {
            $manager = new ConnectionManager(
                defaultName: 'default',
                logger: self::optionalLogger($c),
                logQueries: $logQueries,
            );
            $manager->register('default', $c->make(DatabaseConfigurationContract::class));

            return $manager;
        });
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // No pipeline hooks or event subscriptions — the module is pure infrastructure.
    }

    /**
     * Wire the connection-pool-backed DatabasePort.
     *
     * The ConnectionPool is resolved as an app-lifetime binding when one was
     * provided by the bootstrap (preferred — one pool per worker, reused across
     * requests). If none is bound, a pool is created lazily as a container
     * singleton so the pooled path also works without bootstrap changes.
     *
     * The PooledDatabaseAdapter itself is request-scoped (bind, not singleton):
     * each request borrows one connection and returns it on teardown.
     */
    private function registerPooledPort(ModuleContainer $container, bool $logQueries): void
    {
        if (!$container->has(ConnectionPool::class)) {
            $container->singleton(ConnectionPool::class, static function ($c) use ($logQueries): ConnectionPool {
                $config = $c->make(DatabaseConfigurationContract::class);
                $logger = self::optionalLogger($c);

                $pool = new ConnectionPool(
                    factory: static fn (): MultiDriverDatabaseAdapter =>
                        new MultiDriverDatabaseAdapter($config, $logger, $logQueries),
                    config: PoolConfiguration::fromEnvironment(),
                    driver: $config->driver(),
                );
                $pool->warmup();

                return $pool;
            });
        }

        $container->bind(DatabasePort::class, static fn ($c): DatabasePort =>
            new PooledDatabaseAdapter($c->make(ConnectionPool::class))
        );
    }

    /**
     * Resolve the app logger if one is bound; observability is optional here —
     * the database must work in a bootstrap that has not wired a LoggerPort.
     */
    private static function optionalLogger(mixed $container): ?LoggerPort
    {
        try {
            $logger = $container->make(LoggerPort::class);

            return $logger instanceof LoggerPort ? $logger : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function boolEnv(string $key): bool
    {
        $value = env($key);

        return $value !== false
            && $value !== null
            && in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
