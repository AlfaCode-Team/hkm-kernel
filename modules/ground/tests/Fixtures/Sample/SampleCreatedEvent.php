<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests\Fixtures\Sample;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

final readonly class SampleCreatedEvent implements IntegrationEventContract
{
    public function __construct(
        public string $id,
        public string $title,
    ) {}

    public function name(): string { return 'ground.sample.created'; }

    public function version(): string { return '1.0'; }

    public function payload(): array { return ['id' => $this->id, 'title' => $this->title]; }
}
