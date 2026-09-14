<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Kernel;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{CachePort, DatabasePort, MetricsPort, QueuePort, TracerPort};
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\Fakes\{ArrayCache, ArrayDatabase, ArrayQueue, PassThroughLayer};

/**
 * The kernel feature bench: boot a real kernel, dispatch a real Request, assert
 * on a real Response.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every kernel test before this one was a unit test, and the framework's own
 * documented case study says what that costs: 15 defects in 5,600 lines, every
 * one "a coherent but false belief about something the code called", and neither
 * `php -l` nor PHPStan caught a single one. A method assumed onto the wrong
 * trait, a link pointing at a POST-only route, a controller signature that no
 * longer matches what the stage passes — none of those are visible to a test that
 * mocks the thing it is wrong about.
 *
 * They ARE visible the moment a request goes through the actual pipeline. That
 * is the entire argument for this file.
 *
 * WHAT IT DELIBERATELY DOES NOT FAKE
 * ----------------------------------
 * The boot pipeline, the manifest compilation, the route matcher, the dependency
 * graph, the scoped containers, the stages, the controller resolution. All real.
 * Only the OUTSIDE of the process is faked — database, cache, queue — because
 * those are the parts a test cannot own.
 *
 * ISOLATION
 * ---------
 * Each test gets its own temp root and its own compiled manifests, so tests
 * cannot leak state through `var/cache/manifests`. Paths are restored in
 * tearDown, since Paths is process-global.
 */
abstract class KernelTestCase extends TestCase
{
    protected string $root;

    protected ArrayDatabase $db;
    protected ArrayCache    $cache;
    protected ArrayQueue    $queue;

    private ?string $previousBase    = null;
    private ?string $previousProject = null;

    /** @var array<string, string> env keys this test set, and their prior values */
    private array $previousEnv = [];

    protected ?Kernel $kernel = null;

    /**
     * The default security layer, kept so a test can assert on what the
     * gateway saw. Replaced entirely when a test passes its own layers.
     */
    protected PassThroughLayer $security;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/hkm-feature-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousBase    = Paths::base();
        $this->previousProject = Paths::project();

        $this->db    = new ArrayDatabase();
        $this->cache = new ArrayCache();
        $this->queue = new ArrayQueue();
        $this->security = new PassThroughLayer();

        // BOOT_CACHE would let one test's compiled manifests answer another's
        // build. Off, explicitly, rather than by assumption.
        $this->setEnv('BOOT_CACHE', '0');
        $this->setEnv('APP_KEY', 'feature-test-key-0123456789abcdef');
    }

    protected function tearDown(): void
    {
        Paths::setBase((string) $this->previousBase);
        Paths::setProject($this->previousProject);

        foreach ($this->previousEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        $this->previousEnv = [];

        $this->kernel = null;

        self::rmrf($this->root);

        parent::tearDown();
    }

    /** Set an env var for this test only; restored in tearDown. */
    protected function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->previousEnv)) {
            $this->previousEnv[$key] = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    // ── Booting ──────────────────────────────────────────────────────────────

    /**
     * Boot a kernel for this test.
     *
     * @param list<class-string>                    $modules  provider classes
     * @param list<array<string, mixed>>            $routes   project routes (proj.json shape)
     * @param array<class-string, object>           $ports    extra/overriding port bindings
     * @param list<object>                          $security security layers
     * @param array<string, mixed>                  $groups   project route groups
     */
    protected function boot(
        array $modules = [],
        array $routes = [],
        array $ports = [],
        array $security = [],
        array $groups = [],
        array $essentials = [],
        array $domains = [],
        array $disable = [],
    ): Kernel {
        Paths::setBase($this->root);
        Paths::setProject($this->root);

        $builder = Kernel::configure()
            ->withBasePath($this->root)
            ->withProjectPath($this->root)
            ->withPorts([
                DatabasePort::class => $this->db,
                CachePort::class    => $this->cache,
                QueuePort::class    => $this->queue,
                ...$ports,
            ]);

        if ($modules !== []) {
            $builder->withModules($modules);
        }
        if ($essentials !== []) {
            $builder->withEssentialModules($essentials);
        }
        // The kernel refuses to boot with no security layer — see
        // BindSecurityStage, and BootFailureTest which pins that behaviour. The
        // harness therefore always supplies one rather than relaxing the check.
        $builder->withSecurity($security !== [] ? $security : [$this->security]);
        if ($domains !== []) {
            $builder->withProjectDomains($domains);
        }
        if ($groups !== []) {
            $builder->withRouteGroups($groups);
        }
        if ($routes !== []) {
            $builder->withRoutes($routes);
        }
        if ($disable !== []) {
            $builder->withRoutePolicy($disable);
        }

        return $this->kernel = $builder->build();
    }

    // ── Dispatching ──────────────────────────────────────────────────────────

    /** @param array<string, string> $headers */
    protected function get(string $uri, array $headers = []): TestResponse
    {
        return $this->call('GET', $uri, headers: $headers);
    }

    /** @param array<string, mixed> $data */
    protected function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, $data, $headers);
    }

    /** @param array<string, mixed> $data */
    protected function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('PUT', $uri, $data, $headers);
    }

    protected function delete(string $uri, array $headers = []): TestResponse
    {
        return $this->call('DELETE', $uri, headers: $headers);
    }

    /**
     * POST a JSON body — the shape most API routes actually receive, and the one
     * that exercises Request's JSON decoding rather than its form parsing.
     *
     * @param array<string, mixed> $data
     */
    protected function postJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, headers: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
            ...$headers,
        ], content: json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Dispatch through the REAL HttpPipeline.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers server-style keys (HTTP_X_FOO, CONTENT_TYPE)
     */
    protected function call(
        string $method,
        string $uri,
        array $data = [],
        array $headers = [],
        ?string $content = null,
        ?Identity $as = null,
    ): TestResponse {
        if ($this->kernel === null) {
            throw new \LogicException('Call boot() before dispatching a request.');
        }

        $request = Request::create($uri, $method, $data, server: $headers, content: $content);

        // Attach an identity directly when a test is exercising something behind
        // auth without wanting to model the whole login. The pipeline's own
        // SecurityStage still runs and can still overwrite this.
        if ($as !== null) {
            $request = $request->withIdentity($as);
        }

        return new TestResponse($this->kernel->http()->handle($request));
    }

    /** Dispatch as an authenticated user. */
    protected function actingAs(Identity $identity, string $method, string $uri, array $data = []): TestResponse
    {
        return $this->call($method, $uri, $data, as: $identity);
    }

    // ── Introspection ────────────────────────────────────────────────────────

    /**
     * A compiled manifest, straight off disk.
     *
     * Feature tests assert on BEHAVIOUR, but a few things (a disabled route, an
     * override) are most honestly asserted on the artefact the compiler actually
     * produced.
     *
     * @return array<string, mixed>
     */
    protected function manifest(string $file): array
    {
        $path = $this->root . '/var/cache/manifests/' . $file;

        return is_file($path) ? (require $path) : [];
    }

    /** Whatever metrics the request emitted, when a recorder was bound. */
    protected function metricsRecorder(): ?MetricsPort
    {
        return $this->kernel?->container()->has(MetricsPort::class)
            ? $this->kernel->container()->make(MetricsPort::class)
            : null;
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
