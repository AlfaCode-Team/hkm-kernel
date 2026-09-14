<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityVerdict;

/**
 * Denies every request with a chosen code, so the suite can prove the framework's
 * headline claim: a denial costs ZERO module loading.
 *
 * Note it RETURNS a verdict rather than throwing — the contract says a layer must
 * never throw, and a fake that threw would test the error pipeline instead of the
 * gateway.
 */
final class DenyingLayer implements SecurityLayerContract
{
    public function __construct(
        private readonly int $code = 403,
        private readonly string $reason = 'denied by test layer',
    ) {}

    public function check(Request $request): SecurityVerdict
    {
        return SecurityVerdict::deny($this->code, $this->reason);
    }
}
