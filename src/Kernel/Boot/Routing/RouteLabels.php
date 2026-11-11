<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Routing;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;

/**
 * RouteLabels — a route's declared observability intent, validated at BOOT.
 *
 * Three optional keys on a route entry in module.json / proj.json:
 *
 *   "audit":  "invoice.create"                  one action name
 *   "metric": "invoices.created"  | [ ... ]     counter name(s)
 *   "emits":  "invoice.created"   | [ ... ]     integration event name(s)
 *
 * The kernel only CARRIES them: {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages\RouteLabelStage}
 * hands each to whatever sink is installed. What a metric or an audit action
 * MEANS belongs to that sink, not here.
 *
 * ABSENT BY DEFAULT. A route that declares none of the three compiles with no
 * `labels` key at all, so an existing module.json produces a byte-identical
 * manifest.
 *
 * WHY A MALFORMED LABEL FAILS THE BOOT
 * ------------------------------------
 * A label is a promise that something will be recorded. A typo'd name, a number
 * where a string belongs, or a label written on a group (where it would have
 * applied to nothing) all degrade to the same outcome: the route works, nothing
 * is recorded, and nobody finds out until the dashboard is empty. That is the
 * silent no-op this compiler exists to turn into an error that names the route —
 * same policy as a bad `{name:type}` or a dead `routePolicy.disable` spec.
 *
 * ONE NAME GRAMMAR for all three. Lower-case, starts with a letter, then letters,
 * digits and `. _ : -`, at most {@see self::MAX_LENGTH}. It is deliberately the
 * strictest of what any sink is likely to accept, so a name that passes here can
 * be stored by any of them; and it matches the dotted style event names already
 * use (`invoice.created`, `payment.succeeded`). A sink that is stricter still
 * is guarded at run time (a throwing sink never fails the response).
 */
final class RouteLabels
{
    public const KEYS = ['audit', 'metric', 'emits'];

    public const MAX_LENGTH = 128;

    /** A fan-out cap: one route naming dozens of events is a design smell, and unbounded in a manifest. */
    public const MAX_PER_KEY = 16;

    /**
     * Read the three keys off one route declaration.
     *
     * @param array<string, mixed> $route
     * @return array{audit?: string, metric?: list<string>, emits?: list<string>}|null
     *         null when the route declares none — the caller then adds NO key.
     */
    public static function normalize(array $route, string $context): ?array
    {
        $labels = [];

        if (array_key_exists('audit', $route) && $route['audit'] !== null) {
            $audit = $route['audit'];
            if (!is_string($audit)) {
                throw new BootException(
                    "{$context} declares an audit label that is not a string — it is a single action "
                    . 'name such as "invoice.create".'
                );
            }
            $labels['audit'] = self::name($audit, $context, 'audit');
        }

        foreach (['metric', 'emits'] as $key) {
            if (!array_key_exists($key, $route) || $route[$key] === null) {
                continue;
            }

            $names = self::names($route[$key], $context, $key);
            if ($names !== []) {
                $labels[$key] = $names;
            }
        }

        return $labels === [] ? null : $labels;
    }

    /**
     * Labels are per-ROUTE. A source or group carrying one would apply to
     * nothing (the expander only reads routes), which is exactly the quiet
     * no-op this class refuses — so it is named and rejected.
     *
     * `emits` is exempt at SOURCE level only: a module.json's top-level `emits`
     * is the module's own list of events it may publish, a different thing.
     * On a group it has no such meaning and is rejected like the others.
     *
     * @param array<string, mixed> $declaration a group, or a module.json / proj.json root
     */
    public static function assertNoneOn(array $declaration, string $context, bool $isGroup): void
    {
        foreach (self::KEYS as $key) {
            if ($key === 'emits' && !$isGroup) {
                continue;
            }

            if (array_key_exists($key, $declaration)) {
                throw new BootException(
                    "{$context} declares [{$key}], but route labels belong on an individual route, "
                    . 'not on a group or the module root: nothing would be recorded. '
                    . 'Move it onto the routes[] entries that should carry it.'
                );
            }
        }
    }

    /**
     * Every event a plugin route emits must be one its module.json declares in
     * `emits[]`. That list is what listeners, documentation and the test harness
     * read; an event missing from it is published and observed by nobody who
     * went looking. A project has no such list, so its routes are not checked.
     *
     * @param array{audit?: string, metric?: list<string>, emits?: list<string>}|null $labels
     * @param mixed $declared the module's top-level `emits`
     */
    public static function assertEmitsDeclared(?array $labels, mixed $declared, string $context): void
    {
        $emits = $labels['emits'] ?? [];
        if ($emits === []) {
            return;
        }

        $known = is_array($declared) ? array_values(array_filter($declared, 'is_string')) : [];

        foreach ($emits as $event) {
            if (!in_array($event, $known, true)) {
                throw new BootException(
                    "{$context} emits [{$event}], which the module does not declare in its module.json "
                    . 'emits[]. Add it there, or fix the spelling — an undeclared event is invisible to '
                    . 'everything that reads that list.'
                );
            }
        }
    }

    /** @return list<string> */
    private static function names(mixed $value, string $context, string $key): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw new BootException(
                "{$context} declares a {$key} label that is neither a name nor a list of names."
            );
        }

        $out = [];
        foreach ($value as $name) {
            if (!is_string($name)) {
                throw new BootException("{$context} declares a {$key} label that is not a string.");
            }
            $out[] = self::name($name, $context, $key);
        }

        $out = array_values(array_unique($out));

        if (count($out) > self::MAX_PER_KEY) {
            throw new BootException(sprintf(
                '%s declares %d %s labels; the limit is %d.',
                $context,
                count($out),
                $key,
                self::MAX_PER_KEY,
            ));
        }

        return $out;
    }

    private static function name(string $name, string $context, string $key): string
    {
        if ($name === '' || strlen($name) > self::MAX_LENGTH || preg_match('/^[a-z][a-z0-9._:-]*$/', $name) !== 1) {
            throw new BootException(sprintf(
                '%s declares %s label [%s]: a name is lower-case letters, digits and . _ : -, '
                . 'starting with a letter, at most %d characters.',
                $context,
                $key,
                substr($name, 0, 40),
                self::MAX_LENGTH,
            ));
        }

        return $name;
    }
}
