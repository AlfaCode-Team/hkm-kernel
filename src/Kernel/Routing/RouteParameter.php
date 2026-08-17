<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Routing;

/**
 * Route parameter types — the `{name:type}` grammar.
 *
 * Shared by CompileRouteManifestStage (which VALIDATES type names and PRECOMPILES
 * the regex at boot) and RouteMatcher (which matches with it), so the two can
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
 *
 * ── ADDITIONS ────────────────────────────────────────────────────────────────
 *
 * `path`      — like `any` (crosses '/') but REFUSES a `..` sequence and control
 *               characters. `any` is kept byte-for-byte as it was, so nothing
 *               regresses; `path` is what a file-serving route should use.
 * `enum(a|b)` — a closed set of literal values. Members are restricted to
 *               `[A-Za-z0-9_.-]` and are preg_quote'd, so the grammar can never
 *               inject regex metacharacters (no ReDoS surface from JSON).
 * `{id?}`     — an OPTIONAL parameter. The separator in front of it is folded
 *               into the optional group, so `/posts/{page?}` matches `/posts`
 *               as well as `/posts/2`. Omitted parameters are reported as ''.
 *
 * DECODING CONTRACT
 * -----------------
 * {@see RouteMatcher} matches against the RAW (percent-encoded) request path and
 * then decodes each captured value and RE-VALIDATES it against this table. A
 * type therefore constrains what the CONTROLLER receives, not merely what the
 * wire bytes looked like — `%2F` cannot smuggle a '/' past `{id}` any more.
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
        // Traversal-safe catch-all: crosses '/', but no '..' anywhere and no
        // control characters. Prefer this over `any` for anything that reaches a
        // filesystem or a StoragePort.
        'path'     => '(?!(?s:.*)\.\.)[^\x00-\x1f\x7f]+',
    ];

    /** The pattern used when no type is given — unchanged from before typing existed. */
    public const DEFAULT_PATTERN = '[^/]+';

    /**
     * Matches one placeholder: `{name}`, `{name:type}`, `{name?}`, `{name:type?}`.
     *
     * The NAME class stays permissive (anything but `}`, `:` and `?`) so paths
     * that already rely on the matcher's name sanitisation — `{user-id}` — keep
     * compiling exactly as they did. Tightening it here would silently turn a
     * working route into a never-matching literal.
     */
    public const PLACEHOLDER = '/\{([^}:?]+)(?::([^}?]+))?(\?)?\}/';

    /** A well-formed `enum(a|b|c)` type. Members may not contain regex metacharacters. */
    private const ENUM = '/^enum\(([A-Za-z0-9_.-]+(?:\|[A-Za-z0-9_.-]+)*)\)$/';

    /** @return list<string> every valid type name, for error messages */
    public static function names(): array
    {
        return [...array_keys(self::TYPES), 'enum(a|b|…)'];
    }

    public static function isValidType(string $type): bool
    {
        return isset(self::TYPES[$type]) || preg_match(self::ENUM, $type) === 1;
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

        if (isset(self::TYPES[$type])) {
            return self::TYPES[$type];
        }

        if (preg_match(self::ENUM, $type, $m) === 1) {
            $members = array_map(
                static fn(string $v): string => preg_quote($v, '#'),
                explode('|', $m[1]),
            );

            return '(?:' . implode('|', $members) . ')';
        }

        throw new \InvalidArgumentException(
            "Unknown route parameter type [{$type}]. Valid types: " . implode(', ', self::names()) . '.'
        );
    }

    /**
     * Every placeholder in a path, as [name, type] pairs (type '' when untyped).
     *
     * Kept to exactly this shape — it is public API. Use {@see parseDetailed()}
     * when the optional flag matters.
     *
     * @return list<array{name: string, type: string}>
     */
    public static function parse(string $path): array
    {
        $found = [];

        foreach (self::parseDetailed($path) as $placeholder) {
            $found[] = ['name' => $placeholder['name'], 'type' => $placeholder['type']];
        }

        return $found;
    }

    /**
     * Every placeholder with its optional flag.
     *
     * @return list<array{name: string, type: string, optional: bool}>
     */
    public static function parseDetailed(string $path): array
    {
        if (!str_contains($path, '{')) {
            return [];
        }

        preg_match_all(self::PLACEHOLDER, $path, $matches, PREG_SET_ORDER);

        $found = [];
        foreach ($matches as $match) {
            $found[] = [
                'name'     => $match[1],
                'type'     => ($match[2] ?? '') !== '' ? $match[2] : '',
                'optional' => ($match[3] ?? '') === '?',
            ];
        }

        return $found;
    }

    /**
     * The capture-group name for a placeholder.
     *
     * PCRE group names must be `[A-Za-z_][A-Za-z0-9_]*`, so a declared `{user-id}`
     * is folded to `userid`. This sanitisation predates typing and is preserved
     * verbatim: the resulting key is what `route_params` has always contained.
     */
    public static function groupName(string $declaredName): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_]/', '', $declaredName);
    }

    /**
     * Compile a path template to an anchored regex plus its parameter list.
     *
     * THE single place a route path becomes a regex — the boot compiler calls it
     * to bake the result into the manifest, and RouteMatcher calls it only when
     * handed a legacy (un-indexed) manifest. One implementation, so the compiled
     * and the on-the-fly paths cannot drift.
     *
     * Three properties this guarantees that the previous inline compilation did
     * not:
     *   - literal text is preg_quote'd, so `/sitemap.xml/{id}` cannot match
     *     `/sitemapXxml/1`;
     *   - the pattern is anchored with `$…#D`, so a trailing newline in the
     *     request path cannot satisfy `$`;
     *   - duplicate or PCRE-invalid group names throw here instead of producing
     *     a pattern that makes preg_match() return false on every request (a
     *     permanent silent 404).
     *
     * @return array{regex: string, params: list<array{name: string, type: string, optional: bool}>}
     *
     * @throws \InvalidArgumentException on an unknown type or an unusable name
     */
    public static function compile(string $path): array
    {
        if (!str_contains($path, '{')) {
            return ['regex' => '#^' . preg_quote($path, '#') . '$#D', 'params' => []];
        }

        preg_match_all(self::PLACEHOLDER, $path, $sets, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $regex  = '';
        $params = [];
        $seen   = [];
        $offset = 0;

        foreach ($sets as $set) {
            [$whole, $start] = $set[0];

            $declared = $set[1][0];

            // With PREG_OFFSET_CAPTURE an unmatched group is reported as
            // ['', -1] — or omitted entirely when it is trailing — so both the
            // group's presence and its offset have to be checked.
            $type     = isset($set[2]) && $set[2][1] !== -1 ? $set[2][0] : '';
            $optional = isset($set[3]) && $set[3][1] !== -1;

            $name = self::groupName($declared);

            if ($name === '' || !preg_match('/^[A-Za-z_]/', $name)) {
                throw new \InvalidArgumentException(sprintf(
                    'Route parameter {%s} in [%s] is not a usable capture name. '
                    . 'A name must start with a letter or underscore once non-word characters are stripped '
                    . '(so {2fa} is invalid; use {twoFactor}).',
                    $declared,
                    $path,
                ));
            }

            if (isset($seen[$name])) {
                throw new \InvalidArgumentException(sprintf(
                    'Route parameter {%s} in [%s] repeats the capture name [%s]. '
                    . 'Each placeholder in a path must be uniquely named — duplicates compile to an '
                    . 'invalid pattern that never matches.',
                    $declared,
                    $path,
                    $name,
                ));
            }
            $seen[$name] = true;

            $literal = substr($path, $offset, $start - $offset);
            $offset  = $start + strlen($whole);

            // An optional parameter swallows the separator in front of it, so
            // `/posts/{page?}` matches `/posts` as well as `/posts/7`.
            $separator = '';
            if ($optional && $literal !== '' && str_ends_with($literal, '/')) {
                $literal   = substr($literal, 0, -1);
                $separator = '/';
            }

            $group = '(?P<' . $name . '>' . self::pattern($type) . ')';

            $regex .= preg_quote($literal, '#')
                . ($optional ? '(?:' . preg_quote($separator, '#') . $group . ')?' : $group);

            $params[] = ['name' => $name, 'type' => $type, 'optional' => $optional];
        }

        $regex .= preg_quote(substr($path, $offset), '#');

        // `$…#D` — without the D modifier, `$` also matches immediately before a
        // trailing newline, so "/users/1\n" would satisfy "#^/users/{id:num}$#".
        return ['regex' => '#^' . $regex . '$#D', 'params' => $params];
    }
}
