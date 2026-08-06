<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Routing;

/**
 * Route parameter types — the `{name:type}` grammar.
 *
 * Shared by CompileRouteManifestStage (which VALIDATES type names at boot) and
 * RouteMatcher (which compiles them to regex at match time), so the two can
 * never disagree about what a type means.
 *
 * WHY TYPES EXIST
 * ---------------
 * Before this, every `{param}` compiled to `[^/]+`, so `/users/{id}` matched
 * `/users/abc`. HKM 0.3 had `users/(:num)` and 404'd non-numeric ids before any
 * controller ran; losing that pushed the check into controllers and DTOs with
 * nothing enforcing that it happened. A typed segment restores a cheap, total
 * guarantee at the routing layer.
 *
 * The table is a deliberate port of 0.3's placeholders so old routes translate
 * one-for-one:
 *
 *   0.3                Sentinel
 *   (:num)             {id:num}
 *   (:alpha)           {name:alpha}
 *   (:alphanum)        {code:alphanum}
 *   (:segment)         {slug:segment}   (same as untyped)
 *   (:any)             {path:any}       (crosses '/', catch-all)
 *
 * COMPATIBILITY
 * -------------
 * An untyped `{id}` keeps its exact previous meaning (`[^/]+`), so no existing
 * route changes behaviour. Types are opt-in.
 *
 * CAUTION — the type is part of the route KEY. `GET /users/{id}` and
 * `GET /users/{id:num}` are DIFFERENT routes, so a project overriding a plugin
 * route must repeat the plugin's exact path, type suffix included. This is the
 * same literal-match rule that already governs overrides; typing does not
 * loosen it.
 */
final class RouteParameter
{
    /**
     * type => regex fragment. Values are anchored by the matcher.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'num'      => '[0-9]+',
        'alpha'    => '[a-zA-Z]+',
        'alphanum' => '[a-zA-Z0-9]+',
        'slug'     => '[a-zA-Z0-9_-]+',
        'uuid'     => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
        'segment'  => '[^/]+',
        'any'      => '.*',
    ];

    /** The pattern used when no type is given — unchanged from before typing existed. */
    public const DEFAULT_PATTERN = '[^/]+';

    /** Matches one `{name}` or `{name:type}` placeholder. */
    public const PLACEHOLDER = '/\{([^}:]+)(?::([^}]+))?\}/';

    /** @return list<string> every valid type name, for error messages */
    public static function names(): array
    {
        return array_keys(self::TYPES);
    }

    public static function isValidType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /**
     * The regex fragment for a type. An empty type means "untyped".
     *
     * @throws \InvalidArgumentException on an unknown type — callers that need a
     *         boot-time failure (the compiler) catch and rethrow as BootException.
     */
    public static function pattern(string $type): string
    {
        if ($type === '') {
            return self::DEFAULT_PATTERN;
        }

        if (!self::isValidType($type)) {
            throw new \InvalidArgumentException(
                "Unknown route parameter type [{$type}]. Valid types: " . implode(', ', self::names()) . '.'
            );
        }

        return self::TYPES[$type];
    }

    /**
     * Every placeholder in a path, as [name, type] pairs (type '' when untyped).
     *
     * @return list<array{name: string, type: string}>
     */
    public static function parse(string $path): array
    {
        if (!str_contains($path, '{')) {
            return [];
        }

        preg_match_all(self::PLACEHOLDER, $path, $matches, PREG_SET_ORDER);

        $found = [];
        foreach ($matches as $match) {
            $found[] = [
                'name' => $match[1],
                'type' => $match[2] ?? '',
            ];
        }

        return $found;
    }
}
