<?php

declare(strict_types=1);

namespace Project\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\AbstractLock;

/**
 * FileLock — a CROSS-PROCESS lock backed by an exclusively-created file.
 *
 * Pairs with {@see FileCache}: both are the daemon-free default that still
 * behaves correctly when PHP-FPM serves each request in a different process.
 *
 * ACQUIRE  fopen($file, 'x') — the 'x' mode maps to O_CREAT|O_EXCL, which the
 *          kernel guarantees is atomic on a local filesystem: exactly one caller
 *          can create the file, everyone else gets false. That is the whole lock.
 *
 * RELEASE  flock(LOCK_EX) around read-owner-then-unlink, so the compare and the
 *          delete cannot interleave with another process acquiring after our TTL
 *          expired. Same reasoning as the Lua script in RedisLock.
 *
 * LIMITS — read these before deploying on anything unusual:
 *   - O_EXCL is NOT reliable on NFS (older protocol versions in particular). On
 *     a shared/network filesystem use Redis, not this.
 *   - An expired lock is reclaimed lazily by the next acquirer, so a crashed
 *     holder blocks others until its TTL passes — pass a real TTL.
 *   - Single-machine only. Multiple app servers need Redis.
 */
final class FileLock extends AbstractLock
{
    public function __construct(
        private readonly string $dir,
        string $name,
        int $seconds,
        ?string $owner = null,
    ) {
        parent::__construct($name, $seconds, $owner ?? self::randomOwner());
    }

    public function acquire(): bool
    {
        $file = $this->file();

        // Reclaim an expired lock so a crashed holder does not block forever.
        $existing = $this->readRecord($file);
        if ($existing !== null && $this->isExpired($existing)) {
            @unlink($file);
        }

        // Atomic create-if-absent. Anyone losing the race gets false here.
        $handle = @fopen($file, 'x');
        if ($handle === false) {
            return false;
        }

        try {
            $expires = $this->seconds > 0 ? \time() + $this->seconds : 0;
            \fwrite($handle, $this->owner . '|' . $expires);
        } finally {
            \fclose($handle);
        }

        @\chmod($file, 0664);

        return true;
    }

    public function release(): bool
    {
        $file = $this->file();
        if (!\is_file($file)) {
            return false;
        }

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            // Exclusive: makes compare-then-delete atomic against other holders.
            if (!\flock($handle, LOCK_EX)) {
                return false;
            }

            $raw   = (string) \stream_get_contents($handle);
            $owner = \explode('|', $raw)[0] ?? '';

            // hash_equals: owner tokens are secrets — never compare with ===.
            if (!\hash_equals($this->owner, $owner)) {
                return false;
            }

            @\unlink($file);

            return true;
        } finally {
            \flock($handle, LOCK_UN);
            \fclose($handle);
        }
    }

    public function forceRelease(): void
    {
        @\unlink($this->file());
    }

    /** @return array{owner: string, expires: int}|null */
    private function readRecord(string $file): ?array
    {
        if (!\is_file($file)) {
            return null;
        }
        $raw = @\file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $parts = \explode('|', $raw);

        return ['owner' => $parts[0] ?? '', 'expires' => (int) ($parts[1] ?? 0)];
    }

    /** @param array{owner: string, expires: int} $record */
    private function isExpired(array $record): bool
    {
        // expires === 0 means "no TTL" — never reclaimed automatically.
        return $record['expires'] > 0 && $record['expires'] < \time();
    }

    private function file(): string
    {
        $dir = \rtrim($this->dir, '/');
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Cannot create lock directory: {$dir}");
        }

        return $dir . '/' . \sha1($this->name) . '.lock';
    }
}
