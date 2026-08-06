<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Config\Repository;

/**
 * Show resolved configuration from the compiled config manifest.
 *
 * Usage:
 *   config:show                      every group, with key counts
 *   config:show mail                 one group, flattened to dotted keys
 *   config:show mail.from.address    a single resolved value
 *   config:show --json               raw JSON of whatever was selected
 *
 * This answers the question the old per-plugin config lookup could not: what
 * value is ACTUALLY in effect after the project's overrides were merged over
 * the plugin defaults.
 */
final class ConfigShowCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'config:show';
        $this->description = 'Show resolved configuration (group, dotted key, or everything)';

        $this->addArgument('key', 'Config group or dotted key', required: false);
        $this->addOption('json', 'j', 'Output raw JSON');
    }

    protected function handle(): int
    {
        $items = ManifestReader::readCompiled('config-manifest.php');

        if ($items === []) {
            $this->warning('No config manifest found. Run the app once (or config:cache) to compile it.');

            return self::SUCCESS;
        }

        $config = new Repository($items);
        $key    = (string) ($this->argument('key') ?? '');

        if ($key === '') {
            return $this->showGroups($items);
        }

        if (!$config->has($key)) {
            $this->error("Config key [{$key}] is not set.");

            return self::FAILURE;
        }

        $value = $config->get($key);

        if ($this->hasOption('json')) {
            $this->info((string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (!is_array($value)) {
            $this->info($key . ' = ' . $this->render($value));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($this->flatten($value, $key) as $dotted => $leaf) {
            $rows[] = [$dotted, $this->render($leaf)];
        }

        $this->table()->headers(['Key', 'Value'])->rows($rows)->render();

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $items */
    private function showGroups(array $items): int
    {
        if ($this->hasOption('json')) {
            $this->info((string) json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($items as $group => $values) {
            $rows[] = [
                (string) $group,
                is_array($values) ? (string) count($this->flatten($values, (string) $group)) : '1',
            ];
        }

        $this->table()->headers(['Group', 'Keys'])->rows($rows)->render();
        $this->newLine();
        $this->info(count($items) . ' config group(s). Pass a group or dotted key to drill in.');

        return self::SUCCESS;
    }

    /**
     * Flatten nested config to dotted leaves so the table stays readable.
     *
     * @param  array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function flatten(array $values, string $prefix): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $dotted = $prefix . '.' . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $flat += $this->flatten($value, $dotted);
                continue;
            }
            $flat[$dotted] = $value;
        }

        return $flat;
    }

    private function render(mixed $value): string
    {
        return match (true) {
            $value === null    => 'null',
            is_bool($value)    => $value ? 'true' : 'false',
            is_array($value)   => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            default            => (string) $value,
        };
    }
}
