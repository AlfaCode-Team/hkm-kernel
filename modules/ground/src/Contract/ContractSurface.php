<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Contract;

use AlfacodeTeam\Ground\FrameworkVars;
use AlfacodeTeam\Ground\PluginManifest;

/**
 * The strings a plugin publishes that SOMETHING OUTSIDE IT already holds.
 *
 * WHY THESE FIVE AND NOT EVERYTHING
 * ---------------------------------
 * A lock file is only worth its maintenance if breaking an entry breaks
 * something the plugin cannot see. Each kind here is a string that is PERSISTED
 * or REFERENCED outside this repository, so renaming it is a silent break
 * rather than a compile error:
 *
 *   solves       Every other plugin's `requires[]` and every project's
 *                `proj.json` "essentials" names this domain as a STRING. The
 *                kernel fails the boot on an unknown requires[] entry, so
 *                renaming a domain breaks every application that depended on
 *                the plugin — at boot, in production, with no code change
 *                anywhere near it.
 *
 *   routes       Route names are an application-wide flat namespace and
 *                `route('posts.show')` is written in views, mails and other
 *                plugins. The kernel throws at the call site on an unknown
 *                name, which means a renamed route surfaces as a 500 on
 *                whichever page happened to link to it.
 *
 *   events       `emits[]` is the contract `listens[]` and
 *                `$events->subscribe('post.published', …)` are written against.
 *                A renamed event does not fail: the subscriber simply never
 *                runs again, which is the worst failure mode of the five.
 *
 *   contracts    `exposes[]` entries are imported and type-hinted by consumer
 *                plugins. Removing one is a fatal at resolve time, on the
 *                request that needed it.
 *
 *   config       `hkm plugins enable` SEEDS config[] into every project's
 *                `.env`. Those files are deployed, hand-edited and hold real
 *                secrets. Renaming a key means the operator's configured value
 *                is silently no longer read, and the plugin falls back to a
 *                default — the plugin boots, works, and is wrong.
 *
 * `permissions[]` is read when a manifest declares it. No first-party manifest
 * does today — permission strings currently live as config[] defaults
 * (`POSTS_WRITE_PERMISSION` → `posts:write`), which the config surface already
 * covers. The hook is here because a permission string is persisted in rows and
 * policy files, which makes it exactly the kind of value this exists for; it
 * costs nothing until someone declares one.
 *
 * WHAT IS DELIBERATELY NOT LOCKED
 * -------------------------------
 *   requires[]/listens[]  These are things the plugin CONSUMES. Breaking one is
 *                         the publisher's problem and shows up in the
 *                         publisher's own lock, so locking them here would
 *                         report the same break twice and let a plugin's CI go
 *                         red for a change it did not make.
 *   paths                 A URL path can be moved behind a stable route name,
 *                         which is the entire reason route names exist.
 *   handlers, filters     Internal. A handler class is free to move.
 */
final readonly class ContractSurface
{
    /**
     * The kinds, in the order a lock file writes them.
     *
     * Order is fixed so two people locking the same plugin produce the same
     * file — a lock whose diff depends on array iteration is a lock nobody can
     * review.
     */
    public const KINDS = ['solves', 'routes', 'events', 'contracts', 'config', 'permissions'];

    /**
     * @param array<string, list<string>> $values kind => sorted, unique values
     */
    private function __construct(public array $values) {}

    public static function of(PluginManifest $manifest): self
    {
        $routes = [];
        foreach ($manifest->expandedRoutes() as $route) {
            if ($route['name'] !== null && $route['name'] !== '') {
                $routes[] = $route['name'];
            }
        }

        // A framework-owned variable is not this plugin's to promise. Several
        // first-party manifests list APP_KEY and APP_NAME in config[]; locking
        // them would report a plugin TIDYING those lines out as a removed
        // contract, which is the opposite of true. Caught by running the
        // command against a real plugin, not by a test.
        $config = [];
        foreach ($manifest->configVars() as $var) {
            if ($var['key'] !== '' && !FrameworkVars::owns($var['key'])) {
                $config[] = $var['key'];
            }
        }

        /** @var list<string> $permissions */
        $permissions = array_values(array_filter(
            array_map('strval', (array) ($manifest->data['permissions'] ?? [])),
            static fn (string $value): bool => $value !== '',
        ));

        return self::from([
            'solves'      => $manifest->solves() === '' ? [] : [$manifest->solves()],
            'routes'      => $routes,
            'events'      => $manifest->emits(),
            'contracts'   => $manifest->exposes(),
            'config'      => $config,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Normalise to the canonical shape: every kind present, sorted, unique.
     *
     * Every kind present matters as much as the sorting. A lock file that omits
     * an empty kind and one that writes `[]` would compare as different files
     * while describing the same surface, and the first plugin to add its first
     * route would produce a diff that looks like the lock format changed.
     *
     * @param  array<string, list<string>|array<array-key, string>> $values
     */
    public static function from(array $values): self
    {
        $normalised = [];

        foreach (self::KINDS as $kind) {
            $entries = array_values(array_unique(array_map(
                static fn (string $value): string => ltrim(trim($value), '\\'),
                $values[$kind] ?? [],
            )));

            sort($entries);

            $normalised[$kind] = $entries;
        }

        return new self($normalised);
    }

    /** @return list<string> */
    public function kind(string $kind): array
    {
        return $this->values[$kind] ?? [];
    }

    public function has(string $kind, string $value): bool
    {
        return \in_array($value, $this->kind($kind), true);
    }

    public function isEmpty(): bool
    {
        foreach ($this->values as $entries) {
            if ($entries !== []) {
                return false;
            }
        }

        return true;
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->values));
    }
}
