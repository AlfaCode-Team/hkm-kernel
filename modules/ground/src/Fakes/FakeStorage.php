<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;

/**
 * An in-memory StoragePort.
 *
 * Files live in an array, so a test asserting an upload landed needs no disk, no
 * bucket, and no cleanup. temporaryUrl() returns a deterministic fake URL — real
 * signing belongs to the adapter, and asserting on a signature generated here
 * would only prove this class signed it.
 */
final class FakeStorage implements StoragePort
{
    /** @var array<string, array{contents: string, visibility: string}> */
    private array $files = [];

    /** @var list<array{op: string, path: string}> */
    public array $operations = [];

    public function store(string $contents, string $filename, string $path = '', string $visibility = 'private'): string
    {
        $full = $this->join($path, $filename);

        $this->files[$full]   = ['contents' => $contents, 'visibility' => $visibility];
        $this->operations[]   = ['op' => 'store', 'path' => $full];

        return $full;
    }

    public function storeStream($resource, string $filename, string $path = '', string $visibility = 'private'): string
    {
        if (!\is_resource($resource)) {
            throw new \InvalidArgumentException('FakeStorage::storeStream() expects an open resource.');
        }

        $contents = stream_get_contents($resource);

        return $this->store($contents === false ? '' : $contents, $filename, $path, $visibility);
    }

    public function get(string $path): string
    {
        $this->operations[] = ['op' => 'get', 'path' => $path];

        // Throwing matches every real adapter. Returning '' would let a test
        // pass while production raised — the opposite of what a double is for.
        return $this->files[$path]['contents']
            ?? throw new \RuntimeException("FakeStorage: no file at [{$path}].");
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('FakeStorage: could not open a memory stream.');
        }

        fwrite($stream, $this->get($path));
        rewind($stream);

        return $stream;
    }

    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string
    {
        return 'https://ground.test/' . ltrim($path, '/') . '?expires=' . $expiresInSeconds;
    }

    public function exists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function delete(string $path): bool
    {
        $this->operations[] = ['op' => 'delete', 'path' => $path];

        if (!isset($this->files[$path])) {
            return false;
        }
        unset($this->files[$path]);

        return true;
    }

    // ── Asserting ─────────────────────────────────────────────────────────────

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->files);
    }

    public function visibilityOf(string $path): ?string
    {
        return $this->files[$path]['visibility'] ?? null;
    }

    public function reset(): void
    {
        $this->files      = [];
        $this->operations = [];
    }

    private function join(string $path, string $filename): string
    {
        $path = trim($path, '/');

        return $path === '' ? $filename : $path . '/' . $filename;
    }
}
