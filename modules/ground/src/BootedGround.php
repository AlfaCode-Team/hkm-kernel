<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground;

use AlfacodeTeam\PhpIoCli\BufferIO;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Kernel;
use AlfacodeTeam\PhpServicePlatform\Kernel\Loading\DependencyGraphCalculator;
use AlfacodeTeam\PhpServicePlatform\Kernel\Loading\OnDemandLoader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use AlfacodeTeam\Ground\Fakes;
use AlfacodeTeam\Ground\Ui\PageResolver;
use AlfacodeTeam\Ground\Ui\UiManifest;

/**
 * A booted plugin: dispatch requests at it, resolve its contracts, read what it
 * recorded.
 *
 * Created by {@see PluginGround::boot()} — never directly, because the workspace
 * and env restoration it owns are set up there.
 */
final class BootedGround
{
    private ?ModuleContainer $container = null;

    /** @var array<string, PageResolver> surface => resolver, memoized (each scans the disk) */
    private array $pageResolvers = [];

    private ?UiManifest $ui = null;

    /** @var list<string> aliases the ground stubbed because nothing registered them */
    private array $stubbedFilters = [];

    private bool $destroyed = false;

    public function __construct(
        private readonly Kernel $kernel,
        private readonly PluginManifest $manifest,
        private readonly Workspace $workspace,
        /** @var array<class-string, object> */
        private readonly array $ports,
        private readonly EventRecorder $recorder,
        private ?Identity $identity,
        /** @var array<string, array{0: bool, 1: string|null}> */
        private readonly array $restoreEnv,
        /** @var array<string, string> env applied for this ground, re-asserted on activate() */
        private readonly array $envValues,
        /** @var list<string> required vars filled with a placeholder */
        private readonly array $placeholders,
        private readonly Fakes\StubFilterStage $filterStub = new Fakes\StubFilterStage(),
        bool $stubFilters = true,
        /** @var list<class-string> essential providers, resolved from domains */
        private readonly array $essentials = [],
    ) {
        // Materialize now rather than on first request: Provider::boot() runs
        // here, so a hook or subscription that throws is reported by boot()
        // instead of by an unrelated request three assertions later.
        $this->kernel->container();

        $this->subscribeRecorder();
        $this->stubbedFilters = $stubFilters ? $this->stubMissingFilters() : [];
    }

    /**
     * Route-filter aliases the ground supplied a PASS-THROUGH stand-in for,
     * because no loaded plugin registered them.
     *
     * Read this before trusting a test about a protected route: a stubbed filter
     * did not run, so `auth` being stubbed means the route was NOT authenticated
     * during that test. Load the providing plugin when the filter is the subject.
     *
     * @return list<string>
     */
    public function stubbedFilters(): array
    {
        return $this->stubbedFilters;
    }

    /** The shared stand-in, for asserting which routes passed through one. */
    public function filterStub(): Fakes\StubFilterStage
    {
        return $this->filterStub;
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    public function get(string $path, array $query = [], array $headers = []): GroundResponse
    {
        return $this->call('GET', $path, query: $query, headers: $headers);
    }

    public function post(string $path, array $body = [], array $headers = []): GroundResponse
    {
        return $this->call('POST', $path, body: $body, headers: $headers);
    }

    public function put(string $path, array $body = [], array $headers = []): GroundResponse
    {
        return $this->call('PUT', $path, body: $body, headers: $headers);
    }

    public function patch(string $path, array $body = [], array $headers = []): GroundResponse
    {
        return $this->call('PATCH', $path, body: $body, headers: $headers);
    }

    public function delete(string $path, array $body = [], array $headers = []): GroundResponse
    {
        return $this->call('DELETE', $path, body: $body, headers: $headers);
    }

    /**
     * Send a JSON request — body encoded, Content-Type and Accept set.
     *
     * Worth its own method because the kernel branches on both headers:
     * Request::expectsJson() decides whether an error comes back as JSON or as
     * the debug HTML page, so a JSON API tested with form encoding is not being
     * tested the way it is called.
     */
    public function json(string $method, string $path, array $body = [], array $headers = []): GroundResponse
    {
        return $this->call($method, $path, body: $body, headers: [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            ...$headers,
        ], rawBody: (string) json_encode($body));
    }

    /**
     * The general form. Everything above delegates here.
     *
     * @param string|null $host sets the VALIDATED route_host attribute, the one
     *                          ResolveStage selects a domain group with. Passing
     *                          it is how a domain-grouped route is reached.
     * @param string|null $face sets route_face, for routes declaring faces[].
     */
    public function call(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        array $headers = [],
        string $rawBody = '',
        array $cookies = [],
        ?string $host = null,
        ?string $face = null,
    ): GroundResponse {
        $this->activate();

        $logger    = $this->ports[Ports\LoggerPort::class] ?? null;
        $logBefore = $logger instanceof Fakes\FakeLogger ? \count($logger->records) : 0;

        $request = Request::build(
            method:  $method,
            path:    $path,
            headers: $headers,
            query:   $query,
            body:    $body,
            rawBody: $rawBody,
            cookies: $cookies,
            server:  $host === null ? [] : ['HTTP_HOST' => $host],
        );

        if ($this->identity !== null) {
            $request = $request->withIdentity($this->identity);
        }
        if ($host !== null) {
            $request = $request->withAttribute('route_host', $host);
        }
        if ($face !== null) {
            $request = $request->withAttribute('route_face', $face);
        }

        $response = $this->kernel->http()->handle($request);

        // Only the lines this request produced — carrying earlier failures into
        // every later assertion message would make each one harder to read.
        $problems = $logger instanceof Fakes\FakeLogger
            ? $logger->problemsSince($logBefore)
            : [];

        return new GroundResponse($response, $problems);
    }

    // ── Pageflow (the visual layer's server contract) ─────────────────────────

    /**
     * Request a page as the Pageflow CLIENT does, and get the page object.
     *
     * Sends `X-Pageflow: true`, which is what PageflowResponder::isPageflow()
     * checks — so the response is the JSON page object rather than the HTML
     * shell. That matters for a test: the shell's markup is a React concern that
     * changes freely, while `{component, props, url, version}` is the contract
     * between server and client and is what a route actually promises.
     */
    public function pageflow(string $path, array $query = [], array $headers = []): PageflowResponse
    {
        return PageflowResponse::fromJson($this->call('GET', $path, query: $query, headers: [
            'X-Pageflow' => 'true',
            'Accept'     => 'application/json',
            ...$headers,
        ]));
    }

    /** The same, for a mutating request. */
    public function pageflowSend(string $method, string $path, array $body = [], array $headers = []): PageflowResponse
    {
        return PageflowResponse::fromJson($this->call($method, $path, body: $body, headers: [
            'X-Pageflow'   => 'true',
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            ...$headers,
        ], rawBody: (string) json_encode($body)));
    }

    /**
     * A FULL page load — the HTML shell — with the page object parsed back out.
     *
     * Use when the test is about the first load: the shell is where the CSRF
     * meta tag, the Vite tags and the mount point live, so it is the only path
     * that can catch a broken layout or a missing asset entry.
     */
    public function pageflowLoad(string $path, array $query = [], array $headers = []): PageflowResponse
    {
        return PageflowResponse::fromHtml($this->call('GET', $path, query: $query, headers: [
            'Accept' => 'text/html',
            ...$headers,
        ]));
    }

    /**
     * The page-file resolver for a surface, scoped to the plugin under test.
     *
     * Answers the question no PHP test otherwise can: does the component this
     * route names actually EXIST as a page the surface can find? Widen it with
     * PageResolver's with*() methods when the test is about a project override.
     */
    public function pages(string $surface = 'admin'): PageResolver
    {
        return $this->pageResolvers[$surface] ??= PageResolver::forPlugin(
            $this->manifest->directory(),
            $surface,
        );
    }

    /** The plugin's `ui/ui.json`, present or not. */
    public function ui(): UiManifest
    {
        return $this->ui ??= UiManifest::for($this->manifest->directory());
    }

    /**
     * Every surface this plugin ships pages for, derived from the faces it
     * actually has on disk — `ui/admin/Pages` → 'admin', `ui/site/Pages` → both
     * 'admin' and 'project', because the admin surface globs site pages too.
     *
     * Use it to assert a component resolves on EVERY surface that can serve it,
     * rather than guessing which one a route meant.
     *
     * @return list<string>
     */
    public function surfaces(): array
    {
        $surfaces = [];

        if ($this->ui()->pageFilesFor('admin') !== []) {
            $surfaces['admin'] = true;
        }
        if ($this->ui()->pageFilesFor('site') !== []) {
            $surfaces['admin']   = true;
            $surfaces['project'] = true;
        }

        return array_keys($surfaces);
    }

    /** The Identity subsequent requests and containers carry. */
    public function as(?Identity $identity): self
    {
        $this->identity  = $identity;
        // The container caches the Identity binding, so it must not survive a
        // change of who is acting.
        $this->container = null;

        return $this;
    }

    // ── Resolving ─────────────────────────────────────────────────────────────

    /**
     * Resolve one of the plugin's published contracts, in the plugin's own scope.
     *
     * Scope matters: resolving in the plugin's scope is what lets a service
     * reach its own bindInternal() repository. Resolving the same contract from
     * outside is supposed to throw ScopeViolationException, and
     * {@see makeFromScope()} exists so a test can prove it does.
     */
    public function service(string $contract): mixed
    {
        return $this->makeFromScope($contract, $this->manifest->solves());
    }

    /** Resolve as if another module were asking — the cross-scope isolation test. */
    public function makeFromScope(string $abstract, string $scope): mixed
    {
        return $this->container()->makeInScope($abstract, $scope);
    }

    /**
     * The request-scoped container, built from the plugin's real dependency
     * graph. Memoized: two service() calls in one test should see the same
     * instances, as they would inside one request.
     */
    public function container(): ModuleContainer
    {
        $this->activate();

        if ($this->container !== null) {
            return $this->container;
        }

        $manifest = ManifestReader::readCompiled('service-manifest.php');
        $graph    = (new DependencyGraphCalculator($manifest))->resolve($this->manifest->solves());

        // The essentials MUST be passed, exactly as HttpPipeline does
        // (HttpPipeline.php:114). Without them this container silently omits
        // every essential module's bindings, so `$ground->service()` and
        // `$ground->get()` would disagree about what is bound — the harness
        // itself becoming the reason a test passes or fails.
        $loader = new OnDemandLoader($this->core(), $this->essentials);

        return $this->container = $loader->loadWithIdentity($graph, $this->identity);
    }

    /** Discard the request-scoped container, as the end of a request would. */
    public function newRequest(): self
    {
        $this->container = null;

        return $this;
    }

    public function core(): CoreContainer
    {
        $this->activate();

        return $this->kernel->container();
    }

    public function kernel(): Kernel
    {
        return $this->kernel;
    }

    // ── Ports ─────────────────────────────────────────────────────────────────

    public function db(): Fakes\FakeDatabase       { return $this->fake(Ports\DatabasePort::class, Fakes\FakeDatabase::class); }
    public function cache(): Fakes\FakeCache       { return $this->fake(Ports\CachePort::class, Fakes\FakeCache::class); }
    public function queue(): Fakes\FakeQueue       { return $this->fake(Ports\QueuePort::class, Fakes\FakeQueue::class); }
    public function mail(): Fakes\FakeMail         { return $this->fake(Ports\MailPort::class, Fakes\FakeMail::class); }
    public function sms(): Fakes\FakeSms           { return $this->fake(Ports\SmsPort::class, Fakes\FakeSms::class); }
    public function storage(): Fakes\FakeStorage   { return $this->fake(Ports\StoragePort::class, Fakes\FakeStorage::class); }
    public function logger(): Fakes\FakeLogger     { return $this->fake(Ports\LoggerPort::class, Fakes\FakeLogger::class); }
    public function clock(): Fakes\FrozenClock     { return $this->fake(Ports\ClockPort::class, Fakes\FrozenClock::class); }
    public function hasher(): Fakes\FakeHasher     { return $this->fake(Ports\HashingPort::class, Fakes\FakeHasher::class); }
    public function encrypter(): Fakes\FakeEncrypter { return $this->fake(Ports\EncryptionPort::class, Fakes\FakeEncrypter::class); }

    /** The instance bound for a port, whatever it is. Use when you supplied your own. */
    public function portFor(string $abstract): ?object
    {
        return $this->ports[$abstract] ?? null;
    }

    // ── Events ────────────────────────────────────────────────────────────────

    /**
     * Everything the plugin dispatched — but only for names it declared in
     * module.json `emits[]`, which is all EventBus allows subscribing to.
     */
    public function events(): EventRecorder
    {
        return $this->recorder;
    }

    /** Dispatch an event AT the plugin, to drive a listener it subscribed. */
    public function dispatch(IntegrationEventContract $event): self
    {
        $this->activate();
        $this->core()->get(EventBus::class)->dispatch($event);

        return $this;
    }

    // ── CLI ───────────────────────────────────────────────────────────────────

    /**
     * Run one of the plugin's commands through the REAL CliPipeline.
     *
     *     $result = $ground->cli('user:outbox:relay', ['--limit', '10']);
     *     $result->exitCode;  $result->sees('relayed');
     *
     * Output is captured with BufferIO — the seam `CLIApplication::withIO()`
     * exists for. Commands are the surface most often shipped untested, because
     * exercising one usually means running the whole binary against a real
     * database; here it runs against the same fake ports as everything else.
     *
     * @param list<string> $args arguments and flags, WITHOUT the command name
     */
    public function cli(string $command, array $args = []): CliResult
    {
        $this->activate();

        $io = new BufferIO();
        $this->kernel->cli()->application()->withIO($io);

        // argv[0] is the script name — CliPipeline::run() slices it off, so
        // omitting it would silently swallow the command name instead.
        $code = $this->kernel->cli()->run(['ground', $command, ...$args]);

        return new CliResult($code, $io->getOutput());
    }

    /** Every command class registered by the loaded plugins' boot(). */
    public function commands(): array
    {
        $this->activate();

        return $this->kernel->cli()->commands();
    }

    /** Is this command name registered? Cheaper than running it, and a better failure. */
    public function hasCommand(string $name): bool
    {
        $this->activate();

        foreach ($this->kernel->cli()->application()->all() as $command) {
            if ($command->getName() === $name) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> registered command NAMES, for a readable failure message */
    public function commandNames(): array
    {
        $this->activate();

        $names = array_map(
            static fn($c): string => $c->getName(),
            $this->kernel->cli()->application()->all(),
        );
        sort($names);

        return array_values($names);
    }

    // ── Jobs ──────────────────────────────────────────────────────────────────

    /**
     * Run a job class directly, in a container built from the plugin's graph.
     *
     * This is the job's handle(), not the worker pipeline — no dequeue, no
     * signature check, no retry. Those belong to the kernel and are tested there;
     * what a plugin author needs is to run their own handler against fake ports.
     */
    public function runJob(string $jobClass, array $data = [], int $attempts = 0): mixed
    {
        $this->activate();

        $payload = new JobPayload(
            jobId:       'ground-job',
            jobClass:    $jobClass,
            data:        $data,
            queue:       'default',
            attempts:    $attempts,
            maxAttempts: Fakes\FakeQueue::MAX_ATTEMPTS,
            enqueuedAt:  new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            // Signed through the kernel's own signer — see FakeQueue::push().
            // A handler that verifies its own payload must be able to, and a
            // hand-rolled HMAC here would make that impossible for no reason.
            signature:   JobPayload::sign(
                Fakes\FakeQueue::SECRET,
                'ground-job',
                $jobClass,
                $data,
                'default',
                Fakes\FakeQueue::MAX_ATTEMPTS,
            ),
        );

        $job = $this->container()->makeInScope($jobClass, $this->manifest->solves());

        return $job->handle($payload);
    }

    /**
     * Drain the queue through the REAL WorkerLoop.
     *
     * The difference from {@see runJob()} matters. runJob() calls a handler.
     * This runs the whole worker path: pop → resolve the job's module scope from
     * job-manifest.php → build a container → handle → ack, release or fail. Retry
     * and dead-lettering are decided here, by the kernel, so this is the ONLY way
     * to test that a failing job is actually retried rather than swallowed.
     *
     * `maxIterations` is a hard stop — a worker loop runs forever by design, and
     * a test that forgot the bound would hang the suite. It is capped rather
     * than defaulted to "drain until empty" because a job that re-queues itself
     * would never empty the queue.
     *
     * @param int $maxIterations how many pops to attempt. Each empty pop costs a
     *                           100ms idle backoff inside the loop, so keep it
     *                           close to the number of jobs actually queued.
     */
    public function work(string $queue = 'default', int $maxIterations = 1): self
    {
        $this->activate();

        $this->kernel->workerLoop()->run(null, $maxIterations, $queue);

        return $this;
    }

    /**
     * Push a job the way application code does, then drain it.
     *
     * The shorthand for the common case, and it goes through QueuePort::push()
     * so the payload (including its signature) is built exactly as production
     * would build it.
     */
    public function pushAndWork(string $jobClass, array $data = [], string $queue = 'default'): self
    {
        $this->queue()->push($jobClass, $data, $queue);

        return $this->work($queue, 1);
    }

    /** The compiled job manifest — name => {handler, queue, module, solves}. */
    public function jobs(): array
    {
        $this->activate();

        return ManifestReader::readCompiled('job-manifest.php');
    }

    /** Did the plugin's module.json declare this job name? */
    public function hasJob(string $name): bool
    {
        return \array_key_exists($name, $this->jobs());
    }

    // ── What the boot produced ────────────────────────────────────────────────

    /** The compiled route manifest: "METHOD /path" => entry. */
    public function routes(): array
    {
        $this->activate();

        return ManifestReader::readCompiled('route-manifest.php');
    }

    /** Did the plugin's routes actually compile to this key? */
    public function hasRoute(string $method, string $path, ?string $domain = null): bool
    {
        $key = strtoupper($method) . ($domain === null ? '' : '@' . $domain) . ' ' . $path;

        return \array_key_exists($key, $this->routes());
    }

    /** The compiled service manifest — the dependency graph the kernel derived. */
    public function services(): array
    {
        $this->activate();

        return ManifestReader::readCompiled('service-manifest.php');
    }

    public function manifest(): PluginManifest
    {
        return $this->manifest;
    }

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    /**
     * Required env vars that had no declared default and were filled with a
     * stand-in so the boot could proceed.
     *
     * Anything a test asserts about while these are in play is being asserted
     * against a placeholder. `plugin:probe` prints them for the same reason.
     *
     * @return list<string>
     */
    public function placeholders(): array
    {
        return $this->placeholders;
    }

    // ── Teardown ──────────────────────────────────────────────────────────────

    /**
     * Remove the workspace and put the environment back.
     *
     * Always call it — a PHPUnit tearDown() is the right home. Leaking a ground
     * leaves $_ENV mutated and Paths pointing at a deleted directory, which
     * surfaces as an unrelated later test failing for no visible reason.
     */
    public function destroy(): void
    {
        $this->container = null;
        $this->destroyed = true;
        PluginGround::restoreEnv($this->restoreEnv);
        $this->workspace->destroy();
    }

    /** Has destroy() already run? */
    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Re-assert the process-global state this ground depends on.
     *
     * Paths is a static singleton and env lives in superglobals, so a second
     * ground booted in the same process silently redirects the first one's
     * manifest reads. Re-asserting before every operation is cheap and removes
     * a class of failure that is very hard to diagnose from the symptom.
     */
    private function activate(): void
    {
        // Using a destroyed ground otherwise points Paths at a DELETED directory
        // and the symptom is a manifest that reads as empty — surfacing as
        // "route not found" or "no bindings", a long way from the real cause.
        if ($this->destroyed) {
            throw new \LogicException(
                'Ground: this ground was destroyed. Its workspace is gone and its env was '
                . 'restored. Boot a new one — PluginGroundTestCase does that per test.'
            );
        }

        Paths::setBase($this->workspace->root);
        Paths::setProject($this->workspace->root);

        foreach ($this->envValues as $key => $value) {
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * Subscribe the recorder to every name the plugin declares it emits.
     *
     * EventBus has no wildcard, so `emits[]` is the only list available. A name
     * missing from it is simply not recorded — which is why `plugin:check`
     * reports declared-vs-dispatched drift separately.
     */
    private function subscribeRecorder(): void
    {
        $emits = $this->manifest->emits();
        if ($emits === []) {
            return;
        }

        $bus = $this->core()->get(EventBus::class);
        foreach ($emits as $name) {
            $bus->subscribe($name, EventRecorder::class);
        }
    }

    /**
     * Register the pass-through stand-in for every filter alias the compiled
     * routes name and nothing registered.
     *
     * Registration is attempted for EVERY alias and the conflict is caught,
     * rather than asking the registry what it holds: `FilterRegistry::has()` is
     * reachable only through the pipeline's private property, and
     * `register()` already throws precisely when a real filter owns the alias.
     * Catching that is the same question, asked through the public API.
     *
     * @return list<string>
     */
    private function stubMissingFilters(): array
    {
        $http    = $this->core()->get(HttpPipeline::class);
        $stubbed = [];

        foreach ($this->routes() as $entry) {
            $specs = \is_array($entry['filter_specs'] ?? null)
                ? array_column($entry['filter_specs'], 'alias')
                : array_map(
                    static fn($f): string => explode(':', trim((string) $f), 2)[0],
                    (array) ($entry['filters'] ?? []),
                );

            foreach ($specs as $alias) {
                if ($alias === '' || \in_array($alias, $stubbed, true)) {
                    continue;
                }

                try {
                    $http->filter($alias, Fakes\StubFilterStage::class);
                    $stubbed[] = $alias;
                } catch (\InvalidArgumentException) {
                    // A real plugin owns this alias — leave it alone. This is the
                    // path taken when the test loaded SecurityFilters on purpose.
                }
            }
        }

        sort($stubbed);

        return $stubbed;
    }

    /**
     * @template T of object
     * @param  class-string<T> $expected
     * @return T
     */
    private function fake(string $port, string $expected): object
    {
        $bound = $this->ports[$port] ?? null;

        if (!$bound instanceof $expected) {
            $actual = $bound === null ? 'nothing' : $bound::class;

            throw new \LogicException(
                "Ground: {$port} is bound to {$actual}, not {$expected}. "
                . 'You replaced it with port() — reach it through portFor() instead.'
            );
        }

        return $bound;
    }
}
