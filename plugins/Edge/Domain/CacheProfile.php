<?php

declare(strict_types=1);

namespace Plugins\Edge\Domain;

/**
 * The browser-cache strategy baked into a generated vhost.
 *
 * The profile is derived ONLY from the application environment (APP_ENV) — never
 * from the kernel mode (HKM_DEV / `--dev` kernel selection), so choosing which
 * kernel to run against and choosing how assets are cached stay independent.
 *
 *   local        → Development   (developer machine)
 *   development  → Development   (shared dev / staging server)
 *   production   → Production    (live server)
 *
 * Anything unrecognised falls back to Development: a stale-asset bug in dev is
 * cheap, while wrongly shipping year-long immutable caching is not.
 */
enum CacheProfile: string
{
    case Development = 'development';
    case Production  = 'production';

    /** Environments that select the production profile. Everything else is dev. */
    private const PRODUCTION_ENVS = ['production', 'prod', 'live'];

    /**
     * Map an APP_ENV value to a profile. Fail-safe by construction: only an
     * explicit production environment yields Production.
     */
    public static function fromAppEnv(?string $appEnv): self
    {
        return in_array(strtolower(trim((string) $appEnv)), self::PRODUCTION_ENVS, true)
            ? self::Production
            : self::Development;
    }

    public function isDevelopment(): bool
    {
        return $this === self::Development;
    }

    /** The two comment lines written at the top of every generated vhost. */
    public function banner(): string
    {
        return match ($this) {
            self::Development => "# HKM Edge cache profile: DEVELOPMENT\n"
                . "# Static assets are not cached to prevent stale asset issues.\n",
            self::Production => "# HKM Edge cache profile: PRODUCTION\n"
                . "# Fingerprinted assets use immutable long-term caching.\n",
        };
    }
}
