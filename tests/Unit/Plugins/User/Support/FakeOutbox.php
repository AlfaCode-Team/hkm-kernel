<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use Plugins\User\Application\Ports\OutboxPort;

/** Records what the service writes to the outbox. */
final class FakeOutbox implements OutboxPort
{
    /** @var list<IntegrationEventContract> */
    public array $events = [];

    /** @var list<int> ids marked dispatched by the service after commit */
    public array $dispatched = [];

    private int $nextId = 0;

    public function write(IntegrationEventContract $event): int
    {
        $this->events[] = $event;

        return ++$this->nextId;
    }

    public function markDispatched(int $id): void
    {
        $this->dispatched[] = $id;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn(IntegrationEventContract $e) => $e->name(), $this->events);
    }
}
