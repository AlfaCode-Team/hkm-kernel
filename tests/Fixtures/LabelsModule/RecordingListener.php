<?php

declare(strict_types=1);

namespace Tests\Fixtures\LabelsModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\{EventListenerContract, IntegrationEventContract};

/**
 * Takes a constructor argument ON PURPOSE: it is bound with bind() in the
 * module's register(), and an unbound listener would be autowired by reflection
 * from the kernel scope and could not reach it.
 */
final class RecordingListener implements EventListenerContract
{
    public function __construct(private readonly string $marker) {}

    public function handle(IntegrationEventContract $event): void
    {
        Recorder::$events[] = ['name' => $event->name(), 'payload' => $event->payload() + ['marker' => $this->marker]];
    }
}
