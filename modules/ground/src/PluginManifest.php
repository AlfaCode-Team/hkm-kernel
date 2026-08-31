<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground;

/**
 * A plugin's module.json, located the way the kernel locates it.
 *
 * The path comes from reflecting the Provider class and reading module.json
 * beside it — exactly what {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader}
 * does. Deriving it any other way (a plugins/ glob, a naming convention) would
 * let the harness read a different file from the one the kernel boots, and the
 * two would disagree precisely when it mattered.
 *
 * The kernel's reader is not reused directly because this needs the file PATH
 * and the normalised config[] shape as well as the decoded array.
 */
final class PluginManifest
{
    /** @var array<string, self> keyed by provider class */
    private static array $cache = [];

    private function __construct(
        public readonly string $providerClass,
        public readonly string $path,
        /** @var array<string, mixed> */
        public readonly array $data,
    ) {}

    /** @param class-string $providerClass */
    public static function of(string $providerClass): self
    {
        if (isset(self::$cache[$providerClass])) {
            return self::$cache[$providerClass];
        }

        if (!class_exists($providerClass)) {
            throw new \InvalidArgumentException("Ground: provider class [{$providerClass}] does not exist.");
        }

        $file = (new \ReflectionClass($providerClass))->getFileName();
        if ($file === false) {
            throw new \InvalidArgumentException("Ground: cannot locate the source file for [{$providerClass}].");
        }

        return self::$cache[$providerClass] = self::fromPath($providerClass, \dirname($file) . '/module.json');
    }

    /** Read a manifest from an explicit path — used by the inspector, which scans directories. */
    public static function fromPath(string $providerClass, string $path): self
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Ground: module.json not found at {$path}.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException("Ground: module.json at {$path} is not valid JSON.");
        }

        return new self($providerClass, $path, $decoded);
    }

    public function directory(): string
    {
        return \dirname($this->path);
    }

    public function name(): string
    {
        $name = $this->data['name'] ?? null;

        return \is_string($name) && $name !== '' ? $name : 'plugin';
    }

    public function solves(): string
    {
        return (string) ($this->data['solves'] ?? '');
    }

    /** @return list<string> */
    public function requires(): array
    {
        return array_values(array_map('strval', (array) ($this->data['requires'] ?? [])));
    }

    /** @return list<string> */
    public function exposes(): array
    {
        return array_values(array_map('strval', (array) ($this->data['exposes'] ?? [])));
    }

    /** @return list<string> */
    public function emits(): array
    {
        return array_values(array_map('strval', (array) ($this->data['emits'] ?? [])));
    }

    /** @return list<array<string, mixed>> flat routes[] only — groups[] are expanded by the compiler */
    public function routes(): array
    {
        $routes = $this->data['routes'] ?? [];

        return \is_array($routes) ? array_values(array_filter($routes, 'is_array')) : [];
    }

    /** @return list<array<string, mixed>> */
    public function groups(): array
    {
        $groups = $this->data['groups'] ?? [];

        return \is_array($groups) ? array_values(array_filter($groups, 'is_array')) : [];
    }

    /**
     * Every route in the manifest, including those nested in groups[] at any depth.
     *
     * @return list<array<string, mixed>>
     */
    public function allRoutes(): array
    {
        $routes = $this->routes();

        $walk = static function (array $groups) use (&$walk, &$routes): void {
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
        $walk($this->groups());

        return $routes;
    }

    /**
     * Module domains required by INDIVIDUAL routes or groups, rather than by the
     * module as a whole.
     *
     * These are the per-route opt-in (`"requires": ["view.rendering"]`) and are
     * easy to overlook, because they do NOT appear in the module-level
     * `requires[]`. They are still hard boot dependencies: the route compiler
     * validates every one against the registered modules and FAILS the boot on
     * an unknown domain. Auth's `GET /auth/login` requires `http.pageflow` this
     * way, so Auth cannot boot in a project without the Pageflow plugin —
     * a constraint its module-level requires[] does not state.
     *
     * @return list<string>
     */
    /**
     * Module domains this plugin's SCHEMA needs, which its code does not.
     *
     * Settings creates `tenant_settings_*` with a foreign key onto `tenants` —
     * a table the Tenancy plugin owns — while its services never call anything
     * of Tenancy's. Putting `tenancy.routing` in `requires[]` to make the
     * migrations run would make EVERY request load Tenancy, which is a runtime
     * coupling that does not exist and a cost paid on every page.
     *
     * So the schema dependency is declared separately, and read only here:
     *
     *     "migrationRequires": ["tenancy.routing"]
     *
     * `ground migrate` seeds the chain with it; nothing else in the kernel
     * looks at the key, so it cannot affect what a request loads.
     *
     * @return list<string>
     */
    public function migrationRequires(): array
    {
        return array_values(array_map('strval', (array) ($this->data['migrationRequires'] ?? [])));
    }

    public function routeRequires(): array
    {
        $domains = [];

        foreach ($this->allRoutes() as $route) {
            foreach ((array) ($route['requires'] ?? []) as $domain) {
                $domains[(string) $domain] = true;
            }
        }

        $walk = static function (array $groups) use (&$walk, &$domains): void {
            foreach ($groups as $group) {
                foreach ((array) ($group['requires'] ?? []) as $domain) {
                    $domains[(string) $domain] = true;
                }
                if (\is_array($group['groups'] ?? null)) {
                    $walk($group['groups']);
                }
            }
        };
        $walk($this->groups());

        // The module-wide default, which applies to every route in the file.
        foreach ((array) ($this->data['routeRequires'] ?? []) as $domain) {
            $domains[(string) $domain] = true;
        }

        return array_keys($domains);
    }

    /**
     * config[] normalised to one shape, whatever form it was declared in.
     *
     * Three declarations are legal and ValidateConfigStage accepts all of them:
     * a bare "VAR" string, an object at a numeric index, and an object keyed by
     * the var name. Normalising once here means every caller reads the same
     * structure instead of re-deriving it (and getting the map form wrong).
     *
     * @return list<array{key: string, type: string, required: bool, default: ?string}>
     */
    public function configVars(): array
    {
        $out = [];

        foreach ((array) ($this->data['config'] ?? []) as $key => $spec) {
            if (\is_array($spec)) {
                $name = (string) ($spec['key'] ?? $key);
                $out[] = [
                    'key'      => $name,
                    'type'     => (string) ($spec['type'] ?? 'string'),
                    'required' => (bool) ($spec['required'] ?? true),
                    'default'  => self::renderDefault($spec['default'] ?? null),
                ];
                continue;
            }

            $out[] = [
                'key'      => \is_int($key) ? (string) $spec : (string) $key,
                'type'     => 'string',
                'required' => true,
                'default'  => null,
            ];
        }

        return $out;
    }

    /**
     * A JSON default rendered as an env value.
     *
     * An explicit null is NOT a default — it says "no value", which is the state
     * an absent key already expresses. Arrays and objects have no dotenv
     * representation, so they are treated the same way.
     */
    private static function renderDefault(mixed $value): ?string
    {
        return match (true) {
            $value === null       => null,
            \is_bool($value)      => $value ? 'true' : 'false',
            \is_scalar($value)    => (string) $value,
            default               => null,
        };
    }

    /** @internal test seam — the per-process cache would otherwise outlive a fixture */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
