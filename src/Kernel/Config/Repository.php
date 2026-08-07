<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Config;

/**
 * Repository — the kernel's configuration reader.
 *
 * Configuration is compiled at boot by {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileConfigManifestStage}
 * into ONE array, then handed to this object. Reads use dotted paths:
 *
 *     $config->get('mail.from.address', 'noreply@example.test');
 *     $config->get('database.connections.mysql.host');
 *
 * DELIBERATELY IMMUTABLE
 * ----------------------
 * There is no set(). HKM 0.3's config repository was mutable and globally
 * reachable, which meant any request could reconfigure the process — invisible
 * coupling under PHP-FPM, and genuinely unsafe under OpenSwoole where one
 * coroutine's write is every other coroutine's surprise. Configuration is
 * decided at boot and read thereafter.
 *
 * If something must change per request, it is request state, not configuration:
 * put it on the Request or in the ModuleContainer.
 *
 * PRECEDENCE
 * ----------
 * Compiled project-over-plugin, the same rule routes and views already follow.
 * A project's `config/mail.php` overrides a plugin's `config/mail.php` key by
 * key (deep merge), so a project overrides only what it names and inherits the
 * rest. See the compile stage for the merge semantics.
 */
final class Repository
{
    /** Memoised dotted lookups — the same keys are read repeatedly per request. */
    private array $resolved = [];

    /** @param array<string, mixed> $items the compiled config manifest */
    public function __construct(private readonly array $items = []) {}

    /**
     * Read a dotted path. Returns $default when any segment is missing.
     *
     * A null STORED value is a real value and is returned as null — it does not
     * fall through to $default. Use has() to distinguish "absent" from "null".
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $miss  = false;
        $value = $this->lookup($key, $miss);

        if ($miss) {
            return $default; // NOT memoised: a later call may pass a different default
        }

        return $this->resolved[$key] = $value;
    }

    /** True when the dotted path exists, even if its value is null. */
    public function has(string $key): bool
    {
        $miss = false;
        $this->lookup($key, $miss);

        return !$miss;
    }

    /**
     * A whole config group as an array, e.g. all of `mail`.
     *
     * @return array<string, mixed>
     */
    public function group(string $name): array
    {
        $value = $this->get($name, []);

        return is_array($value) ? $value : [];
    }

    /** Every compiled config key, for `config:show` and debugging. */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Walk the dotted path. $miss is set by reference so a stored null is
     * distinguishable from an absent key.
     */
    private function lookup(string $key, bool &$miss): mixed
    {
        $miss = false;

        if (array_key_exists($key, $this->items)) {
            return $this->items[$key]; // exact hit — no split needed
        }

        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $miss = true;

                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
