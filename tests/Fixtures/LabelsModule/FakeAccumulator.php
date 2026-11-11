<?php

declare(strict_types=1);

namespace Tests\Fixtures\LabelsModule;

/** Stands in for a metering plugin's per-request accumulator: one public count(). */
final class FakeAccumulator
{
    public function count(string $metric, int $by = 1): void
    {
        if (Recorder::$metricSinkThrows) {
            throw new \InvalidArgumentException('sink refused the name');
        }

        Recorder::$counts[$metric] = (Recorder::$counts[$metric] ?? 0) + $by;
    }
}
