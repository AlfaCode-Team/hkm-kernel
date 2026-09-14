<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Schedule;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Scheduling\Scheduler;

/**
 * `schedule:run` — the once-a-minute tick.
 *
 * ONE crontab line drives the whole application:
 *
 *     * * * * * cd /srv/app && hkm cli schedule:run >> /dev/null 2>&1
 *
 * Everything else is declared in module.json, so adding a task never means
 * touching the server again.
 */
final class ScheduleRunCommand extends AbstractCommand
{
    public function __construct(private readonly Scheduler $scheduler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'schedule:run';
        $this->description = 'Dispatch every scheduled task that is due right now.';

        $this->addOption(
            'task',
            't',
            'Run ONE task by name, whether or not it is due. For testing a schedule.',
            acceptsValue: true,
        );
        $this->addOption(
            'at',
            '',
            'Evaluate against this moment instead of now (any strtotime format). Dry-run friendly.',
            acceptsValue: true,
        );
        $this->addOption('pretend', '', 'List what WOULD be dispatched, and dispatch nothing.');
    }

    protected function handle(): int
    {
        $name = (string) ($this->option('task') ?? '');

        if ($name !== '') {
            $result = $this->scheduler->runTask($name);

            if ($result === null) {
                $this->error("No scheduled task named [{$name}].");
                $this->info('Run `schedule:list` to see what is declared.');

                return self::FAILURE;
            }

            $this->report([$result]);

            return $result['outcome'] === 'failed' ? self::FAILURE : self::SUCCESS;
        }

        $moment = $this->moment();

        if ($moment === false) {
            $this->error('Could not parse --at. Use anything strtotime() understands, e.g. "2026-01-01 02:00".');

            return self::INVALID;
        }

        if ($this->hasOption('pretend')) {
            $due = $this->scheduler->due($moment);

            if ($due === []) {
                $this->info('Nothing is due.');

                return self::SUCCESS;
            }

            foreach ($due as $task) {
                $this->info(sprintf('would dispatch  %-32s %s', $task->name, $task->kind . ':' . $task->target));
            }

            return self::SUCCESS;
        }

        $results = $this->scheduler->run($moment);

        if ($results === []) {
            // Silent by design: this runs every minute and almost always has
            // nothing to do. A line per tick would bury the interesting ones.
            return self::SUCCESS;
        }

        $this->report($results);

        // A dispatch failure must reach the exit code — an operator's only
        // signal that the tick is unhealthy is cron's mail on a non-zero exit.
        foreach ($results as $result) {
            if ($result['outcome'] === 'failed') {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /** @return \DateTimeImmutable|null|false null = now, false = unparseable */
    private function moment(): \DateTimeImmutable|null|false
    {
        $at = (string) ($this->option('at') ?? '');

        if ($at === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($at);
        } catch (\Exception) {
            return false;
        }
    }

    /** @param list<array{task: string, outcome: string, detail: string}> $results */
    private function report(array $results): void
    {
        foreach ($results as $result) {
            $line = sprintf('%-9s %-32s %s', $result['outcome'], $result['task'], $result['detail']);

            match ($result['outcome']) {
                'failed'  => $this->error($line),
                'skipped' => $this->info($line),
                default   => $this->success($line),
            };
        }
    }
}
