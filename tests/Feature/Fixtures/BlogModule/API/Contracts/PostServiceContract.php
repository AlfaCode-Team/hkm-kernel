<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures\BlogModule\API\Contracts;

/** The published surface — the only thing a controller may depend on. */
interface PostServiceContract
{
    /** @return list<array<string, mixed>> */
    public function all(): array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return array<string, mixed> */
    public function create(string $title): array;

    public function delete(string $id): void;
}
