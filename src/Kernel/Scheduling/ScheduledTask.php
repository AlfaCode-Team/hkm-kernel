<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Scheduling;

/**
 * ScheduledTask — one compiled `schedule[]` entry from a module.json.
 *
 * Immutable and self-contained: the runner needs nothing but this object and a
 * clock to decide whether the task should run right now.
 */
final readonly class ScheduledTask
{
    public const KIND_JOB     = 'job';
    public const KIND_COMMAND = 'command';

    /**
     * @param non-empty-string           $name       application-wide unique
     * @param self::KIND_*               $kind       queue a job, or run a CLI command
     * @param string                     $target     job name, or command line
     * @param array<string, mixed>       $payload    job payload (job kind only)
     * @param bool                       $withoutOverlapping whether a lock guards it
     * @param int                        $expiresAfter seconds the overlap lock is held
     */
    public function __construct(
        public string          $name,
        public CronExpression  $cron,
        public string          $kind,
        public string          $target,
        public string          $module = '',
        public string          $solves = '',
        public string          $queue = 'default',
        public array           $payload = [],
        public bool            $withoutOverlapping = false,
        public int             $expiresAfter = 3600,
        public string          $timezone = '',
    ) {}

    /**
     * Is this task due at $moment?
     *
     * The moment is converted into the task's own timezone first, so a report
     * declared `"at": "0 9 * * 1"` in `Africa/Nairobi` runs at 9am Nairobi time
     * regardless of where the server is. Without this a schedule is only correct
     * while the server stays in one region, which is exactly the assumption that
     * breaks silently during a migration.
     */
    public function dueAt(\DateTimeImmutable $moment): bool
    {
        if ($this->timezone !== '') {
            $moment = $moment->setTimezone(new \DateTimeZone($this->timezone));
        }

        return $this->cron->dueAt($moment);
    }

    /** The cache key an overlap lock is held under. */
    public function lockKey(): string
    {
        return 'schedule:lock:' . $this->name;
    }

    /** @return array<string, mixed> the compiled manifest shape */
    public function toArray(): array
    {
        return [
            'name'                => $this->name,
            'at'                  => $this->cron->expression,
            'kind'                => $this->kind,
            'target'              => $this->target,
            'module'              => $this->module,
            'solves'              => $this->solves,
            'queue'               => $this->queue,
            'payload'             => $this->payload,
            'withoutOverlapping'  => $this->withoutOverlapping,
            'expiresAfter'        => $this->expiresAfter,
            'timezone'            => $this->timezone,
        ];
    }

    /** Rebuild from the compiled manifest. @param array<string, mixed> $entry */
    public static function fromArray(array $entry): self
    {
        return new self(
            /** @phpstan-ignore-next-line the compiler guarantees a non-empty name */
            name:               (string) $entry['name'],
            cron:               CronExpression::parse((string) $entry['at']),
            kind:               (string) $entry['kind'],
            target:             (string) $entry['target'],
            module:             (string) ($entry['module'] ?? ''),
            solves:             (string) ($entry['solves'] ?? ''),
            queue:              (string) ($entry['queue'] ?? 'default'),
            payload:            is_array($entry['payload'] ?? null) ? $entry['payload'] : [],
            withoutOverlapping: (bool) ($entry['withoutOverlapping'] ?? false),
            expiresAfter:       (int) ($entry['expiresAfter'] ?? 3600),
            timezone:           (string) ($entry['timezone'] ?? ''),
        );
    }
}
