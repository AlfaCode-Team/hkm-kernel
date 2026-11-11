<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * One search hit. The vector itself is not returned — it is the largest thing in
 * the row and the caller already has the query; use `VectorStorePort::get()`.
 *
 * `$distance` is LOWER = CLOSER for every metric, see {@see VectorMetric}.
 */
final readonly class VectorMatch
{
    /** @param array<string, bool|int|float|string> $payload empty when the query asked for no payload */
    public function __construct(
        public string $id,
        public float $distance,
        public array $payload = [],
    ) {}
}
