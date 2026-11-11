<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * One stored vector: an id, the vector and a flat payload to filter on.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE PAYLOAD IS FOR FILTERING, NOT FOR STORING THE DOCUMENT
 * ────────────────────────────────────────────────────────────────────────────
 * Flat, scalar, and bounded:
 *   • no nesting and no lists — nested path syntax and array-membership
 *     semantics differ between stores, and {@see VectorFilter} has no way to
 *     name either;
 *   • no null — "present but null" and "absent" are different things to
 *     different stores, and rule 1 of {@see VectorFilter} says absent never
 *     matches; a null would force a choice;
 *   • at most {@see self::MAX_PAYLOAD_KEYS} keys and {@see self::MAX_PAYLOAD_BYTES}
 *     bytes of JSON. 32 KiB is the intersection with the tightest documented
 *     store limit this was written against (Pinecone's 40 KB of metadata per
 *     record, recalled from its documentation and NOT checked here). The point is
 *     the same on any of them: the text of a chunk belongs in the table it came
 *     from, and the vector's payload carries the foreign key.
 *
 * Ids are opaque non-empty strings of at most 255 bytes with no NUL.
 */
final readonly class VectorRecord
{
    public const MAX_ID_BYTES      = 255;
    public const MAX_PAYLOAD_KEYS  = 64;
    public const MAX_PAYLOAD_BYTES = 32768;

    /** @param array<string, bool|int|float|string> $payload */
    public function __construct(
        public string $id,
        public Vector $vector,
        public array $payload = [],
    ) {
        self::assertId($id);
        self::assertPayload($payload);
    }

    /** @throws \InvalidArgumentException */
    public static function assertId(string $id): void
    {
        if ($id === '' || \strlen($id) > self::MAX_ID_BYTES || str_contains($id, "\0")) {
            throw new \InvalidArgumentException(\sprintf(
                'A vector id must be 1 to %d bytes with no NUL byte; got %d bytes.',
                self::MAX_ID_BYTES,
                \strlen($id),
            ));
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     * @throws \InvalidArgumentException
     */
    public static function assertPayload(array $payload): void
    {
        if (\count($payload) > self::MAX_PAYLOAD_KEYS) {
            throw new \InvalidArgumentException(\sprintf(
                'A payload holds at most %d keys; %d were given.',
                self::MAX_PAYLOAD_KEYS,
                \count($payload),
            ));
        }

        foreach ($payload as $key => $value) {
            if (\is_string($key) === false) {
                throw new \InvalidArgumentException('A payload is a map of field name to value, not a list.');
            }

            VectorFilter::assertField($key);

            if (\is_float($value) && (is_nan($value) || is_infinite($value))) {
                throw new \InvalidArgumentException(\sprintf('Payload field "%s" is not finite.', $key));
            }

            // jsonb cannot hold U+0000 — measured on PostgreSQL 18.6: `'{"a":"x\u0000y"}'::jsonb`
            // fails with "unsupported Unicode escape sequence". Refused here so the failure is a
            // named field at the call site and not a driver error mid-batch.
            if (\is_string($value) && str_contains($value, "\0")) {
                throw new \InvalidArgumentException(\sprintf(
                    'Payload field "%s" contains a NUL byte, which PostgreSQL jsonb cannot store.',
                    $key,
                ));
            }

            if (!\is_string($value) && !\is_int($value) && !\is_float($value) && !\is_bool($value)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Payload field "%s" is %s. A payload is flat: strings, numbers and booleans only — '
                    . 'no null, no list, no nested object.',
                    $key,
                    get_debug_type($value),
                ));
            }
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('A payload must be valid UTF-8: ' . $e->getMessage(), 0, $e);
        }

        if (\strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new \InvalidArgumentException(\sprintf(
                'A payload may be at most %d bytes of JSON; this one is %d. Keep the text in its own '
                . 'table and store a reference.',
                self::MAX_PAYLOAD_BYTES,
                \strlen($json),
            ));
        }
    }
}
