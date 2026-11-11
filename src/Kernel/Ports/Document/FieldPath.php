<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document;

/**
 * Validation for a dotted field path — `status`, `address.city`, `meta.a.b`.
 *
 * One place, because a path is checked by {@see DocumentFilter},
 * {@see DocumentQuery} ordering and {@see DocumentIndex}, and three copies of a
 * rule drift.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY A DOT IS A SEPARATOR AND NEVER PART OF A NAME
 * ────────────────────────────────────────────────────────────────────────────
 * Both stores address nested fields with dots, so `address.city` has one
 * meaning everywhere. What differs is whether a FIELD may itself be named
 * `address.city`: MongoDB has permitted dots in field names since 4.0, and
 * Firestore's own escaping (backticks in a field reference) exists precisely so
 * such a name can be addressed at all.
 *
 * This port forbids it. A store holding both a field named `a.b` and a nested
 * `b` under `a` has two different values addressed by one string, and which one
 * a query means becomes a property of the adapter rather than of the query.
 * Refusing the name costs nothing real and removes the ambiguity entirely.
 */
final class FieldPath
{
    /**
     * Firestore's documented maximum for a field name and for a field path,
     * both 1,500 bytes. Checked in BYTES, not characters: the limit is a byte
     * limit, and a path of accented or CJK characters passes a character count
     * while failing the real one.
     */
    public const MAX_BYTES = 1500;

    /**
     * Firestore's documented maximum depth of fields within a map or array.
     * A path of 21 segments cannot be stored, so it cannot be queried.
     */
    public const MAX_DEPTH = 20;

    private function __construct() {}

    /**
     * @throws \InvalidArgumentException when $path could not address a field on
     *         every supported store
     */
    public static function assertValid(string $path, string $what = 'field path'): void
    {
        if ($path === '') {
            throw new \InvalidArgumentException("A {$what} cannot be empty.");
        }

        if (\strlen($path) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(\sprintf(
                'A %s may be at most %d bytes; %s is %d. Firestore rejects longer names outright, '
                . 'so this would store on MongoDB and fail on Firestore.',
                $what,
                self::MAX_BYTES,
                self::preview($path),
                \strlen($path),
            ));
        }

        $segments = explode('.', $path);

        if (\count($segments) > self::MAX_DEPTH) {
            throw new \InvalidArgumentException(\sprintf(
                'A %s may be at most %d segments deep; %s has %d.',
                $what,
                self::MAX_DEPTH,
                self::preview($path),
                \count($segments),
            ));
        }

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new \InvalidArgumentException(\sprintf(
                    'A %s has an empty segment: %s. A leading, trailing or doubled dot is almost '
                    . 'always a concatenation bug, and both stores would read it as a field with no name.',
                    $what,
                    self::preview($path),
                ));
            }

            // A `$` prefix is how MongoDB marks an operator. A field path
            // starting with one is read as an operator by the Mongo adapter and
            // as an ordinary name by the Firestore one, which is the same class
            // of divergence this whole port exists to remove.
            if (str_starts_with($segment, '$')) {
                throw new \InvalidArgumentException(\sprintf(
                    'A %s segment may not start with "$": %s. MongoDB reads a leading "$" as an '
                    . 'operator, so this path means different things on different stores.',
                    $what,
                    self::preview($path),
                ));
            }

            if (str_contains($segment, "\0")) {
                throw new \InvalidArgumentException(
                    "A {$what} may not contain a NUL byte.",
                );
            }
        }
    }

    /** The number of segments in a path. `address.city` is 2. */
    public static function depth(string $path): int
    {
        return \count(explode('.', $path));
    }

    /**
     * A bounded, printable form of a path for an exception message.
     *
     * A field path can be 1,500 bytes and can arrive from user input, so it is
     * never interpolated raw into a message that will land in a log.
     */
    private static function preview(string $path): string
    {
        $safe = preg_replace('/[^\x20-\x7E]/', '?', $path) ?? '';

        return \strlen($safe) > 64
            ? '"' . substr($safe, 0, 61) . '..."'
            : '"' . $safe . '"';
    }
}
