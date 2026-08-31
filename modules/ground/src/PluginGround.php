<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Kernel;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\Ground\Fakes;

/**
 * Boot ONE plugin, on a real kernel, in an isolated workspace.
 *
 *     $ground = PluginGround::for(Provider::class)->boot();
 *     $ground->get('/tasks')->status();          // through the real HttpPipeline
 *     $ground->service(TaskServiceContract::class);
 *     $ground->events()->dispatched('task.created');
 *     $ground->destroy();
 *
 * Nothing here simulates the framework. It runs the real BootPipeline, the real
 * route compiler, the real dependency graph, the real scope isolation — the
 * things a plugin actually gets wrong. What it replaces is only the
 * infrastructure underneath (the ports) and the project around it.
 *
 * Three problems it solves that a hand-rolled test bootstrap does not:
 *
 *   - Ports. A plugin requiring DatabasePort cannot boot without one, so every
 *     port is pre-bound to a fake. Override any of them with port().
 *   - Config. ValidateConfigStage fails the boot on a missing required env var.
 *     Declared defaults are seeded from module.json; a required var with no
 *     default gets a MARKED placeholder, listed by placeholders(), so the boot
 *     proceeds without the substitution being invisible.
 *   - Isolation. Compiled manifests are written into a temp workspace, never
 *     over the project's own.
 */
final class PluginGround
{
    /** @var list<class-string<ModuleContract>> */
    private array $providers = [];

    /** @var list<string> provider class-strings or solves domains */
    private array $essentials = [];

    /** @var array<class-string, object> */
    private array $ports = [];

    /** @var list<SecurityLayerContract> */
    private array $security = [];

    /** @var list<array<string, mixed>> */
    private array $routes = [];

    /** @var array<string, mixed> */
    private array $groups = [];

    /** @var list<string> */
    private array $domains = [];

    /** @var array<string, string> env overrides applied for the boot */
    private array $env = [];

    private ?Identity $identity = null;

    private bool $stubFilters = true;

    private function __construct() {}

    /**
     * The plugin under test, by its Provider class. Extra providers are its
     * dependencies — every domain in the plugin's requires[] must be registered
     * or the boot fails, by design.
     *
     * @param class-string<ModuleContract> $provider
     * @param class-string<ModuleContract> ...$dependencies
     */
    public static function for(string $provider, string ...$dependencies): self
    {
        $ground = new self();
        $ground->providers = [$provider, ...$dependencies];

        return $ground;
    }

    /** @param class-string<ModuleContract> ...$providers */
    public function with(string ...$providers): self
    {
        foreach ($providers as $provider) {
            if (!\in_array($provider, $this->providers, true)) {
                $this->providers[] = $provider;
            }
        }

        return $this;
    }

    /**
     * Load these on EVERY request rather than on demand — the ground equivalent
     * of proj.json "essentials". Accepts provider class-strings or solves domains.
     */
    public function essential(string ...$modules): self
    {
        $this->essentials = [...$this->essentials, ...$modules];

        return $this;
    }

    /** Replace one pre-bound fake with your own double or a real adapter. */
    public function port(string $abstract, object $implementation): self
    {
        $this->ports[$abstract] = $implementation;

        return $this;
    }

    /** @param array<class-string, object> $ports */
    public function ports(array $ports): self
    {
        $this->ports = [...$this->ports, ...$ports];

        return $this;
    }

    /**
     * Install security layers.
     *
     * By default the ground installs a single allow-all stand-in
     * ({@see Fakes\OpenGateLayer}), because BindSecurityStage refuses to boot
     * with an empty list. Supplying any layer here replaces it — which is what
     * a test ABOUT authentication or CSRF wants.
     */
    public function security(SecurityLayerContract ...$layers): self
    {
        $this->security = [...$this->security, ...$layers];

        return $this;
    }

    /** Project-layer routes, as proj.json declares them. */
    public function routes(array $routes): self
    {
        $this->routes = [...$this->routes, ...$routes];

        return $this;
    }

    /** Project-layer route groups, as proj.json declares them. */
    public function routeGroups(array $groups): self
    {
        $this->groups = $groups;

        return $this;
    }

    /**
     * Hosts the pretend project serves. Required before a route group may name a
     * `domain` — the compiler validates declared hosts against this list, and
     * that check is one of the things a ground test should be exercising.
     */
    public function domains(string ...$hosts): self
    {
        $this->domains = [...$this->domains, ...$hosts];

        return $this;
    }

    /**
     * Do NOT stand in for unregistered route-filter aliases.
     *
     * By default the ground registers a pass-through for any alias its routes
     * name that no loaded plugin provides — otherwise `ROUTE_STRICT_FILTERS`
     * refuses to build the pipeline and almost no real plugin could be tested
     * at all. Turn it off to assert the opposite: that every alias this plugin's
     * routes declare is genuinely registered by the plugins loaded with it.
     */
    public function realFilters(): self
    {
        $this->stubFilters = false;

        return $this;
    }

    /** The Identity requests carry. Defaults to guest. */
    public function as(?Identity $identity): self
    {
        $this->identity = $identity;

        return $this;
    }

    /**
     * Set env vars for this boot. Applied to $_ENV/$_SERVER — which is what
     * env() reads — and restored on destroy().
     *
     * @param array<string, string|int|float|bool> $vars
     */
    public function env(array $vars): self
    {
        foreach ($vars as $key => $value) {
            $this->env[$key] = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $this;
    }

    /**
     * Compile, wire and materialize. Throws whatever the kernel throws — a
     * BootException here is a real finding about the plugin, not harness noise.
     */
    public function boot(): BootedGround
    {
        $manifest  = PluginManifest::of($this->providers[0]);
        $workspace = Workspace::create($manifest->name());

        // Everything below mutates process-global state ($_ENV, Paths). Any
        // failure must still hand those back, or one broken boot poisons every
        // later test in the process.
        $restoreEnv = [];
        $recorder   = new EventRecorder();
        $filterStub = new Fakes\StubFilterStage();
        $ports      = $this->resolvePorts();

        try {
            [$restoreEnv, $placeholders, $envValues] = $this->applyEnv();

            $kernel = Kernel::configure()
                ->withBasePath($workspace->root)
                ->withProjectPath($workspace->root)
                // The recorder is bound as a port so it lands in the CoreContainer
                // BEFORE freeze; EventBus then resolves this instance rather than
                // constructing a fresh listener per dispatch.
                // Both stand-ins are bound as ports so they land in the
                // CoreContainer BEFORE it freezes — FilterRegistry::resolve()
                // and EventBus both prefer a bound instance over `new`, so the
                // test and the pipeline share these exact objects.
                ->withPorts([
                    ...$ports,
                    EventRecorder::class          => $recorder,
                    Fakes\StubFilterStage::class  => $filterStub,
                ])
                // BindSecurityStage refuses an EMPTY layer list — fail-closed, so
                // an app that forgot to configure security never serves traffic.
                // A ground must still boot, so it installs an allow-all stand-in
                // when the test supplied none. Supplying one replaces it.
                ->withSecurity($this->security === [] ? [new Fakes\OpenGateLayer()] : $this->security)
                ->withModules($this->providers);

            if ($this->essentials !== []) {
                $kernel->withEssentialModules($this->essentials);
            }
            if ($this->routes !== []) {
                $kernel->withRoutes($this->routes);
            }
            if ($this->groups !== []) {
                $kernel->withRouteGroups($this->groups);
            }
            if ($this->domains !== []) {
                $kernel->withProjectDomains($this->domains);
            }

            $kernel->build();

            return new BootedGround(
                kernel:       $kernel,
                manifest:     $manifest,
                workspace:    $workspace,
                ports:        $ports,
                recorder:     $recorder,
                identity:     $this->identity,
                restoreEnv:   $restoreEnv,
                envValues:    $envValues,
                placeholders: $placeholders,
                filterStub:   $filterStub,
                stubFilters:  $this->stubFilters,
                essentials:   $this->resolveEssentialProviders(),
            );
        } catch (\Throwable $e) {
            self::restoreEnv($restoreEnv);
            $workspace->destroy();

            throw $e;
        }
    }

    /**
     * Every port pre-bound to a fake, with the caller's overrides on top.
     *
     * Bound eagerly rather than as lazy closures so a test can reach the SAME
     * instance through the ground's accessors — a closure would build a second
     * one on first resolution and every assertion would read an empty double.
     *
     * @return array<class-string, object>
     */
    private function resolvePorts(): array
    {
        $clock = new Fakes\FrozenClock();

        $defaults = [
            Ports\DatabasePort::class   => new Fakes\FakeDatabase(),
            Ports\CachePort::class      => new Fakes\FakeCache($clock),
            Ports\QueuePort::class      => new Fakes\FakeQueue(),
            Ports\MailPort::class       => new Fakes\FakeMail(),
            Ports\SmsPort::class        => new Fakes\FakeSms(),
            Ports\StoragePort::class    => new Fakes\FakeStorage(),
            Ports\LoggerPort::class     => new Fakes\FakeLogger(),
            Ports\ClockPort::class      => $clock,
            Ports\HashingPort::class    => new Fakes\FakeHasher(),
            Ports\EncryptionPort::class => new Fakes\FakeEncrypter(),
        ];

        return [...$defaults, ...$this->ports];
    }

    /**
     * Seed env for the boot: the plugin's declared defaults, a marked
     * placeholder for anything required without one, then the caller's values.
     *
     * @return array{0: array<string, array{0: bool, 1: string|null}>, 1: list<string>, 2: array<string, string>}
     */
    private function applyEnv(): array
    {
        $values = [
            // A key, because plenty of plugins sign something; a fixed one so a
            // signature is reproducible across runs.
            'APP_KEY'   => 'base64:' . base64_encode(str_repeat('g', 32)),
            'APP_ENV'   => 'testing',
            'APP_DEBUG' => 'true',
            'APP_URL'   => 'http://ground.test',
        ];

        $placeholders = [];
        foreach ($this->providers as $provider) {
            foreach (PluginManifest::of($provider)->configVars() as $var) {
                if ($var['default'] !== null) {
                    $values[$var['key']] ??= $var['default'];
                    continue;
                }
                if (!$var['required']) {
                    // Deliberately left unset. An empty string is a VALUE and
                    // would beat the plugin's own internal default — the same
                    // distinction `hkm plugins enable` makes when it writes the
                    // optional vars commented out.
                    continue;
                }

                $values[$var['key']] ??= self::placeholder($var['key'], $var['type']);
                $placeholders[] = $var['key'];
            }
        }

        // The plugin's own `.env` sits BETWEEN the synthesised floor and an
        // explicit ->env(): it supplies the real values this machine has (a
        // sandbox key, a local mail host), overriding placeholders and
        // defaults. A test naming a value still wins, because that value is a
        // precondition of the test and must not depend on a file the test
        // never mentions.
        $fromFile = EnvFile::read($this->pluginDirectory());
        $values   = [...$values, ...$fromFile, ...$this->env];

        $restore = [];
        foreach ($values as $key => $value) {
            $restore[$key] = [\array_key_exists($key, $_ENV), $_ENV[$key] ?? null];
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }

        // A var the .env actually sets is no longer standing on a placeholder,
        // so it must not be reported as one — that list is what the harness
        // prints as "not configured", and naming a var the developer just
        // configured sends them looking for a problem that is not there.
        $placeholders = array_values(array_filter(
            array_unique($placeholders),
            static fn(string $key): bool => !isset($fromFile[$key]),
        ));

        return [$restore, $placeholders, $values];
    }

    /**
     * The directory of the plugin UNDER TEST — providers[0] by construction.
     *
     * Only that plugin's `.env` is read. A dependency's would be reaching into
     * a checkout the developer is not working on, and two of them could set the
     * same key with no way to see which won.
     */
    private function pluginDirectory(): string
    {
        return PluginManifest::of($this->providers[0])->directory();
    }

    /**
     * A stand-in that satisfies the declared TYPE — ValidateConfigStage checks
     * it, so an int var filled with "ground-placeholder" would fail the boot with
     * a type error and send the developer looking in the wrong place.
     */
    private static function placeholder(string $key, string $type): string
    {
        return match ($type) {
            'int', 'integer' => '1',
            'float'          => '1.0',
            'bool', 'boolean' => 'false',
            default          => 'ground-placeholder-' . $key,
        };
    }

    /**
     * The essential entries resolved to PROVIDER CLASSES, the shape
     * OnDemandLoader wants.
     *
     * `essential()` accepts class-strings AND solves domains (the shape
     * proj.json uses), and the kernel resolves domains internally — but keeps
     * the result private. Without resolving them here too, the ground's own
     * container() would build a container WITHOUT the essential modules while
     * an HTTP request through HttpPipeline got them, so `$ground->service()`
     * and `$ground->get()` would disagree about what is bound.
     *
     * An unresolvable domain is skipped rather than thrown on: Kernel::build()
     * already fails the boot on one, with a better message than this could give.
     *
     * @return list<class-string>
     */
    private function resolveEssentialProviders(): array
    {
        if ($this->essentials === []) {
            return [];
        }

        $byDomain = [];
        foreach ($this->providers as $provider) {
            try {
                $byDomain[PluginManifest::of($provider)->solves()] = $provider;
            } catch (\Throwable) {
                // A provider without a readable module.json fails the boot itself.
            }
        }

        $resolved = [];
        foreach ($this->essentials as $entry) {
            $class = str_contains($entry, '\\') ? $entry : ($byDomain[$entry] ?? null);
            if ($class !== null) {
                $resolved[$class] = true;
            }
        }

        return array_keys($resolved);
    }

    /** @param array<string, array{0: bool, 1: string|null}> $restore */
    public static function restoreEnv(array $restore): void
    {
        foreach ($restore as $key => [$existed, $previous]) {
            if ($existed) {
                $_ENV[$key]    = $previous;
                $_SERVER[$key] = $previous;
            } else {
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }
}
