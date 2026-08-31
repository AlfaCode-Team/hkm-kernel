<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\Ground\Inspection\PluginLocator;

/**
 * make:ground-test <plugin> — write a ground test skeleton for a plugin.
 *
 * The skeleton is generated FROM the plugin's manifest: one test per declared
 * route, one per exposed contract, one per emitted event. That is the difference
 * between a template and a scaffold — the file that lands already names the
 * things this plugin actually has, so the first run tells you something.
 */
final class MakeGroundTestCommand extends AbstractCommand
{
    use ResolvesPlugin;

    protected function configure(): void
    {
        $this->name        = 'make:ground-test';
        $this->description = 'Scaffold a PluginGround test case from a plugin\'s manifest';

        $this->addArgument('plugin', 'Plugin name (default: the plugin you are standing in)');
        $this->addOption('force', 'f', 'Overwrite an existing file');
        $this->addOption('print', 'p', 'Write to stdout instead of a file');
    }

    protected function handle(): int
    {
        $manifest = $this->resolvePlugin('plugin');

        if ($manifest === null) {
            return self::FAILURE;
        }

        $namespace = substr($manifest->providerClass, 0, strrpos($manifest->providerClass, '\\') ?: 0);
        $class     = $this->studly($manifest->name()) . 'GroundTest';
        $source    = $this->render($manifest, $namespace, $class);

        if ($this->hasOption('print')) {
            $this->info($source);

            return self::SUCCESS;
        }

        $dir  = $manifest->directory() . '/tests';
        $path = $dir . '/' . $class . '.php';

        if (is_file($path) && !$this->hasOption('force')) {
            $this->error("{$path} already exists. Pass --force to overwrite it.");

            return self::FAILURE;
        }

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            $this->error("Could not create {$dir}.");

            return self::FAILURE;
        }

        file_put_contents($path, $source);

        $this->success("Wrote {$path}");
        $this->muted('Run it with: vendor/bin/phpunit ' . $path);

        // The file extends PluginGroundTestCase, so the harness has to BE
        // there. A plugin that has never been tested does not declare it, and
        // the resulting "class not found" reads as a broken scaffolder rather
        // than a missing dev dependency.
        if (!class_exists(\AlfacodeTeam\Ground\PluginGroundTestCase::class)) {
            $this->newLine();
            $this->warning('The ground harness is not installed in this plugin.');
            $this->muted('  bin/link-local            — for local development, touches no tracked file');
            $this->muted('  composer require --dev alfacode-team/hkm-plugin-ground   — to keep the tests');
        }

        return self::SUCCESS;
    }

    private function render(\AlfacodeTeam\Ground\PluginManifest $manifest, string $namespace, string $class): string
    {
        $tests = [];

        // The boot itself. Worth a test of its own: it is the assertion that
        // fails first, and a failure here explains every other failure.
        $tests[] = <<<'PHP'
            public function testItBoots(): void
            {
                $this->assertNotSame('', $this->ground()->manifest()->solves());
            }
        PHP;

        foreach ($manifest->routes() as $route) {
            $method = strtoupper((string) ($route['method'] ?? 'GET'));
            $path   = (string) ($route['path'] ?? '');
            $prefix = (string) ($manifest->data['routePrefix'] ?? '');
            $full   = ($prefix . $path) ?: '/';

            $tests[] = sprintf(
                <<<'PHP'
                    public function test%s(): void
                    {
                        $this->assertRouteExists('%s', '%s');

                        // $response = $this->ground()->%s('%s');
                        // $this->assertOk($response);
                    }
                PHP,
                $this->studly($method . '_' . str_replace(['/', '{', '}', ':'], '_', $full)),
                $method,
                $full,
                strtolower($method),
                $full,
            );
        }

        foreach ($manifest->exposes() as $contract) {
            $short = substr(strrchr($contract, '\\') ?: $contract, 1);

            $tests[] = sprintf(
                <<<'PHP'
                    public function testItResolves%s(): void
                    {
                        // Resolving in the plugin's own scope — the only scope that
                        // may reach its bindInternal() dependencies.
                        $this->assertInstanceOf(
                            \%s::class,
                            $this->ground()->service(\%s::class),
                        );
                    }
                PHP,
                $this->studly($short),
                $contract,
                $contract,
            );
        }

        foreach ($manifest->emits() as $event) {
            $tests[] = sprintf(
                <<<'PHP'
                    public function testItEmits%s(): void
                    {
                        $this->markTestIncomplete('Drive the action that emits %s, then assert it.');

                        // $this->assertDispatched('%s');
                    }
                PHP,
                $this->studly(str_replace('.', '_', $event)),
                $event,
                $event,
            );
        }

        $deps = $this->renderDependencies($manifest);

        $body = implode("\n\n", $tests);

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace}\\Tests;

        use AlfacodeTeam\\Ground\\PluginGroundTestCase;
        use {$namespace}\\Provider;

        /**
         * Ground tests for the {$manifest->name()} plugin.
         *
         * Generated by `make:ground-test` from module.json. Each test names
         * something the manifest declares; fill in the bodies.
         */
        final class {$class} extends PluginGroundTestCase
        {
            protected function plugin(): string
            {
                return Provider::class;
            }
        {$deps}
        {$body}
        }

        PHP;
    }

    /**
     * The `dependencies()` override, RESOLVED — not a placeholder comment.
     *
     * The locator already knows which installed plugin solves each domain (it
     * is how `plugin:probe` boots one), so emitting `// Plugins\Example\...`
     * and leaving the author to look them up was throwing away an answer this
     * command already had. A scaffold whose first run fails on a missing
     * dependency teaches nothing except that scaffolds do not work.
     *
     * Unresolvable domains are still listed, as a comment naming the domain —
     * that IS the useful output when the providing plugin is not installed.
     */
    private function renderDependencies(\AlfacodeTeam\Ground\PluginManifest $manifest): string
    {
        $requires = $manifest->requires();

        if ($requires === []) {
            return '';
        }

        ['providers' => $providers, 'missing' => $missing] = PluginLocator::fromCwd()->dependenciesFor($manifest);

        $lines = [];
        foreach ($providers as $provider) {
            $lines[] = '            \\' . $provider . '::class,';
        }
        foreach ($missing as $domain) {
            $lines[] = "            // no installed plugin solves '{$domain}' — the boot will fail until one does";
        }

        $body = $lines === [] ? '            //' : implode("\n", $lines);

        return "\n    /**\n     * Providers for: " . implode(', ', $requires) . "\n     *\n"
            . "     * Every required domain must be registered or the boot fails — that is\n"
            . "     * the kernel enforcing the dependency, not the harness being strict.\n"
            . "     */\n"
            . "    protected function dependencies(): array\n    {\n"
            . "        return [\n" . $body . "\n        ];\n    }\n";
    }

    private function studly(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9]+/', ' ', $value) ?? $value;

        return str_replace(' ', '', ucwords(trim($value)));
    }
}
