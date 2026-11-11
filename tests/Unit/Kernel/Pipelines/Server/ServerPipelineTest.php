<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Pipelines\Server;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestWriter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\KernelException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server\ServerLimits;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server\ServerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ServerModule\DemoServer;

/**
 * The fourth surface: resolving a declared server, running it, and stopping it.
 *
 * The stopping is the part worth testing hardest. Every other surface ends by
 * finishing its unit of work; a server ends only when something tells it to,
 * and the only thing that ever tells it is a signal from a supervisor followed
 * by SIGKILL a few seconds later. A trap that does not work is not a degraded
 * shutdown — it is no shutdown, discovered in production.
 */
#[CoversClass(ServerPipeline::class)]
final class ServerPipelineTest extends TestCase
{
    private string $root;
    private ?string $previousBase = null;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        DemoServer::reset();

        $this->root = sys_get_temp_dir() . '/hkm-server-pipe-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);
        mkdir($this->root . '/var/logs', 0775, true);

        $this->previousBase    = Paths::base();
        $this->previousProject = Paths::project();
        Paths::setBase($this->root);
        Paths::setProject($this->root);
    }

    protected function tearDown(): void
    {
        Paths::setBase((string) $this->previousBase);
        Paths::setProject($this->previousProject);

        foreach (glob($this->root . '/var/{cache/manifests,logs}/*', \GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/var/cache/manifests');
        @rmdir($this->root . '/var/cache');
        @rmdir($this->root . '/var/logs');
        @rmdir($this->root . '/var');
        @rmdir($this->root);

        DemoServer::reset();
    }

    /** Everything the error pipeline wrote during this test. */
    private function logged(): string
    {
        $logged = '';

        foreach (glob($this->root . '/var/logs/*') ?: [] as $file) {
            $logged .= (string) file_get_contents($file);
        }

        return $logged;
    }

    /** @param array<string, array<string, mixed>> $servers */
    private function writeManifest(array $servers): void
    {
        ManifestWriter::write('server-manifest.php', $servers);
    }

    private function pipeline(?\Closure $enterSurface = null): ServerPipeline
    {
        return new ServerPipeline(
            new CoreContainer(),
            ErrorPipeline::default(),
            enterSurface: $enterSurface,
        );
    }

    // ── Resolving a declaration ─────────────────────────────────────────────

    public function test_a_declared_server_is_listed_without_loading_its_class(): void
    {
        $this->writeManifest([
            'realtime' => ['class' => 'App\\Never\\Loaded', 'module' => 'App\\Provider'],
        ]);

        $servers = $this->pipeline()->all();

        self::assertArrayHasKey('realtime', $servers);
        self::assertFalse(
            class_exists('App\\Never\\Loaded', false),
            'server:list must work on a machine where no server can even be constructed',
        );
    }

    public function test_an_unknown_name_names_the_ones_that_exist(): void
    {
        $this->writeManifest([
            'realtime'   => ['class' => DemoServer::class],
            'bus-bridge' => ['class' => DemoServer::class],
        ]);

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage('Declared servers: realtime, bus-bridge.');

        $this->pipeline()->run('realtiem');
    }

    public function test_with_nothing_declared_it_says_so_rather_than_listing_nothing(): void
    {
        $this->writeManifest([]);

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage('No installed module declares a servers[] entry');

        $this->pipeline()->run('realtime');
    }

    public function test_a_missing_class_names_the_module_whose_manifest_to_fix(): void
    {
        // The real shape of this mistake: a namespace written with single
        // backslashes in JSON, so the class string is subtly wrong.
        $this->writeManifest([
            'realtime' => ['class' => 'Plugins\\WebSocket\\Nope', 'module' => 'Plugins\\WebSocket\\Provider'],
        ]);

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage('It is declared by Plugins\\WebSocket\\Provider');

        $this->pipeline()->run('realtime');
    }

    public function test_a_class_that_is_not_a_server_is_refused(): void
    {
        $this->writeManifest(['realtime' => ['class' => \stdClass::class]]);

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage('does not implement');

        $this->pipeline()->run('realtime');
    }

    // ── Running ─────────────────────────────────────────────────────────────

    public function test_it_runs_the_server_and_returns_its_exit_code(): void
    {
        $this->writeManifest(['realtime' => ['class' => DemoServer::class]]);
        DemoServer::$exitCode = 3;

        $code = $this->pipeline()->run('realtime', limits: new ServerLimits(memoryLimitMb: 1));

        self::assertSame(3, $code);
        self::assertSame(1, DemoServer::$servedTimes);
    }

    public function test_the_context_carries_the_name_and_the_limits(): void
    {
        $this->writeManifest(['realtime' => ['class' => DemoServer::class]]);

        $this->pipeline()->run(
            'realtime',
            options: ['host' => '127.0.0.1', 'verbose' => true],
            limits: new ServerLimits(memoryLimitMb: 1),
        );

        $context = DemoServer::$context;
        self::assertNotNull($context);
        self::assertSame('realtime', $context->name);
        self::assertSame('127.0.0.1', $context->option('host'));
        self::assertTrue($context->flag('verbose'));
        self::assertSame('', $context->option('port'), 'an absent option is absent, not empty-ish');
    }

    public function test_the_declared_restart_policy_becomes_the_default_ceiling(): void
    {
        $this->writeManifest([
            'realtime' => [
                'class'   => DemoServer::class,
                'restart' => ['memoryMb' => 1, 'seconds' => 0],
            ],
        ]);

        $pipeline = $this->pipeline();
        $pipeline->run('realtime');

        $limits = $pipeline->limits();
        self::assertNotNull($limits);
        self::assertSame(1, $limits->memoryLimitMb);
        self::assertSame('memory', $limits->reason());
        self::assertSame(0, DemoServer::$ticks, 'the 1 MB ceiling is exceeded before the first tick');
    }

    public function test_an_explicit_limit_overrides_the_declaration(): void
    {
        $this->writeManifest([
            'realtime' => ['class' => DemoServer::class, 'restart' => ['memoryMb' => 4096]],
        ]);

        $pipeline = $this->pipeline();
        $pipeline->run('realtime', limits: new ServerLimits(maxSeconds: 1, startedAt: microtime(true) - 5));

        self::assertSame('runtime', $pipeline->limits()?->reason());
    }

    public function test_a_passed_lifetime_is_attributed_even_when_the_loop_never_polled_it(): void
    {
        // The forking case, reproduced without a fork: a server whose loop
        // returns without ever polling shouldStop(), having already outlived
        // its ceiling. Under Swoole that is the normal shape — the poll happens
        // in a worker and the decision never comes back across the fork — so
        // the parent has to attribute a wall-clock ceiling itself rather than
        // report "returned with no reason".
        $this->writeManifest(['realtime' => ['class' => DemoServer::class]]);
        DemoServer::$neverPolls = true;

        $pipeline = $this->pipeline();
        $pipeline->run('realtime', limits: new ServerLimits(maxSeconds: 1, startedAt: microtime(true) - 9));

        $limits = $pipeline->limits();
        self::assertNotNull($limits);
        self::assertSame(0, DemoServer::$ticks, 'the fixture must not have polled anything');
        self::assertTrue($limits->hasReason(), 'the parent has to attribute the ceiling itself');
        self::assertSame('runtime', $limits->reason());
    }

    public function test_but_a_MEMORY_ceiling_reached_in_a_fork_is_not_invented_here(): void
    {
        // Wall clock is shared across a fork; resident size is not. A child
        // that grew past 512 MB says nothing about this process, so attributing
        // it here would be a guess dressed as a measurement.
        $this->writeManifest(['realtime' => ['class' => DemoServer::class]]);
        DemoServer::$neverPolls = true;

        $pipeline = $this->pipeline();
        $pipeline->run('realtime', limits: new ServerLimits(memoryLimitMb: 4096));

        $limits = $pipeline->limits();
        self::assertNotNull($limits);
        self::assertFalse($limits->hasReason());
        self::assertStringContainsString('no reason recorded', $limits->describe());
    }

    public function test_a_programmatic_registration_wins_over_the_manifest(): void
    {
        // What makes a declared server substitutable in a test, and what lets a
        // project register its own without a plugin.
        $this->writeManifest(['realtime' => ['class' => \stdClass::class]]);

        $pipeline = $this->pipeline();
        $pipeline->server('realtime', DemoServer::class);

        self::assertSame(0, $pipeline->run('realtime', limits: new ServerLimits(memoryLimitMb: 1)));
    }

    // ── Stopping ────────────────────────────────────────────────────────────

    public function test_a_sigterm_stops_the_loop_and_reaches_the_servers_own_stop(): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('posix_kill')) {
            self::markTestSkipped('ext-pcntl / ext-posix are needed to raise a real signal.');
        }

        $this->writeManifest(['realtime' => ['class' => DemoServer::class]]);
        DemoServer::$raiseTermAtTick = 3;

        $pipeline = $this->pipeline();
        $pipeline->run('realtime');

        self::assertLessThan(
            DemoServer::MAX_TICKS,
            DemoServer::$ticks,
            'the loop ran to its backstop, which means the signal never ended it',
        );
        self::assertSame('signal', $pipeline->limits()?->reason());
        self::assertGreaterThan(0, DemoServer::$stopCalls, 'the server itself must be told to wind down');
    }

    public function test_the_previous_signal_handlers_are_restored(): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_signal_get_handler')) {
            self::markTestSkipped('ext-pcntl is needed.');
        }

        $marker  = static function (int $signal): void {
        };
        pcntl_signal(\SIGTERM, $marker);

        $this->writeManifest(['realtime' => ['class' => DemoServer::class]]);
        $this->pipeline()->run('realtime', limits: new ServerLimits(memoryLimitMb: 1));

        self::assertSame(
            $marker,
            pcntl_signal_get_handler(\SIGTERM),
            'a handler left behind closes over a ServerLimits that no longer means anything',
        );

        pcntl_signal(\SIGTERM, \SIG_DFL);
    }

    // ── Failure ─────────────────────────────────────────────────────────────

    public function test_a_throwing_server_is_reported_and_rethrown(): void
    {
        $this->writeManifest(['realtime' => ['class' => DemoServer::class]]);
        DemoServer::$throw = new \RuntimeException('the bus connection died');

        try {
            $this->pipeline()->run('realtime');
            self::fail('the throwable must reach the caller — a server that died silently looks idle');
        } catch (\RuntimeException $e) {
            self::assertSame('the bus connection died', $e->getMessage());
        }

        $logged = $this->logged();

        self::assertStringContainsString('the bus connection died', $logged);
        self::assertStringContainsString(
            'server:realtime',
            $logged,
            'a server is started by a supervisor whose stderr may go nowhere, so the record has to '
            . 'name which server died',
        );
    }

    public function test_failing_to_START_is_recorded_too(): void
    {
        // A realtime server that never came up delivers exactly as much as one
        // that crashed, and a misspelled class in a manifest is the more likely
        // of the two. Under a supervisor there may be nothing watching the
        // output, so the record is the only trace either way.
        $this->writeManifest([
            'realtime' => ['class' => 'Plugins\\WebSocket\\Nope', 'module' => 'Plugins\\WebSocket\\Provider'],
        ]);

        try {
            $this->pipeline()->run('realtime');
        } catch (KernelException) {
            // expected
        }

        self::assertStringContainsString('server:realtime', $this->logged());
    }

    public function test_the_surface_is_announced_only_once_the_server_can_start(): void
    {
        $entered = 0;
        $enter   = static function () use (&$entered): void {
            $entered++;
        };

        $this->writeManifest(['realtime' => ['class' => 'App\\Nope']]);

        try {
            $this->pipeline($enter)->run('realtime');
        } catch (KernelException) {
            // expected
        }

        self::assertSame(0, $entered, 'a kernel must not record itself as a server that never ran');

        $this->writeManifest(['realtime' => ['class' => DemoServer::class]]);
        $this->pipeline($enter)->run('realtime', limits: new ServerLimits(memoryLimitMb: 1));

        self::assertSame(1, $entered);
    }
}
