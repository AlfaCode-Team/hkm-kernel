<?php

declare(strict_types=1);

namespace AlfacodeTeam\CircuitBreaker\Exception;

use RuntimeException;

/**
 * Thrown instead of calling a dependency whose circuit is open.
 *
 * This is the breaker working, not an error in it. A caller has three reasonable
 * responses and should pick one deliberately:
 *
 *   - serve a degraded result (a cached value, an empty list, a queued retry);
 *   - translate it into the layer's own exception — a Gateway should surface a
 *     `GatewayException`, so the rest of the application sees one failure shape
 *     whether the dependency was down or merely refused;
 *   - let it propagate, which turns into a 503 and is the right answer for an
 *     endpoint that cannot function without the dependency.
 *
 * What a caller must NOT do is retry in a loop. The circuit is open precisely
 * because retrying is known not to help yet.
 */
final class CircuitOpenException extends RuntimeException
{
    public function __construct(
        public readonly string $circuit,
        public readonly int $retryAfter,
    ) {
        parent::__construct(\sprintf(
            'Circuit "%s" is open; not calling the dependency. Retry in ~%ds.',
            $circuit,
            $retryAfter,
        ));
    }
}
