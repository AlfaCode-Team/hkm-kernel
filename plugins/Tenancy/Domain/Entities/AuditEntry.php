<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Entities;

use Project\Support\Entity\Entity;

/**
 * AuditEntry — read model for one `audit_log` row.
 *
 * Reconstituted from a DB row (never constructed by the domain). The append-only
 * trail records identifiers + structured meta only, so this carries no secrets.
 *
 * Built on the shared {@see Entity} attribute-bag base, keyed by the public
 * property names consumers already read (Entity::__get exposes the bag).
 */
final class AuditEntry extends Entity
{
    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $metaRaw = $row['meta'] ?? null;
        $meta    = is_string($metaRaw) && $metaRaw !== ''
            ? (json_decode($metaRaw, true) ?: [])
            : [];

        $e = (new self())->forceFill([
            'id'         => (int) $row['id'],
            'eventId'    => (string) $row['event_id'],
            'userId'     => isset($row['user_id']) ? (string) $row['user_id'] : null,
            'tenantId'   => isset($row['tenant_id']) ? (string) $row['tenant_id'] : null,
            'action'     => (string) $row['action'],
            'ip'         => isset($row['ip']) ? (string) $row['ip'] : null,
            'meta'       => is_array($meta) ? $meta : [],
            'occurredAt' => (string) $row['occurred_at'],
        ]);
        $e->syncOriginal();

        return $e;
    }

    /** @return array<string, mixed> */
    public function toArray(bool $onlyChanged = false): array
    {
        return [
            'id'          => $this->id,
            'event_id'    => $this->eventId,
            'user_id'     => $this->userId,
            'tenant_id'   => $this->tenantId,
            'action'      => $this->action,
            'ip'          => $this->ip,
            'meta'        => $this->meta,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
