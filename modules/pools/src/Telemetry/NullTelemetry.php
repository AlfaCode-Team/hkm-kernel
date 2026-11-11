<?php

declare(strict_types=1);

namespace AlfacodeTeam\Pools\Telemetry;

use AlfacodeTeam\Pools\Telemetry;

/** Measures nothing. The default, so telemetry is opt-in. */
final class NullTelemetry implements Telemetry
{
    public function histogram(string $name, float $seconds, array $labels = []): void {}

    public function gauge(string $name, int|float $value, array $labels = []): void {}
}
