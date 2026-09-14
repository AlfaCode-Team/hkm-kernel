<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Schedule;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Scheduling\Scheduler;

/**
 * `schedule:list` — everything the compiled manifest declares, and when each
 * next runs.
 *
 * The "next run" column is the point of this command. A cron expression is not
 * readable at a glance, and the failure it hides is always the same: the task is
 * declared, the boot accepted it, and it fires at a time nobody intended. Showing
 * the next two occurrences turns that into something an operator can check.
 */
final class ScheduleListCommand extends AbstractCommand
{
    public function __construct(private readonly Scheduler $scheduler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'schedule:list';
        $this->description = 'List every scheduled task and when it next runs.';

        $this->addOption('next', 'n', 'How many upcoming runs to show per task (default 1).', acceptsValue: true, default: 1);
    }

    protected function handle(): int
    {
        $tasks = $this->scheduler->tasks();

        if ($tasks === []) {
            $this->info('No scheduled tasks are declared.');
            $this->info('Declare them in a module.json "schedule": [ { "name": …, "at": …, "job": … } ].');

            return self::SUCCESS;
        }

        $count = max(1, min(10, (int) $this->option('next', 1)));
        $rows  = [];

        foreach ($tasks as $task) {
            $rows[] = [
                $task->name,
                $task->cron->expression,
                $task->kind . ':' . $task->target,
                $task->withoutOverlapping ? 'yes' : '',
                $task->timezone !== '' ? $task->timezone : 'server',
                implode(', ', $this->upcoming($task, $count)),
            ];
        }

        $this->table()
            ->headers(['Task', 'At', 'Runs', 'No overlap', 'TZ', 'Next'])
            ->rows($rows)
            ->render();

        return self::SUCCESS;
    }

    /**
     * The next $count moments this task fires.
     *
     * Found by walking forward a minute at a time. That is not elegant, but a
     * cron expression has no closed form and the search is bounded: any valid
     * five-field expression that fires at all fires within about 4 years
     * (Feb 29 on a leap year is the worst case), and the common ones resolve in
     * a handful of steps. Bounded at ~4 years of minutes so a pathological
     * expression cannot hang the command.
     *
     * @return list<string>
     */
    private function upcoming(\AlfacodeTeam\PhpServicePlatform\Kernel\Scheduling\ScheduledTask $task, int $count): array
    {
        $found  = [];
        $cursor = new \DateTimeImmutable('now');
        // Start from the top of the NEXT minute: "now" is mid-minute, and a task
        // due this minute has either already run or is about to.
        $cursor = $cursor->setTime((int) $cursor->format('G'), (int) $cursor->format('i'), 0)
                         ->modify('+1 minute');

        $limit = 60 * 24 * 366 * 4;

        for ($i = 0; $i < $limit && count($found) < $count; $i++) {
            if ($task->dueAt($cursor)) {
                $found[] = $cursor->format('D d M H:i');
            }

            $cursor = $cursor->modify('+1 minute');
        }

        return $found === [] ? ['never'] : $found;
    }
}
