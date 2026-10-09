# Task Scheduling

The scheduler turns one crontab line into every periodic task your application needs. Tasks are declared in `module.json`, compiled and validated at boot, and dispatched on a once-a-minute tick, either queued as a job or run as a CLI command. Use this page when a module needs work done on a schedule.

## Setup

Add one cron entry per server that should tick:

```text
* * * * * cd /srv/app && hkm cli schedule:run >> /dev/null 2>&1
```

That is the only server change you ever make. Adding a task is a `module.json` edit.

`schedule:run` exits non-zero when any dispatch **failed**, so cron's mail (or your monitoring of the exit code) is the signal that the tick is unhealthy. A tick with nothing due prints nothing.

::: warning Several servers ticking at once
Running the cron line on every application server is normal, and each server dispatches every due task. A task that must happen **once** per occurrence needs `"withoutOverlapping": true` and a shared `CachePort` (Redis, for example) so the servers contend on the same lock.
:::

## Declaring tasks

Tasks go in a module's `schedule[]`:

```jsonc
{
  "name": "billing",
  "solves": "billing.invoicing",
  "schedule": [
    {
      "name": "billing.monthly-invoices",
      "at": "0 2 1 * *",                  // 02:00 on the 1st
      "job": "billing.send-monthly-invoices",
      "queue": "billing",
      "payload": { "dryRun": false },
      "withoutOverlapping": true,
      "expiresAfter": 7200,
      "timezone": "Africa/Nairobi"
    },
    {
      "name": "billing.sync-accounts",
      "at": "@hourly",
      "command": "billing:sync"
    }
  ]
}
```

| Key | Required | Default | Meaning |
|---|---|---|---|
| `name` | yes | — | Unique across the application. Two modules declaring the same name fail the boot. Used for `--task`, logs and the lock key. |
| `at` | yes | — | Five-field cron expression or alias (see below). `cron` is accepted as an alias. |
| `job` | one of | — | Push this job to the queue: the name a worker resolves through the [job manifest](/background/workers-and-jobs#job-declarations-in-module-json). |
| `command` | one of | — | Run this CLI command line, for example `"cache:prune --older-than=7"`. |
| `queue` | no | `default` | Queue a `job` task is pushed to. |
| `payload` | no | `{}` | Data pushed with a `job` task. Must be an object. |
| `withoutOverlapping` | no | `false` | Take a cache lock before dispatching; skip if it is held. |
| `expiresAfter` | no | `3600` | Lock lifetime in seconds (minimum 60). Should exceed the task's runtime. |
| `timezone` | no | the server's | IANA identifier. `at` is evaluated in this zone. |

Every one of these is checked at boot, and a bad declaration fails it with a message naming the module and task:

```text
✗ an entry that is not an object, or has no "name" / no "at"
✗ an "at" that does not parse (including any six-field expression)
✗ both "job" and "command", or neither
✗ a timezone PHP does not know
✗ a non-object "payload"
✗ the same task name in two modules
```

Tasks compile to `schedule-manifest.php`.

## Cron expressions

`CronExpression` is a standard five-field matcher, `minute hour day-of-month month day-of-week`:

| Form | Example | Meaning |
|---|---|---|
| `*` | `*` | every value |
| literal | `5` | exactly 5 |
| list | `1,15` | either |
| range | `9-17` | inclusive |
| step | `*/15`, `1-30/5` | every Nth value, over the whole field or a range |
| names | `JAN`..`DEC`, `SUN`..`SAT` | month and day-of-week only, case-insensitive |

Day-of-week accepts `0`–`7`, where both `0` and `7` mean Sunday.

Aliases: `@yearly` / `@annually` (`0 0 1 1 *`), `@monthly` (`0 0 1 * *`), `@weekly` (`0 0 * * 0`), `@daily` / `@midnight` (`0 0 * * *`), `@hourly` (`0 * * * *`).

Two rules that surprise people:

- **Day-of-month and day-of-week are OR'ed** when both are restricted, as in every cron implementation. `0 0 1,15 * MON` means "the 1st, the 15th, **and** every Monday", not "Mondays that fall on the 1st or 15th".
- **Seconds are not supported.** The tick is once a minute, so a six-field expression is rejected at boot rather than silently rounded.

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Scheduling\CronExpression;

$cron = CronExpression::parse('0 9-17 * * MON-FRI');   // throws \InvalidArgumentException naming the bad field
CronExpression::isValid('*/15 * * * *');               // bool
$cron->dueAt(new \DateTimeImmutable('2026-03-02 09:00'));   // true (a Monday)
$cron->expression;                                     // the original string
```

The constructor is private: always go through `parse()`.

## Timezones

A task with a `timezone` converts the tick's moment into that zone before matching, so `"at": "0 9 * * 1", "timezone": "Africa/Nairobi"` runs at 09:00 Nairobi time wherever the server is. Without a `timezone`, the moment is matched in whatever zone the clock returns, which is PHP's default timezone. Set `date.timezone` explicitly on your servers, or give every task a `timezone`; otherwise a server move silently shifts your schedule.

## Overlap protection

With `"withoutOverlapping": true`, the scheduler takes `CachePort::lock('schedule:lock:<name>', expiresAfter)` before dispatching:

- the lock is held → the task is **skipped** this tick (outcome `skipped`);
- no `CachePort` is bound → the task is **skipped** and a warning logged. It is never run unguarded, because silently dropping protection someone asked for is the worse failure;
- the dispatch throws → the lock is released, so a crash does not block the task until expiry.

::: warning A queued job keeps its lock until it expires
For a `job` task, the lock is **not** released when the job is pushed: the point is that the *work* must not overlap, and the work has only just started. The lock simply expires after `expiresAfter` seconds. So a task that runs more often than its `expiresAfter` is throttled to once per `expiresAfter`: a `*/15 * * * *` job with the default `3600` runs only **once an hour**. Set `expiresAfter` just above the job's real runtime, and below its interval.
:::

For a `command` task, the lock covers the in-process run.

## Job tasks vs command tasks

| | `job` | `command` |
|---|---|---|
| Runs | in a worker, after `QueuePort::push($job, $payload, $queue)` | inside the tick, through the CLI pipeline (same as typing it) |
| Needs | a bound `QueuePort` (otherwise skipped) | nothing extra |
| Retries / timeout | the job's own [retry and timeout](/background/workers-and-jobs#job-declarations-in-module-json) | none: a non-zero exit is reported as `failed` |
| Use for | anything slow, or anything that must survive the tick | quick housekeeping |

A command runs in-process and delays every task after it in the same tick. Anything that takes more than a few seconds belongs in a job.

One task failing never stops the others: `run()` catches each dispatch's exception, logs it, records the outcome as `failed`, and moves on.

## The Scheduler API

The kernel binds `Scheduler` as a lazy singleton in the `CoreContainer`. It reads the manifest on first use, so an HTTP process never touches it. Ports are optional: an app with no queue still gets `schedule:list`, and its job tasks report `skipped`.

```php
final class Scheduler
{
    public function __construct(
        ?QueuePort $queue = null,
        ?CachePort $cache = null,
        ?LoggerPort $logger = null,
        ClockPort $clock = new SystemClock(),
        ?callable $commandRunner = null,      // (string $commandLine): int
    );

    public function tasks(): array;                                   // list<ScheduledTask>, manifest order
    public function due(?\DateTimeImmutable $moment = null): array;   // list<ScheduledTask> due at $moment (default: now)
    public function run(?\DateTimeImmutable $moment = null): array;   // dispatch everything due
    public function runTask(string $name): ?array;                    // dispatch one task now, due or not; null if unknown
}
```

`run()` and `runTask()` return results shaped `['task' => string, 'outcome' => string, 'detail' => string]`. `outcome` is one of `queued` (the job was pushed; `detail` is `"<queue> #<id>"`), `ran` (the command exited 0), `skipped` (lock held, or no `CachePort`, `QueuePort` or command runner), or `failed` (it threw, or the command exited non-zero).

The kernel wires the command runner to its own CLI pipeline, so a `command` task resolves exactly as it would if you typed `hkm <command line>`.

### ScheduledTask

A compiled manifest entry: a value object with public properties.

| Property | Type | Notes |
|---|---|---|
| `name` | `string` | |
| `cron` | `CronExpression` | |
| `kind` | `string` | `ScheduledTask::KIND_JOB` (`'job'`) or `ScheduledTask::KIND_COMMAND` (`'command'`) |
| `target` | `string` | the job name or the command line |
| `module`, `solves` | `string` | the declaring module |
| `queue` | `string` | |
| `payload` | `array` | |
| `withoutOverlapping` | `bool` | |
| `expiresAfter` | `int` | seconds |
| `timezone` | `string` | `''` = server default |

Methods: `dueAt(\DateTimeImmutable $moment): bool` (applies `timezone` first), `lockKey(): string` (`schedule:lock:<name>`), `toArray()` and `ScheduledTask::fromArray()`.

## Commands

| Command | Options |
|---|---|
| `schedule:run` | `--task=<name>` / `-t`: dispatch one task now, whether or not it is due. `--at=<time>`: evaluate against this moment instead of now (any `strtotime()` format). `--pretend`: list what would be dispatched and dispatch nothing. |
| `schedule:list` | `--next=<n>` / `-n`: show the next *n* run times per task (1–10, default 1). Shows name, expression, kind, overlap guard and timezone. |

See [Built-in commands](/cli/built-in-commands#scheduler) for the full reference.

## Testing a schedule

Check what a given moment would dispatch, without dispatching:

```bash
hkm cli schedule:run --pretend --at="2026-03-01 02:00"
hkm cli schedule:list --next=5
hkm cli schedule:run --task=billing.sync-accounts     # actually runs it
```

In PHPUnit, construct a `Scheduler` with fakes and a fixed moment. Its manifest is the compiled `schedule-manifest.php`, so boot the kernel first:

```php
$scheduler = new Scheduler(
    queue: $queue,                                   // a QueuePort fake that records pushes
    cache: $cache,                                   // a CachePort fake
    commandRunner: fn (string $line): int => 0,
);

$results = $scheduler->run(new \DateTimeImmutable('2026-03-01 02:00', new \DateTimeZone('UTC')));

self::assertSame('queued', $results[0]['outcome']);
```

To test an expression alone:

```php
$cron = CronExpression::parse('0 2 * * *');
self::assertTrue($cron->dueAt(new \DateTimeImmutable('2026-01-15 02:00')));
self::assertFalse($cron->dueAt(new \DateTimeImmutable('2026-01-15 03:00')));
```

For a plugin, [ground](/packages/ground) provides `FakeQueue`, `FakeCache` and `FrozenClock`.

## Common mistakes

```text
✗ Declaring tasks under any key but "schedule", or without "name" and "at"
✗ A six-field expression (seconds) — fails the boot
✗ Several servers ticking, overlap guard on, but each with its own in-memory cache
  — every server gets its own lock, so every server dispatches
✗ withoutOverlapping on a frequent JOB with the default expiresAfter (3600)
  — the lock outlives the interval and the task runs once an hour
✗ Slow work as a "command" — it delays every later task in the same tick
✗ Relying on the server's timezone — set "timezone" or date.timezone explicitly
```

## Source

- [`src/Kernel/Scheduling/Scheduler.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Scheduling/Scheduler.php)
- [`src/Kernel/Scheduling/CronExpression.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Scheduling/CronExpression.php)
- [`src/Kernel/Scheduling/ScheduledTask.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Scheduling/ScheduledTask.php)
- [`src/Kernel/Boot/Stages/CompileScheduleManifestStage.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Stages/CompileScheduleManifestStage.php)
- [`src/Commands/Schedule/ScheduleRunCommand.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Commands/Schedule/ScheduleRunCommand.php)
- [`src/Commands/Schedule/ScheduleListCommand.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Commands/Schedule/ScheduleListCommand.php)
