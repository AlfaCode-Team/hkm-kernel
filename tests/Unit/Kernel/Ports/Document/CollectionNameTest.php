<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Ports\Document;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\CollectionName;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentIndex;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One collection-name rule, applied everywhere a collection is named.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY THIS CLASS EXISTS AT ALL
 * ────────────────────────────────────────────────────────────────────────────
 * The rule started out private to {@see DocumentQuery}, so {@see DocumentIndex}
 * had none: an index could be declared on `''` or on `orders/archived`, names a
 * query is forbidden from addressing. The result would be a migration that
 * reports success and creates an index nothing can ever use — the failure being
 * silent on exactly the side that is supposed to be declarative.
 *
 * It was found by PHPStan, not by a test: the constructor wanted a
 * `non-empty-string` and the factory could only promise a `string`, which is
 * the type system noticing a missing guard. The pair of tests at the bottom are
 * what stop it coming back, since a type error can be made to go away by
 * widening the type.
 */
#[CoversClass(CollectionName::class)]
final class CollectionNameTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'empty'            => [''];
        yield 'slash'            => ['orders/archived'];
        yield 'dollar'           => ['orders$1'];
        yield 'nul byte'         => ["orders\0"];
        yield 'single dot'       => ['.'];
        yield 'double dot'       => ['..'];
        yield 'system prefix'    => ['system.indexes'];
        yield 'reserved shape'   => ['__name__'];
        yield 'too long'         => [str_repeat('a', 256)];
    }

    #[DataProvider('badNames')]
    public function test_a_bad_name_is_refused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CollectionName::assertValid($name);
    }

    /** @return iterable<string, array{string}> */
    public static function goodNames(): iterable
    {
        yield 'plain'           => ['orders'];
        yield 'underscored'     => ['push_devices'];
        yield 'dotted'          => ['app.orders'];
        yield 'hyphenated'      => ['push-devices'];
        yield 'mixed case'      => ['pushDevices'];
        yield 'at the ceiling'  => [str_repeat('a', 255)];
    }

    #[DataProvider('goodNames')]
    public function test_a_good_name_is_accepted(string $name): void
    {
        CollectionName::assertValid($name);

        self::assertSame($name, DocumentQuery::for($name)->collection);
    }

    // ── The gap PHPStan found ───────────────────────────────────────────────

    public function test_an_index_rejects_a_collection_name_a_query_would_reject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/path separator/');

        DocumentIndex::of('orders/archived', ['status']);
    }

    public function test_a_ttl_index_rejects_it_too(): void
    {
        // Checked separately because ttl() builds its field list on a different
        // path and originally skipped the guard the other two factories got.
        $this->expectException(\InvalidArgumentException::class);

        DocumentIndex::ttl('', 'expiresAt', 60);
    }

    public function test_a_unique_index_rejects_it_too(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DocumentIndex::unique('system.indexes', ['token']);
    }

    public function test_query_and_index_agree_on_every_bad_name(): void
    {
        // The point of sharing the rule: the two entry points must not drift.
        foreach (self::badNames() as $label => [$name]) {
            $queryRefused = false;
            $indexRefused = false;

            try {
                DocumentQuery::for($name);
            } catch (\InvalidArgumentException) {
                $queryRefused = true;
            }

            try {
                DocumentIndex::of($name, ['status']);
            } catch (\InvalidArgumentException) {
                $indexRefused = true;
            }

            self::assertTrue($queryRefused, "DocumentQuery accepted the {$label} name");
            self::assertTrue($indexRefused, "DocumentIndex accepted the {$label} name");
        }
    }
}
