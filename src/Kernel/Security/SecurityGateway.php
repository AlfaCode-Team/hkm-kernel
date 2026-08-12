<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;

// ─── SecurityGateway ─────────────────────────────────────────────────────────

/**
 * Pre-bootstrap security gate.
 * Runs BEFORE any module loads into memory.
 * Denied requests never touch module code — zero module cost.
 *
 * Layer order matters: cheapest first. A typical stack:
 *   1. CsrfTokenLayer      (timing-safe string compare — microseconds)
 *   2. [Auth module layer] (token verify — milliseconds, optional)
 *
 * The kernel ships exactly ONE layer: CsrfTokenLayer. IP filtering and rate
 * limiting are deliberately NOT kernel layers — they are opt-in route filters
 * from plugins/SecurityFilters ('throttle', 'shield'), so a route pays for them
 * only when it declares them. Do not reintroduce them here.
 */
final class SecurityGateway
{
    /** @param SecurityLayerContract[] $layers */
    public function __construct(
        private readonly array $layers
    ) {}

    public function inspect(Request $request): SecurityVerdict
    {
        foreach ($this->layers as $layer) {
            $verdict = $layer->check($request);

            if ($verdict->isDenied()) {
                return $verdict;  // Short-circuit — nothing else runs
            }

            // If this layer resolved an identity, attach it to the request
            if ($verdict->identity() !== null) {
                $request = $request->withIdentity($verdict->identity());
            }
        }

        return SecurityVerdict::allow($request);
    }
}

