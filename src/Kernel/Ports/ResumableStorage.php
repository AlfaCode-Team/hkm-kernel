<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * ResumableStorage — a StoragePort that can receive one object as a series of
 * chunks, across several requests, and survive an interruption between them.
 *
 * WHY THIS EXISTS
 * ---------------
 * `StoragePort::storeStream()` is one request: the whole object travels in one
 * connection, so a phone that loses signal at 90% of a 2 GB video starts again
 * from zero, and a request that long also has to outlive every proxy timeout on
 * the path. The usual answer is to cut the file into chunks and send them
 * independently. Where the chunks ARE ASSEMBLED depends entirely on the adapter:
 * a local disk appends them to one file, S3 turns each into a multipart `Part`
 * and stitches them server-side. A consumer that wrote its own chunk handling
 * got one of those right and the other quietly wrong (S3 parts below the
 * minimum size are refused only at the very end, when every chunk has already
 * been sent).
 *
 * WHY THIS IS A SEPARATE INTERFACE AND NOT A StoragePort METHOD
 * -------------------------------------------------------------
 * Same reasoning as {@see RangeReadableStorage}: `StoragePort` is implemented by
 * the real adapters AND by in-memory fakes across plugin and project test
 * suites, and an abstract method would break every one of them for a capability
 * most have no opinion about. It is OPTIONAL, checked with `instanceof`.
 *
 * A CONSUMER MUST STILL WORK WITHOUT IT. When the bound port does not implement
 * this, accept the whole object in one request and call `storeStream()`.
 *
 * WHAT AN ADAPTER MUST GUARANTEE
 * ------------------------------
 *  1. NOTHING IS VISIBLE UNTIL completeUpload(). Until then a read of the final
 *     path sees whatever was there before (or nothing). A half-uploaded file
 *     must never be served as if it were the object.
 *  2. CHUNKS ARE ADDRESSED BY BYTE OFFSET AND APPLIED IN ORDER. appendChunk()
 *     accepts a chunk only at the offset equal to what is already received.
 *     A chunk beyond it (a gap) is refused rather than zero-filled; a chunk
 *     that lies entirely inside what is already received is a RETRY of one whose
 *     response was lost, and is acknowledged without being written again. One
 *     that starts inside the received range but runs past it is ambiguous and
 *     is refused; the client resends from the received count.
 *  3. A SESSION THAT IS ABANDONED MUST EXPIRE. An upload nobody finishes holds
 *     disk or bucket space for as long as it exists, and the client that
 *     abandoned it is by definition not going to clean it up. The adapter owns
 *     a time-to-live (refreshed by each accepted chunk) and must release the
 *     chunk data when it lapses, not only forget that the session existed —
 *     forgetting alone leaves the bytes behind with nothing pointing at them.
 *  4. completeUpload() VERIFIES BEFORE IT PUBLISHES. When the total size was
 *     declared at createUpload(), a short or over-long upload is refused and
 *     the session is left intact so the client can send what is missing.
 *  5. THE UPLOAD ID IS NOT AN AUTHORISATION. It is an unguessable handle, and
 *     whoever holds it can append to the object. The consumer decides who may
 *     create, resume or complete an upload and must bind the id to that user;
 *     the adapter cannot know who is asking.
 *
 * Failures are \RuntimeException (the session is unknown or has expired, the
 * backend refused) or \InvalidArgumentException (a gap, an overrun, a bad
 * argument) — the same split StoragePort adapters use.
 */
interface ResumableStorage
{
    /**
     * Open an upload session for the object that will live at $path/$filename
     * (the same arguments, and the same resulting path, as StoragePort::store()).
     *
     * $totalSize is the declared final size in bytes. Pass it whenever the client
     * knows it: it is what lets completeUpload() tell a finished object from a
     * truncated one, and what lets an adapter refuse an oversized upload BEFORE
     * the first byte is sent. null means "unknown"; completeUpload() then trusts
     * the caller to complete only when everything has been sent.
     *
     * Returns an opaque, unguessable upload id.
     *
     * @throws \InvalidArgumentException when $totalSize is below 0 or the filename is unusable
     * @throws \RuntimeException when $totalSize exceeds the adapter's size limit or the backend refused
     */
    public function createUpload(
        string $filename,
        string $path = '',
        ?int $totalSize = null,
        string $visibility = 'private',
    ): string;

    /**
     * Append $chunk, which begins at byte $offset of the object, and return the
     * number of bytes now received (the offset the next chunk must start at).
     *
     * A chunk wholly inside the received range is a retry: nothing is written and
     * the current count is returned. Its CONTENT is not compared with what was
     * stored — a client replaying different bytes at an old offset is not
     * detected, and an end-to-end checksum is the consumer's to verify.
     *
     * Each accepted chunk refreshes the session's time-to-live.
     *
     * @throws \InvalidArgumentException when $offset is ahead of the received count (a gap) or
     *                                   straddles it, when the chunk is empty, or when it runs
     *                                   past the declared total
     * @throws \RuntimeException when the session is unknown or has expired, or the backend refused
     */
    public function appendChunk(string $uploadId, int $offset, string $chunk): int;

    /**
     * How many bytes the session has received — what a client asks after a
     * dropped connection to learn where to resume from.
     *
     * @throws \RuntimeException when the session is unknown or has expired
     */
    public function uploadedBytes(string $uploadId): int;

    /**
     * Assemble the chunks, publish the object atomically and return its path, as
     * StoragePort::store() would. The session and its chunk data are released.
     *
     * @throws \InvalidArgumentException when a declared total size does not match what was received
     * @throws \RuntimeException when the session is unknown or has expired, or the backend refused
     */
    public function completeUpload(string $uploadId): string;

    /**
     * Discard the session and every chunk received so far. Idempotent: aborting
     * an upload that is already gone, expired or completed is not an error.
     */
    public function abortUpload(string $uploadId): void;
}
