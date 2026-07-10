<?php

declare(strict_types=1);

namespace Plugins\User;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, EventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;
use Plugins\User\Application\Ports\BreachChecker;
use Plugins\User\Infrastructure\Gateways\NullBreachChecker;
use Plugins\User\Infrastructure\Gateways\PwnedPasswordGateway;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\Application\Services\UserService;
use Plugins\User\Application\Services\UserSettingsService;
use Plugins\User\Infrastructure\Audit\AuditLogger;
use Plugins\User\Infrastructure\Cli\RelayUserOutboxCommand;
use Plugins\User\Infrastructure\Http\Controllers\UserController;
use Plugins\User\Infrastructure\Http\Controllers\UserPageController;
use Plugins\User\Infrastructure\Http\Controllers\UserSettingsController;
use Plugins\User\Infrastructure\Listeners\ProvisionTenantProfileListener;
use Plugins\User\Infrastructure\Outbox\OutboxWriter;
use Plugins\User\Infrastructure\Persistence\UserRepository;
use Plugins\User\Infrastructure\Persistence\UserSettingsRepository;
use Plugins\View\API\Contracts\ViewRendererContract;

/**
 * User plugin — owns the 'user.management' domain.
 *
 * Requires: database.management (the `users` table is the GLOBAL central
 * identity store — repository + outbox are pinned to the ConnectionManager
 * default/central connection so identity I/O always targets the central DB),
 * crypto.services (HashingPort), cache.redis
 * (CachePort, login lockout), view.rendering (HTML UI).
 * Publishes UserServiceContract for other modules (e.g. Auth).
 *
 * Reliable cross-module delivery uses a transactional outbox drained by the
 * `user:outbox:relay` CLI command (registered in boot()).
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'user.management';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [
            DatabaseConnectionManagerContract::class,
            HashingPort::class,
            CachePort::class,
            ViewRendererContract::class,
            HttpClientPort::class, // breached-password screening (opt-in via USER_BREACH_CHECK)
        ];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        // Only UserServiceContract is consumed cross-module (Auth/Tenancy).
        // Settings are internal to this plugin (their own controller), so they
        // are NOT published — the controller depends on the concrete service.
        return [
            UserServiceContract::class,
        ];
    }

    public function register(ModuleContainer $container): void
    {
        // `users`/`user_outbox` live in the CENTRAL database. Pin both to the
        // ConnectionManager default so identity I/O always targets the central
        // connection regardless of any per-request DatabasePort rebinding.
        $container->bindInternal(UserRepository::class, static fn(ModuleContainer $c) =>
            new UserRepository(self::central($c)));

        $container->bindInternal(OutboxWriter::class, static fn(ModuleContainer $c) =>
            new OutboxWriter(self::central($c)));

        $container->bindInternal(AuditLogger::class, static function (ModuleContainer $c) {
            $identity = $c->make(Identity::class);
            // Persist to the shared central `audit_log` table in addition to the
            // log line (central connection — audit is never tenant-routed). The
            // active tenant is published by Tenancy's TenantContextStage under the
            // 'tenant.current' container key (a plain string — no Tenancy import).
            $tenantId = $c->has('tenant.current') ? (string) $c->make('tenant.current') : null;
            return new AuditLogger(
                $identity->userId ?: null,
                db:       self::central($c),
                tenantId: $tenantId,
            );
        });

        // Breached-password screening (NIST 800-63B). Enabled with
        // USER_BREACH_CHECK; uses the HIBP k-anonymity range API via
        // HttpClientPort. Falls back to a no-op when disabled or no HTTP client
        // is available, so the check is purely opt-in and never a hard dependency.
        $container->bindInternal(BreachChecker::class, static function (ModuleContainer $c): BreachChecker {
            $enabled = filter_var(env('USER_BREACH_CHECK', false), FILTER_VALIDATE_BOOL);
            if (!$enabled || !$c->has(HttpClientPort::class)) {
                return new NullBreachChecker();
            }

            return new PwnedPasswordGateway(
                $c->make(HttpClientPort::class),
                (int) (env('USER_BREACH_THRESHOLD') ?: 1),
            );
        });

        $container->bind(UserServiceContract::class, static fn(ModuleContainer $c) =>
            new UserService(
                repository:    $c->make(UserRepository::class),
                transaction:   $c->make(TransactionManager::class),
                collector:     $c->make(DomainEventCollector::class),
                outbox:        $c->make(OutboxWriter::class),
                hasher:        $c->make(HashingPort::class),
                identity:      $c->make(Identity::class),
                cache:         $c->make(CachePort::class),
                audit:         $c->make(AuditLogger::class),
                breachChecker: $c->make(BreachChecker::class),
            ));

        // Public/admin JSON controller. Bound explicitly so the OPTIONAL MailPort
        // is injected only when a project wired one (else null → email is skipped).
        $container->bindInternal(UserController::class, static fn(ModuleContainer $c) =>
            new UserController(
                $c->make(UserServiceContract::class),
                $c->has(MailPort::class) ? $c->make(MailPort::class) : null,
            ));

        // HTML page controller (renders the AJAX-driven UI shell).
        $container->bindInternal(UserPageController::class, static fn(ModuleContainer $c) =>
            new UserPageController(
                $c->make(ViewRendererContract::class),
            ));

        // ── Per-user settings (TENANT-scoped singletons, internal) ───────────
        // One service + one repository for all four settings resources. Scoped
        // to the authenticated Identity (self only); tenant-routed DatabasePort.
        $container->bindInternal(UserSettingsRepository::class, static fn(ModuleContainer $c) =>
            new UserSettingsRepository($c->make(DatabasePort::class)));

        $container->bindInternal(UserSettingsService::class, static fn(ModuleContainer $c) =>
            new UserSettingsService(
                $c->make(UserSettingsRepository::class),
                $c->make(Identity::class),
                $c->make(AuditLogger::class),
            ));

        $container->bindInternal(UserSettingsController::class, static fn(ModuleContainer $c) =>
            new UserSettingsController($c->make(UserSettingsService::class)));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Outbox relay — resolved via CoreContainer autowiring (DatabasePort + EventBus).
        $cli->command(RelayUserOutboxCommand::class);

        // Write the per-tenant user_profiles row from an at-signup profile block.
        // Resolved from the CoreContainer: the PROJECT binds this WITH a
        // TenantConnectionResolverContract to make it write (else it no-ops).
        $events->subscribe('user.registered', ProvisionTenantProfileListener::class);
    }

    /**
     * The CENTRAL connection (always-connected DB that owns the global `users`
     * table) via the ConnectionManager default.
     */
    private static function central(ModuleContainer $c): DatabasePort
    {
        return $c->make(DatabaseConnectionManagerContract::class)->default();
    }
}
