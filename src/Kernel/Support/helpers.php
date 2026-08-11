<?php
declare(strict_types=1);

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Config\Repository;
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\UrlGenerator;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use Project\Support\Collection;

/**
 * Global path helpers.
 *
 * Registered via composer.json "autoload.files" so they are always available.
 * Thin wrappers over the Paths registry — no per-request state.
 */

if (!function_exists('base_path')) {
    function base_path(string $append = ''): string
    {
        return Paths::base($append);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $append = ''): string
    {
        return Paths::storage($append);
    }
}

if (!function_exists('var_path')) {
    function var_path(string $append = ''): string
    {
        return Paths::var($append);
    }
}

if (!function_exists('logs_path')) {
    function logs_path(string $append = ''): string
    {
        return Paths::logs($append);
    }
}

if (!function_exists('cache_path')) {
    function cache_path(string $append = ''): string
    {
        return Paths::cache($append);
    }
}

if (!function_exists('userdata_path')) {
    function userdata_path(string $append = ''): string
    {
        return Paths::userdata($append);
    }
}

if (!function_exists('config_path')) {
    function config_path(string $append = ''): string
    {
        return Paths::config($append);
    }
}

if (!function_exists('env')) {
    /**
     * Read an environment value.
     *
     * Canonical accessor for values loaded by {@see \App\Bootstrap\Environment\LoadEnvironment}.
     * Reads from $_ENV / $_SERVER first (where the loader injects values) and falls
     * back to getenv() for genuine OS/process variables. This lets the loader skip
     * the expensive, coroutine-unsafe putenv() call entirely — getenv() is no longer
     * the source of truth, so first-party code must read config through env().
     *
     * Strict drop-in for `getenv($k) ?: $d` — returns the raw string or $default;
     * no boolean/null coercion, to preserve existing call-site semantics.
     *
     * @param string $key
     * @param mixed  $default Returned when the key is absent.
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return ($value === false || $value === null) ? $default : $value;
    }
} 



if (!function_exists('config')) {
    /**
     * Read compiled configuration with a dotted path.
     *
     *     config('mail.from.address', 'noreply@example.test')
     *     config('validation')                 // the whole group
     *
     * Reads config-manifest.php, compiled at boot from every plugin's and the
     * project's `config/*.php` (project deep-merged over plugin). The manifest is
     * loaded once per process and served from OPcache thereafter.
     *
     * READ-ONLY BY DESIGN. There is no config('key', value) setter: configuration
     * is decided at boot, and a mutable global would be shared across coroutines
     * under OpenSwoole. Prefer injecting Kernel\Config\Repository where you can —
     * this helper exists for bootstrap files and templates that have no container.
     *
     * @param string|null $key Dotted path; null returns the Repository itself.
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        /** @var Repository|null $repository */
        static $repository = null;

        $repository ??= new Repository(ManifestReader::readCompiled('config-manifest.php'));

        return $key === null ? $repository : $repository->get($key, $default);
    }
}

if (!function_exists('collect')) {
    /**
     * Create a Collection from the given items.
     *
     * @param iterable<mixed,mixed> $items
     */
    function collect(iterable $items = []): Collection
    {
        return new Collection($items);
    }
}

if (!function_exists('url')) {
    /**
     * The shared URL generator, built from the compiled route-name index.
     *
     * Named routes exist so that a link SURVIVES a project overriding or moving
     * the page it points at — but that only pays off if something actually calls
     * the generator, so it gets a helper like config() does. Built once per
     * process; the base URL comes from APP_URL (leave it empty and every URL is
     * relative, which is what a single-host deployment wants).
     */
    function url(): UrlGenerator
    {
        /** @var UrlGenerator|null $generator */
        static $generator = null;

        return $generator ??= UrlGenerator::fromManifest((string) (env('APP_URL') ?: ''));
    }
}

if (!function_exists('route')) {
    /**
     * The URL for a NAMED route.
     *
     *     route('user.show', ['id' => 7]);            // /users/7
     *     route('user.show', ['id' => 7], true);      // https://app.test/users/7
     *
     * Throws on an unknown name or a value its placeholder type forbids — a
     * broken link fails at the call site instead of 404ing in production.
     *
     * @param array<string, string|int|float|bool> $parameters
     */
    function route(string $name, array $parameters = [], bool $absolute = false): string
    {
        return url()->route($name, $parameters, $absolute);
    }
}

if (!function_exists('signed_route')) {
    /**
     * A tamper-proof URL for a named route (email verification, one-time actions).
     *
     * @param array<string, string|int|float|bool> $parameters
     * @param int|null $expiresIn seconds from now; null = no expiry
     */
    function signed_route(
        string $name,
        array $parameters = [],
        ?int $expiresIn = null,
        bool $absolute = false,
    ): string {
        return url()->signedRoute($name, $parameters, $expiresIn, $absolute);
    }
}