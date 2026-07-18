<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Edge\API\Contracts\EdgeServiceContract;

/**
 * edge:service — render the process-manager unit that keeps a project's
 * OpenSwoole server alive behind the nginx reverse proxy. PHP-FPM projects are
 * skipped (php-fpm already supervises those workers).
 *
 *   hkm edge:service                       # print the systemd unit(s)
 *   hkm edge:service --supervisor          # print supervisor program block(s)
 *   hkm edge:service --all                 # every registered project
 *   hkm edge:service --user=deploy         # run the service as this user
 *   hkm edge:service --write               # write to the default unit dir
 *   hkm edge:service --write=/tmp/units    # write into a specific directory
 *
 * Environment flags mirror edge:apply (--local / --development / -d / --production).
 * Writing to /etc/systemd/system needs root; afterwards run:
 *   systemctl daemon-reload && systemctl enable --now <unit>
 */
final class EdgeServiceCommand extends AbstractCommand
{
    private const SYSTEMD_DIR    = '/etc/systemd/system';
    private const SUPERVISOR_DIR = '/etc/supervisor/conf.d';

    public function __construct(private readonly EdgeServiceContract $edge)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'edge:service';
        $this->description = 'Generate the systemd/supervisor unit for a project\'s OpenSwoole server';

        $this->addOption('supervisor', '', 'Render a supervisor program block instead of a systemd unit');
        $this->addOption('all', '', 'Include every registered project (default: only the current one)');
        $this->addOption('user', '', 'User/group the service runs as (default: www-data)', true);
        $this->addOption('write', '', 'Write the unit(s) to disk; optionally give a target directory', true);
        $this->addOption('local', '', 'APP_ENV=local');
        $this->addOption('dev', '', 'Alias for --local');
        $this->addOption('development', 'd', 'APP_ENV=development');
        $this->addOption('production', '', 'APP_ENV=production');
    }

    protected function handle(): int
    {
        $supervisor = $this->hasOption('supervisor');
        $format     = $supervisor ? 'supervisor' : 'systemd';

        $appEnv = $this->resolveAppEnv();
        if ($appEnv === false) {
            $this->error('Pick ONE environment: --local (or --dev), --development/-d, or --production.');

            return self::INVALID;
        }

        $user  = $this->stringOption('user') ?? 'www-data';
        $units = $this->edge->serviceUnits($format, $this->hasOption('all'), $appEnv, $user);

        if ($units === []) {
            $this->warning('No OpenSwoole project in scope — nothing to generate.');
            $this->muted('  PHP-FPM projects need no unit (php-fpm supervises them). Set');
            $this->muted('  proj.json "edge": { "runtime": "openswoole" } to switch a project over.');

            return self::SUCCESS;
        }

        $write = $this->option('write');
        if ($write === null || $write === false) {
            foreach ($units as $name => $body) {
                $this->info('# ' . $name . ($supervisor ? '.conf' : '.service'));
                $this->newLine();
                $this->muted($body);
            }

            return self::SUCCESS;
        }

        $dir = is_string($write) && $write !== ''
            ? rtrim($write, '/')
            : ($supervisor ? self::SUPERVISOR_DIR : self::SYSTEMD_DIR);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->error("Cannot create directory {$dir}");

            return self::FAILURE;
        }

        foreach ($units as $name => $body) {
            $file = $dir . '/' . $name . ($supervisor ? '.conf' : '.service');
            if (@file_put_contents($file, $body) === false) {
                $this->error("Failed to write {$file} (need root?)");

                return self::FAILURE;
            }
            $this->success('wrote ' . $file);
        }

        $this->newLine();
        $this->info($supervisor
            ? 'Next: supervisorctl reread && supervisorctl update'
            : 'Next: systemctl daemon-reload && systemctl enable --now <unit>');

        return self::SUCCESS;
    }

    /** Mirrors edge:apply — 'local'|'development'|'production', null, or false on conflict. */
    private function resolveAppEnv(): string|false|null
    {
        $picked = array_keys(array_filter([
            'local'       => $this->hasOption('local') || $this->hasOption('dev'),
            'development' => $this->hasOption('development'),
            'production'  => $this->hasOption('production'),
        ]));

        return match (\count($picked)) {
            0       => null,
            1       => $picked[0],
            default => false,
        };
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
