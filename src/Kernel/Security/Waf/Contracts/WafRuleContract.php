<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\WafMatch;

/**
 * One family of checks the WafLayer runs against a request.
 *
 * A rule is app-lifetime and shared by every request a worker serves, so it
 * must be stateless: no properties written in inspect(), no statics. It must not
 * throw either — WafLayer fails OPEN on an exception, so a throwing rule is a
 * rule that never blocks anything.
 */
interface WafRuleContract
{
    /** Stable id used in logs and in WafLayer's per-rule exemptions. */
    public function id(): string;

    /** The first thing wrong with the request, or null when it is clean. */
    public function inspect(Request $request): ?WafMatch;
}
