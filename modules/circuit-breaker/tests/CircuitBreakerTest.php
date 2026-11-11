<?php

declare(strict_types=1);

namespace AlfacodeTeam\CircuitBreaker\Tests;

use AlfacodeTeam\CircuitBreaker\BreakerPolicy;
use AlfacodeTeam\CircuitBreaker\CircuitBreaker;
use AlfacodeTeam\CircuitBreaker\CircuitState;
use AlfacodeTeam\CircuitBreaker\Exception\CircuitOpenException;
use AlfacodeTeam\CircuitBreaker\StateStore;
use AlfacodeTeam\CircuitBreaker\Store\InMemoryStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The state machine, driven by a controllable clock.
 *
 * Every interesting behaviour here is time-dependent, so the clock is injected
 * and advanced by hand. A test that slept out a 30-second cool-down would be a
 * test nobody runs.
 */
#[CoversClass(CircuitBreaker::class)]
#[CoversClass(BreakerPolicy::class)]
#[CoversClass(CircuitState::class)]
#[CoversClass(CircuitOpenException::class)]
#[CoversClass(InMemoryStateStore::class)]
final class CircuitBreakerTest extends TestCase
{
    private int $now = 1_000_000;

    private InMemoryStateStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryStateStore(fn (): int => $this->now);
    }

    private function breaker(
        ?BreakerPolicy $policy = null,
        ?callable $onStateChange = null,
        ?StateStore $store = null,
    ): CircuitBreaker {
        return new CircuitBreaker(
            'test.dependency',
            $store ?? $this->store,
            $policy ?? new BreakerPolicy(threshold: 3, window: 60, cooldown: 30),
            fn (): int => $this->now,
            $onStateChange,
        );
    }

    /** @param callable(): mixed $op */
    private function failTimes(CircuitBreaker $breaker, int $times, ?callable $op = null): void
    {
        $op ??= static fn () => throw new RuntimeException('dependency timeout');

        for ($i = 0; $i < $times; $i++) {
            try {
                $breaker->call($op);
            } catch (RuntimeException) {
                // expected
            }
        }
    }

    // ── Closed ───────────────────────────────────────────────────────────────

    public function test_closed_circuit_passes_the_call_through_and_returns_its_value(): void
    {
        $breaker = $this->breaker();

        $this->assertSame('payload', $breaker->call(static fn (): string => 'payload'));
        $this->assertSame(CircuitState::Closed, $breaker->state());
        $this->assertTrue($breaker->isAvailable());
    }

    public function test_failures_below_the_threshold_leave_the_circuit_closed(): void
    {
        $breaker = $this->breaker();

        $this->failTimes($breaker, 2);

        $this->assertSame(CircuitState::Closed, $breaker->state());
    }

    public function test_the_original_exception_propagates_unwrapped(): void
    {
        $breaker = $this->breaker();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dependency timeout');

        $breaker->call(static fn () => throw new RuntimeException('dependency timeout'));
    }

    // ── Tripping ─────────────────────────────────────────────────────────────

    public function test_threshold_failures_open_the_circuit(): void
    {
        $breaker = $this->breaker();

        $this->failTimes($breaker, 3);

        $this->assertSame(CircuitState::Open, $breaker->state());
        $this->assertFalse($breaker->isAvailable());
    }

    /**
     * The whole point: once open, the dependency is not contacted at all. If the
     * operation still ran, the breaker would be saving nothing — the socket
     * timeout is the cost it exists to avoid.
     */
    public function test_an_open_circuit_does_not_call_the_dependency(): void
    {
        $breaker = $this->breaker();
        $this->failTimes($breaker, 3);

        $called = false;

        try {
            $breaker->call(function () use (&$called): string {
                $called = true;

                return 'should never run';
            });
            $this->fail('Expected CircuitOpenException.');
        } catch (CircuitOpenException $e) {
            $this->assertSame('test.dependency', $e->circuit);
            $this->assertGreaterThan(0, $e->retryAfter);
        }

        $this->assertFalse($called, 'The dependency was called despite an open circuit.');
    }

    public function test_retry_after_counts_down_as_the_cooldown_elapses(): void
    {
        $breaker = $this->breaker();
        $this->failTimes($breaker, 3);

        $this->assertSame(30, $breaker->retryAfter());

        $this->now += 10;
        $this->assertSame(20, $breaker->retryAfter());
    }

    // ── The failure predicate ────────────────────────────────────────────────

    /**
     * A remote API answering 422 to bad input is the dependency working. If that
     * counted, a run of invalid user submissions would take out a healthy
     * service — the single most common way a naive breaker makes things worse.
     */
    public function test_exceptions_excluded_by_the_predicate_never_trip_the_circuit(): void
    {
        $policy = (new BreakerPolicy(threshold: 2))->failingOn(ConnectionFailed::class);
        $breaker = $this->breaker($policy);

        $this->failTimes($breaker, 10, static fn () => throw new ValidationFailed('422 unprocessable'));

        $this->assertSame(CircuitState::Closed, $breaker->state());
    }

    public function test_exceptions_matched_by_the_predicate_do_trip_the_circuit(): void
    {
        $policy = (new BreakerPolicy(threshold: 2))->failingOn(ConnectionFailed::class);
        $breaker = $this->breaker($policy);

        $this->failTimes($breaker, 2, static fn () => throw new ConnectionFailed('connect timeout'));

        $this->assertSame(CircuitState::Open, $breaker->state());
    }

    /** A classifier that throws must not be able to make a circuit un-trippable. */
    public function test_a_throwing_predicate_counts_the_failure(): void
    {
        $policy = new BreakerPolicy(
            threshold: 2,
            failsOn: static fn (\Throwable $e): bool => throw new RuntimeException('classifier is broken'),
        );
        $breaker = $this->breaker($policy);

        $this->failTimes($breaker, 2);

        $this->assertSame(CircuitState::Open, $breaker->state());
    }

    // ── Half-open ────────────────────────────────────────────────────────────

    public function test_the_circuit_becomes_half_open_once_the_cooldown_elapses(): void
    {
        $breaker = $this->breaker();
        $this->failTimes($breaker, 3);

        $this->now += 30;

        $this->assertSame(CircuitState::HalfOpen, $breaker->state());
        $this->assertTrue($breaker->isAvailable());
    }

    public function test_a_successful_trial_closes_the_circuit(): void
    {
        $breaker = $this->breaker();
        $this->failTimes($breaker, 3);
        $this->now += 30;

        $this->assertSame('recovered', $breaker->call(static fn (): string => 'recovered'));
        $this->assertSame(CircuitState::Closed, $breaker->state());
    }

    /**
     * Recovery is tested by a trickle, not by the full load arriving the instant
     * the cool-down elapses. The second caller is refused even though the
     * circuit is half-open.
     */
    public function test_only_the_configured_number_of_trials_is_admitted(): void
    {
        $breaker = $this->breaker();
        $this->failTimes($breaker, 3);
        $this->now += 30;

        $secondCalled = false;

        // First caller holds the single trial slot and hangs onto it by failing.
        try {
            $breaker->call(static fn () => throw new RuntimeException('still down'));
        } catch (RuntimeException) {
        }

        // Circuit re-opened, so this one is refused outright.
        try {
            $breaker->call(function () use (&$secondCalled): string {
                $secondCalled = true;

                return 'x';
            });
            $this->fail('Expected CircuitOpenException.');
        } catch (CircuitOpenException) {
        }

        $this->assertFalse($secondCalled);
    }

    public function test_a_second_concurrent_trial_is_refused_while_the_first_is_in_flight(): void
    {
        $breaker = $this->breaker();
        $this->failTimes($breaker, 3);
        $this->now += 30;

        $inner = $this->breaker();
        $secondCalled = false;

        // The outer call holds the trial slot while it runs; a second breaker
        // over the same shared store must not also be admitted.
        $breaker->call(function () use ($inner, &$secondCalled): string {
            try {
                $inner->call(function () use (&$secondCalled): string {
                    $secondCalled = true;

                    return 'x';
                });
            } catch (CircuitOpenException) {
                // expected
            }

            return 'outer';
        });

        $this->assertFalse($secondCalled, 'A second trial was admitted while one was in flight.');
    }

    /**
     * A failed trial re-opens immediately with a fresh cool-down. Counting it as
     * one ordinary failure would mean a still-dead dependency gets probed
     * threshold-times per cool-down instead of once.
     */
    public function test_a_failed_trial_reopens_the_circuit_with_a_fresh_cooldown(): void
    {
        $breaker = $this->breaker();
        $this->failTimes($breaker, 3);
        $this->now += 30;

        $this->assertSame(CircuitState::HalfOpen, $breaker->state());

        $this->failTimes($breaker, 1);

        $this->assertSame(CircuitState::Open, $breaker->state());
        $this->assertSame(30, $breaker->retryAfter());
    }

    // ── Rolling window ───────────────────────────────────────────────────────

    /**
     * Failures accumulate over a window rather than needing to be consecutive —
     * three timeouts in a minute is a problem even with successes between them —
     * but they do expire, so an old failure cannot combine with a new one years
     * later to trip the circuit.
     */
    public function test_failures_outside_the_window_do_not_accumulate(): void
    {
        $breaker = $this->breaker();

        $this->failTimes($breaker, 2);
        $this->now += 61;
        $this->failTimes($breaker, 2);

        $this->assertSame(CircuitState::Closed, $breaker->state());
    }

    public function test_a_success_does_not_reset_the_window(): void
    {
        $breaker = $this->breaker();

        $this->failTimes($breaker, 2);
        $breaker->call(static fn (): string => 'ok');
        $this->failTimes($breaker, 1);

        $this->assertSame(CircuitState::Open, $breaker->state());
    }

    // ── Judging results, not just throws ─────────────────────────────────────

    /**
     * The 503 case. An HTTP client returns a server error as an ordinary
     * response, so an exception-only breaker would never notice the most
     * important unhealthy signal there is.
     */
    public function test_unhealthy_results_trip_the_circuit_without_any_exception(): void
    {
        $breaker = $this->breaker();
        $unhealthy = static fn (int $status): bool => $status < 500;

        for ($i = 0; $i < 3; $i++) {
            $breaker->callWith(static fn (): int => 503, $unhealthy);
        }

        $this->assertSame(CircuitState::Open, $breaker->state());
    }

    /** A recorded failure changes the circuit, never the caller's answer. */
    public function test_an_unhealthy_result_is_still_returned_to_the_caller(): void
    {
        $breaker = $this->breaker();

        $status = $breaker->callWith(static fn (): int => 503, static fn (int $s): bool => $s < 500);

        $this->assertSame(503, $status);
    }

    /** A 404 is the dependency working correctly and must not count. */
    public function test_healthy_results_leave_the_circuit_closed(): void
    {
        $breaker = $this->breaker();
        $isHealthy = static fn (int $status): bool => $status < 500;

        for ($i = 0; $i < 10; $i++) {
            $breaker->callWith(static fn (): int => 404, $isHealthy);
        }

        $this->assertSame(CircuitState::Closed, $breaker->state());
    }

    public function test_a_throwing_health_predicate_is_treated_as_unhealthy(): void
    {
        $breaker = $this->breaker();

        for ($i = 0; $i < 3; $i++) {
            $breaker->callWith(
                static fn (): int => 200,
                static fn (int $s): bool => throw new RuntimeException('predicate is broken'),
            );
        }

        $this->assertSame(CircuitState::Open, $breaker->state());
    }

    public function test_an_unhealthy_trial_result_reopens_the_circuit(): void
    {
        $breaker = $this->breaker();
        $this->failTimes($breaker, 3);
        $this->now += 30;
        $this->assertSame(CircuitState::HalfOpen, $breaker->state());

        $breaker->callWith(static fn (): int => 503, static fn (int $s): bool => $s < 500);

        $this->assertSame(CircuitState::Open, $breaker->state());
        $this->assertSame(30, $breaker->retryAfter());
    }

    public function test_a_healthy_trial_result_closes_the_circuit(): void
    {
        $breaker = $this->breaker();
        $this->failTimes($breaker, 3);
        $this->now += 30;

        $breaker->callWith(static fn (): int => 200, static fn (int $s): bool => $s < 500);

        $this->assertSame(CircuitState::Closed, $breaker->state());
    }

    // ── Observability ────────────────────────────────────────────────────────

    public function test_state_changes_are_announced(): void
    {
        /** @var list<array{string, string, string}> $events */
        $events = [];
        $breaker = $this->breaker(onStateChange: static function (string $name, CircuitState $from, CircuitState $to) use (&$events): void {
            $events[] = [$name, $from->value, $to->value];
        });

        $this->failTimes($breaker, 3);
        $this->now += 30;
        $breaker->call(static fn (): string => 'recovered');

        $this->assertSame([
            ['test.dependency', 'closed', 'open'],
            ['test.dependency', 'half_open', 'closed'],
        ], $events);
    }

    public function test_a_throwing_listener_does_not_break_the_call(): void
    {
        $breaker = $this->breaker(onStateChange: static fn (): never => throw new RuntimeException('metrics down'));

        $this->failTimes($breaker, 3);

        $this->assertSame(CircuitState::Open, $breaker->state());
    }

    // ── Degradation ──────────────────────────────────────────────────────────

    /**
     * A protective component whose own outage takes down the thing it protects
     * has become the attack. With the store broken the breaker disappears and
     * every call is allowed through.
     */
    public function test_a_broken_store_fails_open(): void
    {
        $breaker = $this->breaker(store: new ExplodingStore());

        $this->failTimes($breaker, 10);

        $this->assertSame(CircuitState::Closed, $breaker->state());
        $this->assertSame('served', $breaker->call(static fn (): string => 'served'));
    }

    public function test_reset_closes_an_open_circuit(): void
    {
        $breaker = $this->breaker();
        $this->failTimes($breaker, 3);
        $this->assertSame(CircuitState::Open, $breaker->state());

        $breaker->reset();

        $this->assertSame(CircuitState::Closed, $breaker->state());
        $this->assertSame(0, $breaker->retryAfter());
    }

    // ── Policy validation ────────────────────────────────────────────────────

    public function test_a_nonsensical_policy_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BreakerPolicy(threshold: 0);
    }

    public function test_a_circuit_must_be_named(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CircuitBreaker('', $this->store);
    }
}

// ── Named fakes ──────────────────────────────────────────────────────────────
// Named classes rather than anonymous mocks, so a failure message says what the
// thing was instead of printing a generated class name.

final class ConnectionFailed extends RuntimeException {}

final class ValidationFailed extends RuntimeException {}

/** Every operation throws — stands in for a Redis outage. */
final class ExplodingStore implements StateStore
{
    public function increment(string $key, int $ttl): int
    {
        throw new RuntimeException('store unavailable');
    }

    public function get(string $key): ?string
    {
        throw new RuntimeException('store unavailable');
    }

    public function set(string $key, string $value, int $ttl): void
    {
        throw new RuntimeException('store unavailable');
    }

    public function delete(string $key): void
    {
        throw new RuntimeException('store unavailable');
    }
}
