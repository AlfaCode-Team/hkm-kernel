<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Config;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileConfigManifestStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Config\Repository;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the precedence rule the whole feature rests on: a project overrides
 * only the keys it names, and inherits every other plugin default.
 *
 * Under the OLD per-plugin lookup (project file REPLACES plugin file) a project
 * wanting to change one key had to copy the plugin's entire config and then
 * silently drift from it on every upgrade. These tests pin the new behaviour so
 * that cannot regress.
 */
#[CoversClass(CompileConfigManifestStage::class)]
final class CompileConfigManifestStageTest extends TestCase
{
    private string $root;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-config-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/plugin/config', 0775, true);
        mkdir($this->root . '/project/config', 0775, true);
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

    /** @param array<string, mixed> $plugin @param array<string, mixed> $project */
    private function compile(array $plugin, array $project): Repository
    {
        file_put_contents(
            $this->root . '/plugin/config/demo.php',
            '<?php return ' . var_export($plugin, true) . ';',
        );
        file_put_contents(
            $this->root . '/project/config/demo.php',
            '<?php return ' . var_export($project, true) . ';',
        );

        // The stage locates a plugin's config/ from its Provider's file location,
        // so a stub Provider in the temp plugin dir is all it needs.
        $providerFile = $this->root . '/plugin/Provider.php';
        $class        = 'TestConfigProvider' . bin2hex(random_bytes(4));
        file_put_contents($providerFile, "<?php class {$class} {}");
        require $providerFile;

        (new CompileConfigManifestStage([$class], new ManifestReader()))->run();

        return new Repository(ManifestReader::readCompiled('config-manifest.php'));
    }

    public function test_project_overrides_only_the_key_it_names(): void
    {
        $config = $this->compile(
            plugin:  ['from' => ['address' => 'plugin@test', 'name' => 'Plugin'], 'timeout' => 30],
            project: ['from' => ['address' => 'project@test']],
        );

        self::assertSame('project@test', $config->get('demo.from.address'), 'project wins');
        self::assertSame('Plugin', $config->get('demo.from.name'), 'unnamed sibling key is inherited');
        self::assertSame(30, $config->get('demo.timeout'), 'unnamed sibling group is inherited');
    }

    public function test_a_project_list_replaces_rather_than_appends(): void
    {
        $config = $this->compile(
            plugin:  ['transports' => ['smtp', 'sendmail', 'log']],
            project: ['transports' => ['smtp']],
        );

        // Appending would make it impossible to REMOVE a shipped default, which
        // is usually the entire reason for overriding a list.
        self::assertSame(['smtp'], $config->get('demo.transports'));
    }

    public function test_plugin_config_survives_with_no_project_override(): void
    {
        $config = $this->compile(plugin: ['driver' => 'local'], project: []);

        self::assertSame('local', $config->get('demo.driver'));
    }

    public function test_a_config_file_returning_a_non_array_fails_the_boot(): void
    {
        file_put_contents($this->root . '/project/config/broken.php', '<?php return "nope";');

        $this->expectExceptionMessageMatches('/must return an array, got string/');

        (new CompileConfigManifestStage([], new ManifestReader()))->run();
    }
}
