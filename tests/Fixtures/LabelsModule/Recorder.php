<?php

declare(strict_types=1);

namespace Tests\Fixtures\LabelsModule;

/** What the fixture sinks saw. Static on purpose: it is test scaffolding, reset per test. */
final class Recorder
{
    /** @var array<string, int> */
    public static array $counts = [];

    /** @var list<array{action: string, meta: array<string, mixed>}> */
    public static array $audits = [];

    /** @var list<array{name: string, payload: array<string, mixed>}> */
    public static array $events = [];

    public static bool $metricSinkThrows = false;
    public static bool $installUsageHook = true;
    public static bool $bindAuditSink    = true;
    public static bool $auditSinkInternal = false;
    public static bool $usageIsCountable = false;
    public static int $countableCalls    = 0;
    public static bool $usageIsPlainObject = false;

    public static function reset(): void
    {
        self::$counts = [];
        self::$audits = [];
        self::$events = [];
        self::$metricSinkThrows = false;
        self::$installUsageHook = true;
        self::$bindAuditSink    = true;
        self::$auditSinkInternal = false;
        self::$usageIsCountable = false;
        self::$countableCalls   = 0;
        self::$usageIsPlainObject = false;
    }
}
