<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Listeners;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\EventListenerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract;
use Plugins\User\Application\Services\TenantProfileProvisioner;
use Plugins\User\Infrastructure\Persistence\UserSettingsRepository;

/**
 * Creates the per-tenant user_profiles row from a user.registered event.
 *
 * WHY A LISTENER (not part of register): identity lives in the CENTRAL `users`
 * table; the profile lives in the per-tenant `user_profiles` table — a DIFFERENT
 * database. The two cannot share one transaction, so the profile is written
 * asynchronously, eventually-consistent, at-least-once (the outbox re-delivers
 * on failure; the upsert on user_id makes replay idempotent).
 *
 * ACCESS RULE: the listener only ORCHESTRATES. The tenant connection is known
 * solely at relay time, so the listener acts as the composition point — but it
 * never touches a DatabasePort. It resolves the tenant connection, wires a
 * repository against it and hands that to the provisioner service:
 *
 *     listener → TenantProfileProvisioner (service) → UserSettingsRepository → DatabasePort
 *
 * WIRING: the EventBus resolves this listener from the CoreContainer. To actually
 * write, the PROJECT must bind it WITH a TenantConnectionResolverContract in
 * bootstrap (same pattern as the SEO IndexNow listener). Left unbound it is
 * constructed argument-free and safely no-ops — a signup with no profile block
 * and a platform with no Tenancy simply skip it.
 */
final class ProvisionTenantProfileListener implements EventListenerContract
{
    /** Only these primitive columns are trusted off the event. */
    private const ALLOWED = ['first_name', 'last_name', 'phone', 'timezone', 'locale'];

    public function __construct(
        private readonly ?TenantConnectionResolverContract $connections = null,
    ) {}

    public function handle(IntegrationEventContract $event): void
    {
        if ($event->name() !== 'user.registered') {
            return;
        }

        $payload  = $event->payload();
        $tenantId = (string) ($payload['tenantId'] ?? '');
        $userId   = (string) ($payload['userId'] ?? '');
        $profile  = $payload['profile'] ?? [];

        // No tenant, no profile, or no resolver bound → nothing to persist.
        if ($this->connections === null || $tenantId === '' || $userId === '' || !is_array($profile) || $profile === []) {
            return;
        }

        // Compose service → repository against the ORIGIN tenant's connection.
        // The listener never calls the DatabasePort itself — the repository does.
        $repository  = new UserSettingsRepository($this->connections->for($tenantId));
        $provisioner = new TenantProfileProvisioner($repository);

        $provisioner->provision($userId, array_intersect_key($profile, array_flip(self::ALLOWED)));
    }
}
