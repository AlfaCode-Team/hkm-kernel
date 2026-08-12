<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * ClockPort — the current time, as an injectable dependency.
 *
 * WHY A PORT FOR SOMETHING SO SMALL
 * ---------------------------------
 * A framework that forbids statics for OpenSwoole safety should not hardcode the
 * one global everything depends on. `time()` is unmockable, so any behaviour
 * that turns on elapsed time can only be tested by actually waiting — which
 * makes those tests slow, flaky, and therefore usually not written at all.
 *
 * The concrete case here is {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\CsrfTokenLayer}:
 * its tokens live in a time window, so testing "an expired token is rejected"
 * meant sleeping past a 12-hour lifetime. With a frozen clock it is three lines.
 * Security behaviour that cannot be tested cheaply does not stay tested.
 *
 * USE SPARINGLY. This is not a licence to inject a clock everywhere — a log
 * timestamp or a filename date has no behaviour worth testing. Reach for it when
 * elapsed time CHANGES A DECISION: expiry, throttling, backoff, scheduling.
 */
interface ClockPort
{
    /** Now, as an immutable instant. */
    public function now(): \DateTimeImmutable;

    /** Now, as a UNIX timestamp — the common case, without an object round trip. */
    public function timestamp(): int;
}
