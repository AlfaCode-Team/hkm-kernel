<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document;

/**
 * Validation for a collection name.
 *
 * Shared by {@see DocumentQuery} and {@see DocumentIndex} because both name a
 * collection and the rule must be the same in both. It was briefly only in the
 * query, which meant an index could be declared on a collection a query could
 * never address — a migration that creates something unreachable, reported as a
 * success.
 */
final class CollectionName
{
    /**
     * A conservative ceiling.
     *
     * MongoDB's documented limit is on the full NAMESPACE — "255 bytes" for
     * `<database>.<collection>` — so the collection alone has no published
     * figure to check against, and Firestore's own limit is on a document name
     * rather than a collection id. This bound therefore exists to turn a
     * concatenation accident into a message, not to mirror a documented rule: a
     * name anywhere near this long is a bug rather than a schema.
     */
    public const MAX_BYTES = 255;

    private function __construct() {}

    /**
     * @phpstan-assert non-empty-string $name
     * @throws \InvalidArgumentException when $name could not address a
     *         collection on every supported store
     */
    public static function assertValid(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('A collection name cannot be empty.');
        }

        if (\strlen($name) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(\sprintf(
                'A collection name may be at most %d bytes; this one is %d.',
                self::MAX_BYTES,
                \strlen($name),
            ));
        }

        if (str_contains($name, '/')) {
            throw new \InvalidArgumentException(\sprintf(
                'A collection name may not contain "/": "%s". Firestore reads a slash as a path '
                . 'separator between collections and documents, so this would address something '
                . 'other than a collection.',
                $name,
            ));
        }

        // MongoDB: a collection name "cannot contain the $ character" or a NUL.
        if (str_contains($name, '$') || str_contains($name, "\0")) {
            throw new \InvalidArgumentException(\sprintf(
                'A collection name may not contain "$" or a NUL byte: "%s".',
                $name,
            ));
        }

        if ($name === '.' || $name === '..') {
            throw new \InvalidArgumentException('A collection may not be named "." or "..".');
        }

        // MongoDB reserves the `system.` prefix for its own collections.
        if (str_starts_with($name, 'system.')) {
            throw new \InvalidArgumentException(\sprintf(
                'The "system." prefix is reserved by MongoDB: "%s".',
                $name,
            ));
        }

        // Refused as a precaution rather than because both stores document the
        // same reservation: `__name__` is how Firestore addresses a document id
        // inside a query, and a collection whose name takes that shape is at
        // best confusing to read in one.
        if (preg_match('/^__.*__$/', $name) === 1) {
            throw new \InvalidArgumentException(\sprintf(
                'A collection name of the form __name__ is reserved: "%s".',
                $name,
            ));
        }
    }
}
