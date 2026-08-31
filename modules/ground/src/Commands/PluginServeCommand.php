<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\Ground\Inspection\PluginLocator;

/**
 * plugin:serve <name> — browse a plugin, alone, with no project around it.
 *
 * `plugin:check` reads the plugin, `plugin:probe` boots it, and this SERVES it:
 * the real HttpPipeline behind PHP's built-in server, against the same fake
 * ports every ground test uses. It is the third question a plugin author asks,
 * and until now the only one that needed a whole project wired up first.
 *
 * ─── WHAT IT IS NOT ─────────────────────────────────────────────────────────
 *
 * Not a production server, and not a substitute for a project. Every port is a
 * FAKE: the database returns whatever the fake was seeded with (nothing, by
 * default), mail goes nowhere, the cache is per-request. A page that reads from
 * storage renders empty here and is not broken.
 *
 * What it does prove, and prove cheaply:
 *
 *   - the route compiles, matches, and reaches its handler
 *   - the controller, service and view actually run
 *   - the markup, layout, assets and Pageflow page object are what you expect
 *   - the failure, when there is one, with a real stack trace in the browser
 *
 * ─── WHY IT BOOTS PER REQUEST ───────────────────────────────────────────────
 *
 * `php -S` re-executes its router script on every request, so the ground is
 * built fresh each time: ~35 ms to boot, ~20 ms for the first request. That is
 * not a limitation to work around — it is the behaviour you want while editing
 * a plugin, because every request picks up the code AND the module.json you
 * just saved with no restart. A route added to module.json is live on reload.
 */
final class PluginServeCommand extends AbstractCommand
{
    use ResolvesPlugin;

    protected function configure(): void
    {
        $this->name        = 'plugin:serve';
        $this->description = 'Serve a plugin over HTTP against fake ports — no project needed';

        $this->addArgument('name', 'Plugin name (default: the plugin you are standing in)');
        $this->addOption('port', 'P', 'Port to listen on (default 8321)', acceptsValue: true);
        $this->addOption('host', 'o', 'Host to bind (default 127.0.0.1)', acceptsValue: true);
        $this->addOption('path', 'p', 'Search from this directory instead of the working directory', acceptsValue: true);
        $this->addOption('here', 'H', 'Only this directory and its plugins/ — skip sibling repos');
        $this->addOption('with', 'w', 'Extra plugin NAMES to load, comma-separated', acceptsValue: true);
    }

    protected function handle(): int
    {
        $target = $this->resolvePlugin();

        if ($target === null) {
            return self::FAILURE;
        }

        $path    = (string) ($this->option('path') ?? '');
        $root    = $path !== '' ? rtrim($path, '/') : (getcwd() ?: '.');
        $locator = $this->locator();
        $name    = $target->name();

        ['providers' => $providers, 'missing' => $missing] = $locator->dependenciesFor($target);

        if ($missing !== []) {
            $this->error('No installed plugin provides: ' . implode(', ', $missing));
            $this->muted('The plugin cannot boot without them, so it cannot be served either.');

            return self::FAILURE;
        }

        // --with is for plugins nothing DECLARES a dependency on but a page
        // still needs: session and the security filters are the usual pair. A
        // route naming the `auth` filter is served with a pass-through stand-in
        // unless SecurityFilters is actually loaded, and a stand-in that lets
        // everything through is exactly the wrong thing to debug an auth
        // problem against.
        foreach ($this->extraPlugins() as $extra) {
            $manifest = $locator->find($extra);
            if ($manifest === null) {
                $this->error("--with names '{$extra}', which is not installed.");

                return self::FAILURE;
            }

            $providers[] = $manifest->providerClass;
            $providers   = [...$providers, ...$locator->dependenciesFor($manifest)['providers']];
        }

        $host   = (string) ($this->option('host') ?? '127.0.0.1');
        $port   = (int) ($this->option('port') ?? 8321);
        $router = $this->writeRouter(
            $target->providerClass,
            array_values(array_unique($providers)),
            $root,
            !$this->hasOption('here'),
        );

        $this->section("Serving {$name}");
        $this->info('Provider  : ' . $target->providerClass);
        $this->info('Loaded    : ' . (\count($providers) + 1) . ' plugin(s)');
        $this->info('Listening : http://' . $host . ':' . $port);
        $this->newLine();
        $this->muted('Every port is a fake — the database is empty and mail goes nowhere.');
        $this->muted('Edit the plugin and reload: each request boots fresh, so there is nothing to restart.');
        $this->muted('Ctrl-C to stop.');
        $this->newLine();

        // -t is the plugin's own directory, so a file that exists on disk (a
        // built asset under ui/, an image) is served by php -S directly and
        // never reaches the kernel. The router returns false for those.
        $command = sprintf(
            'php -S %s -t %s %s',
            escapeshellarg($host . ':' . $port),
            escapeshellarg($target->directory()),
            escapeshellarg($router),
        );

        // Ctrl-C is the NORMAL way this command ends, and it signals the whole
        // process group — so this process can die before the unlink below ever
        // runs, leaving the generated router in the temp directory forever.
        // A shutdown function covers a fatal, and the signal handlers cover the
        // interrupt; both are idempotent because @unlink on a missing file is
        // not an error.
        register_shutdown_function(static fn() => @unlink($router));

        if (\function_exists('pcntl_signal')) {
            pcntl_async_signals(true);

            foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
                pcntl_signal($signal, static function () use ($router): void {
                    @unlink($router);

                    exit(0);
                });
            }
        }

        passthru($command, $exitCode);

        @unlink($router);

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function extraPlugins(): array
    {
        $with = (string) ($this->option('with') ?? '');
        if ($with === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $with))));
    }

    /**
     * The router `php -S` runs per request.
     *
     * Written to a temp file rather than shipped as a fixed script because it
     * has to close over the provider list this invocation resolved, and passing
     * that through the environment would break on a plugin set long enough to
     * exceed the env limit.
     *
     * It re-runs the LOCATOR SCAN before booting. That is not redundant work:
     * `php -S` executes this file in a FRESH process per request, so the
     * namespace→directory autoload mappings PluginLocator registered while
     * resolving the command's arguments do not exist here. Without the rescan
     * every request dies on "provider class does not exist" — the sibling
     * checkouts are not in anyone's composer autoloader and never will be.
     *
     * Rescanning also means a plugin CREATED while the server is running is
     * picked up on the next reload, which is the behaviour that makes this
     * usable while actually developing.
     *
     * @param list<class-string> $providers
     */
    private function writeRouter(string $provider, array $providers, string $root, bool $siblings): string
    {
        $file = sys_get_temp_dir() . '/hkm-ground-router-' . bin2hex(random_bytes(6)) . '.php';

        $autoload = \dirname(__DIR__) . '/vendor/autoload.php';
        $export   = var_export([$provider, ...$providers], true);

        file_put_contents($file, <<<PHP
        <?php

        declare(strict_types=1);

        // Generated by `plugin:serve`. Deleted when the server stops.

        // A real file under the document root — an asset, an image — is served
        // by php -S itself. Only what does NOT exist becomes a kernel request.
        if (PHP_SAPI === 'cli-server' && is_file(\$_SERVER['DOCUMENT_ROOT'] . \$_SERVER['SCRIPT_NAME'])) {
            return false;
        }

        require {$this->export($autoload)};

        // Fresh process per request: re-register the discovered plugins'
        // autoload mappings, or none of the providers below can be constructed.
        (new \\AlfacodeTeam\\Ground\\Inspection\\PluginLocator(
            {$this->export($root)},
            includeSiblings: {$this->export($siblings)},
        ))->all();

        \$providers = {$export};

        \$ground = \\AlfacodeTeam\\Ground\\PluginGround::for(...\$providers)
            ->env(['APP_URL' => 'http://' . (\$_SERVER['HTTP_HOST'] ?? 'localhost')])
            ->boot();

        try {
            \$request  = \\AlfacodeTeam\\PhpServicePlatform\\Kernel\\Http\\Request::capture();
            \$response = \$ground->kernel()->http()->handle(\$request);
            \$response->send();
        } catch (\\Throwable \$e) {
            // The boot itself failed, so there is no ErrorPipeline to render
            // this. Print it plainly: a white page here would hide the one
            // message that says what is wrong with the plugin.
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo \$e::class, "\\n", \$e->getMessage(), "\\n\\n", \$e->getTraceAsString(), "\\n";
        } finally {
            \$ground->destroy();
        }

        PHP);

        return $file;
    }

    private function export(string|bool $value): string
    {
        return var_export($value, true);
    }
}
