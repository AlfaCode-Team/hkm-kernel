<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityVerdict;

/**
 * A security layer that allows everything, and optionally attaches an Identity.
 *
 * BindSecurityStage refuses to boot a kernel with an EMPTY layer list — a
 * deliberate fail-closed choice, since an application that forgot to configure
 * security should not quietly serve traffic. A ground still has to boot, so it
 * installs this when the test supplied no layers of its own.
 *
 * It is a stand-in for the layers a project wires, NOT a way to skip them: a
 * test that is ABOUT authentication passes the real layer to
 * {@see \AlfacodeTeam\Ground\PluginGround::security()}, and this one is then
 * never installed.
 *
 * Attaching the Identity here rather than on the Request is what makes route
 * filters work: RequireAuthStage reads the identity the GATEWAY resolved, so an
 * identity bolted onto the request afterwards would be invisible to it.
 */
final class OpenGateLayer implements SecurityLayerContract
{
    /** @var list<string> paths inspected, in order */
    public array $seen = [];

    public function __construct(
        private readonly ?Identity $identity = null,
    ) {}

    public function check(Request $request): SecurityVerdict
    {
        $this->seen[] = $request->path();

        // The contract is explicit that a layer NEVER throws — it returns a
        // verdict. Attaching an identity is done by allowing the request that
        // already carries it.
        return SecurityVerdict::allow(
            $this->identity === null ? $request : $request->withIdentity($this->identity),
        );
    }
}
