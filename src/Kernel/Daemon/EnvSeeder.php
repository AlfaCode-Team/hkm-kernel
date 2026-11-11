<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Daemon;

use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Exceptions\DaemonException;

/**
 * Add the daemon's connection details to the project's `.env`, and never change
 * a line that is already there.
 *
 * THE "NEVER MODIFY" RULE IS NOT POLITENESS
 * ----------------------------------------
 * This runs on every deploy, and the keys it writes are credentials. Updating
 * an existing `NATS_PASS` would mean a deploy silently changed the password the
 * running daemon is still using — so the application stops authenticating,
 * `nats:status` reports an authorization violation, and nothing in the deploy
 * log mentions touching a credential. `hkm plugins enable` already works this
 * way for `config[]`, and this follows it exactly.
 *
 * So the only two outcomes are "the key was absent and is now set" and "the key
 * was there and was left alone". The second is reported, not hidden: a config
 * pointing at the wrong port is a real state and the operator needs to know the
 * generated value was not applied.
 *
 * APPENDED UNDER A LABELLED BLOCK
 * -------------------------------
 * So it is obvious what wrote them and safe to delete as a unit. The block is
 * only ever added once; a second run with nothing new to say writes nothing at
 * all, which matters because an `.env` that grows a header per deploy is one
 * nobody will keep reading.
 */
final class EnvSeeder
{
    /**
     * What a seeding run did to one key.
     */
    public const ADDED = 'added';

    /**
     * Present, and holding something DIFFERENT from what would be written.
     *
     * The only one of the three worth a warning: the file and the daemon
     * disagree, and whichever is right the application is talking to the wrong
     * thing.
     */
    public const KEPT = 'kept';

    /**
     * Present and already identical. Reported quietly.
     *
     * Distinguished from {@see self::KEPT} because this is what a repeat deploy
     * looks like, and it is the common case. Collapsing the two meant every
     * deploy printed a warning per key for a file that had not changed — which
     * is how people learn to scroll past warnings.
     */
    public const UNCHANGED = 'unchanged';

    /**
     * Seed $values into `<projectRoot>/.env`.
     *
     * @param  array<string, string> $values
     * @return array<string, string> key => self::ADDED | self::KEPT | self::UNCHANGED
     * @throws DaemonException when the file exists and cannot be written
     */
    public static function seed(string $projectRoot, string $label, array $values, bool $dryRun = false): array
    {
        if ($values === []) {
            return [];
        }

        $path     = rtrim($projectRoot, '/') . '/.env';
        $existing = is_file($path) ? (string) @file_get_contents($path) : '';
        $outcome  = [];
        $toAppend = [];

        foreach ($values as $key => $value) {
            if (self::hasKey($existing, $key)) {
                $current = self::valueOf($existing, $key);

                $outcome[$key] = $current !== null && $current === $value
                    ? self::UNCHANGED
                    : self::KEPT;

                continue;
            }

            $outcome[$key] = self::ADDED;
            $toAppend[]    = $key . '=' . self::quote($value);
        }

        if ($toAppend === [] || $dryRun) {
            return $outcome;
        }

        $block = ($existing !== '' && !str_ends_with($existing, "\n") ? "\n" : '')
            . "\n# ── {$label} ──\n"
            . implode("\n", $toAppend) . "\n";

        if (@file_put_contents($path, $block, FILE_APPEND) === false) {
            throw DaemonException::notWritable($path, 'the credentials could not be appended');
        }

        // The file now holds a password. 0640 at most — the web server account
        // reads it through the group, which is the split-ownership model
        // `hkm install --production` sets up.
        if (is_file($path)) {
            @chmod($path, 0o640);
        }

        return $outcome;
    }

    /**
     * Is $key already declared, anywhere, even commented out?
     *
     * A COMMENTED key counts as present, and that is the important case. The
     * plugin-enable step writes optional variables as `# KEY=`, so a naive
     * "is there an active KEY=" test would append a second, active line — and
     * `.env` parsers take the last one, so the file would then disagree with
     * what a reader scrolling from the top sees.
     */
    private static function hasKey(string $contents, string $key): bool
    {
        $pattern = '/^[ \t]*#?[ \t]*(export[ \t]+)?' . preg_quote($key, '/') . '[ \t]*=/m';

        return preg_match($pattern, $contents) === 1;
    }

    /**
     * The value currently assigned to $key, unquoted, or null.
     *
     * The LAST active assignment wins, because that is what every `.env`
     * parser does — reading the first would make this report a value the
     * application never sees. Commented lines are skipped: a `# KEY=` counts as
     * present for the purpose of not appending, but it assigns nothing.
     */
    private static function valueOf(string $contents, string $key): ?string
    {
        $pattern = '/^[ \t]*(?:export[ \t]+)?' . preg_quote($key, '/') . '[ \t]*=(.*)$/m';

        if (preg_match_all($pattern, $contents, $matches) === 0) {
            return null;
        }

        $raw = trim((string) end($matches[1]));

        if ($raw === '') {
            return '';
        }

        if (
            (str_starts_with($raw, '"') && str_ends_with($raw, '"') && \strlen($raw) >= 2)
            || (str_starts_with($raw, "'") && str_ends_with($raw, "'") && \strlen($raw) >= 2)
        ) {
            $inner = substr($raw, 1, -1);

            return $raw[0] === '"'
                ? str_replace(['\\"', '\\\\', '\\$'], ['"', '\\', '$'], $inner)
                : $inner;
        }

        // An unquoted value ends at an inline comment, which is exactly why
        // anything containing a `#` is written quoted in the first place.
        $hash = strpos($raw, ' #');

        return $hash === false ? $raw : rtrim(substr($raw, 0, $hash));
    }

    /**
     * Quote only when the value needs it.
     *
     * A generated password is base64url — `A-Z a-z 0-9 - _` — so it never
     * needs quoting, and leaving it bare keeps the file readable. A URL
     * containing that password does not need quoting either. Anything with
     * whitespace, a `#` or a quote gets double quotes, because an unquoted `#`
     * starts a comment and would silently truncate the value at the point a
     * password happened to contain one.
     */
    private static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9._:\/@+-]+$/', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value) . '"';
    }
}
