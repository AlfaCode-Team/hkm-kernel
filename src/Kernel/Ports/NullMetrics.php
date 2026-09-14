<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * NullMetrics — the default when no metrics adapter is bound.
 *
 * Exists so kernel call sites can be written unconditionally
 * (`$this->metrics->counter(...)`) instead of guarded
 * (`if ($this->metrics !== null)`). A guard at every call site is how
 * instrumentation ends up applied to some paths and not others.
 *
 * Stateless and immutable, so one instance is shared — see {@see instance()}.
 * That is safe under OpenSwoole precisely because it holds nothing.
 */
final class NullMetrics implements MetricsPort
{
    private static ?self $instance = null;

    /** The shared no-op. Holds no state, so sharing it leaks nothing. */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function counter(string $name, int|float $by = 1, array $labels = []): void {}

    public function gauge(string $name, int|float $value, array $labels = []): void {}

    public function histogram(string $name, int|float $value, array $labels = []): void {}

    public function timing(string $name, float $milliseconds, array $labels = []): void {}
}
