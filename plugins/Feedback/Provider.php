<?php

declare(strict_types=1);

namespace Plugins\Feedback;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Feedback\Application\Services\FeedbackService;
use Plugins\Feedback\Infrastructure\Audit\AuditLogger;
use Plugins\Feedback\Infrastructure\Http\Controllers\FeedbackController;
use Plugins\Feedback\Infrastructure\Persistence\FeedbackRepository;

/**
 * Feedback plugin — owns the 'feedback.management' domain.
 *
 * Extracted from the User plugin so each plugin owns exactly one domain. Feedback
 * rows live in the request's TENANT database (the repository is bound to the
 * tenant-routed DatabasePort); the audit trail is written to the shared CENTRAL
 * `audit_log` table (pinned via the ConnectionManager default). Everything is
 * internal — the controller depends on the concrete service directly, so nothing
 * is published cross-module.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'feedback.management';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [
            DatabaseConnectionManagerContract::class,
        ];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        // Feedback is consumed only through its own HTTP routes — no published contract.
        return [];
    }

    public function register(ModuleContainer $container): void
    {
        // Feedback rows are TENANT-scoped → repository takes the request's
        // tenant-routed DatabasePort directly (NOT the central connection).
        $container->bindInternal(FeedbackRepository::class, static fn(ModuleContainer $c) =>
            new FeedbackRepository($c->make(DatabasePort::class)));

        // Audit persists to the shared CENTRAL `audit_log` table + a log line.
        // The active tenant is published by Tenancy's TenantContextStage under
        // the 'tenant.current' container key (a plain string — no Tenancy import).
        $container->bindInternal(AuditLogger::class, static function (ModuleContainer $c) {
            $identity = $c->make(Identity::class);
            $tenantId = $c->has('tenant.current') ? (string) $c->make('tenant.current') : null;
            return new AuditLogger(
                $identity->userId ?: null,
                db:       self::central($c),
                tenantId: $tenantId,
            );
        });

        $container->bindInternal(FeedbackService::class, static fn(ModuleContainer $c) =>
            new FeedbackService(
                repository: $c->make(FeedbackRepository::class),
                eventBus:   $c->make(EventBus::class),
                identity:   $c->make(Identity::class),
                audit:      $c->make(AuditLogger::class),
            ));

        $container->bindInternal(FeedbackController::class, static fn(ModuleContainer $c) =>
            new FeedbackController($c->make(FeedbackService::class)));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // No pipeline hooks or subscriptions — routes carry the wiring.
    }

    /** The CENTRAL connection (owns the shared `audit_log` table). */
    private static function central(ModuleContainer $c): DatabasePort
    {
        return $c->make(DatabaseConnectionManagerContract::class)->default();
    }
}
