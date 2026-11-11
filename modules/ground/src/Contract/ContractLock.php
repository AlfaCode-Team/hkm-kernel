<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Contract;

use AlfacodeTeam\Ground\Inspection\Finding;
use AlfacodeTeam\Ground\PluginManifest;

/**
 * `contract.lock.json` — the record of every string this plugin has ever
 * published, so that none of them can be removed by accident.
 *
 * THE RULE, AND WHOSE IT IS
 * -------------------------
 * Appwrite locks its API scopes in `app/config/scopes/lock.json` and states the
 * reason in one line: **scopes are persisted by API keys, so they can never be
 * removed.** A key issued last year holds the literal string `databases.write`.
 * Deleting that scope from the catalogue does not invalidate the key — it makes
 * the key authorise nothing, silently, for a customer who changed nothing.
 *
 * The same shape of value exists all through this framework, and nothing
 * guarded it. A `solves` domain sits in other plugins' `requires[]` and in
 * deployed `proj.json` files. A route name sits in views and emails. An event
 * name sits in a subscriber that will simply stop running. A config key sits in
 * every operator's `.env`, holding a real secret. {@see ContractSurface} says
 * why each one qualifies.
 *
 * So: the lock records every shipped value, and a check fails on
 *
 *   - a REMOVAL — a locked value the manifest no longer publishes; and
 *   - an UNLOCKED ADDITION — a published value the lock does not record.
 *
 * The second half is what makes the first half trustworthy. A lock that only
 * caught removals would quietly stop covering everything added after it was
 * written, and would be most incomplete exactly when the plugin had changed
 * most.
 *
 * RETIREMENT IS `deprecated`, NEVER DELETION
 * ------------------------------------------
 * A value does get retired. The entry stays in `surfaces` forever and is listed
 * in `deprecated` as well: its absence from the manifest then stops being an
 * error, while the string itself is never freed for reuse. Deleting the line
 * instead would free it — and the next release could reuse `posts.publish` for
 * something that means the opposite, which is the one outcome this file exists
 * to make impossible.
 *
 * Removing a value from `surfaces` while leaving it in `deprecated` is itself
 * reported, because that is the shape of someone trying to delete a line and
 * getting half way.
 *
 * OPT-IN
 * ------
 * No `contract.lock.json`, no check. The 67 first-party plugins have published
 * these strings for releases without one, and failing their CI to introduce
 * bookkeeping they never agreed to would be the tooling breaking working
 * repositories to enforce its own records. `ground contract:lock` writes the
 * first one, from what the plugin publishes today, and that act is the opt-in.
 */
final class ContractLock
{
    public const FILE = 'contract.lock.json';

    /** Bumped only if the file's SHAPE changes, which would need a migration. */
    public const VERSION = 1;

    private const NOTE = 'Every string here is published and held outside this plugin. '
        . 'Entries are added by `ground contract:lock --update` and retired with --deprecate — never deleted.';

    /**
     * @param array<string, list<string>> $surfaces   every value ever published
     * @param array<string, list<string>> $deprecated the retired subset of them
     */
    private function __construct(
        public readonly string $path,
        private array $surfaces,
        private array $deprecated,
    ) {}

    public static function pathFor(PluginManifest $manifest): string
    {
        return $manifest->directory() . '/' . self::FILE;
    }

    /** The lock beside a plugin's module.json, or null when it has none. */
    public static function beside(PluginManifest $manifest): ?self
    {
        $path = self::pathFor($manifest);

        return is_file($path) ? self::read($path) : null;
    }

    public static function read(string $path): self
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException("Ground: {$path} is not valid JSON.");
        }

        return new self(
            $path,
            self::listMap($decoded['surfaces'] ?? []),
            self::listMap($decoded['deprecated'] ?? []),
        );
    }

    /** A first lock, recording everything the plugin publishes right now. */
    public static function from(ContractSurface $surface, string $path): self
    {
        return new self($path, $surface->values, []);
    }

    /**
     * What the lock says about this surface.
     *
     * Malformed-lock findings SHORT-CIRCUIT. Comparing against a lock whose own
     * records are inconsistent produces findings that describe the lock rather
     * than the plugin, and burying "your lock file is broken" under twenty
     * "this route is unlocked" lines is how a tool gets ignored.
     *
     * @return list<Finding>
     */
    public function compare(ContractSurface $surface): array
    {
        $malformed = $this->validate();

        if ($malformed !== []) {
            return $malformed;
        }

        $findings = [];

        foreach (ContractSurface::KINDS as $kind) {
            $live     = $surface->kind($kind);
            $locked   = $this->surfaces[$kind] ?? [];
            $retired  = $this->deprecated[$kind] ?? [];

            foreach ($locked as $value) {
                if (\in_array($value, $live, true) || \in_array($value, $retired, true)) {
                    continue;
                }

                $findings[] = Finding::error(
                    'contract.removed',
                    sprintf('%s [%s] was published and is now gone.', self::label($kind), $value),
                    self::removalFix($kind),
                    $this->path,
                );
            }

            foreach ($live as $value) {
                if (\in_array($value, $locked, true)) {
                    continue;
                }

                $findings[] = Finding::error(
                    'contract.unlocked',
                    sprintf('%s [%s] is published but not recorded in the lock.', self::label($kind), $value),
                    'Run `ground contract:lock --update`. Recording it now is what makes the lock able to '
                    . 'tell you, later, that removing it would break something.',
                    $this->path,
                );
            }
        }

        return $findings;
    }

    /**
     * The lock's own internal consistency.
     *
     * One rule, and it is the rule that makes retirement safe: a deprecated
     * value must still be recorded in `surfaces`. Dropping it from there frees
     * the string for reuse, which is precisely what the file exists to prevent.
     *
     * @return list<Finding>
     */
    public function validate(): array
    {
        $findings = [];

        foreach ($this->deprecated as $kind => $values) {
            if (!\in_array($kind, ContractSurface::KINDS, true)) {
                $findings[] = Finding::error(
                    'contract.lock-malformed',
                    sprintf('The lock deprecates an unknown kind [%s].', $kind),
                    'Known kinds: ' . implode(', ', ContractSurface::KINDS) . '.',
                    $this->path,
                );

                continue;
            }

            foreach ($values as $value) {
                if (\in_array($value, $this->surfaces[$kind] ?? [], true)) {
                    continue;
                }

                $findings[] = Finding::error(
                    'contract.lock-malformed',
                    sprintf('%s [%s] is deprecated but no longer recorded in surfaces.', self::label($kind), $value),
                    'A retired value stays in surfaces forever — that is what stops the string being reused '
                    . 'for something else. Put it back; deprecating it is already enough to retire it.',
                    $this->path,
                );
            }
        }

        foreach (array_keys($this->surfaces) as $kind) {
            if (!\in_array($kind, ContractSurface::KINDS, true)) {
                $findings[] = Finding::error(
                    'contract.lock-malformed',
                    sprintf('The lock records an unknown kind [%s].', $kind),
                    'Known kinds: ' . implode(', ', ContractSurface::KINDS) . '.',
                    $this->path,
                );
            }
        }

        return $findings;
    }

    /**
     * Record everything the surface publishes that is not recorded yet.
     *
     * ADDITIVE, with no option to be otherwise. There is no `--prune`, because
     * a flag that removes records is a flag that removes the guarantee, and the
     * one-command fix for a red check would become the one command that makes
     * the check meaningless.
     *
     * @return array<string, list<string>> what was added, by kind
     */
    public function record(ContractSurface $surface): array
    {
        $added = [];

        foreach (ContractSurface::KINDS as $kind) {
            $locked = $this->surfaces[$kind] ?? [];
            $new    = array_values(array_diff($surface->kind($kind), $locked));

            if ($new === []) {
                continue;
            }

            $merged = [...$locked, ...$new];
            sort($merged);

            $this->surfaces[$kind] = array_values(array_unique($merged));
            $added[$kind]          = $new;
        }

        return $added;
    }

    /**
     * Retire a value: keep the record, stop requiring the manifest to publish it.
     *
     * Matched across every kind rather than taking a kind argument, because the
     * caller knows the string and would otherwise have to know which of six
     * buckets it lives in. Two kinds holding the same string is possible in
     * principle and harmless here — both are retired, which is what was asked.
     *
     * @return list<string> the kinds it was found in; empty when it is not locked at all
     */
    public function deprecate(string $value): array
    {
        $value = ltrim(trim($value), '\\');
        $kinds = [];

        foreach (ContractSurface::KINDS as $kind) {
            if (!\in_array($value, $this->surfaces[$kind] ?? [], true)) {
                continue;
            }

            $retired = $this->deprecated[$kind] ?? [];

            if (!\in_array($value, $retired, true)) {
                $retired[] = $value;
                sort($retired);
                $this->deprecated[$kind] = $retired;
            }

            $kinds[] = $kind;
        }

        return $kinds;
    }

    public function isDeprecated(string $kind, string $value): bool
    {
        return \in_array($value, $this->deprecated[$kind] ?? [], true);
    }

    /** @return list<string> */
    public function locked(string $kind): array
    {
        return $this->surfaces[$kind] ?? [];
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->surfaces));
    }

    public function save(): void
    {
        $surfaces   = [];
        $deprecated = [];

        // Written in KINDS order with every kind present, so the file a second
        // person generates is byte-identical to the first — a lock whose diff
        // moves lines around cannot be reviewed, and reviewing it is the point.
        foreach (ContractSurface::KINDS as $kind) {
            $surfaces[$kind] = $this->surfaces[$kind] ?? [];

            if (($this->deprecated[$kind] ?? []) !== []) {
                $deprecated[$kind] = $this->deprecated[$kind];
            }
        }

        $payload = [
            'lockVersion' => self::VERSION,
            '//'          => self::NOTE,
            'surfaces'    => $surfaces,
            // An OBJECT even when empty. json_encode writes an empty PHP array
            // as `[]`, which makes the file disagree with itself about the
            // shape of this key — `{}` on a lock with one retirement and `[]`
            // on every other. The first retirement would then show up in
            // review as a type change rather than as the one line it is.
            'deprecated'  => $deprecated === [] ? new \stdClass() : $deprecated,
        ];

        file_put_contents(
            $this->path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    // ── Wording ───────────────────────────────────────────────────────────────

    private static function label(string $kind): string
    {
        return match ($kind) {
            'solves'      => 'Domain',
            'routes'      => 'Route name',
            'events'      => 'Event',
            'contracts'   => 'Exposed contract',
            'config'      => 'Config key',
            'permissions' => 'Permission',
            default       => ucfirst($kind),
        };
    }

    /** Each kind breaks a different thing, so each gets told what it broke. */
    private static function removalFix(string $kind): string
    {
        $consequence = match ($kind) {
            'solves'      => 'Every plugin with this in requires[], and every proj.json with it in essentials, '
                           . 'now FAILS TO BOOT.',
            'routes'      => 'Every route() call for this name now throws at the call site.',
            'events'      => 'Every listener subscribed to this name simply stops running — no error anywhere.',
            'contracts'   => 'Consumers type-hinted against it now fail to resolve, on the request that needs it.',
            'config'      => 'The value operators already set in their .env is no longer read, and the plugin '
                           . 'falls back to a default without saying so.',
            'permissions' => 'Rows and policy files already hold this string; they now grant nothing.',
            default       => 'Something outside this plugin holds this string.',
        };

        return $consequence . ' If the removal is intended, retire it instead: '
            . '`ground contract:lock --deprecate <value>` keeps the record and stops requiring it.';
    }

    /**
     * @param  mixed $raw
     * @return array<string, list<string>>
     */
    private static function listMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $kind => $values) {
            if (!\is_string($kind) || !\is_array($values)) {
                continue;
            }

            $entries = [];
            foreach ($values as $value) {
                if (\is_string($value) && $value !== '') {
                    $entries[] = ltrim(trim($value), '\\');
                }
            }

            $out[$kind] = array_values(array_unique($entries));
        }

        return $out;
    }
}
