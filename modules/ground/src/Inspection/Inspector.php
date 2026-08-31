<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Inspection;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\Ground\PluginManifest;

/**
 * Static conformance checks for one plugin — no boot, no dependencies, no ports.
 *
 * DELIBERATELY NOT a re-implementation of the boot pipeline. A malformed route
 * path, an unknown parameter type and an unregistered filter alias already fail
 * the boot loudly, and duplicating those checks here would mean maintaining two
 * definitions of the same rule that drift apart.
 *
 * What it covers is the set of mistakes NOTHING else reports:
 *
 *   1. Provider/manifest drift. The kernel reads module.json and never calls
 *      solves()/requires()/exposes(), so a Provider that disagrees with its own
 *      manifest is invisible — until someone trusts the method.
 *   2. Undeclared env. ValidateConfigStage checks declared vars are PRESENT; it
 *      cannot know about an env() call for a var nobody declared. That one boots
 *      fine and returns null in production.
 *   3. Exposed but unbound. A contract in exposes[] that register() never binds
 *      resolves to an autowire attempt or a not-found, at request time.
 *   4. Access-rule violations. Enforced at RUNTIME by the container, so an
 *      unexercised path stays wrong until a user finds it.
 *   5. Handler targets. Verified at boot only when ROUTE_VERIFY_HANDLERS=true,
 *      which is off in production for good reason.
 *
 * Import analysis is textual — top-level `use` statements only. It therefore
 * sees a violation that is imported and misses one written as a fully-qualified
 * inline reference. That is a real limit, stated here rather than implied: this
 * catches drift, it does not prove conformance.
 */
final class Inspector
{
    /** @var list<Finding> */
    private array $findings = [];

    public function __construct(
        private readonly PluginManifest $manifest,
        /**
         * Every OTHER installed plugin, keyed by the domain it solves.
         *
         * Optional, and empty is a supported mode — the inspector's job is to
         * read ONE plugin, and it must still work when nothing else is
         * installed. What the neighbours buy is the difference between "no
         * source file reads this variable" and "this variable is read by the
         * plugin you declared a dependency on", which are opposite conclusions
         * drawn from the same fact.
         *
         * @var array<string, PluginManifest>
         */
        private readonly array $neighbours = [],
    ) {}

    /**
     * @param class-string $providerClass
     */
    public static function forProvider(string $providerClass, array $neighbours = []): self
    {
        return new self(PluginManifest::of($providerClass), $neighbours);
    }

    /** @return list<Finding> */
    public function run(): array
    {
        $this->findings = [];

        $this->checkManifestShape();
        $this->checkProviderAgreement();
        $this->checkExposedContracts();
        $this->checkRouteHandlers();
        $this->checkConfigDeclarations();
        $this->checkAccessRules();

        // The UI half. Silent for a plugin with no ui/ directory, which is most
        // of them — see UiInspector.
        return [...$this->findings, ...UiInspector::for($this->manifest)->run()];
    }

    // ── 1. Manifest shape ─────────────────────────────────────────────────────

    private function checkManifestShape(): void
    {
        if ($this->manifest->solves() === '') {
            $this->add(Finding::error(
                'manifest.no-solves',
                'module.json declares no "solves" domain.',
                'Every module owns exactly one domain. Without it nothing can require this plugin.',
                $this->manifest->path,
            ));
        }

        if (($this->manifest->data['name'] ?? '') === '') {
            $this->add(Finding::error(
                'manifest.no-name',
                'module.json declares no "name".',
                'The name is what `hkm plugins enable <name>` and every diagnostic prints.',
                $this->manifest->path,
            ));
        }

        foreach ($this->manifest->requires() as $domain) {
            // A class-string here is the single most common manifest mistake and
            // fails the boot with a message about an unknown domain, which reads
            // like the plugin is missing rather than like the entry is wrong.
            if (str_contains($domain, '\\')) {
                $this->add(Finding::error(
                    'manifest.requires-class',
                    "requires[] contains a CLASS name: [{$domain}].",
                    'requires[] lists module DOMAINS (a plugin\'s solves value). '
                    . 'Ports resolve from the CoreContainer and are never listed.',
                    $this->manifest->path,
                ));
            }
        }
    }

    // ── 2. Provider ↔ manifest agreement ──────────────────────────────────────

    private function checkProviderAgreement(): void
    {
        $class = $this->manifest->providerClass;

        if (!class_exists($class)) {
            $this->add(Finding::error(
                'provider.missing',
                "Provider class [{$class}] does not exist or is not autoloadable.",
                'Check the composer PSR-4 prefix for this plugin.',
            ));

            return;
        }

        if (!is_subclass_of($class, ModuleContract::class)) {
            $this->add(Finding::error(
                'provider.contract',
                "[{$class}] does not implement ModuleContract.",
            ));

            return;
        }

        try {
            /** @var ModuleContract $provider */
            $provider = new $class();
        } catch (\Throwable $e) {
            // The kernel does `new $moduleClass()` with no arguments, so a
            // constructor that needs any is a boot failure waiting to happen.
            $this->add(Finding::error(
                'provider.construct',
                "[{$class}] could not be constructed with no arguments: {$e->getMessage()}",
                'The kernel instantiates providers as `new $class()` — they must take none.',
            ));

            return;
        }

        if ($provider->solves() !== $this->manifest->solves()) {
            $this->add(Finding::error(
                'manifest.solves-drift',
                sprintf(
                    'Provider::solves() returns [%s]; module.json says [%s].',
                    $provider->solves(),
                    $this->manifest->solves(),
                ),
                'The kernel reads module.json and never calls solves(), so the manifest wins '
                . 'silently. Anything trusting the method is reading a different answer.',
                $this->manifest->path,
            ));
        }

        $this->compareLists('requires', $provider->requires(), $this->manifest->requires());
        $this->compareLists('exposes', $provider->exposes(), $this->manifest->exposes());
    }

    /**
     * @param list<string> $fromProvider
     * @param list<string> $fromManifest
     */
    private function compareLists(string $key, array $fromProvider, array $fromManifest): void
    {
        $onlyProvider = array_values(array_diff($fromProvider, $fromManifest));
        $onlyManifest = array_values(array_diff($fromManifest, $fromProvider));

        if ($onlyProvider === [] && $onlyManifest === []) {
            return;
        }

        $detail = [];
        if ($onlyProvider !== []) {
            $detail[] = 'only in Provider::' . $key . '(): ' . implode(', ', $onlyProvider);
        }
        if ($onlyManifest !== []) {
            $detail[] = 'only in module.json: ' . implode(', ', $onlyManifest);
        }

        $this->add(Finding::warning(
            "manifest.{$key}-drift",
            "Provider::{$key}() and module.json \"{$key}\" disagree — " . implode('; ', $detail),
            'module.json is the source of truth the kernel reads; the method is documentation '
            . 'and must mirror it.',
            $this->manifest->path,
        ));
    }

    // ── 3. Exposed contracts are real, and bound ──────────────────────────────

    private function checkExposedContracts(): void
    {
        $registerBody = $this->methodBody($this->manifest->providerClass, 'register');

        foreach ($this->manifest->exposes() as $contract) {
            // A short name in exposes[] is legal for humans but useless to a
            // consumer, which resolves by fully-qualified class-string.
            if (!str_contains($contract, '\\')) {
                $this->add(Finding::warning(
                    'exposes.short-name',
                    "exposes[] entry [{$contract}] is not a fully-qualified class name.",
                    'Consumers resolve by class-string; a bare name cannot be resolved.',
                    $this->manifest->path,
                ));

                continue;
            }

            if (!interface_exists($contract) && !class_exists($contract)) {
                $this->add(Finding::error(
                    'exposes.missing',
                    "exposes[] names [{$contract}], which does not exist.",
                    '',
                    $this->manifest->path,
                ));

                continue;
            }

            if ($registerBody === null) {
                continue;
            }

            // Match on the short name: register() nearly always writes
            // `Foo::class` against an imported alias, not the FQN.
            $short = substr(strrchr($contract, '\\') ?: $contract, 1);
            if (!str_contains($registerBody, $short)) {
                $this->add(Finding::warning(
                    'exposes.unbound',
                    "exposes[] names [{$short}] but Provider::register() does not appear to bind it.",
                    'An exposed contract that is never bound fails at request time, in the '
                    . 'consuming module, which is a long way from here.',
                    $this->manifest->path,
                ));
            }
        }
    }

    // ── 4. Route handlers exist ───────────────────────────────────────────────

    private function checkRouteHandlers(): void
    {
        foreach ($this->allRoutes() as $route) {
            $handler = (string) ($route['handler'] ?? '');
            if ($handler === '' || substr_count($handler, '@') !== 1) {
                // Shape is a boot failure already — not this checker's job.
                continue;
            }

            [$class, $method] = explode('@', $handler);

            if (!class_exists($class)) {
                $this->add(Finding::error(
                    'route.handler-class',
                    "Route handler class [{$class}] does not exist or is not autoloadable.",
                    'Plugin handlers need the FULL class path in module.json, including the '
                    . 'Plugins\\ prefix.',
                    $this->manifest->path,
                ));

                continue;
            }

            if (!method_exists($class, $method)) {
                $this->add(Finding::error(
                    'route.handler-method',
                    "Route handler [{$class}] has no method [{$method}].",
                    '',
                    $this->manifest->path,
                ));

                continue;
            }

            if (!(new \ReflectionMethod($class, $method))->isPublic()) {
                $this->add(Finding::error(
                    'route.handler-visibility',
                    "Route handler [{$class}::{$method}()] is not public.",
                    '',
                    $this->manifest->path,
                ));
            }
        }
    }

    /** Flat routes plus every route nested in groups[], at any depth. */
    private function allRoutes(): array
    {
        $routes = $this->manifest->routes();

        $walk = function (array $groups) use (&$walk, &$routes): void {
            foreach ($groups as $group) {
                foreach ($group['routes'] ?? [] as $route) {
                    if (\is_array($route)) {
                        $routes[] = $route;
                    }
                }
                if (\is_array($group['groups'] ?? null)) {
                    $walk($group['groups']);
                }
            }
        };
        $walk($this->manifest->groups());

        return $routes;
    }

    // ── 5. env() usage vs config[] ────────────────────────────────────────────

    private function checkConfigDeclarations(): void
    {
        $declared = array_column($this->manifest->configVars(), 'key');
        $used     = [];

        foreach ($this->sourceFiles() as $file) {
            $calls = $this->envCalls((string) file_get_contents($file));

            foreach ($calls['env'] as $key => $line) {
                $used[$key][] = $file;
            }

            foreach ($calls['getenv'] as $key => $line) {
                $this->add(Finding::error(
                    'config.getenv',
                    "getenv('{$key}') is used in " . $this->relative($file) . ":{$line}.",
                    'LoadEnvironment does not call putenv(), so getenv() returns nothing for '
                    . 'any .env value. Use env() — it reads $_ENV/$_SERVER first.',
                    $file,
                ));
            }
        }

        foreach ($used as $key => $files) {
            if (\in_array($key, $declared, true) || self::isFrameworkVar($key)) {
                continue;
            }

            $this->add(Finding::error(
                'config.undeclared',
                "env('{$key}') is read but module.json config[] does not declare it.",
                'Undeclared vars are never validated at boot and never seeded into a project '
                . '.env by `hkm plugins enable`, so this reads null in production.',
                $files[0],
            ));
        }

        foreach ($declared as $key) {
            if (isset($used[$key])) {
                continue;
            }

            // Before calling it unread, ask whether a plugin this one DEPENDS ON
            // declares it. `user` declares HASH_BCRYPT_COST and never reads it,
            // because `crypto` — which it requires — does. Reporting that as
            // "no source file reads it" is true of this plugin's files and
            // false about the system, and it sends someone to delete a line
            // that is doing a job.
            $owner = $this->dependencyDeclaring($key);

            if ($owner !== null) {
                $this->add(Finding::note(
                    'config.dependency-var',
                    "config[] declares {$key}, which is read by [{$owner}] — a plugin this one requires.",
                    'Legitimate: re-declaring it means `hkm plugins enable` seeds it into the '
                    . 'project .env even when the operator enables this plugin alone. Drop it only '
                    . 'if you would rather the dependency own it.',
                    $this->manifest->path,
                ));

                continue;
            }

            $this->add(Finding::note(
                'config.unused',
                "config[] declares {$key}, which no source file reads.",
                'Harmless, but a required entry still blocks the boot until someone sets it.',
                $this->manifest->path,
            ));
        }
    }

    /**
     * The name of a plugin this one requires (transitively) that declares $key.
     *
     * Declaration, not usage, is the signal: a plugin that declares a variable
     * in its config[] is the plugin that owns it, and reading its whole source
     * tree to confirm would cost a full scan per key for an answer the manifest
     * already gives.
     */
    private function dependencyDeclaring(string $key): ?string
    {
        if ($this->neighbours === []) {
            return null;
        }

        $seen  = [];
        $queue = [...$this->manifest->requires(), ...$this->manifest->routeRequires()];

        while ($queue !== []) {
            $domain = array_shift($queue);

            if (isset($seen[$domain])) {
                continue;
            }
            $seen[$domain] = true;

            $neighbour = $this->neighbours[$domain] ?? null;
            if ($neighbour === null) {
                continue;
            }

            if (\in_array($key, array_column($neighbour->configVars(), 'key'), true)) {
                return $neighbour->name();
            }

            $queue = [...$queue, ...$neighbour->requires(), ...$neighbour->routeRequires()];
        }

        return null;
    }

    /**
     * env()/getenv() calls with a literal key, found by TOKENISING the file.
     *
     * A regex over the raw text was the obvious implementation and was wrong:
     * it matched this class's own docblock, reporting an undeclared variable
     * that exists only in a sentence. Any plugin documenting `env('FOO')` in a
     * comment would have hit the same false positive — the worst kind, because
     * the fix it suggests is to declare a variable nobody reads.
     *
     * Tokenising also removes two other misreadings for free: `$this->env(...)`
     * (this plugin has such a method) and `function env(` are both excluded by
     * looking at the preceding token.
     *
     * @return array{env: array<string, int>, getenv: array<string, int>} key => first line seen
     */
    private function envCalls(string $source): array
    {
        $tokens = @token_get_all($source);
        $found  = ['env' => [], 'getenv' => []];

        for ($i = 0, $count = \count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || !\in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = strtolower(ltrim($token[1], '\\'));
            if ($name !== 'env' && $name !== 'getenv') {
                continue;
            }

            // A method call or a declaration, not the global helper.
            $previous = $this->meaningful($tokens, $i, -1);
            if (\is_array($previous) && \in_array(
                $previous[0],
                [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION],
                true,
            )) {
                continue;
            }

            $open = $this->meaningfulIndex($tokens, $i, 1);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            $argument = $this->meaningfulIndex($tokens, $open, 1);
            if ($argument === null
                || !\is_array($tokens[$argument])
                || $tokens[$argument][0] !== \T_CONSTANT_ENCAPSED_STRING) {
                // env($dynamic) — a real call, but there is no key to check.
                continue;
            }

            $key = trim($tokens[$argument][1], "'\"");
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) === 1) {
                $found[$name][$key] ??= $token[2];
            }
        }

        return $found;
    }

    /** The next/previous token that is not whitespace or a comment. */
    private function meaningful(array $tokens, int $from, int $step): array|string|null
    {
        $index = $this->meaningfulIndex($tokens, $from, $step);

        return $index === null ? null : $tokens[$index];
    }

    private function meaningfulIndex(array $tokens, int $from, int $step): ?int
    {
        $skip = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];

        for ($i = $from + $step; isset($tokens[$i]); $i += $step) {
            if (\is_array($tokens[$i]) && \in_array($tokens[$i][0], $skip, true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    // ── 6. The five access rules, by import ───────────────────────────────────

    /**
     * Layer rules, keyed by the path fragment that identifies the layer.
     *
     * Each entry lists needles that must NOT appear in that layer's imports.
     * The Domain layer is special-cased below: its rule is "nothing external"
     * rather than a deny-list.
     */
    private const LAYER_RULES = [
        'Infrastructure/Persistence' => [
            'HttpClientPort'   => 'A repository talks to DatabasePort only — outbound HTTP belongs to a Gateway.',
            'Kernel\\Http\\Request'  => 'A repository never sees the Request. Pass the values it needs.',
            'Kernel\\Http\\Response' => 'A repository never builds a Response.',
        ],
        'Infrastructure/Gateways' => [
            'DatabasePort'     => 'A gateway talks to its vendor SDK only — persistence belongs to a Repository.',
            'TransactionManager' => 'A gateway is outside the transaction boundary.',
        ],
        // Controllers ONLY. `Infrastructure/Http/Stages/` is a different thing:
        // a pipeline stage legitimately touches ports — Tenancy's
        // TenantContextStage exists to REBIND DatabasePort per request. Matching
        // the whole Infrastructure/Http tree reported that as a violation.
        'Infrastructure/Http/Controllers' => [
            'DatabasePort'     => 'A controller reaches the database only through a service contract.',
            'Repository'       => 'A controller calls a published service contract, never a repository.',
        ],
        'Application/Services' => [
            'Kernel\\Http\\Request'  => 'A service takes a DTO, not a Request — that coupling makes it untestable and unusable from a job.',
            'Kernel\\Http\\Response' => 'A service returns a DTO; translating it to HTTP is the controller\'s three lines.',
        ],
    ];

    private function checkAccessRules(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $relative = $this->relative($file);
            $imports  = $this->imports($file);

            if (str_contains($relative, 'Domain/')) {
                $this->checkDomainPurity($file, $relative, $imports);

                continue;
            }

            foreach (self::LAYER_RULES as $fragment => $rules) {
                if (!str_contains($relative, $fragment)) {
                    continue;
                }

                foreach ($rules as $needle => $why) {
                    foreach ($imports as $import) {
                        if (str_contains($import, $needle)) {
                            $this->add(Finding::error(
                                'access.' . strtolower(str_replace(['/', '\\'], '-', $fragment)),
                                "{$relative} imports [{$import}].",
                                $why,
                                $file,
                            ));
                        }
                    }
                }
            }
        }
    }

    /**
     * The Domain layer imports NOTHING outside Domain/ — the one rule stated as
     * an allow-list, because the whole point is that the list is empty.
     *
     * @param list<string> $imports
     */
    private function checkDomainPurity(string $file, string $relative, array $imports): void
    {
        foreach ($imports as $import) {
            // Its own Domain namespace is fine. So are PHP's built-ins, which
            // live at the root and are part of the language, not a dependency.
            if (str_contains($import, '\\Domain\\') || !str_contains(ltrim($import, '\\'), '\\')) {
                continue;
            }

            if (self::allowedInDomain($import)) {
                continue;
            }

            $this->add(Finding::error(
                'access.domain',
                "{$relative} imports [{$import}].",
                'The Domain layer has zero imports outside Domain/. Anything it needs from '
                . 'outside is a value passed in, not a dependency reached for.',
                $file,
            ));
        }
    }

    /**
     * Imports the Domain layer may make despite the "nothing external" rule.
     *
     * Every entry is sanctioned by CLAUDE.md itself, and each was a FALSE
     * POSITIVE against real first-party plugins before it was listed here —
     * together they were 23 of 63 reported errors. A checker that is wrong about
     * a third of what it reports teaches people to ignore all of it, which costs
     * more than the check is worth.
     *
     *   Project\Support\*        "DI-free … no I/O, read no globals, import
     *                            nothing outside their own namespace — SAFE TO
     *                            USE FROM A PLUGIN'S Domain/ layer". 20 hits.
     *   DomainEventContract      a Domain event has to implement it; the
     *   IntegrationEventContract  framework's own example does exactly this.
     *
     * Kernel EXCEPTIONS are deliberately NOT here. Importing SecurityException
     * into Domain/ couples the domain to an HTTP status mapping, and the stated
     * rule is that a domain throws \DomainException.
     */
    private const DOMAIN_ALLOWED = [
        'Project\\Support\\',
        'Kernel\\Events\\Contracts\\DomainEventContract',
        'Kernel\\Events\\Contracts\\IntegrationEventContract',
    ];

    /**
     * Variables the PROJECT owns, which a plugin may read without declaring.
     *
     * `APP_KEY` is the clearest case: every plugin that signs anything reads it,
     * no plugin should claim it in `config[]`, and `hkm plugins enable` must not
     * offer to write it per-plugin. Requiring a declaration here would be
     * demanding the wrong fix.
     *
     * Deliberately narrow — an unrecognised `SOMETHING_KEY` is still reported,
     * because that is a plugin's own configuration and undeclared means
     * unvalidated at boot and unseeded into any project's .env.
     */
    private static function isFrameworkVar(string $key): bool
    {
        return str_starts_with($key, 'APP_')
            || \in_array($key, ['ENV_CACHE', 'BOOT_CACHE', 'VIEW_PATHS', 'VIEW_EXTENSIONS'], true)
            || str_starts_with($key, 'ROUTE_');
    }

    private static function allowedInDomain(string $import): bool
    {
        foreach (self::DOMAIN_ALLOWED as $allowed) {
            if (str_contains($import, $allowed)) {
                return true;
            }
        }

        return false;
    }

    // ── Source access ─────────────────────────────────────────────────────────

    /** @return list<string> every .php file in the plugin, excluding tests and vendor */
    private function sourceFiles(): array
    {
        $dir = $this->manifest->directory();
        if (!is_dir($dir)) {
            return [];
        }

        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('#/(vendor|tests|node_modules|database/migrations|database/seeders)/#', $path) === 1) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * Top-level `use` imports of one file.
     *
     * Tokenised, not matched. A `^use` regex looked equivalent and was not: it
     * missed every import in a file whose `use` is not at column 0, and could
     * not tell an import from a trait `use` or a closure's `use (...)` by
     * anything better than indentation.
     *
     * Depth 0 is the discriminator — imports live at the top level of a file,
     * trait uses inside a class body. A `namespace X { }` BLOCK would put its
     * imports at depth 1 and they would be skipped; that form appears nowhere in
     * this framework, and treating it as an import site would misread every
     * trait use in a braced namespace.
     *
     * A violation written as an inline fully-qualified reference is still not
     * seen — see the class docblock.
     *
     * @return list<string>
     */
    private function imports(string $file): array
    {
        $tokens  = @token_get_all((string) file_get_contents($file));
        $clauses = [];
        $depth   = 0;

        for ($i = 0, $count = \count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '{') {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth--;
                continue;
            }
            if (!\is_array($token) || $token[0] !== \T_USE || $depth !== 0) {
                continue;
            }

            // `use (` after a closure signature — not an import.
            $next = $this->meaningfulIndex($tokens, $i, 1);
            if ($next === null || $tokens[$next] === '(') {
                continue;
            }

            // Collect the raw clause up to its ';', keeping any group braces so
            // the parser below can expand them. Group braces are consumed here
            // rather than counted as depth.
            $clause = '';
            for ($j = $next; $j < $count && $tokens[$j] !== ';'; $j++) {
                $clause .= \is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            }
            $i = $j;

            $clauses[] = preg_replace('/^(function|const)\s+/i', '', trim($clause)) ?? $clause;
        }

        $imports = [];
        foreach ($clauses as $clause) {
            $clause = trim($clause);

            // Grouped imports: Kernel\Ports\{DatabasePort, CachePort}
            if (preg_match('/^(.*?)\\\\\{(.+)\}$/s', $clause, $group) === 1) {
                foreach (explode(',', $group[2]) as $member) {
                    $member = trim($member);
                    if ($member !== '') {
                        $imports[] = $group[1] . '\\' . preg_split('/\s+as\s+/i', $member)[0];
                    }
                }

                continue;
            }

            $imports[] = trim(preg_split('/\s+as\s+/i', $clause)[0]);
        }

        return $imports;
    }

    private function methodBody(string $class, string $method): ?string
    {
        if (!class_exists($class) || !method_exists($class, $method)) {
            return null;
        }

        $reflection = new \ReflectionMethod($class, $method);
        $file       = $reflection->getFileName();
        $start      = $reflection->getStartLine();
        $end        = $reflection->getEndLine();

        if ($file === false || $start === false || $end === false) {
            return null;
        }

        $lines = file($file);
        if ($lines === false) {
            return null;
        }

        return implode('', \array_slice($lines, $start - 1, $end - $start + 1));
    }

    private function relative(string $file): string
    {
        $dir = $this->manifest->directory();

        return str_starts_with($file, $dir) ? ltrim(substr($file, \strlen($dir)), '/') : $file;
    }

    private function add(Finding $finding): void
    {
        $this->findings[] = $finding;
    }
}
