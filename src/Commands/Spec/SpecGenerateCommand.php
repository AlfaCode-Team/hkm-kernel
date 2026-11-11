<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Spec;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Spec\RouteSpecGenerator;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * spec:generate — write an OpenAPI 3.1 document for the compiled route surface.
 *
 * Reads `var/cache/manifests/route-manifest.php` and nothing else. No server is
 * booted, no controller is autoloaded, no route is executed — the manifest is
 * already the compiled, validated description of what the matcher matches, so
 * generating a spec is a file read and a transform.
 *
 * That is the pay-off of compiling routes at boot. A framework that registers
 * routes at runtime has to start an application and introspect a live route
 * table to produce the same document, and its spec can disagree with its
 * routing. This one cannot: the input IS the routing table.
 *
 * Usage:
 *   hkm cli spec:generate
 *   hkm cli spec:generate --output public/openapi.json --server https://api.example.com
 *   hkm cli spec:generate --title "Billing API" --api-version 2.1.0 --exclude /_internal,/mock
 */
final class SpecGenerateCommand extends AbstractCommand
{
    private const DEFAULT_OUTPUT = 'openapi.json';

    protected function configure(): void
    {
        $this->name = 'spec:generate';
        $this->description = 'Generate an OpenAPI 3.1 document from the compiled route manifest';
        $this->help = <<<'HELP'
Writes an OpenAPI 3.1 document describing every route in the compiled manifest.

The manifest must exist, which means the application must have booted at least
once since the last cache clear. If it is missing, run any command or request
first, or clear and rebuild with your usual deploy step.

What the document describes accurately:
  - every method and path, with group prefixes already expanded
  - typed path parameters, carrying the SAME pattern the router enforces
  - optional segments, expanded into the two URL shapes they actually match
  - which endpoints require authentication, and which permission they need
  - which endpoints are rate limited
  - the kernel's error envelope on 401/403/404/422/429/500

What it cannot describe, and says so rather than inventing:
  - request and response BODY shapes. No route declaration carries them, so
    they are emitted as undescribed objects. A client generated from that
    compiles and hands back raw data; one generated from a guessed shape does
    not compile at all.

Examples:
  hkm cli spec:generate
  hkm cli spec:generate --output public/openapi.json
  hkm cli spec:generate --title "Billing API" --api-version 2.1.0
  hkm cli spec:generate --server https://api.example.com --exclude /_internal,/mock
HELP;

        $this->addOption('output', 'o', 'Where to write the document (relative paths resolve against the project root)', acceptsValue: true);
        $this->addOption('title', 't', 'API title for the info block', acceptsValue: true, default: 'API');
        $this->addOption('api-version', '', 'API version for the info block', acceptsValue: true, default: '1.0.0');
        $this->addOption('description', 'd', 'Description for the info block', acceptsValue: true);
        $this->addOption('server', 's', 'Base server URL', acceptsValue: true);
        $this->addOption('exclude', 'x', 'Comma-separated path prefixes to leave out', acceptsValue: true);
    }

    protected function handle(): int
    {
        $manifestPath = Paths::cache('manifests/route-manifest.php');

        if (!is_file($manifestPath)) {
            $this->alertError('No route manifest', [
                'Expected: ' . $manifestPath,
                '',
                'The manifest is written when the application boots. Run any CLI command or',
                'serve one request, then try again.',
            ]);

            return 1;
        }

        /** @var mixed $manifest */
        $manifest = require $manifestPath;

        if (!is_array($manifest)) {
            $this->error('The route manifest did not return an array: ' . $manifestPath);

            return 1;
        }

        if ($manifest === []) {
            $this->warning('The route manifest is empty — the generated document will describe no paths.');
        }

        /** @var array<string, array<string, mixed>> $manifest */
        $document = (new RouteSpecGenerator(
            title: (string) $this->option('title', 'API'),
            version: (string) $this->option('api-version', '1.0.0'),
            description: $this->stringOrNull('description'),
            excludePrefixes: $this->excludePrefixes(),
            serverUrl: $this->stringOrNull('server'),
        ))->generate($manifest);

        $target = $this->resolveOutput();

        $directory = \dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->error("Could not create the output directory: {$directory}");

            return 1;
        }

        if (@file_put_contents($target, $document->toJson()) === false) {
            $this->error("Could not write: {$target}");

            return 1;
        }

        $this->section('OpenAPI document written');

        $this->table()
            ->headers(['Field', 'Value'])
            ->rows([
                ['File', $target],
                ['Routes in manifest', (string) count($manifest)],
                ['Operations described', (string) $document->operationCount()],
                ['OpenAPI version', '3.1.0'],
            ])
            ->render();

        // Operations can legitimately exceed routes: a route with an optional
        // segment matches two URL shapes and OpenAPI needs a path template for
        // each, so it is described twice.
        $this->success('Done.');

        return 0;
    }

    /** @return list<string> */
    private function excludePrefixes(): array
    {
        $raw = $this->stringOrNull('exclude');

        if ($raw === null) {
            return [];
        }

        $prefixes = [];
        foreach (explode(',', $raw) as $prefix) {
            $prefix = trim($prefix);
            if ($prefix !== '') {
                $prefixes[] = str_starts_with($prefix, '/') ? $prefix : '/' . $prefix;
            }
        }

        return $prefixes;
    }

    private function resolveOutput(): string
    {
        $output = $this->stringOrNull('output');

        if ($output === null) {
            // var/ rather than public/: the document is a build artefact, and
            // writing into a web-served directory by default would publish the
            // whole route surface without anyone asking for it.
            return Paths::var(self::DEFAULT_OUTPUT);
        }

        return str_starts_with($output, '/') ? $output : Paths::project($output);
    }

    private function stringOrNull(string $option): ?string
    {
        $value = $this->option($option);

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
