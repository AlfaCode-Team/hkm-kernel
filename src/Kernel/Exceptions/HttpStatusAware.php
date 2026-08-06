<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions;

/**
 * Lets an exception declare the HTTP status it should produce.
 *
 * WHY
 * ---
 * ErrorStage maps exceptions to status codes with a fixed match on the KERNEL's
 * own exception classes — ValidationException => 422, GatewayException => 502,
 * and so on. Anything else falls to `default => 500`.
 *
 * That is correct for the kernel's own hierarchy but closed to everyone else: a
 * plugin or project exception meaning "this conflicts with existing state" had
 * no way to say 409, and surfaced to the client as an opaque 500 — which also
 * classifies it as CRITICAL severity and pages someone about an ordinary,
 * expected outcome.
 *
 * Implement this on any exception whose status is part of its meaning:
 *
 *     final class SeatTakenException extends \RuntimeException implements HttpStatusAware
 *     {
 *         public function httpStatus(): int { return 409; }
 *     }
 *
 * ErrorStage consults this FIRST, before its built-in match, so it also lets a
 * project override the status of an exception it does not own by subclassing.
 *
 * Only 4xx/5xx are honoured. A 2xx or 3xx here would turn an error path into an
 * apparent success (or a redirect with no Location), so ErrorStage ignores
 * anything outside that range and falls through to its normal mapping.
 */
interface HttpStatusAware
{
    /** The HTTP status for this exception. Must be 4xx or 5xx. */
    public function httpStatus(): int;
}
