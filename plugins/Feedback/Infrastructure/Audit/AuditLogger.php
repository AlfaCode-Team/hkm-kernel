<?php

declare(strict_types=1);

namespace Plugins\Feedback\Infrastructure\Audit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Feedback\Domain\ValueObjects\Ulid;

/**
 * Minimal security audit log.
 *
 * Writes one structured JSON line per security-relevant action (register,
 * delete, failed login, lockout, password rehash, email verification). Records
 * IDENTIFIERS and outcomes only — never passwords, hashes, or raw email/PII —
 * so the audit trail is safe to ship to a SIEM.
 *
 * The JSON line is routed through error_log() (tagged source=user_audit) so it
 * lands in the same stream the platform already collects. When a central
 * DatabasePort is provided, each entry is ALSO persisted to the shared
 * `audit_log` table (the same table Tenancy writes to). DB persistence is
 * best-effort: an audit write must never break the action it records, so a DB
 * failure falls through to the log line only.
 */
final class AuditLogger
{
    /** @var callable(string):void */
    private $sink;

    /**
     * @param (callable(string):void)|null $sink Where to write each JSON line.
     *        Defaults to error_log(); inject a custom sink (file/SIEM) or a
     *        no-op in tests.
     * @param DatabasePort|null $db Central connection for the `audit_log` table.
     *        Null → log-only (tests / no DB available).
     */
    public function __construct(
        private readonly ?string $actorId = null,
        ?callable $sink = null,
        private readonly ?DatabasePort $db = null,
        /** Active tenant for this request ('' / null when unscoped). */
        private readonly ?string $tenantId = null,
    ) {
        $this->sink = $sink ?? static fn(string $line) => error_log($line);
    }

    /** @param array<string,scalar|null> $context */
    public function record(string $action, array $context = []): void
    {
        $occurredAt = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339);

        $entry = json_encode([
            'source'    => 'user_audit',
            'action'    => $action,
            'actor'     => $this->actorId,
            'context'   => $context,
            'timestamp' => $occurredAt,
        ], JSON_UNESCAPED_SLASHES);

        if ($entry !== false) {
            ($this->sink)($entry);
        }

        $this->persist($action, $context, $occurredAt);
    }

    /**
     * Persist to the shared `audit_log` table. Best-effort: any failure is
     * swallowed (already captured in the log line) so auditing never aborts the
     * audited action. `userId` in context maps to the user_id column; everything
     * else is kept in the JSON `meta` column.
     *
     * @param array<string,scalar|null> $context
     */
    private function persist(string $action, array $context, string $occurredAt): void
    {
        if ($this->db === null) {
            return;
        }

        $userId = isset($context['userId']) ? (string) $context['userId'] : ($this->actorId ?: null);
        $ip     = isset($context['ip']) ? (string) $context['ip'] : null;

        $meta = $context;
        unset($meta['userId'], $meta['ip']);
        $metaJson = $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_SLASHES);

        try {
            $this->db->execute(
                'INSERT INTO audit_log (event_id, user_id, tenant_id, action, ip, meta, occurred_at)
                 VALUES (:event_id, :user_id, :tenant_id, :action, :ip, :meta, :occurred_at)',
                [
                    'event_id'    => Ulid::generate(),
                    'user_id'     => $userId,
                    'tenant_id'   => ($this->tenantId ?? '') !== '' ? $this->tenantId : null,
                    'action'      => $action,
                    'ip'          => $ip,
                    'meta'        => $metaJson === false ? null : $metaJson,
                    'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\Throwable) {
            // Best-effort — the log line above is the durable fallback.
        }
    }
}
