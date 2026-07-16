<?php

declare(strict_types=1);

/**
 * Casbin engine global helpers.
 *
 * The ported Casbin engine (Engine/) calls a handful of unqualified helper
 * functions that were provided as lazily-included globals in the old framework
 * (`Functions.Casbin_Helpers`). They are pure string utilities with no framework
 * coupling, recreated here and autoloaded via composer "files" so the engine
 * works standalone under GDA. Guarded so a double-include is harmless.
 */

if (!function_exists('escapeDotsInAssertion')) {
    /**
     * Escape request/policy attribute dots so the expression language can treat
     * them as plain identifiers: `r.sub` → `r_sub`, `p2.act` → `p2_act`.
     */
    function escapeDotsInAssertion(string $assertion): string
    {
        return (string) preg_replace('/\b(r|p)(\d*)\./', '${1}${2}_', $assertion);
    }
}

if (!function_exists('stripInlineComments')) {
    /** Remove a trailing `# …` inline comment and surrounding whitespace. */
    function stripInlineComments(string $value): string
    {
        $pos = strpos($value, '#');

        return $pos === false ? trim($value) : trim(substr($value, 0, $pos));
    }
}

if (!function_exists('containsEval')) {
    /** Whether a matcher expression contains an `eval(...)` call. */
    function containsEval(string $expression): bool
    {
        return (bool) preg_match('/\beval\(([^)]*)\)/', $expression);
    }
}

if (!function_exists('extractEvalParameters')) {
    /**
     * Extract the rule identifiers referenced by every `eval(...)` in the
     * expression (e.g. `eval(p_rule)` → ['p_rule']).
     *
     * @return list<string>
     */
    function extractEvalParameters(string $expression): array
    {
        preg_match_all('/\beval\(([^)]*)\)/', $expression, $matches);

        return array_values(array_map('trim', $matches[1] ?? []));
    }
}

if (!function_exists('replaceEvalWithMappings')) {
    /**
     * Replace each `eval(<ruleName>)` with the mapped rule expression, wrapped in
     * parentheses so operator precedence is preserved.
     *
     * @param array<string,string> $mappings ruleName => rule expression
     */
    function replaceEvalWithMappings(string $expression, array $mappings): string
    {
        return (string) preg_replace_callback(
            '/\beval\(([^)]*)\)/',
            static function (array $m) use ($mappings): string {
                $ruleName = trim($m[1]);

                return isset($mappings[$ruleName]) ? '(' . $mappings[$ruleName] . ')' : $m[0];
            },
            $expression,
        );
    }
}
