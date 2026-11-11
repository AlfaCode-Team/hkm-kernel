<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\EmbeddingException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\Vector;

/**
 * EmbeddingPort — text in, {@see Vector} out. Nothing else.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY IT IS THIS NARROW
 * ────────────────────────────────────────────────────────────────────────────
 * It answers one question — "what is the vector for this text, under THIS
 * model?" — and takes no position on what is done with the answer. It is not a
 * model abstraction, has no notion of a prompt, a tool, a step or a token
 * budget, and does not choose providers. Anything richer than "embed" belongs
 * to a toolkit built on top of it, and is decided there; widening this port now
 * would make that decision by accident.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT AN ADAPTER MUST DO
 * ────────────────────────────────────────────────────────────────────────────
 * 1. REFUSE BLANK INPUT before any provider is asked. A provider may reject an
 *    empty string, accept it and return a meaningless vector, or bill for it;
 *    only the first is safe. `trim($text) === ''` throws
 *    `\InvalidArgumentException`. (That empty input is rejected by OpenAI's
 *    embeddings endpoint is recalled from its documentation and was not
 *    checked here; the port takes the strict reading regardless.)
 * 2. RETURN VECTORS IN INPUT ORDER, exactly one per input, each of
 *    {@see self::dimensions()} length. A provider that answers with a different
 *    count or length has failed — throw {@see EmbeddingException}; never pad,
 *    truncate or reorder.
 * 3. NEVER LEAK THE CREDENTIAL. An adapter holds an API key. The key must not
 *    appear in an exception message, a log line, or {@see self::model()}.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE MODEL IS PART OF THE ANSWER
 * ────────────────────────────────────────────────────────────────────────────
 * Vectors from two models are not comparable even when they have the same
 * length. {@see self::model()} is the identifier a cache or a collection should
 * key on, and it must change whenever the vectors would — including a provider
 * silently re-pointing an alias, which is why an adapter should prefer a
 * pinned name to `latest`.
 *
 * Caching is not part of this port. An implementation may cache (the content
 * hash of `model() . dimensions() . text` is the key); a caller must not be able
 * to tell.
 */
interface EmbeddingPort
{
    /**
     * @throws \InvalidArgumentException blank text
     * @throws EmbeddingException        the provider failed or answered unusably
     */
    public function embed(string $text): Vector;

    /**
     * Embed many texts, in order. One provider round trip per batch the adapter
     * chooses, never one per text.
     *
     * An empty list returns an empty list and asks nobody anything.
     *
     * @param list<string> $texts
     * @return list<Vector> same length and order as $texts
     * @throws \InvalidArgumentException any blank text, or a non-list
     * @throws EmbeddingException
     */
    public function embedMany(array $texts): array;

    /** The length of every vector this adapter returns. */
    public function dimensions(): int;

    /** A stable identifier for the model — and never a credential. */
    public function model(): string;
}
