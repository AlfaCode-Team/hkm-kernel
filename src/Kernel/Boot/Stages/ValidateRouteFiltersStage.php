<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader, ManifestWriter};

/**
 * Validates route-filter ownership, and records who provides what.
 *
 * THE GAP THIS CLOSES
 * -------------------
 * A route opts into a filter by naming an alias — `"filters": ["auth"]`. The
 * alias is published at runtime by whichever plugin calls `$http->filter()` in
 * its `Provider::boot()`. Nothing connects the two: `requires[]` declares module
 * DOMAINS for dependency-graph purposes and has no way to say "and I need the
 * `auth` alias to exist".
 *
 * So a plugin whose routes use `auth` has an undeclarable dependency on
 * whichever plugin publishes it. Remove that plugin from `withModules()` and the
 * pipeline fails at build naming the ALIAS — which is the symptom — while saying
 * nothing about which plugin was supposed to provide it. The person reading the
 * error has to already know the answer.
 *
 * TWO NEW DECLARATIONS
 * --------------------
 *   "providesFilters": ["auth", "throttle"]   // I publish these aliases
 *   "requiresFilters": ["auth"]               // my routes need these to exist
 *
 * This stage checks that everything required is provided, and fails the BOOT
 * with the provider's own domain rather than leaving it to be discovered on the
 * first request to an affected route.
 *
 * WHY IT CANNOT SIMPLY READ THE REGISTERED ALIASES
 * ------------------------------------------------
 * Because they do not exist yet. `build()` compiles manifests; `materialize()`
 * runs `Provider::boot()` and only then does any alias get registered. A boot
 * stage therefore cannot ask the FilterRegistry what it holds — which is exactly
 * why the existing runtime guard lives in HttpPipeline. Static declarations are
 * the only thing available this early, and they are also the thing that lets the
 * error name a plugin.
 *
 * WHY IT IS OPT-IN
 * ----------------
 * A module that declares neither key is not checked. Sixty-odd existing plugins
 * publish and consume aliases today without declaring anything, and failing
 * their boots to introduce a declaration would be the platform breaking working
 * applications to enforce its own paperwork. Declaring `requiresFilters` is how
 * a plugin opts into the guarantee; until it does, the runtime check in
 * HttpPipeline remains the backstop.
 *
 * Output (filter-manifest.php) — consumed by HttpPipeline to enrich its own
 * error message, and by tooling that wants to know which plugin owns an alias:
 *
 *   [ 'provides' => [ 'auth' => 'http.security_filters', ... ],
 *     'requires' => [ 'auth.identity' => ['auth', 'throttle'], ... ] ]
 */
final class ValidateRouteFiltersStage implements BootStageContract
{
    /** @param list<class-string> $moduleClasses */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {}

    public function run(): void
    {
        /** @var array<string, string> alias => the solves domain that provides it */
        $provides = [];
        /** @var array<string, class-string> alias => the module class that provides it */
        $providedBy = [];
        /** @var array<string, list<string>> solves domain => required aliases */
        $requires = [];

        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $this->reader->read($moduleClass);
            $solves = \is_string($manifest['solves'] ?? null) ? $manifest['solves'] : $moduleClass;

            foreach ($this->aliasList($manifest, 'providesFilters', $moduleClass) as $alias) {
                // Same rule the FilterRegistry enforces at runtime, moved to boot:
                // two plugins claiming one alias is ambiguous, and whichever
                // happened to boot last would silently win.
                if (isset($providedBy[$alias]) && $providedBy[$alias] !== $moduleClass) {
                    throw new BootException(
                        "Route filter alias [{$alias}] is declared by two modules: "
                        . "[{$providedBy[$alias]}] and [{$moduleClass}]. An alias has exactly one owner — "
                        . 'rename one of them, or remove the duplicate declaration.'
                    );
                }

                $provides[$alias] = $solves;
                $providedBy[$alias] = $moduleClass;
            }
        }

        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $this->reader->read($moduleClass);
            $solves = \is_string($manifest['solves'] ?? null) ? $manifest['solves'] : $moduleClass;
            $needed = $this->aliasList($manifest, 'requiresFilters', $moduleClass);

            if ($needed === []) {
                continue;
            }

            $requires[$solves] = $needed;

            foreach ($needed as $alias) {
                if (isset($provides[$alias])) {
                    continue;
                }

                throw new BootException(
                    "[{$moduleClass}] declares requiresFilters [{$alias}], which no registered module provides.\n"
                    . 'Declared providers: ' . ($provides === []
                        ? '(none — no module declares providesFilters)'
                        : $this->describe($provides)) . ".\n"
                    . "Add the plugin that publishes [{$alias}] to withModules(), or declare it in that "
                    . "plugin's module.json \"providesFilters\"."
                );
            }
        }

        ManifestWriter::write('filter-manifest.php', [
            'provides' => $provides,
            'requires' => $requires,
        ]);
    }

    /**
     * Read and validate one alias list from a module manifest.
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private function aliasList(array $manifest, string $key, string $moduleClass): array
    {
        $declared = $manifest[$key] ?? null;

        if ($declared === null) {
            return [];
        }

        if (!\is_array($declared) || !array_is_list($declared)) {
            throw new BootException(
                "[{$moduleClass}] declares \"{$key}\" as " . get_debug_type($declared)
                . ' — it must be a list of alias strings.'
            );
        }

        $aliases = [];

        foreach ($declared as $alias) {
            if (!\is_string($alias) || trim($alias) === '') {
                throw new BootException(
                    "[{$moduleClass}] declares a non-string or empty entry in \"{$key}\". "
                    . 'Every entry must be an alias name.'
                );
            }

            $alias = trim($alias);

            // A route writes `throttle:60,1`; the part before the colon is the
            // alias and the rest is arguments. Declaring the arguments here would
            // never match, so it is rejected rather than silently ignored.
            if (str_contains($alias, ':')) {
                throw new BootException(
                    "[{$moduleClass}] declares \"{$key}\" entry [{$alias}] with arguments. "
                    . 'Declare the bare alias only — arguments belong on the route, e.g. '
                    . '"filters": ["throttle:60,1"] against "requiresFilters": ["throttle"].'
                );
            }

            if (!in_array($alias, $aliases, true)) {
                $aliases[] = $alias;
            }
        }

        return $aliases;
    }

    /** @param array<string, string> $provides */
    private function describe(array $provides): string
    {
        $parts = [];

        foreach ($provides as $alias => $solves) {
            $parts[] = "{$alias} (by {$solves})";
        }

        sort($parts);

        return implode(', ', $parts);
    }
}
