<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileRouteManifestStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\KernelException;

/**
 * Plugins — is a module actually installed in THIS application?
 *
 * Answered from the compiled `service-manifest.php`, whose keys are the
 * `solves` domains of every module the project passed to `withModules([...])`.
 * That manifest is the kernel's own record of what got registered, so this
 * needs no knowledge of any particular plugin and no directory scan.
 *
 * WHEN TO USE WHICH GUARD
 * -----------------------
 * A module that CANNOT work without another one declares it in its own
 * `module.json` `requires[]`. That is the better guard, and it is the one to
 * reach for first: `CompileServiceManifestStage` fails the BOOT with the list
 * of registered domains, so the operator learns at deploy time rather than when
 * a request happens to touch the feature.
 *
 * This class is for the cases `requires[]` cannot cover:
 *
 *  - an OPTIONAL integration — light up a feature when the plugin is there,
 *    degrade quietly when it is not;
 *  - code running OUTSIDE the module graph — a standalone CLI entry point, a
 *    bootstrap file, a template — which has no `module.json` to declare
 *    anything in;
 *  - turning an unbound-contract failure deep inside a request into one
 *    sentence at the top of the call, via {@see ensure()}.
 *
 * A domain absent here means the plugin was never registered. It says nothing
 * about whether that plugin's OWN dependencies bound successfully.
 */
final class Plugins
{
    /** @var array<string, string>|null domain => module name, memoized per process */
    private static ?array $domains = null;

    /**
     * Every installed module, `solves` domain => `name` from its module.json.
     *
     * The synthetic `__project__` scope is not a module and is left out.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$domains !== null) {
            return self::$domains;
        }

        $manifest = ManifestReader::readCompiled('service-manifest.php');
        $domains  = [];

        /** @var array<string, array<string, mixed>> $services */
        $services = is_array($manifest['services'] ?? null) ? $manifest['services'] : [];

        foreach ($services as $domain => $entry) {
            if ($domain === CompileRouteManifestStage::PROJECT_SCOPE) {
                continue;
            }
            $domains[(string) $domain] = (string) ($entry['name'] ?? $domain);
        }

        return self::$domains = $domains;
    }

    /**
     * Is this module installed? Accepts EITHER its `solves` domain
     * ('tenancy.routing') or its module.json `name` ('tenancy').
     *
     * The domain is the precise identifier and is matched first — a name is a
     * convenience for call sites that read better with it, and two modules may
     * legitimately share neither.
     */
    public static function installed(string $domainOrName): bool
    {
        $all = self::all();

        return isset($all[$domainOrName]) || in_array($domainOrName, $all, true);
    }

    /**
     * Which of these are NOT installed, in the order given.
     *
     * @return list<string>
     */
    public static function missing(string ...$domainsOrNames): array
    {
        return array_values(array_filter(
            $domainsOrNames,
            static fn (string $d): bool => !self::installed($d),
        ));
    }

    /**
     * Assert every one of these is installed, or throw naming the ones that are
     * not — and what IS registered, because the usual cause is a domain spelled
     * differently from the plugin's `solves`, not a plugin that is really absent.
     *
     * @throws KernelException
     */
    public static function ensure(string ...$domainsOrNames): void
    {
        $missing = self::missing(...$domainsOrNames);
        if ($missing === []) {
            return;
        }

        $registered = array_keys(self::all());

        throw new KernelException(
            'Required plugin(s) are not installed: ' . implode(', ', $missing)
            . '. Add the plugin that solves each domain to withModules([...]) in the project bootstrap.'
            . ' Registered domains: ' . ($registered === [] ? '(none)' : implode(', ', $registered)),
            layer: 'kernel.plugins',
            context: ['missing' => $missing, 'registered' => $registered],
        );
    }

    /**
     * Drop the memoized manifest. For tests that compile a manifest per case —
     * a long-lived process otherwise answers from the first one it read.
     */
    public static function reset(): void
    {
        self::$domains = null;
    }
}
