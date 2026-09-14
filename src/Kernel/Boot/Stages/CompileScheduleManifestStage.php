<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader, ManifestWriter};
use AlfacodeTeam\PhpServicePlatform\Kernel\Scheduling\{CronExpression, ScheduledTask};

/**
 * Reads schedule[] from every module.json -> schedule-manifest.php.
 *
 * WHY THE CRON EXPRESSION IS PARSED AT BOOT
 * -----------------------------------------
 * Every other declaration in this framework is validated at boot precisely
 * because the alternative is a silent failure discovered later. A schedule is the
 * worst case of that: `"at": "0 25 * * *"` (hour 25) does not throw, does not
 * 404, and does not appear in any log — the task simply never runs, and nobody
 * finds out until somebody asks why last month's report is missing.
 *
 * So the expression is parsed HERE, the boot fails with the field that is wrong,
 * and the compiled manifest carries an expression already known to be valid.
 *
 * DECLARATION SHAPE
 * -----------------
 *   "schedule": [
 *     { "name": "invoices.nightly", "at": "0 2 * * *",
 *       "job": "jobs.rebuild-index", "queue": "maintenance",
 *       "withoutOverlapping": true, "timezone": "Africa/Nairobi" },
 *
 *     { "name": "cache.prune", "at": "@hourly", "command": "cache:prune --stale" }
 *   ]
 *
 * A task names EITHER a `job` (pushed onto the queue — the normal case, because
 * it keeps the scheduler tick fast and gets retries for free) OR a `command`
 * (run in-process by the scheduler). Naming both, or neither, fails the boot.
 */
final class CompileScheduleManifestStage implements BootStageContract
{
    /** @param list<class-string> $moduleClasses */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {}

    public function run(): void
    {
        $tasks = [];

        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $this->reader->read($moduleClass);
            $declared = $manifest['schedule'] ?? [];

            if (!is_array($declared)) {
                throw new BootException(
                    "[{$moduleClass}] declares \"schedule\" as " . get_debug_type($declared)
                    . ' — it must be a list of task objects.'
                );
            }

            foreach ($declared as $entry) {
                $task = $this->compile($entry, $moduleClass, (string) ($manifest['solves'] ?? ''));

                // Names are one flat, application-wide namespace, for the same
                // reason route names are: `schedule:run --task=x` and the
                // overlap lock key both address a task by name, and neither
                // carries a module to disambiguate with.
                if (isset($tasks[$task->name])) {
                    throw new BootException(
                        "Duplicate scheduled task name [{$task->name}] — declared by "
                        . "[{$tasks[$task->name]['module']}] and [{$moduleClass}]. "
                        . 'Task names are application-wide unique; prefix them with the module name.'
                    );
                }

                $tasks[$task->name] = $task->toArray();
            }
        }

        ManifestWriter::write('schedule-manifest.php', $tasks);
    }

    /** @param mixed $entry */
    private function compile(mixed $entry, string $moduleClass, string $solves): ScheduledTask
    {
        if (!is_array($entry)) {
            throw new BootException(
                "[{$moduleClass}] has a \"schedule\" entry that is " . get_debug_type($entry)
                . ' — each entry must be an object with at least "name", "at" and one of "job"/"command".'
            );
        }

        $name = trim((string) ($entry['name'] ?? ''));
        if ($name === '') {
            throw new BootException("[{$moduleClass}] has a \"schedule\" entry with no \"name\".");
        }

        $at = trim((string) ($entry['at'] ?? $entry['cron'] ?? ''));
        if ($at === '') {
            throw new BootException(
                "Scheduled task [{$name}] in [{$moduleClass}] has no \"at\" — a five-field cron "
                . 'expression or an alias (@daily, @hourly, …).'
            );
        }

        try {
            $cron = CronExpression::parse($at);
        } catch (\InvalidArgumentException $e) {
            throw new BootException(
                "Scheduled task [{$name}] in [{$moduleClass}]: " . $e->getMessage(),
                previous: $e,
            );
        }

        $job     = trim((string) ($entry['job'] ?? ''));
        $command = trim((string) ($entry['command'] ?? ''));

        if ($job !== '' && $command !== '') {
            throw new BootException(
                "Scheduled task [{$name}] in [{$moduleClass}] declares both \"job\" and \"command\" — "
                . 'a task does exactly one thing. Split it into two tasks.'
            );
        }

        if ($job === '' && $command === '') {
            throw new BootException(
                "Scheduled task [{$name}] in [{$moduleClass}] declares neither \"job\" nor \"command\" — "
                . 'it would compile to a schedule that runs nothing.'
            );
        }

        $timezone = trim((string) ($entry['timezone'] ?? ''));
        if ($timezone !== '' && !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new BootException(
                "Scheduled task [{$name}] in [{$moduleClass}] declares timezone [{$timezone}], which PHP "
                . 'does not know. Use an IANA identifier (e.g. "Africa/Nairobi", "UTC").'
            );
        }

        $payload = $entry['payload'] ?? [];
        if (!is_array($payload)) {
            throw new BootException(
                "Scheduled task [{$name}] in [{$moduleClass}] has a \"payload\" that is "
                . get_debug_type($payload) . ' — it must be an object.'
            );
        }

        return new ScheduledTask(
            /** @phpstan-ignore-next-line checked non-empty above */
            name:               $name,
            cron:               $cron,
            kind:               $job !== '' ? ScheduledTask::KIND_JOB : ScheduledTask::KIND_COMMAND,
            target:             $job !== '' ? $job : $command,
            module:             $moduleClass,
            solves:             $solves,
            queue:              trim((string) ($entry['queue'] ?? 'default')) ?: 'default',
            payload:            $payload,
            withoutOverlapping: (bool) ($entry['withoutOverlapping'] ?? false),
            // An overlap lock must outlive the task it guards or it protects
            // nothing; an hour is long enough for a batch job and short enough
            // that a hard-killed worker unblocks the schedule the same day.
            expiresAfter:       max(60, (int) ($entry['expiresAfter'] ?? 3600)),
            timezone:           $timezone,
        );
    }
}
