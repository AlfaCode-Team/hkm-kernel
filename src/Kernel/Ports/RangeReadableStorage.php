<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * RangeReadableStorage — a StoragePort that can report an object's size and
 * open part of it.
 *
 * WHY THIS EXISTS
 * ---------------
 * `StoragePort::readStream()` is the only read the port declared, and what it
 * returns depends entirely on the adapter. A local file handle can be `fstat`ed
 * and `fseek`ed; the body of an S3 GetObject is a network stream that can do
 * neither. A consumer serving a document with `Range` support (a PDF reader
 * paging through a large file, a video player) therefore worked on the local
 * driver and silently fell back to sending the whole object on S3 — the same
 * code, a different deployment, a different bandwidth bill.
 *
 * Both answers it needs are cheap on every real backend: the size is a stat or
 * a HEAD request, and a range is a seek or a `Range:` GET. They were missing
 * only from the contract.
 *
 * WHY THIS IS A SEPARATE INTERFACE AND NOT A StoragePort METHOD
 * -------------------------------------------------------------
 * Same reasoning as {@see DriverAware}: `StoragePort` is implemented by the real
 * adapters AND by in-memory fakes across plugin and project test suites. An
 * abstract method on the port would break every one of them for a capability
 * most have no opinion about. It is OPTIONAL, checked with `instanceof`.
 *
 * A CONSUMER MUST STILL WORK WITHOUT IT. When the bound port does not implement
 * this, fall back to `readStream()` and serve the whole object.
 */
interface RangeReadableStorage
{
    /**
     * Byte length of a stored object, without reading its contents.
     *
     * @throws \RuntimeException when the object does not exist or cannot be stat'ed
     */
    public function size(string $path): int;

    /**
     * Open the bytes [$offset, $offset + $length) of a stored object.
     *
     * The returned stream's first read yields the byte at $offset. It is NOT
     * guaranteed to end at the range: a local adapter may hand back a file
     * handle that continues to EOF, while an HTTP adapter's body ends exactly at
     * it. A caller must therefore read at most $length bytes itself.
     *
     * $length null means "to the end of the object". The caller owns closing
     * the returned handle.
     *
     * @return resource
     * @throws \InvalidArgumentException when $offset is negative or $length is below 1
     * @throws \RuntimeException when the object does not exist or cannot be opened
     */
    public function readRange(string $path, int $offset, ?int $length = null);
}
