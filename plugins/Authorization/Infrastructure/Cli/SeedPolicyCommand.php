<?php

declare(strict_types=1);

namespace Plugins\Authorization\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Authorization\Engine\Enforcer;

/**
 * authz:seed — import a Casbin CSV policy file into the database policy table.
 *
 * Loads the role hierarchy + permission grants from a policy CSV (the old
 * __DEV__ etc/casbin/policy.csv format: `p, role, object, action` and
 * `g, user, role` lines; `#` comments) into the DatabasePolicyAdapter-backed
 * store. Existing identical rules are skipped, so re-running is idempotent.
 *
 *   hkm authz:seed                     # seed from the bundled policy.seed.csv
 *   hkm authz:seed --file=/path/x.csv  # seed from a custom CSV
 *   hkm authz:seed --dry               # parse + report without writing
 */
final class SeedPolicyCommand extends AbstractCommand
{
    /** @var \Closure(): Enforcer lazy — building an Enforcer loads policy from the DB */
    private readonly \Closure $enforcerFactory;

    /** @param \Closure(): Enforcer $enforcerFactory */
    public function __construct(
        \Closure $enforcerFactory,
        private readonly string $defaultFile,
    ) {
        $this->enforcerFactory = $enforcerFactory;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'authz:seed';
        $this->description = 'Seed the Casbin policy table from a policy CSV file';

        $this->addOption('file', '', 'Path to the policy CSV', acceptsValue: true, default: '');
        $this->addOption('dry', '', 'Parse and report without writing');
    }

    protected function handle(): int
    {
        $file = (string) $this->option('file') ?: $this->defaultFile;
        if (!is_readable($file)) {
            $this->error("Policy file [{$file}] is not readable.");

            return self::FAILURE;
        }

        $policies  = [];
        $groupings = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode(',', $line));
            $type  = array_shift($parts);

            if ($type === 'p' && \count($parts) >= 3) {
                $policies[] = $parts;
            } elseif ($type === 'g' && \count($parts) >= 2) {
                $groupings[] = $parts;
            }
        }

        $this->info(\sprintf('Parsed %d policy rule(s) and %d role assignment(s) from %s.', \count($policies), \count($groupings), $file));

        if ($this->hasOption('dry')) {
            $this->info('Dry run — nothing written.');

            return self::SUCCESS;
        }

        $enforcer = ($this->enforcerFactory)();

        $added = 0;
        foreach ($policies as $rule) {
            if ($enforcer->addPolicy(...$rule)) {
                $added++;
            }
        }
        foreach ($groupings as $rule) {
            if ($enforcer->addGroupingPolicy(...$rule)) {
                $added++;
            }
        }

        $skipped = (\count($policies) + \count($groupings)) - $added;
        $this->info("Seeded {$added} new rule(s); {$skipped} already present.");

        return self::SUCCESS;
    }
}
