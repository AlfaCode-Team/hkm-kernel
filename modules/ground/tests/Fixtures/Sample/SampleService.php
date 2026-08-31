<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests\Fixtures\Sample;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\Ground\Tests\Fixtures\Sample\Persistence\SampleRepository;

final class SampleService implements SampleServiceContract
{
    public function __construct(
        private readonly SampleRepository $repository,
        private readonly EventBus $eventBus,
    ) {}

    public function all(): array
    {
        return ['limit' => (int) env('GROUND_SAMPLE_LIMIT', 10), 'items' => $this->repository->all()];
    }

    public function create(string $title): array
    {
        $id = $this->repository->insert($title);

        // Dispatched AFTER the write, never inside it — the ordering the
        // service-layer pattern requires, so the fixture models it correctly.
        $this->eventBus->dispatch(new SampleCreatedEvent($id, $title));

        return ['id' => $id, 'title' => $title];
    }
}
