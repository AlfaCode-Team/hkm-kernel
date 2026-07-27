<?php

declare(strict_types=1);

namespace Project\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;

/**
 * FileCache — a dependency-free, CROSS-PROCESS CachePort adapter.
 *
 * One file per key under var/cache/store/, holding a serialized
 * {key, value, expires} record. Unlike {@see InMemoryCache}, entries survive the
 * end of a request, so a value written by `POST /auth/password/forgot` is still
 * readable by the separate `POST /auth/password/verify-otp` request under
 * PHP-FPM. That is what a password-reset OTP, a reset token or a rate-limit
 * counter actually requires — an in-memory store makes every one of them fail
 * on the first read, looking exactly like an expired entry.
 *
 * Not a high-throughput cache: every write takes an exclusive lock and every
 * deletePattern()/flush() scans the directory. It is a correct, daemon-free
 * default for dev and small deployments. Set REDIS_HOST in production — the
 * RedisCache plugin overrides this binding.
 *
 * Values are stored with serialize(), so the cache directory must be treated as
 * trusted, application-owned storage (it lives under var/, never the docroot).
 */
final class FileCache implements CachePort
{
    public function __construct(
        private readonly string $dir,
    ) {
    }

    public function get(string $key): mixed
    {
        $record = $this->read($this->file($key));

        return $record === null ? null : $record['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->write($this->file($key), [
            'key'     => $key,
            'value'   => $value,
            'expires' => $ttl !== null ? time() + $ttl : null,
        ]);
    }

    public function delete(string $key): bool
    {
        $file = $this->file($key);

        return !is_file($file) || @unlink($file);
    }

    public function has(string $key): bool
    {
        return $this->read($this->file($key)) !== null;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $record = $this->read($this->file($key));
        if ($record !== null) {
            return $record['value'];
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Atomic read-modify-write — the counter is held under one exclusive lock for
     * the whole operation, so concurrent FPM workers cannot lose an increment
     * (that would silently weaken every rate limiter built on this).
     */
    public function increment(string $key, int $by = 1): int
    {
        $file = $this->file($key);

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return 0;
        }

        try {
            flock($handle, LOCK_EX);

            $raw     = stream_get_contents($handle) ?: '';
            $record  = $this->decode($raw);
            $expires = $record['expires'] ?? null;
            $current = $record === null ? 0 : (int) $record['value'];
            $next    = $current + $by;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, serialize(['key' => $key, 'value' => $next, 'expires' => $expires]));
            fflush($handle);

            return $next;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function deletePattern(string $pattern): int
    {
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
        $count = 0;

        foreach ($this->files() as $file) {
            $record = $this->read($file, deleteExpired: false);
            // Match on the ORIGINAL key stored in the record — the filename is a
            // hash and carries no pattern to match against.
            if ($record !== null && preg_match($regex, (string) $record['key']) === 1 && @unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    public function flush(): bool
    {
        $ok = true;

        foreach ($this->files() as $file) {
            $ok = @unlink($file) && $ok;
        }

        return $ok;
    }

    /**
     * @return array{key: string, value: mixed, expires: int|null}|null  null when
     *         missing, unreadable, corrupt or expired (expired files are reaped).
     */
    private function read(string $file, bool $deleteExpired = true): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return null;
        }

        try {
            flock($handle, LOCK_SH);
            $raw = stream_get_contents($handle) ?: '';
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $record = $this->decode($raw);
        if ($record === null) {
            return null;
        }

        if ($record['expires'] !== null && $record['expires'] < time()) {
            if ($deleteExpired) {
                @unlink($file);
            }

            return null;
        }

        return $record;
    }

    /** @return array{key: string, value: mixed, expires: int|null}|null */
    private function decode(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        $record = @unserialize($raw);

        return is_array($record) && array_key_exists('value', $record)
            ? ['key' => (string) ($record['key'] ?? ''), 'value' => $record['value'], 'expires' => $record['expires'] ?? null]
            : null;
    }

    /** @param array{key: string, value: mixed, expires: int|null} $record */
    private function write(string $file, array $record): bool
    {
        // Write-then-rename: a concurrent reader never sees a half-written entry.
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temp, serialize($record), LOCK_EX) === false) {
            return false;
        }

        if (!@rename($temp, $file)) {
            @unlink($temp);

            return false;
        }

        // Group-writable: the web user (php-fpm) and the CLI user share this
        // directory, and increment() reopens entries for writing in place.
        @chmod($file, 0664);

        return true;
    }

    private function file(string $key): string
    {
        return $this->directory() . '/' . sha1($key) . '.cache';
    }

    /** @return list<string> */
    private function files(): array
    {
        $found = glob($this->directory() . '/*.cache');

        return $found === false ? [] : $found;
    }

    private function directory(): string
    {
        $dir = rtrim($this->dir, '/');

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create cache directory: {$dir}");
            }
            // setgid so entries stay in the parent's group no matter which user
            // (php-fpm or CLI) created them.
            @chmod($dir, 02775);
        }

        return $dir;
    }
}
