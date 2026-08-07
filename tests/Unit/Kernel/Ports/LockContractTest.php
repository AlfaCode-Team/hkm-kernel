<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\LockTimeoutException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\AbstractLock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Lock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Project\Infrastructure\FileCache;
use Project\Infrastructure\FileLock;
use Project\Infrastructure\InMemoryCache;
use Project\Infrastructure\ProcessLocalLock;

/**
 * One contract, every implementation.
 *
 * Each behaviour is asserted against BOTH lock implementations via a data
 * provider, so an adapter cannot quietly diverge from the contract. RedisLock is
 * excluded only because it needs a live server — its distinguishing logic (the
 * Lua compare-and-delete) is what these tests describe in the abstract.
 */
#[CoversClass(AbstractLock::class)]
#[CoversClass(FileLock::class)]
#[CoversClass(ProcessLocalLock::class)]
#[CoversClass(LockTimeoutException::class)]
final class LockContractTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hkm-lock-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (glob($this->dir . '/**/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** @return array<string, array{callable(string, int): Lock}> */
    public static function implementations(): array
    {
        return [
            'process-local' => [static function (string $dir, int $ttl): Lock {
                // One cache instance per factory call would give each lock its own
                // table; the caller shares a cache to test contention.
                static $caches = [];
                $caches[$dir] ??= new InMemoryCache();

                return $caches[$dir]->lock('job:nightly', $ttl);
            }],
            'file' => [static function (string $dir, int $ttl): Lock {
                return (new FileCache($dir))->lock('job:nightly', $ttl);
            }],
        ];
    }

    #[DataProvider('implementations')]
    public function test_acquire_succeeds_when_free(callable $make): void
    {
        self::assertTrue($make($this->dir, 60)->acquire());
    }

    #[DataProvider('implementations')]
    public function test_second_acquirer_is_refused_while_held(callable $make): void
    {
        $first  = $make($this->dir, 60);
        $second = $make($this->dir, 60);

        self::assertTrue($first->acquire(), 'first caller must win');
        self::assertFalse($second->acquire(), 'lock must be mutually exclusive');
    }

    #[DataProvider('implementations')]
    public function test_release_frees_the_lock_for_the_next_caller(callable $make): void
    {
        $first = $make($this->dir, 60);
        self::assertTrue($first->acquire());
        self::assertTrue($first->release());

        self::assertTrue($make($this->dir, 60)->acquire(), 'lock must be reusable after release');
    }

    #[DataProvider('implementations')]
    public function test_a_non_owner_cannot_release(callable $make): void
    {
        $owner    = $make($this->dir, 60);
        $intruder = $make($this->dir, 60);

        self::assertTrue($owner->acquire());

        // The intruder never acquired, so its token differs — release must refuse.
        self::assertFalse($intruder->release(), 'release() must be owner-checked');
        self::assertFalse($make($this->dir, 60)->acquire(), 'lock must still be held');
    }

    #[DataProvider('implementations')]
    public function test_an_expired_lock_is_reclaimed(callable $make): void
    {
        // A 1s TTL that has already elapsed stands in for a crashed holder.
        $stale = $make($this->dir, 1);
        self::assertTrue($stale->acquire());

        sleep(2);

        self::assertTrue($make($this->dir, 60)->acquire(), 'expired lock must be reclaimable');
    }

    #[DataProvider('implementations')]
    public function test_force_release_ignores_ownership(callable $make): void
    {
        $owner = $make($this->dir, 60);
        self::assertTrue($owner->acquire());

        $make($this->dir, 60)->forceRelease();

        self::assertTrue($make($this->dir, 60)->acquire(), 'forceRelease must clear regardless of owner');
    }

    #[DataProvider('implementations')]
    public function test_block_runs_the_callback_and_always_releases(callable $make): void
    {
        $result = $make($this->dir, 60)->block(1, static fn(): string => 'done');

        self::assertSame('done', $result);
        self::assertTrue($make($this->dir, 60)->acquire(), 'block() must release after the callback');
    }

    #[DataProvider('implementations')]
    public function test_block_releases_even_when_the_callback_throws(callable $make): void
    {
        try {
            $make($this->dir, 60)->block(1, static function (): never {
                throw new \RuntimeException('boom');
            });
            self::fail('the callback exception must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertTrue(
            $make($this->dir, 60)->acquire(),
            'a throwing callback must not strand the lock until its TTL',
        );
    }

    #[DataProvider('implementations')]
    public function test_block_times_out_when_the_lock_stays_held(callable $make): void
    {
        self::assertTrue($make($this->dir, 60)->acquire());

        $this->expectException(LockTimeoutException::class);
        $make($this->dir, 60)->block(1);
    }

    #[DataProvider('implementations')]
    public function test_owner_tokens_are_unique_per_lock(callable $make): void
    {
        self::assertNotSame(
            $make($this->dir, 60)->owner(),
            $make($this->dir, 60)->owner(),
            'a shared owner token would let any caller release any lock',
        );
    }
}
