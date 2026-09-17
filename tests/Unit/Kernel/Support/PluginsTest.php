<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\KernelException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Plugins;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * "Is this plugin installed?" answered from the compiled service manifest.
 *
 * The case that matters is the NEGATIVE one: a caller asking about a plugin
 * that is absent must get `false` rather than a fatal, because the whole point
 * is to let an optional integration degrade instead of taking the request down.
 */
#[CoversClass(Plugins::class)]
final class PluginsTest extends TestCase
{
    private string $root;
    private ?string $previousBase = null;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-plugins-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousBase    = Paths::base();
        $this->previousProject = Paths::project();
        Paths::setBase($this->root);
        Paths::setProject($this->root);

        Plugins::reset();
    }

    protected function tearDown(): void
    {
        Plugins::reset();

        if ($this->previousBase !== null) {
            Paths::setBase($this->previousBase);
        }
        Paths::setProject($this->previousProject);

        foreach (glob($this->root . '/var/cache/manifests/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/var/cache/manifests');
        @rmdir($this->root . '/var/cache');
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    public function test_it_reads_installed_modules_from_the_service_manifest(): void
    {
        $this->writeManifest([
            'tenancy.routing'     => ['name' => 'tenancy', 'module' => 'Plugins\\Tenancy\\Provider'],
            'database.management' => ['name' => 'database', 'module' => 'Plugins\\Database\\Provider'],
        ]);

        self::assertSame(
            ['tenancy.routing' => 'tenancy', 'database.management' => 'database'],
            Plugins::all(),
        );
    }

    public function test_it_matches_a_module_by_domain_or_by_name(): void
    {
        $this->writeManifest(['tenancy.routing' => ['name' => 'tenancy']]);

        self::assertTrue(Plugins::installed('tenancy.routing'), 'by solves domain');
        self::assertTrue(Plugins::installed('tenancy'), 'by module.json name');
        self::assertFalse(Plugins::installed('tenancy.hosting'), 'a domain nobody solves');
    }

    /**
     * A plugin that was never registered is a `false`, not an exception: the
     * optional-integration call site is the main reason this class exists.
     */
    public function test_an_absent_plugin_is_false_and_a_missing_manifest_is_empty(): void
    {
        self::assertSame([], Plugins::all(), 'no manifest compiled yet');
        self::assertFalse(Plugins::installed('tenancy.routing'));
        self::assertSame(['a.b', 'c.d'], Plugins::missing('a.b', 'c.d'));
    }

    public function test_the_synthetic_project_scope_is_not_a_plugin(): void
    {
        $this->writeManifest([
            '__project__'     => ['name' => '__project__', 'module' => null],
            'tenancy.routing' => ['name' => 'tenancy'],
        ]);

        self::assertSame(['tenancy.routing' => 'tenancy'], Plugins::all());
        self::assertFalse(Plugins::installed('__project__'));
    }

    public function test_ensure_names_the_missing_plugins_and_what_is_registered(): void
    {
        $this->writeManifest(['database.management' => ['name' => 'database']]);

        Plugins::ensure('database.management');   // installed — must not throw

        try {
            Plugins::ensure('database.management', 'tenancy.routing');
            self::fail('ensure() should have thrown for the absent plugin.');
        } catch (KernelException $e) {
            self::assertStringContainsString('tenancy.routing', $e->getMessage());
            self::assertStringNotContainsString(
                'database.management. ',
                $e->getMessage(),
                'an INSTALLED plugin must not be reported as missing',
            );
            // The usual cause is a misspelled domain, so the message has to show
            // what IS registered for the reader to spot the difference.
            self::assertStringContainsString('Registered domains: database.management', $e->getMessage());
            self::assertSame(['tenancy.routing'], $e->context['missing']);
        }
    }

    /** @param array<string, array<string, mixed>> $services */
    private function writeManifest(array $services): void
    {
        file_put_contents(
            $this->root . '/var/cache/manifests/service-manifest.php',
            '<?php return ' . var_export(['services' => $services], true) . ';',
        );
        Plugins::reset();
    }
}
