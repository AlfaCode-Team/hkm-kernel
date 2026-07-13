<?php

declare(strict_types=1);

namespace Plugins\Audit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Audit\API\Contracts\AuditReaderContract;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Audit\Application\Ports\AuditWriter;
use Plugins\Audit\Application\Services\AuditService;
use Plugins\Audit\Infrastructure\Persistence\AuditLogRepository;
use Plugins\Audit\Infrastructure\Persistence\AuditTrail;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;

/**
 * Audit plugin — owns the 'audit.trail' domain and the central `audit_log` table.
 *
 * The SINGLE writer/reader of that table. Other plugins (User, Feedback, Tenancy)
 * declare requires: ["audit.trail"] and record through the published
 * {@see AuditServiceContract} — they never write `audit_log` themselves.
 *
 * The audit trail is always central: the writer/reader are pinned to the
 * ConnectionManager default connection, never a tenant-routed one.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'audit.trail';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [DatabaseConnectionManagerContract::class];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [AuditServiceContract::class, AuditReaderContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        // Write side: persistence seam behind the audit service (central conn).
        $container->bindInternal(AuditWriter::class, static fn (ModuleContainer $c): AuditWriter =>
            new AuditTrail(self::central($c)));

        // Published write contract — the ONE way any plugin records an action.
        // Auto-fills actor (Identity) and tenant (Tenancy's `tenant.current`
        // container key — a plain string, no Tenancy import) when omitted.
        $container->bind(AuditServiceContract::class, static function (ModuleContainer $c): AuditServiceContract {
            $identity = $c->has(Identity::class) ? $c->make(Identity::class) : null;
            $actorId  = $identity !== null ? ($identity->userId ?: null) : null;

            // Tenant source: the routed tenant (`tenant.current`, set by Tenancy's
            // TenantContextStage) when present, else the authoritative Identity
            // tenant — which is always bound at load and drives the routing itself,
            // so it is populated even on paths where the stage bound nothing.
            $tenantId = $c->has('tenant.current')
                ? ((string) $c->make('tenant.current') ?: null)
                : ($identity !== null ? ($identity->tenantId ?: null) : null);

            $clientIp = $c->has('client.ip') ? (string) $c->make('client.ip') : null;

            return new AuditService(
                writer:        $c->make(AuditWriter::class),
                actorId:       $actorId,
                currentTenant: $tenantId,
                clientIp:      $clientIp,
            );
        });

        // Published read/query contract for control-plane admin surfaces.
        $container->bind(AuditReaderContract::class, static fn (ModuleContainer $c): AuditReaderContract =>
            new AuditLogRepository(self::central($c)));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // No pipeline hooks or subscriptions — a pure infrastructure domain.
    }

    /** The CENTRAL connection (owns the shared `audit_log` table). */
    private static function central(ModuleContainer $c): DatabasePort
    {
        return $c->make(DatabaseConnectionManagerContract::class)->default();
    }
}
