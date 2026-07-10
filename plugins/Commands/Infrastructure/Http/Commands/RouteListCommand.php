<?php

declare(strict_types=1);

namespace Plugins\Commands\Infrastructure\Http\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\Depends\Colors;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * route:list — display every route compiled into the route manifest.
 *
 * The kernel compiles all plugin + project routes into
 * `var/cache/manifests/route-manifest.php` (CompileRouteManifestStage). That file
 * is the single source of truth for "what URLs does this app answer", so this
 * command reads it directly rather than re-parsing module.json files — run a boot
 * (any entry point) first so the manifest exists.
 *
 * Usage:
 *   hkm route:list
 *   hkm route:list --method=GET
 *   hkm route:list --path=/api
 *   hkm route:list --json
 *
 * Each manifest entry is keyed by "METHOD path" and carries:
 *   handler, module, solves, filters[], requires[], overrides
 */
final class RouteListCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'route:list';
        $this->description = 'List all routes compiled into the route manifest';
        $this->help        = <<<'HELP'
Reads the compiled route manifest (var/cache/manifests/route-manifest.php) and
prints every registered route with its handler, owning module/scope, filters and
per-route module requires.

Project routes resolve under the synthetic "__project__" scope; a project route
that overrides a plugin route shows the overridden module.

Options:
  --method=VERB   Only routes matching this HTTP method (case-insensitive)
  --path=PREFIX   Only routes whose path starts with PREFIX
  --json          Emit the raw manifest as JSON (for scripting)

Examples:
  hkm route:list
  hkm route:list --method=POST
  hkm route:list --path=/api/invoices
  hkm route:list --json
HELP;

        $this->addOption('method', 'm', 'Filter by HTTP method', acceptsValue: true);
        $this->addOption('path',   'p', 'Filter by path prefix',  acceptsValue: true);
        $this->addOption('json',   'j', 'Output the manifest as JSON');
    }

    protected function handle(): int
    {
        $manifest = $this->loadManifest();

        if ($manifest === null) {
            $this->alertWarning('Route manifest not found', [
                'Expected: ' . Paths::cache('manifests/route-manifest.php'),
                'Boot the app once (any entry point) to compile it, then retry.',
            ]);
            return self::FAILURE;
        }

        $methodFilter = strtoupper(trim((string) $this->option('method', '')));
        $pathFilter   = (string) $this->option('path', '');

        $rows = $this->filterRoutes($manifest, $methodFilter, $pathFilter);

        if ($this->hasOption('json')) {
            echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('No routes match the given filters.');
            return self::SUCCESS;
        }

        $this->section('Registered routes');

        $tableRows = [];
        foreach ($rows as $key => $entry) {
            [$method, $path] = array_pad(explode(' ', $key, 2), 2, '');

            $tableRows[] = [
                $this->colorMethod($method),
                $path,
                (string) ($entry['handler'] ?? '—'),
                $this->scopeLabel($entry),
                $this->listLabel($entry['filters'] ?? []),
                $this->listLabel($entry['requires'] ?? []),
            ];
        }

        $this->table()
            ->headers(['Method', 'Path', 'Handler', 'Scope', 'Filters', 'Requires'])
            ->rows($tableRows)
            ->render();

        $this->muted('  ' . count($rows) . ' route' . (count($rows) === 1 ? '' : 's') . ' shown');

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function loadManifest(): ?array
    {
        $path = Paths::cache('manifests/route-manifest.php');

        if (!is_file($path)) {
            return null;
        }

        /** @var mixed $manifest */
        $manifest = require $path;

        return is_array($manifest) ? $manifest : [];
    }

    /**
     * @param array<string, array<string, mixed>> $manifest
     * @return array<string, array<string, mixed>>
     */
    private function filterRoutes(array $manifest, string $methodFilter, string $pathFilter): array
    {
        $filtered = [];

        foreach ($manifest as $key => $entry) {
            [$method, $path] = array_pad(explode(' ', $key, 2), 2, '');

            if ($methodFilter !== '' && strtoupper($method) !== $methodFilter) {
                continue;
            }
            if ($pathFilter !== '' && !str_starts_with($path, $pathFilter)) {
                continue;
            }

            $filtered[$key] = $entry;
        }

        // Deterministic order: path, then method.
        uksort($filtered, static function (string $a, string $b): int {
            [$ma, $pa] = array_pad(explode(' ', $a, 2), 2, '');
            [$mb, $pb] = array_pad(explode(' ', $b, 2), 2, '');
            return [$pa, $ma] <=> [$pb, $mb];
        });

        return $filtered;
    }

    private function colorMethod(string $method): string
    {
        $method = strtoupper($method);

        return match ($method) {
            'GET'    => Colors::wrap($method, Colors::GREEN),
            'POST'   => Colors::wrap($method, Colors::BLUE),
            'PUT',
            'PATCH'  => Colors::wrap($method, Colors::YELLOW),
            'DELETE' => Colors::wrap($method, Colors::RED),
            default  => Colors::muted($method),
        };
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function scopeLabel(array $entry): string
    {
        $solves = (string) ($entry['solves'] ?? '');

        if ($solves === '__project__') {
            $overrides = $entry['overrides'] ?? null;
            $label     = Colors::wrap('project', Colors::CYAN);
            return $overrides !== null
                ? $label . Colors::muted(' (overrides ' . $this->shortClass((string) $overrides) . ')')
                : $label;
        }

        return $solves !== '' ? $solves : Colors::muted('—');
    }

    /**
     * @param mixed $list
     */
    private function listLabel(mixed $list): string
    {
        if (!is_array($list) || $list === []) {
            return Colors::muted('—');
        }

        return implode(', ', array_map(static fn($v): string => (string) $v, $list));
    }

    private function shortClass(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts) ?: $class;
    }
}
