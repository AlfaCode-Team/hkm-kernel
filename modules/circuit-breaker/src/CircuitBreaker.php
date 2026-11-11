<?php

declare(strict_types=1);

namespace AlfacodeTeam\CircuitBreaker;

use AlfacodeTeam\CircuitBreaker\Exception\CircuitOpenException;
use Throwable;

/**
 * Stops calling a dependency that is already failing.
 *
 * THE PROBLEM
 * -----------
 * A dependency that is DOWN is cheap: the connection is refused and the call
 * returns in microseconds. A dependency that is SICK is what takes an
 * application with it — it accepts the connection and never answers, so every
 * call costs the full socket timeout. Under PHP-FPM, where a worker is occupied
 * for the whole request, thirty-two workers times a five-second timeout is a
 * site that is down because a third party is slow. Retries make it worse by
 * multiplying the load on something already struggling.
 *
 * A breaker converts "wait five seconds and fail" into "fail now", and stops
 * sending traffic to something that cannot serve it. The dependency gets room to
 * recover, and the application spends its workers on requests it can serve.
 *
 * COST ON THE HAPPY PATH
 * ----------------------
 * One store read per call while Closed, and NO writes. That is deliberate: a
 * breaker that wrote to Redis on every successful call would be a throughput
 * regression on the path that matters most. Failure counting is a rolling
 * window rather than consecutive failures precisely so that a success needs no
 * write to reset anything.
 *
 * STORE FAILURES FAIL OPEN
 * ------------------------
 * If the state store itself breaks, every call is allowed through and the
 * breaker effectively disappears. This is the same trade the WAF makes: a
 * protective component whose own outage takes down the thing it protects has
 * become the attack. A breaker is a performance and resilience optimisation, and
 * losing an optimisation must not be an outage.
 *
 * @see StateStore for why state is shared and why increment() must be atomic.
 * @see BreakerPolicy for why the failure predicate is the part that matters.
 */
final class CircuitBreaker
{
    private const PREFIX = 'cb:';

    /** @var (callable(): int)|null */
    private readonly mixed $clock;

    /** @var (callable(string, CircuitState, CircuitState): void)|null */
    private readonly mixed $onStateChange;

    /**
     * @param string $name           identifies the circuit in the store and in logs. One circuit
     *                               per dependency, not per call site: `mail.smtp`, not
     *                               `mail.smtp.welcome-email`. Two circuits over one dependency
     *                               each need their own threshold's worth of failures to notice
     *                               the same outage.
     * @param (callable(): int)|null $clock unix seconds; defaults to time(). Injectable because
     *                               every interesting behaviour here is time-dependent, and a test
     *                               that has to sleep for a 30-second cool-down is a test nobody
     *                               runs.
     * @param (callable(string, CircuitState, CircuitState): void)|null $onStateChange notified when
     *                               the circuit trips or closes. This is where a consumer wires a
     *                               log line and a metric — a circuit tripping is an operational
     *                               event, and a breaker nobody can see is one that silently hides
     *                               an outage.
     */
    public function __construct(
        private readonly string $name,
        private readonly StateStore $store,
        private readonly BreakerPolicy $policy = new BreakerPolicy(),
        ?callable $clock = null,
        ?callable $onStateChange = null,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('A circuit must have a name.');
        }

        $this->clock = $clock;
        $this->onStateChange = $onStateChange;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Run $operation through the circuit.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     *
     * @throws CircuitOpenException when the circuit is open, or when it is
     *         half-open and another process already holds the trial. The
     *         dependency is NOT called in either case.
     * @throws Throwable whatever $operation threw, after recording it. The
     *         original exception is never swallowed or wrapped: a caller must
     *         still be able to tell a timeout from a 404.
     */
    public function call(callable $operation): mixed
    {
        return $this->callWith($operation, static fn (): bool => true);
    }

    /**
     * Run $operation and decide health from its RESULT as well as from throws.
     *
     * WHY THIS EXISTS
     * ---------------
     * Not every dependency failure is an exception, and the most common one is
     * not. An HTTP client hands back a `503` as an ordinary response object —
     * `$response->serverError()` is true and nothing was thrown — so a breaker
     * that watches only for exceptions never notices the single most important
     * signal that a dependency is unhealthy.
     *
     * The alternative, having the caller throw a marker exception to get the
     * failure counted, means either abusing exceptions for control flow on a
     * hot path or forcing every policy to whitelist that marker. This keeps the
     * decision where it belongs: with whoever understands the result type.
     *
     * The result is returned EITHER WAY. A recorded failure changes the
     * circuit's state, never the caller's answer — a gateway asking for a URL
     * still receives the real 503 and can handle it as it always did.
     *
     * @template T
     * @param callable(): T      $operation
     * @param callable(T): bool  $isHealthy false records a failure against the circuit.
     *                           It MUST NOT treat a client error as unhealthy: a
     *                           404 or a 422 is the dependency working. See
     *                           {@see BreakerPolicy} for the same reasoning about
     *                           exceptions.
     * @return T
     *
     * @throws CircuitOpenException when the circuit is open, or half-open and the
     *         trial is already taken. $operation is NOT run.
     * @throws Throwable whatever $operation threw, unwrapped.
     */
    public function callWith(callable $operation, callable $isHealthy): mixed
    {
        $state = $this->state();

        if ($state === CircuitState::Open) {
            throw new CircuitOpenException($this->name, $this->retryAfter());
        }

        if ($state === CircuitState::HalfOpen && !$this->admitTrial()) {
            // Someone else is already probing. Refusing is the point: the whole
            // purpose of half-open is that recovery is tested by a trickle, not
            // by the full load arriving the instant the cool-down elapses.
            throw new CircuitOpenException($this->name, 1);
        }

        try {
            $result = $operation();
        } catch (Throwable $e) {
            if ($this->policy->countsAsFailure($e)) {
                // A failed trial re-opens immediately with a fresh cool-down.
                // Counting it would need the threshold met all over again, so a
                // dependency that is still down would be probed threshold-times
                // per cool-down instead of once.
                $state === CircuitState::HalfOpen ? $this->trip($state) : $this->countFailure($state);
            }

            throw $e;
        }

        // A health predicate that throws is treated as "unhealthy", for the same
        // reason a throwing failure predicate counts: a broken classifier must
        // not be able to make a circuit un-trippable.
        try {
            $healthy = (bool) $isHealthy($result);
        } catch (Throwable) {
            $healthy = false;
        }

        if (!$healthy) {
            $state === CircuitState::HalfOpen ? $this->trip($state) : $this->countFailure($state);

            return $result;
        }

        if ($state === CircuitState::HalfOpen) {
            $this->close($state);
        }

        return $result;
    }

    /**
     * Where the circuit is right now.
     *
     * Derived from the stored trip timestamp rather than stored as a state, so
     * the Open → HalfOpen transition needs no scheduled job and no write: it
     * happens because time passed.
     */
    public function state(): CircuitState
    {
        $openedAt = $this->read($this->key('open'));

        if ($openedAt === null) {
            return CircuitState::Closed;
        }

        return ($this->now() - (int) $openedAt) >= $this->policy->cooldown
            ? CircuitState::HalfOpen
            : CircuitState::Open;
    }

    /**
     * Cheap pre-check for a caller that wants to choose a degraded path without
     * catching an exception.
     *
     * Advisory only — the state can change between this call and the next, and a
     * caller that skips {@see self::call()} on the strength of it is not
     * protected by the breaker at all.
     */
    public function isAvailable(): bool
    {
        return $this->state() !== CircuitState::Open;
    }

    /** Seconds until a trial call will be admitted. At least 1, never negative. */
    public function retryAfter(): int
    {
        $openedAt = $this->read($this->key('open'));

        if ($openedAt === null) {
            return 0;
        }

        return max(1, $this->policy->cooldown - ($this->now() - (int) $openedAt));
    }

    /**
     * Force the circuit closed — an operator escape hatch for when a dependency
     * is known to be healthy and nobody wants to wait out the cool-down.
     */
    public function reset(): void
    {
        $this->close($this->state());
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function countFailure(CircuitState $from): void
    {
        $failures = $this->write(fn (): int => $this->store->increment($this->key('fail'), $this->policy->window), 0);

        if ($failures >= $this->policy->threshold) {
            $this->trip($from);
        }
    }

    private function trip(CircuitState $from): void
    {
        $this->write(function (): void {
            // The TTL must outlive the cool-down, or the key expires before the
            // Open → HalfOpen transition can be observed and the circuit springs
            // straight back to Closed without a trial. Expiry is still a safe
            // fallback (it errs toward availability), but it should be the
            // exception, not the mechanism.
            $this->store->set($this->key('open'), (string) $this->now(), $this->policy->cooldown + $this->policy->window);
            $this->store->delete($this->key('fail'));
            $this->store->delete($this->key('trial'));
        }, null);

        $this->announce($from, CircuitState::Open);
    }

    private function close(CircuitState $from): void
    {
        $this->write(function (): void {
            $this->store->delete($this->key('open'));
            $this->store->delete($this->key('fail'));
            $this->store->delete($this->key('trial'));
        }, null);

        $this->announce($from, CircuitState::Closed);
    }

    /**
     * Claim one of the half-open trial slots.
     *
     * Atomic by way of increment(), so exactly $trialCalls callers across every
     * process get through per cool-down. A read-then-write here would let every
     * worker believe it was the single trial, which is the stampede this exists
     * to prevent.
     */
    private function admitTrial(): bool
    {
        $taken = $this->write(
            fn (): int => $this->store->increment($this->key('trial'), $this->policy->cooldown),
            // A store failure means the slot cannot be claimed. Fail open: let
            // the call through rather than refusing every request because the
            // bookkeeping is unavailable.
            1,
        );

        return $taken <= $this->policy->trialCalls;
    }

    private function announce(CircuitState $from, CircuitState $to): void
    {
        if ($this->onStateChange === null || $from === $to) {
            return;
        }

        try {
            ($this->onStateChange)($this->name, $from, $to);
        } catch (Throwable) {
            // A listener that throws must not turn an observation into an
            // outage. The caller's own result is what matters here.
        }
    }

    private function key(string $suffix): string
    {
        return self::PREFIX . $this->name . ':' . $suffix;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }

    /** A store read that treats an unavailable store as "no circuit recorded". */
    private function read(string $key): ?string
    {
        try {
            return $this->store->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A store write whose failure must not propagate.
     *
     * @template T
     * @param callable(): T $op
     * @param T $onFailure
     * @return T
     */
    private function write(callable $op, mixed $onFailure): mixed
    {
        try {
            return $op();
        } catch (Throwable) {
            return $onFailure;
        }
    }
}
