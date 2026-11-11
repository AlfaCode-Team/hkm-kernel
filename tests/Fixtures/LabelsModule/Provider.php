<?php

declare(strict_types=1);

namespace Tests\Fixtures\LabelsModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\Labels\AuditLabelSink;

/** Fixture: a plugin that carries route labels and provides sinks for them. */
final class Provider implements ModuleContract
{
    public function solves(): string { return 'labels.fixture'; }

    public function requires(): array { return []; }

    public function exposes(): array { return []; }

    public function register(ModuleContainer $container): void
    {
        if (Recorder::$bindAuditSink) {
            // PUBLIC bind(): the label stage resolves it from outside this scope.
            // (bindInternal() variant: the stage cannot read it, so it is inert.)
            $bindMethod = Recorder::$auditSinkInternal ? 'bindInternal' : 'bind';
            $container->$bindMethod(AuditLabelSink::class, static fn (): AuditLabelSink => new RecordingAuditSink());
        }

        // bind(), not bindInternal(): EventBus resolves listeners from outside this scope.
        $container->bind(RecordingListener::class, static fn (): RecordingListener => new RecordingListener('from-module-scope'));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        if (Recorder::$installUsageHook) {
            $http->hook('after.execute', UsageLikeHook::class, priority: 90);
        }

        $http->filter('lab-deny', DenyFilter::class);

        $events->subscribe('order.created', RecordingListener::class);
        $events->subscribe('order.viewed', RecordingListener::class);
    }
}
