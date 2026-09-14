<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures\BlogModule\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;

/**
 * Bound with bindInternal — resolving this from outside blog.publishing must
 * throw ScopeViolationException, and a feature test asserts exactly that.
 */
final class PostRepository
{
    public function __construct(
        private readonly DatabasePort $db,
        private readonly Identity $identity,
    ) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->query(
            'SELECT * FROM posts WHERE tenant_id = :tenant AND deleted_at IS NULL',
            ['tenant' => $this->identity->tenantId],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->db->queryOne(
            'SELECT * FROM posts WHERE id = :id AND tenant_id = :tenant',
            ['id' => $id, 'tenant' => $this->identity->tenantId],
        );
    }

    public function insert(string $title): string
    {
        $this->db->execute(
            'INSERT INTO posts (title, tenant_id) VALUES (:title, :tenant)',
            ['title' => $title, 'tenant' => $this->identity->tenantId],
        );

        return $this->db->lastInsertId();
    }

    public function delete(string $id): void
    {
        $this->db->execute(
            'UPDATE posts SET deleted_at = NOW() WHERE id = :id AND tenant_id = :tenant',
            ['id' => $id, 'tenant' => $this->identity->tenantId],
        );
    }
}
