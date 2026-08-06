<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileLangManifestStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the priority model for translation catalogues.
 *
 * The rule that matters: a PROJECT source outranks a PLUGIN source by default,
 * so a deployment can reword a plugin's copy without forking it — and a plugin
 * can only outrank the project by explicitly asking for it. If this ordering
 * ever inverts, plugins would silently override wording the project chose.
 */
#[CoversClass(CompileLangManifestStage::class)]
final class CompileLangManifestStageTest extends TestCase
{
    private string $root;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-lang-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/plugin/lang', 0775, true);
        mkdir($this->root . '/project/resources/lang', 0775, true);
        mkdir($this->root . '/project/var/cache/manifests', 0775, true);

        $this->previousProject = Paths::project();
        Paths::setBase($this->root);
        Paths::setProject($this->root . '/project');
    }

    protected function tearDown(): void
    {
        Paths::setProject($this->previousProject);
        $this->rmrf($this->root);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Create a stub plugin whose module.json carries the given "lang"
     * declaration, and return its Provider class-string.
     *
     * @param mixed $lang
     */
    private function plugin(string $name, mixed $lang, string $dir = 'lang'): string
    {
        $base = $this->root . '/' . $name;
        @mkdir($base . '/' . $dir, 0775, true);

        $manifest = ['name' => $name, 'solves' => $name . '.domain'];
        if ($lang !== null) {
            $manifest['lang'] = $lang;
        }
        file_put_contents($base . '/module.json', json_encode($manifest));

        $class = 'TestLangProvider' . bin2hex(random_bytes(4));
        file_put_contents($base . '/Provider.php', "<?php class {$class} {}");
        require $base . '/Provider.php';

        return $class;
    }

    /**
     * @param list<class-string> $modules
     * @return array{global:list<string>,namespaces:array<string,list<string>>}
     */
    private function compile(array $modules): array
    {
        (new CompileLangManifestStage($modules, new ManifestReader()))->run();

        /** @var array{global:list<string>,namespaces:array<string,list<string>>} $m */
        $m = ManifestReader::readCompiled('lang-manifest.php');

        return $m;
    }

    // --- Priority -------------------------------------------------------------

    public function test_the_project_catalogue_outranks_every_plugin(): void
    {
        $plugin = $this->plugin('user', 'lang');

        $manifest = $this->compile([$plugin]);

        self::assertSame(
            [$this->realpath('/project/resources/lang'), $this->realpath('/user/lang')],
            $manifest['global'],
            'project first, plugin after',
        );
    }

    public function test_a_plugin_can_preempt_the_project_with_an_explicit_priority(): void
    {
        // The single sanctioned escape hatch — it must be opt-in and explicit.
        $plugin = $this->plugin('user', ['path' => 'lang', 'priority' => -1]);

        $manifest = $this->compile([$plugin]);

        self::assertSame($this->realpath('/user/lang'), $manifest['global'][0]);
    }

    public function test_plugin_order_is_stable_for_equal_priorities(): void
    {
        $a = $this->plugin('alpha', 'lang');
        $b = $this->plugin('beta', 'lang');

        $manifest = $this->compile([$a, $b]);

        self::assertSame(
            [
                $this->realpath('/project/resources/lang'),
                $this->realpath('/alpha/lang'),
                $this->realpath('/beta/lang'),
            ],
            $manifest['global'],
            'ties break by declaration order, never by filesystem or load order',
        );
    }

    // --- Namespaces -----------------------------------------------------------

    public function test_a_plugin_catalogue_is_namespaced_by_its_module_name(): void
    {
        $plugin = $this->plugin('user', 'lang');

        $manifest = $this->compile([$plugin]);

        self::assertSame(['user' => [$this->realpath('/user/lang')]], $manifest['namespaces']);
    }

    public function test_an_explicit_namespace_overrides_the_module_name(): void
    {
        $plugin = $this->plugin('user', ['path' => 'lang', 'namespace' => 'accounts']);

        $manifest = $this->compile([$plugin]);

        self::assertArrayHasKey('accounts', $manifest['namespaces']);
        self::assertArrayNotHasKey('user', $manifest['namespaces']);
    }

    public function test_a_non_global_source_is_reachable_only_by_namespace(): void
    {
        // Maximum collision safety: the messages exist, but only under ns::key.
        $plugin = $this->plugin('user', ['path' => 'lang', 'global' => false]);

        $manifest = $this->compile([$plugin]);

        self::assertNotContains($this->realpath('/user/lang'), $manifest['global']);
        self::assertSame([$this->realpath('/user/lang')], $manifest['namespaces']['user']);
    }

    // --- Declaration shapes ---------------------------------------------------

    public function test_several_sources_can_be_declared_as_a_list(): void
    {
        $base = $this->root . '/multi';
        @mkdir($base . '/lang-a', 0775, true);
        @mkdir($base . '/lang-b', 0775, true);
        file_put_contents($base . '/module.json', json_encode([
            'name' => 'multi',
            'lang' => ['lang-a', 'lang-b'],
        ]));
        $class = 'TestLangProviderMulti' . bin2hex(random_bytes(4));
        file_put_contents($base . '/Provider.php', "<?php class {$class} {}");
        require $base . '/Provider.php';

        $manifest = $this->compile([$class]);

        self::assertContains($this->realpath('/multi/lang-a'), $manifest['global']);
        self::assertContains($this->realpath('/multi/lang-b'), $manifest['global']);
    }

    public function test_a_module_declaring_no_lang_contributes_nothing(): void
    {
        $plugin = $this->plugin('silent', null);

        $manifest = $this->compile([$plugin]);

        self::assertSame([$this->realpath('/project/resources/lang')], $manifest['global']);
        self::assertSame([], $manifest['namespaces']);
    }

    // --- Robustness -----------------------------------------------------------

    public function test_a_declared_but_missing_directory_never_breaks_the_boot(): void
    {
        // A catalogue is optional content. A typo in module.json must not make
        // the application unbootable.
        $base = $this->root . '/ghost';
        @mkdir($base, 0775, true);
        file_put_contents($base . '/module.json', json_encode(['name' => 'ghost', 'lang' => 'does-not-exist']));
        $class = 'TestLangProviderGhost' . bin2hex(random_bytes(4));
        file_put_contents($base . '/Provider.php', "<?php class {$class} {}");
        require $base . '/Provider.php';

        $manifest = $this->compile([$class]);

        self::assertSame([$this->realpath('/project/resources/lang')], $manifest['global']);
    }

    public function test_an_empty_path_fails_the_boot(): void
    {
        $base = $this->root . '/broken';
        @mkdir($base, 0775, true);
        file_put_contents($base . '/module.json', json_encode(['name' => 'broken', 'lang' => ['path' => '  ']]));
        $class = 'TestLangProviderBroken' . bin2hex(random_bytes(4));
        file_put_contents($base . '/Provider.php', "<?php class {$class} {}");
        require $base . '/Provider.php';

        // Silence is the wrong answer here: an unusable declaration means the
        // author expected messages that will never load.
        $this->expectExceptionMessageMatches('/needs a non-empty "path"/');

        $this->compile([$class]);
    }

    public function test_the_same_directory_declared_twice_appears_once(): void
    {
        $a = $this->plugin('dup', 'lang');
        $manifest = $this->compile([$a, $a]);

        self::assertSame(
            [$this->realpath('/project/resources/lang'), $this->realpath('/dup/lang')],
            $manifest['global'],
        );
    }

    private function realpath(string $suffix): string
    {
        $path = realpath($this->root . $suffix);

        return $path !== false ? $path : $this->root . $suffix;
    }
}
