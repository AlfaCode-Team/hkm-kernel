<?php

declare(strict_types=1);

namespace AlfacodeTeam\CircuitBreaker;

use Throwable;

/**
 * When a circuit trips, how long it stays open, and — most importantly — which
 * exceptions count as a failure at all.
 *
 * THE FAILURE PREDICATE IS THE PART THAT MATTERS
 * ----------------------------------------------
 * The obvious implementation counts every exception, and it is wrong in a way
 * that only shows up in production. Put a breaker over an HTTP client and a
 * remote API that correctly answers `422 Unprocessable Entity` to a malformed
 * request will trip the circuit — so a run of bad user input takes out a
 * dependency that never had a problem. Put one over a database and a unique-key
 * violation does the same.
 *
 * A breaker exists for failures that mean THE DEPENDENCY IS UNHEALTHY: connect
 * timeouts, read timeouts, connection resets, 502/503/504. A `404`, a `422` and
 * a validation error are the dependency working correctly and saying no. Those
 * must pass straight through, uncounted.
 *
 * {@see self::$failsOn} is therefore required thinking, not an optional tweak.
 * The default counts everything, which is the safe starting point for a
 * dependency whose exceptions you have not yet classified — but a decorator
 * over a real port should always narrow it.
 */
final class BreakerPolicy
{
    /**
     * @param int                    $threshold  failures within $window before the circuit opens.
     * @param int                    $window     seconds over which failures accumulate. A rolling
     *                                           window, not consecutive failures: three timeouts in
     *                                           a minute is a problem even with successes between.
     * @param int                    $cooldown   seconds the circuit stays fully open before any
     *                                           trial call is admitted.
     * @param int                    $trialCalls how many calls may be admitted while HalfOpen. Keep
     *                                           it small — this is load applied to something that
     *                                           was just failing. 1 is usually right.
     * @param (callable(Throwable): bool)|null $failsOn decides whether a thrown exception counts
     *                                           against the circuit. Null counts everything.
     */
    public function __construct(
        public readonly int $threshold = 5,
        public readonly int $window = 60,
        public readonly int $cooldown = 30,
        public readonly int $trialCalls = 1,
        private readonly mixed $failsOn = null,
    ) {
        if ($threshold < 1) {
            throw new \InvalidArgumentException('threshold must be at least 1.');
        }
        if ($window < 1) {
            throw new \InvalidArgumentException('window must be at least 1 second.');
        }
        if ($cooldown < 1) {
            throw new \InvalidArgumentException('cooldown must be at least 1 second.');
        }
        if ($trialCalls < 1) {
            throw new \InvalidArgumentException('trialCalls must be at least 1.');
        }
        if ($failsOn !== null && !\is_callable($failsOn)) {
            throw new \InvalidArgumentException('failsOn must be callable or null.');
        }
    }

    /**
     * Whether this exception is evidence that the dependency is unhealthy.
     *
     * A predicate that itself throws is treated as "counts as a failure": a
     * broken classifier must not be able to make a circuit un-trippable, which
     * would silently disable the breaker exactly when it is needed.
     */
    public function countsAsFailure(Throwable $e): bool
    {
        if ($this->failsOn === null) {
            return true;
        }

        try {
            return (bool) ($this->failsOn)($e);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Narrow an existing policy to specific exception classes.
     *
     * The common case: `BreakerPolicy::new()->failingOn(ConnectException::class,
     * TimeoutException::class)` reads better than writing the closure by hand,
     * and makes the intent greppable.
     *
     * @param class-string<Throwable> ...$classes
     */
    public function failingOn(string ...$classes): self
    {
        return new self(
            $this->threshold,
            $this->window,
            $this->cooldown,
            $this->trialCalls,
            static function (Throwable $e) use ($classes): bool {
                foreach ($classes as $class) {
                    if ($e instanceof $class) {
                        return true;
                    }
                }

                return false;
            },
        );
    }
}
