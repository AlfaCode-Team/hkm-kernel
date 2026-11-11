<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ResumableStorage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The kernel ships only the CONTRACT; adapters live in the Storage plugin and
 * are tested there. Pinned here: the capability is optional, and its shape.
 */
#[CoversNothing]
final class ResumableStorageTest extends TestCase
{
    public function test_it_does_not_extend_the_storage_port(): void
    {
        self::assertNotContains(StoragePort::class, class_implements(ResumableStorage::class) ?: []);
    }

    public function test_the_storage_port_itself_does_not_declare_the_capability(): void
    {
        $port = new \ReflectionClass(StoragePort::class);

        foreach (['createUpload', 'appendChunk', 'uploadedBytes', 'completeUpload', 'abortUpload'] as $method) {
            self::assertFalse($port->hasMethod($method), "{$method}() belongs to ResumableStorage, not StoragePort");
        }
    }

    public function test_a_plain_storage_port_is_not_resumable(): void
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

        self::assertNotInstanceOf(ResumableStorage::class, $fake);
    }

    public function test_the_contract_signatures(): void
    {
        $create = new \ReflectionMethod(ResumableStorage::class, 'createUpload');
        self::assertSame(['filename', 'path', 'totalSize', 'visibility'], array_map(static fn($p) => $p->getName(), $create->getParameters()));
        self::assertTrue($create->getParameters()[2]->allowsNull(), 'an unknown total size must be expressible');
        self::assertSame('string', (string) $create->getReturnType());

        $append = new \ReflectionMethod(ResumableStorage::class, 'appendChunk');
        self::assertSame(['uploadId', 'offset', 'chunk'], array_map(static fn($p) => $p->getName(), $append->getParameters()));
        self::assertSame('int', (string) $append->getReturnType());

        self::assertSame('int', (string) (new \ReflectionMethod(ResumableStorage::class, 'uploadedBytes'))->getReturnType());
        self::assertSame('string', (string) (new \ReflectionMethod(ResumableStorage::class, 'completeUpload'))->getReturnType());
        self::assertSame('void', (string) (new \ReflectionMethod(ResumableStorage::class, 'abortUpload'))->getReturnType());
    }
}
