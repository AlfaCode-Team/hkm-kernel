<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests\Fixtures\Sample\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

final class SampleRepository
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    public function all(): array
    {
        return $this->db->query('SELECT id, title FROM samples ORDER BY id');
    }

    public function insert(string $title): string
    {
        $this->db->execute('INSERT INTO samples (title) VALUES (:title)', ['title' => $title]);

        return $this->db->lastInsertId();
    }
}
