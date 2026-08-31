<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests;

use AlfacodeTeam\Ground\Commands\PluginServeCommand;
use PHPUnit\Framework\TestCase;

/**
 * The router `plugin:serve` writes for `php -S` to execute per request.
 *
 * This exists because of a defect that shipped in v1.8.0 and was invisible to
 * every check that ran: the router's `require` line was built as
 * `dirname(__DIR__) . '/vendor/autoload.php'`, which from `src/Commands/`
 * names `modules/ground/src/vendor/autoload.php` — a path that exists in no
 * layout, and never did. `php -l` passed, the whole suite passed, and the
 * command started and printed "Listening" happily; the fatal only appeared in
 * a DIFFERENT process, on the first request:
 *
 *     Failed opening required '…/modules/ground/src/vendor/autoload.php'
 *
 * A generated file is executed by something other than the test runner, so
 * nothing about it is checked unless it is checked deliberately. That is what
 * these tests do: read the emitted router and confirm the paths it names are
 * real.
 */
final class ServeTest extends TestCase
{
    /** The generated router must require an autoloader that EXISTS. */
    public function testRouterRequiresARealAutoloader(): void
    {
        $router = $this->writeRouter();

        try {
            $source = (string) file_get_contents($router);

            self::assertSame(
                1,
                preg_match("/^require '([^']+)';$/m", $source, $m),
                'The router should require exactly one autoloader.',
            );

            self::assertFileExists(
                $m[1],
                'The router requires an autoloader that does not exist, so every request 500s.',
            );
        } finally {
            @unlink($router);
        }
    }

    /**
     * The specific shape of the v1.8.0 bug: a path under the package's own
     * `src/`. No composer layout puts `vendor/` there.
     */
    public function testAutoloaderIsNotResolvedRelativeToTheSourceTree(): void
    {
        $path = $this->autoloaderPath();

        self::assertStringNotContainsString(
            'ground/src/vendor',
            $path,
            'Resolved relative to the source file again instead of to the real autoloader.',
        );
        self::assertFileExists($path);
    }

    /**
     * It must be the autoloader ACTUALLY IN EFFECT, not merely one that exists.
     *
     * Ground runs from a kernel checkout, an installed bundle
     * (`lib/hkm-kernel/`) and a plugin's own `vendor/`, and picking the wrong
     * one of several present is how the child process ends up without the
     * classes the parent had.
     */
    public function testAutoloaderIsTheOneThatLoadedThisProcess(): void
    {
        $classLoader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();

        self::assertIsString($classLoader);
        self::assertSame(\dirname($classLoader, 2) . '/autoload.php', $this->autoloaderPath());
    }

    /** The router closes over the resolved providers — they must survive export. */
    public function testRouterCarriesTheResolvedProviders(): void
    {
        $router = $this->writeRouter();

        try {
            $source = (string) file_get_contents($router);

            // Compared in EXPORTED form: `var_export` escapes the namespace
            // separators, so the file holds `A\\B`, not `A\B`.
            self::assertStringContainsString(var_export(Fixtures\Sample\Provider::class, true), $source);
            self::assertStringContainsString(var_export('/plugins/root', true), $source);
        } finally {
            @unlink($router);
        }
    }

    // ── Reaching the private surface ──────────────────────────────────────────

    private function writeRouter(): string
    {
        $method = new \ReflectionMethod(PluginServeCommand::class, 'writeRouter');

        return $method->invoke(
            $this->command(),
            Fixtures\Sample\Provider::class,
            [],
            '/plugins/root',
            true,
        );
    }

    private function autoloaderPath(): string
    {
        return (new \ReflectionMethod(PluginServeCommand::class, 'autoloaderPath'))
            ->invoke($this->command());
    }

    /**
     * Built without the constructor: the router is written from arguments
     * already resolved, so none of the command's collaborators participate.
     */
    private function command(): PluginServeCommand
    {
        return (new \ReflectionClass(PluginServeCommand::class))->newInstanceWithoutConstructor();
    }
}
