<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document;

/**
 * One document: its id, and its fields.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY THE ID IS NOT A FIELD
 * ────────────────────────────────────────────────────────────────────────────
 * This is the one place the two stores disagree about something unavoidable.
 *
 * In MongoDB the id IS a field — `_id` is part of the document, returned by
 * every query, and a document can be written with any `_id` value including a
 * nested one. In Firestore the id is part of the document's NAME, not its data;
 * a document may happily also contain a field called `_id`, holding something
 * else entirely.
 *
 * Returning flat arrays would force a choice between two bad options: leave the
 * id out, and every caller has to pass it alongside; or merge it in under a
 * reserved key, and a Firestore document that has its own `_id` field silently
 * loses it on the way out. Keeping them in separate properties makes the
 * question disappear — there is nowhere for the two to collide.
 */
final class DocumentRecord
{
    /**
     * @param non-empty-string     $id
     * @param array<string, mixed> $data the document's fields, WITHOUT the id
     */
    public function __construct(
        public readonly string $id,
        public readonly array $data,
    ) {}

    /**
     * A field by dotted path, or $default when it is absent.
     *
     * Absent and null are NOT distinguished here — both answer $default. In a
     * store with no schema that is almost always what a caller means, and a
     * caller who needs the difference should read {@see self::has()} first.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $cursor = $this->data;

        foreach (explode('.', $path) as $segment) {
            if (!\is_array($cursor) || !\array_key_exists($segment, $cursor)) {
                return $default;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor ?? $default;
    }

    /** Whether a dotted path is present, even if its value is null. */
    public function has(string $path): bool
    {
        $cursor = $this->data;

        foreach (explode('.', $path) as $segment) {
            if (!\is_array($cursor) || !\array_key_exists($segment, $cursor)) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }

    /**
     * The document as one array, with the id under $idKey.
     *
     * For handing to a view or a JSON response. Not for writing back — see the
     * class docblock for why the id does not belong among the fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(string $idKey = 'id'): array
    {
        return [$idKey => $this->id] + $this->data;
    }
}
