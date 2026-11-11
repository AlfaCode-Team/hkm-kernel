<?php

declare(strict_types=1);

namespace AlfacodeTeam\CircuitBreaker;

/**
 * Where a circuit is in its lifecycle.
 *
 * Closed → (threshold failures) → Open → (cool-down elapses) → HalfOpen
 *   HalfOpen → (trial succeeds) → Closed
 *   HalfOpen → (trial fails)    → Open, cool-down restarts
 *
 * HalfOpen is the state that makes a breaker useful rather than merely a switch.
 * Without it, recovery means either letting the full load back onto a dependency
 * that has only just come up — which knocks it straight back down — or waiting
 * for a human. HalfOpen admits a bounded number of trial calls and lets the
 * answer decide.
 */
enum CircuitState: string
{
    /** Calls pass through. Failures are being counted. */
    case Closed = 'closed';

    /** Calls are refused immediately, without touching the dependency. */
    case Open = 'open';

    /** Cool-down has elapsed; a bounded number of trial calls may pass through. */
    case HalfOpen = 'half_open';
}
