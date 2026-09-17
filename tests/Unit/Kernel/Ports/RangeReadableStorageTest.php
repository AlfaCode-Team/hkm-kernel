<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\RangeReadableStorage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The kernel ships only the CONTRACT; the adapters implementing it live in the
 * Storage plugin and are tested there. What is pinned here is the one property
 * that makes the capability safe to add: it is optional.
 */
#[CoversNothing]
final class RangeReadableStorageTest extends TestCase
{
    public function test_it_does_not_extend_the_storage_port(): void
    {
        // If it did, every StoragePort fake would have to implement it — the
        // breakage the separate interface exists to avoid.
        self::assertNotContains(StoragePort::class, class_implements(RangeReadableStorage::class) ?: []);
    }

    public function test_the_storage_port_itself_does_not_declare_the_capability(): void
    {
        $port = new \ReflectionClass(StoragePort::class);

        self::assertFalse($port->hasMethod('size'), 'size() belongs to RangeReadableStorage, not StoragePort');
        self::assertFalse($port->hasMethod('readRange'), 'readRange() belongs to RangeReadableStorage, not StoragePort');
    }

    public function test_a_plain_storage_port_is_not_range_readable(): void
    {
        $fake = new class implements StoragePort {
            public function store(string $contents, string $filename, string $path = '', string $visibility = 'private'): string { return $filename; }
            public function storeStream($resource, string $filename, string $path = '', string $visibility = 'private'): string { return $filename; }
            public function get(string $path): string { return ''; }
            public function readStream(string $path) { return fopen('php://memory', 'rb'); }
            public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string { return $path; }
            public function exists(string $path): bool { return false; }
            public function delete(string $path): bool { return true; }
        };

        self::assertNotInstanceOf(RangeReadableStorage::class, $fake);
    }

    public function test_the_contract_signatures(): void
    {
        $size = new \ReflectionMethod(RangeReadableStorage::class, 'size');
        self::assertSame('int', (string) $size->getReturnType());

        $range  = new \ReflectionMethod(RangeReadableStorage::class, 'readRange');
        $params = $range->getParameters();
        self::assertSame(['path', 'offset', 'length'], array_map(static fn($p) => $p->getName(), $params));
        self::assertTrue($params[2]->allowsNull());
        self::assertNull($params[2]->getDefaultValue());
    }
}
