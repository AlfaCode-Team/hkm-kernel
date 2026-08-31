<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests\Fixtures\Sample;

interface SampleServiceContract
{
    public function all(): array;

    public function create(string $title): array;
}
