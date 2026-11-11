<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\Rules;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\Contracts\WafRuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\WafMatch;

/**
 * Attempts to walk out of a directory or reach a PHP stream wrapper.
 *
 * The PATH is checked strictly: browsers normalise `..` segments away before
 * sending, so a `..` segment that arrives — however many times it was encoded —
 * was put there on purpose. The `{name:path}` route type already rejects it for
 * the route it guards; this catches it for every route, including `{name:any}`.
 *
 * QUERY values are checked narrowly, because `?next=../account` is an ordinary
 * relative link. Only a climb toward a system directory, or a stream-wrapper
 * scheme, is flagged there.
 */
final class PathTraversalRule implements WafRuleContract
{
    /** Decoding passes. Three covers %2e, %252e and %25252e. */
    private const DECODE_DEPTH = 3;

    private const PATH_DOTDOT = '#(?:^|[/\\\\])\.\.(?:[/\\\\]|$)#';

    /**
     * System directories a climb aims at. Found as the literal `../<dir>` after
     * the slashes are normalised — every climb ends with that step, whatever
     * came before it. The regex this replaces, `\.\.[/\\]+(?:\.\.[/\\]+)*(?:etc|…)`,
     * re-scanned a run of `../` from each of its steps: 8 KB of `../` cost 0.6 s
     * of CPU per request.
     */
    private const SYSTEM_DIRS = ['etc', 'proc', 'sys', 'boot', 'root', 'var', 'usr', 'windows', 'winnt'];

    private const QUERY_WRAPPER = '#^\s*(?:php|phar|zip|glob|expect|data|file|zlib|compress\.zlib|ogg|rar|ssh2)://#i';

    public function id(): string
    {
        return 'traversal';
    }

    public function inspect(Request $request): ?WafMatch
    {
        $rawPath = $request->path();
        $path    = self::decode($rawPath);
        if (str_contains($path, '..') && preg_match(self::PATH_DOTDOT, $path) === 1) {
            return WafMatch::of($this->id(), 'dot-dot-segment', 'path', $rawPath);
        }

        foreach (self::values($request->queryAll()) as $value) {
            $decoded = self::decode($value);
            if (\str_contains($decoded, '..') && self::climbsToSystemDir($decoded)) {
                return WafMatch::of($this->id(), 'climb-to-system-dir', 'query', $value);
            }
            if (str_contains($decoded, '://') && preg_match(self::QUERY_WRAPPER, $decoded) === 1) {
                return WafMatch::of($this->id(), 'stream-wrapper', 'query', $value);
            }
        }

        return null;
    }

    private static function climbsToSystemDir(string $value): bool
    {
        // `..\\windows`, `..//etc` and `../etc` are the same step.
        $value = \strtolower(\strtr($value, '\\', '/'));
        while (\str_contains($value, '//')) {
            $value = \str_replace('//', '/', $value);
        }

        // Search the directory NAME and look back for `../`. Searching for
        // `../etc` instead stops at every '.', and a run of `../` is all dots.
        foreach (self::SYSTEM_DIRS as $dir) {
            $offset = 0;
            while (($at = \strpos($value, $dir, $offset)) !== false) {
                $offset = $at + 1;
                if ($at < 3 || \substr_compare($value, '../', $at - 3, 3) !== 0) {
                    continue;
                }
                $next = $value[$at + \strlen($dir)] ?? '';
                if ($next === '' || $next === '/') {
                    return true;
                }
            }
        }

        return false;
    }

    private static function decode(string $value): string
    {
        for ($i = 0; $i < self::DECODE_DEPTH && str_contains($value, '%'); $i++) {
            $value = rawurldecode($value);
        }

        return $value;
    }

    /**
     * Every scalar leaf of a (possibly nested) input array, as a string.
     *
     * @return \Generator<int, string>
     */
    public static function values(array $input): \Generator
    {
        foreach ($input as $value) {
            if (\is_array($value)) {
                yield from self::values($value);
            } elseif (\is_scalar($value)) {
                yield (string) $value;
            }
        }
    }
}
