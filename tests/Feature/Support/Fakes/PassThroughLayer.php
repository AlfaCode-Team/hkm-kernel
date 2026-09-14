<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityVerdict;

/**
 * Allows everything, preserving whatever identity the request already carries.
 *
 * The kernel REFUSES TO BOOT with no security layer — deliberately, and the
 * feature suite proves it (see BootFailureTest). So the harness supplies this
 * one by default rather than relaxing the check: a test about routing should not
 * have to model authentication, and a test about authentication should say so by
 * passing its own layers.
 *
 * It also counts calls, which is how the suite asserts the gateway runs BEFORE
 * any module is loaded — the central claim of the architecture.
 */
final class PassThroughLayer implements SecurityLayerContract
{
    public int $calls = 0;

    /** @var list<string> paths this layer saw, in order */
    public array $saw = [];

    public function check(Request $request): SecurityVerdict
    {
        $this->calls++;
        $this->saw[] = $request->path();

        return SecurityVerdict::allow($request);
    }
}
