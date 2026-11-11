<?php

declare(strict_types=1);

namespace AlfacodeTeam\Pools\Tests;

use AlfacodeTeam\Pools\Exception\UnknownPoolException;
use AlfacodeTeam\Pools\Group;
use AlfacodeTeam\Pools\Pool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Named pools addressed as one thing.
 *
 * The LRU bound is the reason this is not just an array: a long-lived worker
 * that has ever served N tenants would otherwise hold N pools' worth of sockets
 * forever, even when a handful are ever active at once.
 */
#[CoversClass(Group::class)]
final class GroupTest extends TestCase
{
    protected function setUp(): void
    {
        Resource_::$created = 0;
    }

    public function testItHoldsPoolsByName(): void
    {
        $group = new Group();
        $group->add($this->pool('central'))->add($this->pool('tenant:acme'));

        self::assertTrue($group->has('central'));
        self::assertSame('tenant:acme', $group->get('tenant:acme')->name);
        self::assertSame(2, $group->count());
    }

    public function testAnUnknownNameNamesWhatIsRegistered(): void
    {
        $group = new Group();
        $group->add($this->pool('central'));

        try {
            $group->get('tenant:ghost');
            self::fail('Expected an UnknownPoolException.');
        } catch (UnknownPoolException $e) {
            self::assertStringContainsString('tenant:ghost', $e->getMessage());
            self::assertStringContainsString('central', $e->getMessage(), 'say what IS registered');
        }
    }

    public function testRememberCreatesOnFirstAskAndReusesAfterwards(): void
    {
        $group = new Group();
        $calls = 0;

        $factory = function () use (&$calls): Pool {
            $calls++;

            return $this->pool('tenant:acme');
        };

        $first  = $group->remember('tenant:acme', $factory);
        $second = $group->remember('tenant:acme', $factory);

        self::assertSame($first, $second);
        self::assertSame(1, $calls);
    }

    /**
     * A factory that names its pool differently would produce a group whose
     * `get()` and `remove()` silently miss the thing it just created.
     */
    public function testRememberRefusesAFactoryThatMisnamesThePool(): void
    {
        $group = new Group();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must name the pool exactly as it is addressed');

        $group->remember('tenant:acme', fn (): Pool => $this->pool('something-else'));
    }

    // ── The bound ─────────────────────────────────────────────────────────────

    public function testItEvictsTheLeastRecentlyUsedPoolAtTheCap(): void
    {
        $group = new Group(maxPools: 2);

        $group->add($this->pool('a'))->add($this->pool('b'))->add($this->pool('c'));

        self::assertSame(['b', 'c'], $group->names());
        self::assertFalse($group->has('a'));
    }

    /**
     * Eviction by CREATION order would throw away the pool serving most of the
     * traffic — a tenant host that every request hits, evicted because it was
     * registered first.
     */
    public function testUseMakesAPoolTheLastToBeEvicted(): void
    {
        $group = new Group(maxPools: 2);
        $group->add($this->pool('a'))->add($this->pool('b'));

        $group->get('a'); // 'a' is now the most recently used

        $group->add($this->pool('c'));

        self::assertSame(['a', 'c'], $group->names(), "'b' was the idle one");
    }

    public function testAnEvictedPoolIsClosed(): void
    {
        $group = new Group(maxPools: 1);

        $first = $this->pool('a');
        $group->add($first);
        $connection = $first->pop();
        $group->reclaim();

        $group->add($this->pool('b'));

        self::assertTrue($first->isClosed());
        self::assertTrue($connection->resource->closed, 'its sockets go with it');
    }

    public function testReplacingANameIsNotGrowth(): void
    {
        $group = new Group(maxPools: 2);
        $group->add($this->pool('a'))->add($this->pool('b'));

        $group->add($this->pool('a'));

        self::assertSame(2, $group->count());
        self::assertTrue($group->has('b'), 'replacing a must not have evicted b');
    }

    public function testZeroMeansUnbounded(): void
    {
        $group = new Group(maxPools: 0);

        for ($i = 0; $i < 25; $i++) {
            $group->add($this->pool('p' . $i));
        }

        self::assertSame(25, $group->count());
    }

    public function testANegativeCapIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Group(maxPools: -1);
    }

    // ── Lifecycle across every pool ───────────────────────────────────────────

    public function testUseBorrowsFromEveryNamedPoolAndReturnsThemAll(): void
    {
        $group = new Group();
        $group->add($this->pool('a'))->add($this->pool('b'));

        $ids = $group->use(['a', 'b'], static fn (Resource_ $x, Resource_ $y): array => [$x->id, $y->id]);

        self::assertCount(2, $ids);
        self::assertNotSame($ids[0], $ids[1]);
        self::assertSame(2, $group->get('a')->count(), 'a returned');
        self::assertSame(2, $group->get('b')->count(), 'b returned');
    }

    /**
     * The reason this is nested use() calls rather than a loop that acquires
     * then releases: a throw anywhere — in the callback OR in a later
     * acquisition — must still return everything already borrowed.
     */
    public function testUseReturnsEverythingWhenTheCallbackThrows(): void
    {
        $group = new Group();
        $group->add($this->pool('a'))->add($this->pool('b'));

        try {
            $group->use(['a', 'b'], static fn (): never => throw new \RuntimeException('boom'));
            self::fail('Expected the callback’s exception to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(2, $group->get('a')->count());
        self::assertSame(2, $group->get('b')->count());
    }

    public function testUseReturnsTheFirstResourceWhenTheSecondPoolIsExhausted(): void
    {
        $group = new Group();
        $group->add($this->pool('a'))->add($this->pool('b', size: 1));

        // Exhaust 'b' before asking for both.
        $held = $group->get('b')->pop();

        try {
            $group->use(['a', 'b'], static fn (): string => 'never');
            self::fail('Expected the exhausted pool to refuse.');
        } catch (\AlfacodeTeam\Pools\Exception\PoolTimeoutException) {
            // expected
        }

        self::assertSame(2, $group->get('a')->count(), "a's resource must not be stranded");
        self::assertNotNull($held);
    }

    public function testReclaimAndMaintainReachEveryPool(): void
    {
        $group = new Group();
        $group->add($this->pool('a'))->add($this->pool('b'));

        $a = $group->get('a')->pop();
        $b = $group->get('b')->pop();

        $group->reclaim();

        self::assertSame(2, $group->get('a')->count());
        self::assertSame(2, $group->get('b')->count());

        $group->maintain();

        self::assertSame(1, $a->resource->pings);
        self::assertSame(1, $b->resource->pings);
    }

    public function testRemoveClosesAndForgets(): void
    {
        $group = new Group();
        $pool  = $this->pool('a');
        $group->add($pool);

        $group->remove('a');

        self::assertFalse($group->has('a'));
        self::assertTrue($pool->isClosed());
    }

    public function testRemovingAnUnknownNameIsIgnored(): void
    {
        $group = new Group();

        $group->remove('nope');

        self::assertSame(0, $group->count());
    }

    public function testCloseClosesEveryPool(): void
    {
        $group = new Group();
        $a     = $this->pool('a');
        $b     = $this->pool('b');
        $group->add($a)->add($b);

        $group->close();

        self::assertSame(0, $group->count());
        self::assertTrue($a->isClosed());
        self::assertTrue($b->isClosed());
    }

    public function testStatsCoverEveryPool(): void
    {
        $group = new Group();
        $group->add($this->pool('a'))->add($this->pool('b'));

        self::assertSame(['a', 'b'], array_column($group->stats(), 'name'));
    }

    /** @return Pool<Resource_> */
    private function pool(string $name, int $size = 2): Pool
    {
        return new Pool($name, $size, static fn (): Resource_ => new Resource_(), 0.0);
    }
}
