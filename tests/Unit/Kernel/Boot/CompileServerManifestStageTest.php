<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileServerManifestStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\BadServerModule\Provider as BadProvider;
use Tests\Fixtures\JobModule\Provider as JobProvider;
use Tests\Fixtures\RivalServerModule\Provider as RivalProvider;
use Tests\Fixtures\ServerModule\Provider as ServerProvider;

/**
 * servers[] → server-manifest.php.
 *
 * Everything this stage refuses is a typo whose only other symptom is a server
 * that cannot be started, or one that starts without something it needs — in a
 * surface where "not running" and "running correctly" look identical from the
 * outside.
 */
#[CoversClass(CompileServerManifestStage::class)]
final class CompileServerManifestStageTest extends TestCase
{
    private string $root;
    private ?string $previousBase = null;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-servers-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousBase    = Paths::base();
        $this->previousProject = Paths::project();
        Paths::setBase($this->root);
        Paths::setProject($this->root);
    }

    protected function tearDown(): void
    {
        Paths::setBase((string) $this->previousBase);
        Paths::setProject($this->previousProject);

        foreach (glob($this->root . '/var/cache/manifests/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/var/cache/manifests');
        @rmdir($this->root . '/var/cache');
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    /**
     * @param list<class-string> $modules
     * @return array<string, array<string, mixed>>
     */
    private function compile(array $modules): array
    {
        (new CompileServerManifestStage($modules, new ManifestReader()))->run();

        /** @var array<string, array<string, mixed>> $manifest */
        $manifest = ManifestReader::readCompiled('server-manifest.php');

        return $manifest;
    }

    // ── What compiles ───────────────────────────────────────────────────────

    public function test_a_declared_server_is_indexed_by_its_name(): void
    {
        $servers = $this->compile([ServerProvider::class, JobProvider::class]);

        self::assertArrayHasKey('realtime', $servers);
        self::assertArrayHasKey('bus-bridge', $servers);
    }

    public function test_it_records_the_class_module_and_domain(): void
    {
        $server = $this->compile([ServerProvider::class, JobProvider::class])['realtime'];

        self::assertSame('Tests\\Fixtures\\ServerModule\\DemoServer', $server['class']);
        self::assertSame(ServerProvider::class, $server['module']);
        self::assertSame('server.demo', $server['solves'], 'the module domain its container is built from');
    }

    public function test_it_records_the_reason_an_operator_reads(): void
    {
        $server = $this->compile([ServerProvider::class, JobProvider::class])['realtime'];

        self::assertStringContainsString('open connections', (string) $server['why']);
    }

    public function test_extra_required_domains_are_compiled_through(): void
    {
        $server = $this->compile([ServerProvider::class, JobProvider::class])['realtime'];

        self::assertSame(['jobs.demo'], $server['requires']);
    }

    public function test_the_restart_policy_is_normalised(): void
    {
        $servers = $this->compile([ServerProvider::class, JobProvider::class]);

        self::assertSame(['memoryMb' => 512, 'seconds' => 3600], $servers['realtime']['restart']);
        self::assertSame(
            ['memoryMb' => 0, 'seconds' => 0],
            $servers['bus-bridge']['restart'],
            'an undeclared ceiling is OFF — a limit the kernel invented would look like a crash',
        );
    }

    public function test_a_module_declaring_no_servers_compiles_an_empty_manifest(): void
    {
        self::assertSame([], $this->compile([JobProvider::class]));
    }

    // ── What it refuses ─────────────────────────────────────────────────────

    public function test_a_name_that_cannot_be_typed_fails_the_boot(): void
    {
        $this->expectException(BootException::class);
        // "Realtime Server" is two argv tokens, so `server:run Realtime Server`
        // could never resolve it.
        $this->expectExceptionMessage('not usable on a command line');

        $this->compile([BadProvider::class]);
    }

    public function test_two_modules_claiming_one_name_fail_the_boot(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessage('Two modules declare a server named [realtime]');

        $this->compile([ServerProvider::class, RivalProvider::class, JobProvider::class]);
    }

    public function test_an_unknown_required_domain_fails_the_boot(): void
    {
        $this->expectException(BootException::class);
        // 'view.rendring' — the typo that otherwise loads nothing and starts a
        // server without the capability it asked for.
        $this->expectExceptionMessage('which no registered module solves');

        $this->compile([RivalProvider::class]);
    }
}
