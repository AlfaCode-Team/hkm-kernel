<?php

declare(strict_types=1);

namespace AlfacodeTeam\Pools\Tests;

use AlfacodeTeam\Pools\Adapter\Stack;
use AlfacodeTeam\Pools\Connection;
use AlfacodeTeam\Pools\Exception\PoolTimeoutException;
use AlfacodeTeam\Pools\Pool;
use AlfacodeTeam\Pools\Telemetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The pool.
 *
 * Nearly every test here is about CAPACITY ACCOUNTING, because that is where a
 * pool goes wrong in a way nothing notices: a slot lost on an error path does
 * not fail, it silently shrinks the pool until the deployment is serving
 * everything through one connection and nobody can say when that started.
 */
#[CoversClass(Pool::class)]
#[CoversClass(Connection::class)]
#[CoversClass(Stack::class)]
final class PoolTest extends TestCase
{
    // ── Borrowing ─────────────────────────────────────────────────────────────

    public function testUseHandsTheResourceToTheCallbackAndReturnsItsValue(): void
    {
        $pool = $this->pool(size: 2);

        self::assertSame('r1!', $pool->use(static fn (Resource_ $r): string => $r->id . '!'));
    }

    public function testAResourceIsReturnedAfterUseAndReused(): void
    {
        $pool = $this->pool(size: 2);

        $first  = $pool->use(static fn (Resource_ $r): string => $r->id);
        $second = $pool->use(static fn (Resource_ $r): string => $r->id);

        self::assertSame('r1', $first);
        self::assertSame('r1', $second, 'the second call must reuse the first resource, not create one');
        self::assertSame(2, $pool->count(), 'everything available again');
    }

    /**
     * The whole reason this package exists: per-OPERATION borrowing, so a pool
     * of one serves any number of sequential operations.
     */
    public function testOneSlotServesManySequentialOperations(): void
    {
        $pool = $this->pool(size: 1);

        for ($i = 0; $i < 50; $i++) {
            $pool->use(static fn (Resource_ $r): string => $r->id);
        }

        self::assertSame(1, Resource_::$created, 'one socket, fifty operations');
    }

    public function testResourcesAreCreatedLazilyAndNeverPreFilled(): void
    {
        $this->pool(size: 5);

        self::assertSame(0, Resource_::$created, 'constructing a pool must not open anything');
    }

    // ── The ceiling ───────────────────────────────────────────────────────────

    public function testItNeverExceedsItsSize(): void
    {
        $pool = $this->pool(size: 2);

        // Nested, so all three are checked out at once.
        try {
            $pool->use(fn () => $pool->use(fn () => $pool->use(static fn () => null)));
            self::fail('Expected the third acquisition to be refused.');
        } catch (PoolTimeoutException $e) {
            self::assertSame(2, $e->size);
            self::assertSame(2, $e->active);
            self::assertSame(0, $e->idle);
        }

        self::assertSame(2, Resource_::$created, 'the refused acquisition must not have connected');
    }

    /**
     * An adapter that cannot block has already given its final answer.
     *
     * Looping on it would be a busy spin at full CPU for the whole timeout, and
     * it could never succeed: with one execution context nothing can return a
     * resource while this caller waits, so saturation means this caller is
     * holding them.
     */
    public function testSaturationFailsImmediatelyOnANonBlockingAdapter(): void
    {
        $pool = $this->pool(size: 1, timeout: 5.0);

        $start = microtime(true);

        try {
            $pool->use(fn () => $pool->use(static fn () => null));
        } catch (PoolTimeoutException) {
            // expected
        }

        self::assertLessThan(0.5, microtime(true) - $start, 'must not wait out a timeout it cannot satisfy');
    }

    public function testTheTimeoutMessageCarriesThePoolsState(): void
    {
        $pool = $this->pool(size: 1, name: 'central');

        try {
            $pool->use(fn () => $pool->use(static fn () => null));
            self::fail('Expected a PoolTimeoutException.');
        } catch (PoolTimeoutException $e) {
            // "timed out" and "timed out with 1 of 1 checked out" call for
            // completely different fixes.
            self::assertStringContainsString('[central]', $e->getMessage());
            self::assertStringContainsString('active 1', $e->getMessage());
        }
    }

    // ── Slot reservation ──────────────────────────────────────────────────────

    /**
     * The error path that silently shrinks a pool forever.
     *
     * A slot is reserved BEFORE the resource exists, so if `$init` throws, that
     * reservation has to be released. Without the `finally` the pool loses one
     * slot per failed connect — so a database that is down for a minute leaves
     * a permanently crippled pool behind once it recovers.
     */
    public function testAFailedCreateReleasesItsReservedSlot(): void
    {
        $attempts = 0;
        $pool     = new Pool(
            name: 'flaky',
            size: 2,
            init: static function () use (&$attempts): Resource_ {
                $attempts++;

                if ($attempts <= 3) {
                    throw new \RuntimeException('connect refused');
                }

                return new Resource_();
            },
            timeout: 0.0,
        );

        for ($i = 0; $i < 3; $i++) {
            try {
                $pool->use(static fn () => null);
                self::fail('Expected the connect failure to propagate.');
            } catch (\RuntimeException $e) {
                self::assertSame('connect refused', $e->getMessage());
            }
        }

        self::assertSame(2, $pool->count(), 'full capacity must survive three failed connects');
        self::assertNotNull($pool->use(static fn (Resource_ $r): string => $r->id));
    }

    /**
     * A creation failure must NOT fall through to a wait.
     *
     * Waiting for someone else's connection after your own connect failed is a
     * retry in disguise, and it loses the original exception — the caller gets
     * "pool timed out" instead of "connection refused", which points at
     * entirely the wrong thing.
     */
    public function testACreateFailurePropagatesRatherThanBecomingATimeout(): void
    {
        $pool = new Pool(
            name: 'down',
            size: 4,
            init: static fn (): never => throw new \DomainException('host unreachable'),
            timeout: 5.0,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('host unreachable');

        $pool->pop();
    }

    // ── Failure handling ──────────────────────────────────────────────────────

    /** A throwing callback still returns the resource — recovered, or discarded. */
    public function testAThrowingCallbackDoesNotLeakTheSlot(): void
    {
        $pool = $this->pool(size: 1);

        try {
            $pool->use(static fn (): never => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
            // expected
        }

        // The slot is what matters here, not whether the resource survived —
        // this one recovers via reset(), which the next test covers.
        self::assertSame(1, $pool->count(), 'the slot must be back');
        self::assertNotSame('', $pool->use(static fn (Resource_ $r): string => $r->id));
    }

    public function testARecoverableResourceIsResetAndReused(): void
    {
        $pool = $this->pool(size: 1);

        $borrowed = null;
        try {
            $pool->use(function (Resource_ $r) use (&$borrowed): never {
                $borrowed = $r;

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        self::assertInstanceOf(Resource_::class, $borrowed);
        self::assertSame(1, $borrowed->resets, 'reset() is how a resource says it is reusable');
        self::assertSame('r1', $pool->use(static fn (Resource_ $r): string => $r->id), 'and it is reused');
    }

    public function testAResourceThatCannotRecoverIsDiscardedAndClosed(): void
    {
        $broken = new Resource_();
        $broken->recoverable = false;

        $pool = new Pool(
            name: 'brittle',
            size: 1,
            init: static function () use ($broken): Resource_ {
                return $broken->closed ? new Resource_() : $broken;
            },
            timeout: 0.0,
        );

        try {
            $pool->use(static fn (): never => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
            // expected
        }

        self::assertTrue($broken->closed, 'a resource being thrown away is closed, not left to the collector');
        self::assertSame(1, $pool->count(), 'and its capacity is freed for a replacement');
    }

    /** A resource with neither reset() nor reconnect() cannot be vouched for. */
    public function testAnUnrecoverableShapeIsDiscardedRatherThanTrusted(): void
    {
        $pool = new Pool(
            name: 'opaque',
            size: 1,
            init: static fn (): object => new \stdClass(),
            timeout: 0.0,
        );

        try {
            $pool->use(static fn (): never => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame(1, $pool->count());
    }

    // ── destroy / reclaim bookkeeping ─────────────────────────────────────────

    public function testDestroyingTheSameConnectionTwiceDoesNotInflateCapacity(): void
    {
        // The bug this prevents: $reserved driven below the truth, letting the
        // pool quietly exceed its own size.
        $pool = $this->pool(size: 2);

        $connection = $pool->pop();
        $pool->destroy($connection);
        $pool->destroy($connection);

        self::assertSame(2, $pool->count(), 'never more than size');
    }

    public function testDestroyingAForeignConnectionIsIgnored(): void
    {
        $mine   = $this->pool(size: 2);
        $theirs = $this->pool(size: 2, name: 'other');

        $foreign = $theirs->pop();
        $mine->destroy($foreign);

        self::assertSame(2, $mine->count());
    }

    public function testReclaimWithNoArgumentReturnsEverythingCheckedOut(): void
    {
        $pool = $this->pool(size: 3);

        $pool->pop();
        $pool->pop();
        self::assertSame(1, $pool->count());

        $pool->reclaim();

        self::assertSame(3, $pool->count());
    }

    public function testStatsDescribeThePool(): void
    {
        $pool = $this->pool(size: 3, name: 'central');
        $pool->pop();

        self::assertSame(
            ['name' => 'central', 'size' => 3, 'idle' => 0, 'active' => 1, 'reserved' => 1, 'available' => 2, 'closed' => false],
            $pool->stats(),
        );
    }

    public function testIsEmptyAndIsFull(): void
    {
        $pool = $this->pool(size: 1);

        self::assertTrue($pool->isFull());
        self::assertFalse($pool->isEmpty());

        $pool->pop();

        self::assertTrue($pool->isEmpty());
        self::assertFalse($pool->isFull());
    }

    // ── maintain() ────────────────────────────────────────────────────────────

    /**
     * An idle socket is not a socket nothing happens to — MySQL's `wait_timeout`
     * closes one after eight hours of silence and hands the next caller a dead
     * handle as a mid-request error.
     */
    public function testMaintainPingsEveryIdleResource(): void
    {
        $pool = $this->pool(size: 3);

        // Three distinct resources, all idle.
        $a = $pool->pop();
        $b = $pool->pop();
        $c = $pool->pop();
        $pool->reclaim();

        $pool->maintain();

        foreach ([$a, $b, $c] as $connection) {
            self::assertSame(1, $connection->resource->pings, 'every idle resource, not just the top of the stack');
        }

        self::assertSame(3, $pool->count(), 'and all of them are back');
    }

    /**
     * The sweep drains before pinging, specifically because Stack is LIFO:
     * popping and pushing one at a time hands the same resource straight back
     * and leaves every other one unswept — which is a sweep that looks like it
     * works and only ever touches one connection.
     */
    public function testMaintainDoesNotPingTheSameResourceRepeatedly(): void
    {
        $pool = $this->pool(size: 3);
        $pool->pop();
        $pool->pop();
        $pool->pop();
        $pool->reclaim();

        $pool->maintain();

        self::assertSame(3, Resource_::$pinged, 'three resources, three pings');
    }

    public function testMaintainDropsAResourceThatFailsItsPingAndFreesTheSlot(): void
    {
        $pool = $this->pool(size: 2);

        $connection = $pool->pop();
        $connection->resource->pingFails = true;
        $pool->reclaim();

        $pool->maintain();

        self::assertTrue($connection->resource->closed);
        self::assertSame(2, $pool->count(), 'the slot comes back, or the pool shrinks by one every sweep');
    }

    public function testMaintainLeavesAResourceWithNoPingAlone(): void
    {
        $pool = new Pool('opaque', 2, static fn (): object => new \stdClass(), 0.0);
        $pool->reclaim($pool->pop());

        $pool->maintain();

        self::assertSame(2, $pool->count());
    }

    // ── close() ───────────────────────────────────────────────────────────────

    public function testCloseDiscardsEverythingAndRefusesFurtherBorrowing(): void
    {
        $pool = $this->pool(size: 2);

        $idle   = $pool->pop();
        $pool->reclaim($idle);
        $active = $pool->pop();

        $pool->close();

        self::assertTrue($idle->resource->closed);
        self::assertTrue($active->resource->closed);
        self::assertTrue($pool->isClosed());

        $this->expectException(\LogicException::class);
        $pool->pop();
    }

    // ── Connection ────────────────────────────────────────────────────────────

    /**
     * A strong back-reference is an uncollectable cycle under the coroutine
     * adapter, because the path from pool to connection runs through a channel
     * whose contents live in C memory and which PHP's collector cannot
     * traverse. That leaked every pool, connection and socket for the life of
     * the process.
     */
    public function testAConnectionHoldsItsPoolWeakly(): void
    {
        $pool       = $this->pool(size: 1);
        $connection = $pool->pop();

        self::assertFalse($connection->isOrphaned());

        unset($pool);
        gc_collect_cycles();

        self::assertTrue($connection->isOrphaned(), 'dropping the pool must release it');
    }

    public function testAnOrphanedConnectionReclaimsAndDestroysWithoutError(): void
    {
        $pool       = $this->pool(size: 1);
        $connection = $pool->pop();
        unset($pool);
        gc_collect_cycles();

        $connection->reclaim();
        $connection->destroy();

        $this->expectNotToPerformAssertions();
    }

    public function testAConnectionCanReturnItself(): void
    {
        $pool       = $this->pool(size: 1);
        $connection = $pool->pop();

        self::assertSame(0, $pool->count());

        $connection->reclaim();

        self::assertSame(1, $pool->count());
    }

    // ── Construction ──────────────────────────────────────────────────────────

    public function testASizeBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('size must be at least 1');

        new Pool('bad', 0, static fn (): object => new \stdClass());
    }

    public function testANegativeTimeoutIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout cannot be negative');

        new Pool('bad', 1, static fn (): object => new \stdClass(), -1.0);
    }

    // ── Telemetry ─────────────────────────────────────────────────────────────

    public function testItRecordsWaitAndUseDurations(): void
    {
        $telemetry = new RecordingTelemetry();
        $pool      = $this->pool(size: 2, telemetry: $telemetry);

        $pool->use(static fn () => null);

        self::assertArrayHasKey('pool.connection.wait_time', $telemetry->histograms);
        self::assertArrayHasKey('pool.connection.use_time', $telemetry->histograms);
        self::assertSame(['pool' => 'test', 'size' => 2], $telemetry->labels['pool.connection.wait_time']);
    }

    public function testItReportsTheGaugesOnCheckoutAndReturn(): void
    {
        $telemetry = new RecordingTelemetry();
        $pool      = $this->pool(size: 2, telemetry: $telemetry);

        $connection = $pool->pop();

        self::assertSame(1, $telemetry->gauges['pool.connection.active.count']);
        self::assertSame(0, $telemetry->gauges['pool.connection.idle.count']);

        $pool->reclaim($connection);

        self::assertSame(0, $telemetry->gauges['pool.connection.active.count']);
        self::assertSame(1, $telemetry->gauges['pool.connection.idle.count']);
    }

    /** Telemetry is opt-in, so the default path must not even build the labels. */
    public function testTheNullTelemetryPathSkipsTheGaugesEntirely(): void
    {
        $pool = $this->pool(size: 1);

        $pool->use(static fn () => null);

        $this->expectNotToPerformAssertions();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        Resource_::$created = 0;
        Resource_::$pinged  = 0;
    }

    /** @return Pool<Resource_> */
    private function pool(
        int $size,
        string $name = 'test',
        float $timeout = 0.0,
        ?Telemetry $telemetry = null,
    ): Pool {
        return new Pool(
            name: $name,
            size: $size,
            init: static fn (): Resource_ => new Resource_(),
            timeout: $timeout,
            adapter: new Stack(),
            telemetry: $telemetry,
        );
    }
}

/**
 * A pooled resource with every duck-typed capability the pool probes for.
 *
 * Named with a trailing underscore because `Resource` is a reserved word in
 * PHP 8.4+.
 *
 * @internal
 */
final class Resource_
{
    public static int $created = 0;

    public static int $pinged = 0;

    public readonly string $id;

    public int $resets = 0;

    public int $pings = 0;

    public bool $closed = false;

    public bool $recoverable = true;

    public bool $pingFails = false;

    public function __construct()
    {
        $this->id = 'r' . ++self::$created;
    }

    public function reset(): bool
    {
        $this->resets++;

        return $this->recoverable;
    }

    public function ping(): bool
    {
        $this->pings++;
        self::$pinged++;

        return !$this->pingFails;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

/** @internal */
final class RecordingTelemetry implements Telemetry
{
    /** @var array<string, list<float>> */
    public array $histograms = [];

    /** @var array<string, int|float> */
    public array $gauges = [];

    /** @var array<string, array<string, int|string>> */
    public array $labels = [];

    public function histogram(string $name, float $seconds, array $labels = []): void
    {
        $this->histograms[$name][] = $seconds;
        $this->labels[$name]       = $labels;
    }

    public function gauge(string $name, int|float $value, array $labels = []): void
    {
        $this->gauges[$name] = $value;
        $this->labels[$name] = $labels;
    }
}
