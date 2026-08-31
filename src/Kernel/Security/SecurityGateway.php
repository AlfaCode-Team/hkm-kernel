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
        $last = \count($this->layers) - 1;

        foreach ($this->layers as $i => $layer) {
            $verdict = $layer->check($request);

            if ($verdict->isDenied()) {
                return $verdict;  // Short-circuit — nothing else runs
            }

            // If this layer resolved an identity, attach it so LATER layers can
            // see it (a role check after authentication).
            //
            // Only when there IS a later layer. withIdentity() deep-clones all
            // seven parameter bags, and on the last layer that clone exists for
            // exactly one statement: the allow() below, which reads the identity
            // straight off it. SecurityStage then clones a second time to put the
            // identity on the request the pipeline actually carries. The typical
            // stack — CSRF, then an Auth layer that resolves the identity last —
            // therefore paid for a whole request copy nothing ever read.
            $identity = $verdict->identity();

            if ($identity !== null) {
                if ($i === $last) {
                    return SecurityVerdict::allowWithIdentity($identity);
                }

                $request = $request->withIdentity($identity);
            }
        }

        return SecurityVerdict::allow($request);
    }
}

