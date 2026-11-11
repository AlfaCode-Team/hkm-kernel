<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * FileSanitizerPort — makes uploaded bytes safe to store and later serve, or
 * refuses them.
 *
 * WHY THIS EXISTS
 * ---------------
 * MIME detection is not a safety check, and treating it as one is the gap this
 * closes. A storage adapter can `finfo` an upload and get `image/svg+xml` back,
 * and be completely correct — because an SVG containing `<script>` IS an SVG.
 * Serve it from your own origin and it runs with your origin's privileges: it
 * reads the session cookie of whoever opens it. An avatar becomes account
 * takeover, and nothing about the content type was wrong.
 *
 * The same shape recurs beyond SVG: HTML uploads, XML with an external entity
 * reference, an office document with a macro. In each case the file is a valid
 * instance of a type the application legitimately accepts, and the danger is
 * inside it.
 *
 * WHY A KERNEL PORT AND NOT A PLUGIN CONTRACT
 * -------------------------------------------
 * Several unrelated capabilities accept uploads — object storage, a file
 * manager, image processing, a post editor, document archives. If this contract
 * lived in whichever plugin happened to implement it first, every one of those
 * would import that plugin's internals and carry a hard dependency on it. The
 * kernel declares the contract for the same reason it declares
 * {@see StoragePort}: so consumers type against the interface and a project
 * chooses the implementation. Implementations are always plugins.
 *
 * A CONSUMER MUST WORK WITHOUT ONE BOUND. Not every deployment accepts
 * untrusted uploads, so this port is optional like {@see RangeReadableStorage}.
 * But a consumer that stores files a DIFFERENT user can later download should
 * treat an unbound sanitiser as a deployment error and say so loudly, rather
 * than storing hostile bytes quietly.
 *
 * WHAT THIS IS NOT
 * ----------------
 * Not a malware scanner — that is {@see FileScannerPort}, which is a different
 * shape of problem: it needs a daemon, a signature database and a retry, and it
 * answers about a file on disk rather than rewriting bytes in memory. A
 * deployment handling untrusted uploads wants BOTH: this one synchronously on
 * write, the scanner asynchronously after it.
 */
interface FileSanitizerPort
{
    /**
     * Whether this sanitiser has an opinion about the given content type.
     *
     * A caller asks first so it can tell "inspected and safe" apart from "never
     * inspected" — the distinction an audit trail needs, and the one that makes
     * an unhandled-but-dangerous type visible rather than silently allowed.
     *
     * Match on the bare type: callers strip any `; charset=` parameter before
     * asking, and an implementation should not depend on receiving one.
     */
    public function handles(string $mimeType): bool;

    /**
     * Inspect the bytes and return what is safe to store.
     *
     * MUST NOT trust $mimeType. It is a hint from the caller's own detection,
     * useful for choosing a strategy, and an implementation that rewrites XML
     * because the hint said XML — without confirming the bytes are XML — can be
     * steered by whoever set the hint.
     *
     * For a type {@see self::handles()} returns false for, return
     * {@see SanitizedFile::unchanged()}: "no opinion" is not "unsafe", and
     * refusing every unknown type would reject ordinary JPEGs.
     *
     * MUST NOT throw for hostile input — that is what
     * {@see SanitizedFile::rejected()} is for, and this path meets hostile input
     * as a matter of routine. Throwing is reserved for a genuine infrastructure
     * failure, where the caller cannot know whether the file is safe and must
     * therefore refuse the upload rather than proceed.
     */
    public function sanitize(string $contents, string $mimeType): SanitizedFile;
}
