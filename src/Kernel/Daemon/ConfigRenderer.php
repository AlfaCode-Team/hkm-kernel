<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Daemon;

use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Exceptions\DaemonException;

/**
 * Substitute `{token}` placeholders, and refuse to write a template that still
 * has any.
 *
 * WHY AN UNRESOLVED PLACEHOLDER IS AN ERROR AND NOT AN EMPTY STRING
 * ----------------------------------------------------------------
 * The two obvious fallbacks are both worse than failing. Leaving `{port}` in
 * place writes a config containing literal braces, which a daemon either
 * rejects at startup — after the deploy, from a service unit, where the
 * operator sees "failed to start" and nothing else — or reads as a value.
 * Substituting an empty string is worse still, because `listen: ""` and
 * `authorization { password: "" }` are both syntactically valid and both mean
 * something very different from what was intended.
 *
 * So an unresolved token stops the write, names every token it could not
 * resolve, and leaves no file behind. A plugin that adds a placeholder to its
 * template without adding it to `daemon.defaults` finds out on the first run
 * rather than in production.
 *
 * THE TOKEN SYNTAX IS `{name}` AND NOT `{{name}}`
 * -----------------------------------------------
 * One brace, because the daemon config formats this renders — NATS conf, YAML,
 * TOML, ini — use `{` for blocks but never `{identifier}` as a bare token, so
 * single braces cannot collide with the target syntax. Double braces would be
 * safer still and would make every template harder to read for no gain here.
 */
final class ConfigRenderer
{
    /** `{name}` where name is a bare identifier. Anything else is left alone. */
    private const TOKEN = '/\{([a-z][a-z0-9_]*)\}/i';

    /**
     * Render $template with $values.
     *
     * @param  array<string, string> $values
     * @throws DaemonException when any `{token}` is left unresolved
     */
    public static function render(string $template, array $values, string $describedAs): string
    {
        $rendered = preg_replace_callback(
            self::TOKEN,
            static fn (array $match): string => $values[$match[1]] ?? $match[0],
            $template,
        ) ?? $template;

        $unresolved = self::tokensIn($rendered);

        if ($unresolved !== []) {
            throw DaemonException::unresolvedTokens($describedAs, $unresolved);
        }

        return $rendered;
    }

    /**
     * Every `{token}` still present, de-duplicated and in order.
     *
     * @return list<string>
     */
    public static function tokensIn(string $text): array
    {
        if (preg_match_all(self::TOKEN, $text, $matches) === 0) {
            return [];
        }

        $seen = [];

        foreach ($matches[1] as $name) {
            $seen['{' . $name . '}'] = true;
        }

        return array_keys($seen);
    }

    /**
     * Render each string of an argv list.
     *
     * Used for `daemon.service.args` and `daemon.config.verify`, which carry the
     * same tokens as the config — and which must be rendered as SEPARATE
     * arguments rather than joined into a command line. A path containing a
     * space is ordinary on macOS, and a joined string would split it into two
     * arguments the daemon does not understand.
     *
     * @param  list<string>          $argv
     * @param  array<string, string> $values
     * @return list<string>
     * @throws DaemonException
     */
    public static function renderArgv(array $argv, array $values, string $describedAs): array
    {
        $out = [];

        foreach ($argv as $index => $argument) {
            $out[] = self::render($argument, $values, "{$describedAs} argument " . ($index + 1));
        }

        return $out;
    }
}
