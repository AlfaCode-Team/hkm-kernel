<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{ManifestReader, ManifestWriter};

/**
 * Reads jobs[] from every module.json -> job-manifest.php.
 *
 * RETRY AND TIMEOUT ARE PART OF THE DECLARATION.
 *
 * module.json has always documented them —
 *
 *     { "type": "job", "queue": "emails", "timeout": 30,
 *       "retry": { "max": 3, "strategy": "exponential", "jitter": true } }
 *
 * — but this stage used to drop both on the floor, so every job in every
 * application silently shared one hardcoded exponential strategy and no timeout
 * at all. A declaration that compiles to nothing is worse than no declaration:
 * it reads as a guarantee. They are compiled through now, and WorkerLoop honours
 * them per job.
 */
final class CompileJobManifestStage implements BootStageContract
{
    /** @param list<class-string> $moduleClasses */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {}

    public function run(): void
    {
        $jobs = [];
        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $this->reader->read($moduleClass);
            foreach ($manifest['jobs'] ?? [] as $job) {
                $name = is_array($job) ? ($job['name'] ?? null) : $job;
                if ($name === null) {
                    continue;
                }
                $spec = is_array($job) ? $job : [];

                $jobs[$name] = [
                    'handler' => $spec['handler'] ?? $name,
                    'queue'   => $spec['queue'] ?? 'default',
                    'module'  => $moduleClass,
                    'solves'  => $manifest['solves'] ?? '',
                    // Fall back to the module-wide declaration: a module.json
                    // whose "type" is "job" states retry/timeout at the top
                    // level, which is the shape the docs show.
                    'retry'   => self::retry($spec['retry'] ?? $manifest['retry'] ?? null),
                    'timeout' => self::timeout($spec['timeout'] ?? $manifest['timeout'] ?? null),
                ];
            }
        }

        ManifestWriter::write('job-manifest.php', $jobs);
    }

    /**
     * Normalise a `retry` declaration into a shape WorkerLoop can act on without
     * re-parsing JSON per job.
     *
     * An UNDECLARED retry compiles to null, not to a default: that keeps "this
     * job says nothing" distinguishable from "this job asked for the defaults",
     * so the loop's own fallback stays the single place the default lives.
     *
     * @return array{max: int, strategy: string, base: int, jitter: bool}|null
     */
    private static function retry(mixed $spec): ?array
    {
        if ($spec === null) {
            return null;
        }

        // "retry": 5 — the shorthand for "just give me five attempts".
        if (is_int($spec) || (is_string($spec) && ctype_digit($spec))) {
            $spec = ['max' => (int) $spec];
        }

        if (!is_array($spec)) {
            return null;
        }

        $strategy = strtolower((string) ($spec['strategy'] ?? 'exponential'));

        return [
            // A job may not declare fewer than one attempt — "retry": {"max": 0}
            // would mean the job can never run, which is never what it means.
            'max'      => max(1, (int) ($spec['max'] ?? 3)),
            'strategy' => in_array($strategy, ['exponential', 'linear', 'fixed'], true)
                ? $strategy
                : 'exponential',
            'base'     => max(1, (int) ($spec['base'] ?? $spec['delay'] ?? 1)),
            'jitter'   => (bool) ($spec['jitter'] ?? false),
        ];
    }

    /** Seconds a single attempt may run, or null when the job declares none. */
    private static function timeout(mixed $spec): ?int
    {
        if ($spec === null || !is_numeric($spec)) {
            return null;
        }

        $seconds = (int) $spec;

        return $seconds > 0 ? $seconds : null;
    }
}
