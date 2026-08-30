<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileModuleFilesStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\LoadModuleFilesStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ComposerFilesModule\Provider as ComposerFilesProvider;
use Tests\Fixtures\FilesModule\Provider as FilesProvider;

/**
 * Module helper FILES — the plain PHP files that define global functions.
 *
 * A plugin is loaded by the kernel, not by Composer: plugins are symlinked into
 * `plugins/` and reached through the PSR-4 `Plugins\` map, so no plugin's own
 * composer.json is ever read. Classes are fine; functions are not. A plugin
 * declaring `autoload.files` has declared it in the one place nothing looks, and
 * nothing complains until something calls the function and dies with
 * "Call to undefined function".
 */
#[CoversClass(CompileModuleFilesStage::class)]
#[CoversClass(LoadModuleFilesStage::class)]
final class ModuleFilesStageTest extends TestCase
{
    private string $root;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-modulefiles-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousProject = Paths::project();
        Paths::setProject($this->root);
    }

    protected function tearDown(): void
    {
        Paths::setProject($this->previousProject);
        $this->deleteTree($this->root);
    }

    public function test_it_compiles_files_declared_in_module_json(): void
    {
        (new CompileModuleFilesStage([FilesProvider::class]))->run();

        $compiled = ManifestReader::readCompiled('files-manifest.php');

        self::assertCount(1, $compiled);
        self::assertStringEndsWith('tests/Fixtures/FilesModule/helpers.php', $compiled[0]);
    }

    public function test_it_falls_back_to_the_modules_own_composer_autoload_files(): void
    {
        // The case every existing plugin is in: helpers declared correctly, in
        // composer.json, which the kernel's loader never reads. Honouring it
        // means those plugins work with no plugin change at all.
        (new CompileModuleFilesStage([ComposerFilesProvider::class]))->run();

        $compiled = ManifestReader::readCompiled('files-manifest.php');

        self::assertCount(1, $compiled, 'the non-existent composer entry must be skipped, not compiled');
        self::assertStringEndsWith('ComposerFilesModule/Engine/functions.php', $compiled[0]);
    }

    public function test_module_json_takes_precedence_over_composer_json(): void
    {
        // module.json is the kernel's contract and the single source of truth.
        // A module declaring both must not get composer's list appended to it,
        // or removing an entry from module.json would silently do nothing.
        (new CompileModuleFilesStage([FilesProvider::class, ComposerFilesProvider::class]))->run();

        $compiled = ManifestReader::readCompiled('files-manifest.php');

        self::assertCount(2, $compiled);
    }

    public function test_a_declared_file_that_does_not_exist_fails_the_boot(): void
    {
        // The entire point: a missing helper must stop being a silent runtime
        // fatal in a plugin and become a loud, located boot failure.
        $module = $this->fixtureModule(['files' => ['nope.php']]);

        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/nope\.php/');

        (new CompileModuleFilesStage([$module]))->run();
    }

    public function test_a_non_string_files_entry_fails_the_boot(): void
    {
        $module = $this->fixtureModule(['files' => [42]]);

        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/non-empty strings/');

        (new CompileModuleFilesStage([$module]))->run();
    }

    public function test_the_loader_actually_defines_the_global_functions(): void
    {
        // Compiling a list proves nothing on its own — the functions have to end
        // up defined in the process, which is the only thing callers care about.
        self::assertFalse(function_exists('psp_test_declared_helper'));
        self::assertFalse(function_exists('psp_test_composer_helper'));

        (new CompileModuleFilesStage([FilesProvider::class, ComposerFilesProvider::class]))->run();
        (new LoadModuleFilesStage())->run();

        self::assertTrue(function_exists('psp_test_declared_helper'));
        self::assertTrue(function_exists('psp_test_composer_helper'));
        self::assertSame('declared', psp_test_declared_helper());
        self::assertSame('composer', psp_test_composer_helper());
    }

    public function test_the_loader_is_idempotent(): void
    {
        // build() can run more than once in a process (tests, a Swoole worker
        // rebuilding). A second require of a file defining functions would be a
        // fatal redeclare, so this must stay require_once.
        (new CompileModuleFilesStage([FilesProvider::class]))->run();

        (new LoadModuleFilesStage())->run();
        (new LoadModuleFilesStage())->run();

        self::assertTrue(function_exists('psp_test_declared_helper'));
    }

    public function test_the_loader_tolerates_a_path_that_vanished(): void
    {
        // A stale cache naming a plugin removed between deploys. Skipping keeps
        // that recoverable; the next compile drops the entry.
        $missing = $this->root . '/gone.php';
        \AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestWriter::write('files-manifest.php', [$missing]);

        (new LoadModuleFilesStage())->run();

        $this->addToAssertionCount(1); // reaching here without a fatal is the assertion
    }

    public function test_a_module_declaring_nothing_anywhere_contributes_nothing(): void
    {
        $module = $this->fixtureModule([]);

        (new CompileModuleFilesStage([$module]))->run();

        self::assertSame([], ManifestReader::readCompiled('files-manifest.php'));
    }

    /**
     * Build a throwaway module (Provider class + module.json) on disk and return
     * its class-string. ManifestReader finds module.json by reflecting on the
     * Provider's file, so both have to be real and adjacent.
     *
     * @param  array<string, mixed> $manifest extra module.json keys
     * @return class-string
     */
    private function fixtureModule(array $manifest): string
    {
        $name  = 'Fx' . bin2hex(random_bytes(6));
        $dir   = $this->root . '/modules/' . $name;
        mkdir($dir, 0775, true);

        file_put_contents($dir . '/module.json', json_encode([
            'name'     => strtolower($name),
            'version'  => '1.0.0',
            'solves'   => strtolower($name) . '.fixture',
            'type'     => 'module',
            'requires' => [],
            'exposes'  => [],
            'config'   => [],
        ] + $manifest, JSON_THROW_ON_ERROR));

        $class = 'PspFixture\\' . $name . '\\Provider';
        file_put_contents(
            $dir . '/Provider.php',
            "<?php\nnamespace PspFixture\\{$name};\nfinal class Provider {}\n"
        );
        require_once $dir . '/Provider.php';

        /** @var class-string $class */
        return $class;
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
