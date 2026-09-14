<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Scheduling;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{CachePort, ClockPort, LoggerPort, QueuePort, SystemClock};

/**
 * Scheduler — decides which declared tasks are due, and dispatches them.
 *
 * THE TICK MODEL
 * --------------
 * One OS-level cron entry drives everything:
 *
 *     * * * * * cd /srv/app && hkm cli schedule:run >> /dev/null 2>&1
 *
 * Every minute the runner asks each compiled task whether it is due now. That is
 * the same model Laravel uses and it is the right one here: the operator
 * maintains ONE crontab line for the life of the application, and adding a task
 * is a module.json edit rather than a server change — which matters a great deal
 * when the unit of deployment is a plugin someone else wrote.
 *
 * WHY DUE TASKS ARE QUEUED, NOT RUN
 * ---------------------------------
 * A `job` task is pushed onto the queue and the tick returns immediately. This
 * is deliberate:
 *
 *   - the tick must finish well inside a minute, or the next tick overlaps it
 *     and every schedule starts drifting;
 *   - a queued job gets the worker's retry strategy, timeout and signing for
 *     free, so "the nightly report failed" is a retry rather than a silence;
 *   - the job runs in a WorkerLoop container with the project's essentials
 *     registered, which a command run from a bare scheduler process would not
 *     have.
 *
 * A `command` task runs in-process, because that is what a command IS. Keep them
 * short; anything slow should be a job.
 *
 * OVERLAP PROTECTION IS OPT-IN AND LOCK-BACKED
 * --------------------------------------------
 * `"withoutOverlapping": true` takes a CachePort lock keyed by task name before
 * dispatching, and does not dispatch when the lock is held. This is what makes
 * the scheduler safe to run on SEVERAL application servers at once — the common
 * deployment, and the one where an unguarded schedule sends every customer two
 * invoice emails. Without a CachePort bound there is nothing to lock with, so an
 * overlap-guarded task is SKIPPED and the reason logged, rather than run
 * unguarded: silently dropping the protection someone explicitly asked for is
 * the worse failure.
 */
final class Scheduler
{
    /** @var list<ScheduledTask>|null lazily read from the compiled manifest */
    private ?array $tasks = null;

    public function __construct(
        private readonly ?QueuePort  $queue = null,
        private readonly ?CachePort  $cache = null,
        private readonly ?LoggerPort $logger = null,
        private readonly ClockPort   $clock = new SystemClock(),
        /**
         * Runs a `command` task. Injected rather than resolved so the scheduler
         * has no dependency on the CLI pipeline — which lets it be tested with a
         * closure, and keeps the two surfaces independent.
         *
         * @var (callable(string): int)|null
         */
        private readonly mixed $commandRunner = null,
    ) {}

    /**
     * Every declared task, in manifest order.
     *
     * @return list<ScheduledTask>
     */
    public function tasks(): array
    {
        if ($this->tasks !== null) {
            return $this->tasks;
        }

        $tasks = [];
        foreach (ManifestReader::readCompiled('schedule-manifest.php') as $entry) {
            if (is_array($entry)) {
                $tasks[] = ScheduledTask::fromArray($entry);
            }
        }

        return $this->tasks = $tasks;
    }

    /**
     * The tasks due at $moment (default: now).
     *
     * @return list<ScheduledTask>
     */
    public function due(?\DateTimeImmutable $moment = null): array
    {
        $moment = $moment ?? $this->clock->now();

        return array_values(array_filter(
            $this->tasks(),
            static fn(ScheduledTask $t): bool => $t->dueAt($moment),
        ));
    }

    /**
     * Dispatch everything due at $moment.
     *
     * One task's failure never stops the others: a scheduler that abandons the
     * remaining twelve tasks because the first one's queue driver was briefly
     * unreachable turns a small outage into a missed night.
     *
     * @return list<array{task: string, outcome: string, detail: string}>
     */
    public function run(?\DateTimeImmutable $moment = null): array
    {
        $results = [];

        foreach ($this->due($moment) as $task) {
            try {
                $results[] = ['task' => $task->name, ...$this->dispatch($task)];
            } catch (\Throwable $e) {
                $this->logger?->error('Scheduled task [{task}] failed to dispatch: {message}', [
                    'task'      => $task->name,
                    'message'   => $e->getMessage(),
                    'exception' => $e,
                ]);

                $results[] = ['task' => $task->name, 'outcome' => 'failed', 'detail' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Dispatch ONE task by name regardless of whether it is due — `schedule:run
     * --task=x`. Returns null when no such task is declared.
     *
     * @return array{task: string, outcome: string, detail: string}|null
     */
    public function runTask(string $name): ?array
    {
        foreach ($this->tasks() as $task) {
            if ($task->name === $name) {
                return ['task' => $task->name, ...$this->dispatch($task)];
            }
        }

        return null;
    }

    /** @return array{outcome: string, detail: string} */
    private function dispatch(ScheduledTask $task): array
    {
        $lock = null;

        if ($task->withoutOverlapping) {
            if ($this->cache === null) {
                $this->logger?->warning(
                    'Scheduled task [{task}] asked for overlap protection but no CachePort is bound — skipped.',
                    ['task' => $task->name],
                );

                return ['outcome' => 'skipped', 'detail' => 'no CachePort bound for the overlap lock'];
            }

            $lock = $this->cache->lock($task->lockKey(), $task->expiresAfter);

            if (!$lock->acquire()) {
                return ['outcome' => 'skipped', 'detail' => 'a previous run still holds the lock'];
            }
        }

        try {
            return $task->kind === ScheduledTask::KIND_JOB
                ? $this->queueJob($task)
                : $this->runCommand($task);
        } catch (\Throwable $e) {
            // Release on failure so a crashed dispatch does not block the task
            // until the lock expires. A SUCCESSFUL job dispatch deliberately
            // keeps the lock — see queueJob().
            $lock?->release();

            throw $e;
        }
    }

    /** @return array{outcome: string, detail: string} */
    private function queueJob(ScheduledTask $task): array
    {
        if ($this->queue === null) {
            return ['outcome' => 'skipped', 'detail' => 'no QueuePort bound'];
        }

        $id = $this->queue->push($task->target, $task->payload, $task->queue);

        // The lock is NOT released here. The point of overlap protection on a
        // queued task is that the WORK does not overlap, and the work has only
        // just been enqueued — releasing now would let the next tick queue a
        // second copy while the first is still running. It expires on its own
        // after expiresAfter, which is why that value describes the task's
        // runtime rather than the dispatch.
        return ['outcome' => 'queued', 'detail' => $task->queue . ' #' . $id];
    }

    /** @return array{outcome: string, detail: string} */
    private function runCommand(ScheduledTask $task): array
    {
        if (!is_callable($this->commandRunner)) {
            return ['outcome' => 'skipped', 'detail' => 'no command runner wired'];
        }

        $code = ($this->commandRunner)($task->target);

        return $code === 0
            ? ['outcome' => 'ran', 'detail' => $task->target]
            : ['outcome' => 'failed', 'detail' => $task->target . ' exited ' . $code];
    }
}
