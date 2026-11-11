<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground;

/**
 * Environment variables the PROJECT owns, which a plugin may read or declare
 * without either being a statement about the plugin.
 *
 * `APP_KEY` is the clearest case: every plugin that signs anything reads it, no
 * plugin should claim it, and `hkm plugins enable` must not offer to write it
 * per-plugin. Several first-party manifests list it in `config[]` anyway, which
 * is harmless — `ValidateConfigStage` only checks presence.
 *
 * Two tools need the same answer and must not disagree about it:
 *
 *   - the {@see Inspection\Inspector}, which would otherwise demand the plugin
 *     declare a variable that is not its to declare;
 *   - the {@see Contract\ContractSurface}, which would otherwise LOCK it — and
 *     then report a plugin tidying `APP_KEY` out of its own `config[]` as a
 *     removed contract, which is the opposite of true. It was doing exactly
 *     that on the first real plugin it was run against.
 *
 * Deliberately narrow. An unrecognised `SOMETHING_KEY` is still the plugin's
 * own configuration, and undeclared means unvalidated at boot and unseeded into
 * any project's `.env`.
 */
final class FrameworkVars
{
    /** Exact names that belong to the framework rather than to any plugin. */
    private const EXACT = ['ENV_CACHE', 'BOOT_CACHE', 'VIEW_PATHS', 'VIEW_EXTENSIONS', 'VIEW_SAVE_DATA'];

    /** Prefixes the framework reserves: APP_*, ROUTE_*. */
    private const PREFIXES = ['APP_', 'ROUTE_'];

    public static function owns(string $key): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return \in_array($key, self::EXACT, true);
    }
}
