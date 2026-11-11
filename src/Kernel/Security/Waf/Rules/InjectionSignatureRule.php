<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\Rules;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\Contracts\WafRuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\WafMatch;

/**
 * Signatures of SQL injection, XSS, Log4Shell and PHP code injection.
 *
 * This rule is DEFENCE IN DEPTH, not the defence. SQL injection is prevented by
 * binding every value through DatabasePort, and XSS by escaping output; neither
 * is something a pattern list can guarantee. What the rule buys is that the
 * automated payloads scanners spray at every site are refused before any module
 * loads, and that they show up in the log.
 *
 * The signatures are deliberately narrow — each needs syntax, not just a
 * keyword. "select … from" in a search box does not match; `UNION SELECT` does.
 * Even so, text fields carry anything (a tutorial post quoting `<script>`), so
 * the request BODY is not inspected unless `$inspectBody` is on. The path,
 * query string and the User-Agent / Referer headers are.
 *
 * COST. Every signature has a GATE: a byte or word it cannot match without
 * ({@see gate()}). The gate is a memchr-speed str_contains, and an ordinary value
 * fails nearly all of them, so a clean request runs a handful of regexes instead
 * of thirteen per value. The gates are exact — each names something the pattern
 * itself requires — so they skip work, never detections.
 *
 * Every pattern is linear on hostile input: no unbounded scan from each `<` or
 * quote. A run of 8 KB of `<`, quotes or `../` costs well under a millisecond.
 * A regex that still hits a PCRE limit counts as a match (`unscannable`) rather
 * than as clean: otherwise a payload padded to exhaust the backtrack limit would
 * walk straight through.
 */
final class InjectionSignatureRule implements WafRuleContract
{
    /** signature => pattern, applied case-insensitively to the decoded value. */
    private const SIGNATURES = [
        // ── SQL ─────────────────────────────────────────────────────────────────
        'sqli-union'      => '/\bunion\b[\s(]++(?:all\s++|distinct\s++)?select\b/i',
        'sqli-schema'     => '/\b(?:information_schema|mysql\.user|pg_catalog|sqlite_master|sys\.objects)\b/i',
        // A complete call: "sleep (8 hours)" in a search box is not one.
        'sqli-timing'     => '/\b(?:sleep|pg_sleep|benchmark)\s*+\(\s*+\d++\s*+[,)]|\bwaitfor\s++delay\s++[\'"]/i',
        'sqli-tautology'  => '/[\'"]\s*+\)?\s*+(?:or|and)\s++[\'"]?(\w++)[\'"]?\s*+=\s*+[\'"]?\1\b/i',
        'sqli-stacked'    => '/;\s*+(?:drop|truncate|alter|create)\s++(?:table|database)\b/i',
        'sqli-file'       => '/\b(?:load_file\s*+\(|into\s++(?:out|dump)file\b)/i',
        // ── Markup / script ─────────────────────────────────────────────────────
        // (`<script` and the other tags are not regexes — see TAGS.)
        // Preceded by a separator, not by `<…`: it also catches the attribute
        // breakout `" onmouseover="…`. The separator is a LOOKBEHIND so the match
        // starts at `on` and PCRE can skip to each `o` — as a leading class it
        // was tried at every space (64 KB of HTML: 0.69 ms → 4 µs).
        'xss-handler'     => '/(?<=[\s"\'\/<])on(?:error|load|mouseover|focus|blur|click|toggle|begin|animationstart|pointerenter)\s*+=/i',
        // Anchored: a redirect target or href that IS a script URL.
        'xss-scheme'      => '/^\s*+(?:(?:javascript|vbscript)\s*+:|data\s*+:\s*+text\/html)/i',
        // ── Code / lookup injection ─────────────────────────────────────────────
        'log4shell'       => '/\$\{\s*+(?:jndi|env|sys|lower|upper|::-)/i',
        'php-tag'         => '/<\?(?:php|=)/i',
        'php-call'        => '/\b(?:base64_decode|system|passthru|shell_exec|proc_open|assert)\s*+\(\s*+[\'"$]/i',
    ];

    /** Not a regex — see {@see endsInSqlComment()}. Listed so `$disable` can name it. */
    private const SQLI_COMMENT = 'sqli-comment';

    /**
     * Tags that run script or rewrite the page, => their signature. Not a
     * regex: an HTML parser reads `<` + whitespace as TEXT, so a tag is always
     * the literal `<svg`, and `<\s*svg` widened the match for nothing while
     * being retried from every `<`. See {@see findTag()} for how it is searched.
     */
    private const TAGS = [
        'script' => 'xss-script',
        'iframe' => 'xss-tag', 'object' => 'xss-tag', 'embed' => 'xss-tag', 'svg'  => 'xss-tag',
        'math'   => 'xss-tag', 'base'   => 'xss-tag', 'meta'  => 'xss-tag',
    ];

    /** @var array<string, string> the signatures left after `$disable` */
    private readonly array $signatures;

    /** @var array<string, string> the TAGS left after `$disable` */
    private readonly array $tags;

    private readonly bool $checkComment;

    /**
     * @param bool         $inspectBody  also scan form / JSON body values
     * @param list<string> $disable      signatures to switch off, e.g. ['sqli-comment']
     * @param int          $maxScanBytes bytes of each value scanned. Bytes beyond it are NOT inspected,
     *                                   which matters only for bodies: the URL and headers are already
     *                                   bounded far below it.
     */
    public function __construct(
        private readonly bool $inspectBody = false,
        array $disable = [],
        private readonly int $maxScanBytes = 65536,
    ) {
        $this->signatures   = \array_diff_key(self::SIGNATURES, \array_flip($disable));
        $this->tags         = \array_diff(self::TAGS, $disable);
        $this->checkComment = !\in_array(self::SQLI_COMMENT, $disable, true);
    }

    public function id(): string
    {
        return 'injection';
    }

    public function inspect(Request $request): ?WafMatch
    {
        $match = $this->scan(rawurldecode($request->path()), 'path');
        if ($match !== null) {
            return $match;
        }

        foreach (PathTraversalRule::values($request->queryAll()) as $value) {
            if (($match = $this->scan($value, 'query')) !== null) {
                return $match;
            }
        }

        foreach (['User-Agent' => 'header:user-agent', 'Referer' => 'header:referer'] as $header => $location) {
            $value = $request->header($header);
            if ($value !== null && ($match = $this->scan($value, $location)) !== null) {
                return $match;
            }
        }

        if ($this->inspectBody) {
            foreach (PathTraversalRule::values($request->body()) as $value) {
                if (($match = $this->scan($value, 'body')) !== null) {
                    return $match;
                }
            }
        }

        return null;
    }

    private function scan(string $value, string $location): ?WafMatch
    {
        if ($value === '') {
            return null;
        }

        if (\strlen($value) > $this->maxScanBytes) {
            $value = \substr($value, 0, $this->maxScanBytes);
        }

        // One extra decode catches double encoding (%2527 → %27 → ').
        // SQL comments used as whitespace (UNION/**/SELECT) collapse to a space.
        $candidates = [$value];
        if (\str_contains($value, '%')) {
            $candidates[] = \rawurldecode($value);
        }
        foreach ($candidates as $candidate) {
            if (\str_contains($candidate, '/*')) {
                $candidates[] = self::stripSqlComments($candidate);
            }
        }

        foreach ($candidates as $candidate) {
            if (($signature = $this->findTag($candidate)) !== null) {
                return WafMatch::of($this->id(), $signature, $location, $value);
            }

            foreach ($this->signatures as $signature => $pattern) {
                if (!self::gate($signature, $candidate)) {
                    continue;
                }

                $result = \preg_match($pattern, $candidate);
                if ($result === 1) {
                    return WafMatch::of($this->id(), $signature, $location, $value);
                }
                if ($result === false) {
                    return WafMatch::of($this->id(), 'unscannable', $location, $value);
                }
            }

            if ($this->checkComment && self::endsInSqlComment($candidate)) {
                return WafMatch::of($this->id(), self::SQLI_COMMENT, $location, $value);
            }
        }

        return null;
    }

    /**
     * Can $signature possibly match $value? Each test names something its
     * pattern REQUIRES, so a false here is a certain non-match.
     */
    private static function gate(string $signature, string $value): bool
    {
        return match ($signature) {
            'sqli-union'              => stripos($value, 'union') !== false,
            'sqli-schema'             => strpbrk($value, '_.') !== false,
            'sqli-timing'             => strpbrk($value, '(\'"') !== false,
            'sqli-tautology'          => str_contains($value, '=') && strpbrk($value, '\'"') !== false,
            'sqli-stacked'            => str_contains($value, ';'),
            'sqli-file'               => stripos($value, 'file') !== false,
            'xss-handler'             => str_contains($value, '='),
            'xss-scheme'              => str_contains($value, ':'),
            'log4shell'               => str_contains($value, '${'),
            'php-tag'                 => str_contains($value, '<?'),
            'php-call'                => str_contains($value, '('),
            default                   => true,
        };
    }

    /**
     * The signature of the first dangerous tag in $value, or null.
     *
     * Searches for the tag NAME and then checks the byte before it is `<`.
     * Searching for `<svg` instead is slow exactly where it matters: strpos
     * stops at every `<`, and markup is full of them (8 KB of `<`: 0.7 ms per
     * search, ×8 tags; this way, 3 µs for all of them).
     */
    private function findTag(string $value): ?string
    {
        if (!\str_contains($value, '<')) {
            return null;
        }

        $lower = \strtolower($value);
        foreach ($this->tags as $tag => $signature) {
            $offset = 0;
            while (($at = \strpos($lower, $tag, $offset)) !== false) {
                $offset = $at + 1;
                if ($at === 0 || $lower[$at - 1] !== '<') {
                    continue;
                }
                // A tag name ends at anything that is not a name character:
                // `<svg>`, `<svg/onload`, `<svg ` — but not `<svgfoo`.
                $next = $lower[$at + \strlen($tag)] ?? '';
                if ($next === '' || !\ctype_alnum($next)) {
                    return $signature;
                }
            }
        }

        return null;
    }

    /**
     * Replace each `/* … *\/` with a space; an unclosed one runs to the end.
     * strpos, not a lazy regex: `.*?` paid a step per byte from every `/*`.
     */
    private static function stripSqlComments(string $value): string
    {
        $out    = '';
        $offset = 0;
        while (($start = \strpos($value, '/*', $offset)) !== false) {
            $out .= \substr($value, $offset, $start - $offset) . ' ';
            $end  = \strpos($value, '*/', $start + 2);
            if ($end === false) {
                return $out;
            }
            $offset = $end + 2;
        }

        return $out . \substr($value, $offset);
    }

    /**
     * The `admin'--` login bypass: a quote, an optional `)`, then `--` or `#`,
     * at the very END of the value. Checked from the end in O(1) instead of a
     * regex anchored at `$`, which PCRE would try from every quote.
     */
    private static function endsInSqlComment(string $value): bool
    {
        $value = rtrim($value);

        if (str_ends_with($value, '--')) {
            $value = substr($value, 0, -2);
        } elseif (str_ends_with($value, '#')) {
            $value = substr($value, 0, -1);
        } else {
            return false;
        }

        $value = rtrim($value);
        if (str_ends_with($value, ')')) {
            $value = rtrim(substr($value, 0, -1));
        }

        return $value !== '' && ($value[-1] === "'" || $value[-1] === '"');
    }
}
