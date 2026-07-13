<?php

declare(strict_types=1);

namespace Plugins\Audit\API\Contracts;

/**
 * Published write contract for the shared central audit trail.
 *
 * The ONE way any plugin records a security-relevant action (register, delete,
 * failed login, lockout, password rehash, email verification, tenant switch,
 * member invite, …). Best-effort by policy: an audit write must never break the
 * action it records.
 *
 * Records IDENTIFIERS + structured meta only — never passwords, hashes, tokens,
 * or raw PII — so the trail is safe to ship to a SIEM.
 *
 * `userId` / `tenantId` are OPTIONAL: when null the implementation fills them
 * from the request Identity and the active tenant (Tenancy's `tenant.current`),
 * so a caller that omits them still gets an attributed entry.
 */
interface AuditServiceContract
{
    /**
     * @param array<string, scalar|null> $meta structured, non-PII context
     */
    public function record(
        string $action,
        ?string $userId = null,
        ?string $tenantId = null,
        array $meta = [],
        ?string $ip = null,
    ): void;
}
