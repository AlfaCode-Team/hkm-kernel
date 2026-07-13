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
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Feedback\Application\Services\FeedbackService;
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
            AuditServiceContract::class,
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

        $container->bindInternal(FeedbackService::class, static fn(ModuleContainer $c) =>
            new FeedbackService(
                repository: $c->make(FeedbackRepository::class),
                eventBus:   $c->make(EventBus::class),
                identity:   $c->make(Identity::class),
                audit:      $c->make(AuditServiceContract::class),
            ));

        $container->bindInternal(FeedbackController::class, static fn(ModuleContainer $c) =>
            new FeedbackController($c->make(FeedbackService::class)));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // No pipeline hooks or subscriptions — routes carry the wiring.
    }
}
