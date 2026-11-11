<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf;

enum WafMode: string
{
    /** A matching request is refused with 403. */
    case Block = 'block';

    /**
     * A matching request is logged and let through, and nobody is banned.
     * Run a new rule set this way first: a false positive then shows up in the
     * log instead of as a user who cannot submit a form.
     */
    case Monitor = 'monitor';
}
