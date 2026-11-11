# File safety & resumable uploads

Three optional contracts around user-supplied files: sanitising them on the way in, scanning them afterwards, and receiving a large one across several requests.

## Two ports, because two questions

`FileSanitizerPort` and `FileScannerPort` are separate contracts with separate costs.

A **sanitiser** rewrites bytes in memory, synchronously, on the way in, and knows the answer itself. A **scanner** asks a daemon with a signature database updated daily, takes long enough that it does not belong in a request, and can be unreachable.

## FileSanitizerPort

```php
interface FileSanitizerPort
{
    public function handles(string $mimeType): bool;
    public function sanitize(string $contents, string $mimeType): SanitizedFile;
}
```

`SanitizedFile` reports what happened rather than returning a bare string:

```php
SanitizedFile::unchanged($contents);          // nothing needed removing
SanitizedFile::cleaned($contents, $removed);  // rewritten; $removed names what went
SanitizedFile::rejected($reason);             // cannot be made safe

$file->isRejected();   $file->wasModified();
$file->contents();     $file->removed();     $file->reason();
```

Check `handles()` first — a sanitiser that does not understand a type must not be asked to guess at it.

Adapter: `hkm-plugin-upload-guard`.

## FileScannerPort

```php
interface FileScannerPort
{
    public function scan(string $path): ScanVerdict;
}
```

### This is the one port that must not fail open

[`WafLayer`](/security/waf) lets a request through when its own cache breaks, because a firewall that takes the site down has become the attack. **The trade here runs the other way.** Withholding a file until it can be scanned costs a delay; serving an unscanned one distributes malware under the application's own domain.

### Three outcomes, not two

```php
ScanVerdict::clean();
ScanVerdict::infected($threat);
ScanVerdict::unavailable($reason);   // the one that matters operationally
```

A scanner is a separate daemon that can be down, overloaded or mid-restart. Both tempting ways to collapse that into the pair are wrong:

- Treat an outage as **clean**, and a daemon restart becomes an open door.
- Treat an outage as **infected**, and that same restart quarantines every upload the business took during it.

So an outage is neither. It maps to `ScanStatus::Pending` — the file is withheld, the next retry scans it, and the reason is logged. The caller does not have to decide; `ScanVerdict::status()` already has.

```php
enum ScanStatus { case Pending; case Clean; case Infected; case Skipped; }
```

`ScanStatus::isServable()` reports `Pending` as **not** servable. That is the posture this port recommends and the default a new gate should take — it is a recommendation, not a guarantee about existing consumers. A gate is free to serve `Pending`, and one already does: `hkm-plugin-file-manager` gates only on `infected`, because its `scan_status` column shipped before any scanner existed and withholding `Pending` would have broken every deployment that set `scan_required` with no scanner installed.

**Read the gate in the consumer; do not infer it from the port.**

Adapter: `hkm-plugin-clamav`.

## ResumableStorage

An optional capability on `StoragePort`, checked with `instanceof`.

```php
interface ResumableStorage
{
    public function createUpload(
        string $filename,
        string $path = '',
        ?int $totalSize = null,
        string $visibility = 'private',
    ): string;

    public function appendChunk(string $uploadId, int $offset, string $chunk): int;
    public function uploadedBytes(string $uploadId): int;
    public function completeUpload(string $uploadId): string;
    public function abortUpload(string $uploadId): void;
}
```

`StoragePort::storeStream()` is one request: the whole object travels in one connection, so a phone that loses signal at 90% of a 2 GB video starts again from zero — and a request that long has to outlive every proxy timeout on the path.

### Why it is a separate interface

The same reasoning as `RangeReadableStorage`. `StoragePort` is implemented by the real adapters **and** by in-memory fakes across plugin and project test suites, and an abstract method would break every one of them for a capability most have no opinion about.

**A consumer must still work without it.** When the bound port does not implement `ResumableStorage`, accept the whole object in one request and call `storeStream()`.

### What an adapter must guarantee

1. **Nothing is visible until `completeUpload()`.** Until then a read of the final path sees whatever was there before, or nothing. A half-uploaded file must never be served as if it were the object.
2. **Chunks are addressed by byte offset and applied in order.** `appendChunk()` accepts a chunk only at the offset equal to what is already received. A chunk beyond it — a gap — is refused rather than zero-filled.
3. **Assembly is the adapter's business.** A local disk appends to one file; S3 turns each chunk into a multipart `Part` and stitches them server-side.

::: warning Why that third point matters
A consumer that wrote its own chunk handling got one of those right and the other quietly wrong: S3 refuses parts below the minimum size only at the very end, after every chunk has already been sent.
:::

Adapter: `hkm-plugin-storage`.
