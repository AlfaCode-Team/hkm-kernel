<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\LockTimeoutException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use AlfacodeTeam\Ground\Fakes\FakeCache;
use AlfacodeTeam\Ground\Fakes\FakeDatabase;
use AlfacodeTeam\Ground\Fakes\FakeEncrypter;
use AlfacodeTeam\Ground\Fakes\FakeHasher;
use AlfacodeTeam\Ground\Fakes\FakeQueue;
use AlfacodeTeam\Ground\Fakes\FakeStorage;
use AlfacodeTeam\Ground\Fakes\FrozenClock;

/**
 * The doubles, tested where they make a PROMISE.
 *
 * Not every getter — the ones asserted here are the behaviours a plugin test
 * will rely on and would be silently wrong about if the double cheated: TTL
 * against the injected clock, lock ownership, transaction depth, and the
 * encrypter's refusal of foreign payloads.
 */
#[CoversClass(FakeCache::class)]
#[CoversClass(FakeDatabase::class)]
#[CoversClass(FrozenClock::class)]
final class FakesTest extends TestCase
{
    // ── Clock ─────────────────────────────────────────────────────────────────

    public function testTheClockOnlyMovesWhenTold(): void
    {
        $clock = new FrozenClock('2026-03-01 12:00:00');
        $first = $clock->timestamp();

        usleep(1000);

        self::assertSame($first, $clock->timestamp());

        $clock->travel('+1 hour');

        self::assertSame($first + 3600, $clock->timestamp());
    }

    public function testTheClockIsUtcRegardlessOfTheMachine(): void
    {
        // A clock adopting the host timezone would make the same assertion pass
        // locally and fail in CI, which is the worst possible flake.
        self::assertSame('UTC', (new FrozenClock())->now()->getTimezone()->getName());
    }

    // ── Cache ─────────────────────────────────────────────────────────────────

    public function testCacheEntriesExpireAgainstTheInjectedClockNotRealTime(): void
    {
        $clock = new FrozenClock();
        $cache = new FakeCache($clock);

        $cache->set('otp', '123456', ttl: 300);
        self::assertTrue($cache->has('otp'));

        $clock->travelSeconds(299);
        self::assertTrue($cache->has('otp'), 'Still inside the window.');

        $clock->travelSeconds(2);
        self::assertFalse($cache->has('otp'));
    }

    public function testIncrementKeepsTheExistingWindow(): void
    {
        $clock = new FrozenClock();
        $cache = new FakeCache($clock);

        $cache->set('throttle:ip', 1, ttl: 60);
        $clock->travelSeconds(30);
        $cache->increment('throttle:ip');

        // A counter that reset its own expiry on every hit would never limit
        // anything — the bug this asserts against.
        $clock->travelSeconds(31);

        self::assertFalse($cache->has('throttle:ip'));
    }

    public function testRememberOnlyComputesOnce(): void
    {
        $cache = new FakeCache();
        $calls = 0;

        $compute = function () use (&$calls): string {
            $calls++;

            return 'value';
        };

        self::assertSame('value', $cache->remember('k', 60, $compute));
        self::assertSame('value', $cache->remember('k', 60, $compute));
        self::assertSame(1, $calls);
    }

    public function testDeletePatternMatchesOnlyTheWildcard(): void
    {
        $cache = new FakeCache();
        $cache->set('user:1:profile', 'a');
        $cache->set('user:2:profile', 'b');
        $cache->set('post:1', 'c');

        self::assertSame(2, $cache->deletePattern('user:*'));
        self::assertSame(['post:1'], $cache->keys());
    }

    // ── Locks ─────────────────────────────────────────────────────────────────

    public function testASecondHolderCannotAcquireAHeldLock(): void
    {
        $cache = new FakeCache();

        self::assertTrue($cache->lock('import')->acquire());
        self::assertFalse($cache->lock('import')->acquire());
    }

    public function testOnlyTheOwnerCanRelease(): void
    {
        $cache = new FakeCache();
        $mine  = $cache->lock('import');
        $mine->acquire();

        // Releasing someone else's lock is the failure owner tokens exist to
        // prevent; a double that ignored ownership would let that bug pass.
        self::assertFalse($cache->lock('import')->release());
        self::assertTrue($mine->release());
    }

    public function testBlockTimesOutRatherThanWaitingForever(): void
    {
        $cache = new FakeCache();
        $cache->lock('import')->acquire();

        $this->expectException(LockTimeoutException::class);

        $cache->lock('import')->block(1);
    }

    public function testBlockReleasesEvenWhenTheCallbackThrows(): void
    {
        $cache = new FakeCache();

        try {
            $cache->lock('import')->block(1, static fn() => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
            // expected
        }

        // The lock must not be stranded until its TTL — AbstractLock's finally.
        self::assertTrue($cache->lock('import')->acquire());
    }

    // ── Database ──────────────────────────────────────────────────────────────

    public function testQueuedResultsAreConsumedInOrder(): void
    {
        $db = new FakeDatabase();
        $db->willReturn(['id' => 1])->willReturn(['id' => 2]);

        self::assertSame(1, $db->query('SELECT 1')[0]['id']);
        self::assertSame(2, $db->query('SELECT 2')[0]['id']);
        self::assertSame([], $db->query('SELECT 3'));
    }

    public function testMatchersBeatQueueOrderSoATestCanNameTheStatementItMeans(): void
    {
        $db = new FakeDatabase();
        $db->onQuery('from users', ['id' => 'u1']);

        self::assertSame([], $db->query('SELECT * FROM posts'));
        self::assertSame('u1', $db->queryOne('SELECT * FROM users WHERE id = :id')['id']);
    }

    public function testTransactionDepthIsTracked(): void
    {
        $db = new FakeDatabase();

        self::assertFalse($db->inTransaction());

        $db->beginTransaction();
        self::assertTrue($db->inTransaction());

        $db->commit();
        self::assertFalse($db->inTransaction());
        self::assertTrue($db->committed());
        self::assertFalse($db->rolledBack());
    }

    public function testCommittingOutsideATransactionIsAnError(): void
    {
        // Swallowing this would hide the exact ordering mistake a service test
        // exists to catch.
        $this->expectException(\LogicException::class);

        (new FakeDatabase())->commit();
    }

    public function testFailOnDrivesTheRollbackPath(): void
    {
        $db = new FakeDatabase();
        $db->failOn('insert into invoices');

        $db->beginTransaction();

        try {
            $db->execute('INSERT INTO invoices (id) VALUES (1)');
            self::fail('Expected the forced failure.');
        } catch (\PDOException) {
            $db->rollback();
        }

        self::assertTrue($db->rolledBack());
        self::assertFalse($db->committed());
    }

    // ── Queue ─────────────────────────────────────────────────────────────────

    public function testPushedJobsAreRecordedAndPoppable(): void
    {
        $queue = new FakeQueue();
        $queue->push('seo.indexnow', ['urls' => ['/a']], 'indexing');

        self::assertTrue($queue->wasPushed('seo.indexnow'));
        self::assertSame(1, $queue->size('indexing'));
        self::assertSame(['/a'], $queue->payloadsFor('seo.indexnow')[0]['urls']);

        $payload = $queue->pop('indexing');

        self::assertNotNull($payload);
        self::assertSame('indexing', $payload->queue());
        self::assertSame(0, $queue->size('indexing'));
    }

    public function testPayloadsCarryAVerifiableSignature(): void
    {
        $queue = new FakeQueue();
        $queue->push('mail.send', ['to' => 'a@b.test']);

        // So a test can drive a signature check without hand-rolling the HMAC.
        self::assertTrue($queue->pop()?->isSignatureValid(FakeQueue::SECRET));
    }

    public function testReleasePutsTheJobBack(): void
    {
        $queue = new FakeQueue();
        $queue->push('mail.send', []);

        $payload = $queue->pop();
        self::assertNotNull($payload);

        $queue->release($payload, 30);

        self::assertSame(1, $queue->size());
        self::assertSame(30, $queue->released[0]['delay']);
    }

    // ── Storage ───────────────────────────────────────────────────────────────

    public function testStoredFilesReadBackAndMissingOnesThrow(): void
    {
        $storage = new FakeStorage();
        $path    = $storage->store('hello', 'note.txt', 'uploads');

        self::assertSame('uploads/note.txt', $path);
        self::assertSame('hello', $storage->get($path));

        // Every real adapter throws here; returning '' would let a test pass
        // while production raised.
        $this->expectException(\RuntimeException::class);
        $storage->get('nope.txt');
    }

    // ── Hashing and encryption ────────────────────────────────────────────────

    public function testHashesVerifyAndCheckDoesNotCountAsHashing(): void
    {
        $hasher = new FakeHasher();
        $hash   = $hasher->make('secret');

        self::assertTrue($hasher->check('secret', $hash));
        self::assertFalse($hasher->check('wrong', $hash));
        self::assertSame(['secret'], $hasher->hashed);
    }

    public function testAForeignDigestAlwaysNeedsRehashing(): void
    {
        self::assertTrue((new FakeHasher())->needsRehash('$2y$10$somethingelse'));
    }

    public function testEncryptionRoundTripsAndRejectsForeignPayloads(): void
    {
        $encrypter = new FakeEncrypter();
        $payload   = $encrypter->encrypt(['a' => 1]);

        self::assertNotSame('a', $payload);
        self::assertSame(['a' => 1], $encrypter->decrypt($payload));

        // Catches the genuinely interesting bug: decrypting something that was
        // never encrypted, or decrypting twice.
        $this->expectException(\RuntimeException::class);
        $encrypter->decrypt('plain text');
    }

    public function testUnserializeRefusesToInstantiateClassesFromThePayload(): void
    {
        $encrypter = new FakeEncrypter();
        $payload   = $encrypter->encrypt(new \stdClass());

        // allowed_classes: false — the vulnerability class this port exists
        // around. __PHP_Incomplete_Class is the correct, defused result.
        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $encrypter->decrypt($payload));
    }
}
