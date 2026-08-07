<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions;

/**
 * Thrown by {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Lock::block()}
 * when the lock could not be acquired within the allowed wait.
 *
 * This is contention, not corruption — the usual response is to skip the work
 * (someone else is doing it) or retry later, NOT to treat it as a fault.
 * Severity is therefore WARNING, not CRITICAL.
 */
final class LockTimeoutException extends FrameworkException
{
    public static function for(string $name, int $seconds): self
    {
        return new self(
            "Timed out after {$seconds}s waiting for lock [{$name}].",
            layer: 'cache.lock',
            context: ['lock' => $name, 'waited' => $seconds],
        );
    }
}
