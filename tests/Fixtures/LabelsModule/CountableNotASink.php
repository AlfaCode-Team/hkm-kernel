<?php

declare(strict_types=1);

namespace Tests\Fixtures\LabelsModule;

/**
 * A user-land \Countable whose count() takes no arguments: PHP would happily call
 * it with a metric name and ignore the name, "succeeding" while recording nothing.
 */
final class CountableNotASink implements \Countable
{
    public function count(): int
    {
        Recorder::$countableCalls++;

        return 0;
    }
}
