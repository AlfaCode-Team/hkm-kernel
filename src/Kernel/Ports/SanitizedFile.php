<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * The result of one {@see FileSanitizerPort::sanitize()} call.
 *
 * Three outcomes, because "safe" and "unsafe" are not the whole story:
 *
 *   - UNCHANGED — the file was already safe. The common case; no copy is made.
 *   - CLEANED   — the file contained something dangerous that could be REMOVED,
 *                 and {@see self::contents()} is the version safe to store.
 *                 {@see self::removed()} names what went, because "we silently
 *                 altered the file a user uploaded" is a thing an audit trail
 *                 has to be able to answer for.
 *   - REJECTED  — the file cannot be made safe and must not be stored at all.
 *
 * A verdict object rather than a `?string` return, because the three cases are
 * genuinely distinct and the two that are easy to conflate are the dangerous
 * ones: a caller that treats CLEANED as UNCHANGED stores the original bytes it
 * was just handed a replacement for, which is the exact bug the sanitiser
 * existed to prevent. Making `contents()` unavailable on a rejection, and
 * distinct from the input on a clean, removes both mistakes.
 */
final class SanitizedFile
{
    /** @param list<string> $removed */
    private function __construct(
        private readonly ?string $contents,
        private readonly array $removed,
        private readonly string $reason,
        private readonly bool $modified,
    ) {}

    /** Already safe. Store the bytes you already have. */
    public static function unchanged(string $contents): self
    {
        return new self($contents, [], '', false);
    }

    /**
     * Made safe by removing something. STORE {@see self::contents()}, not the input.
     *
     * @param list<string> $removed short descriptions of what was stripped,
     *                              e.g. ['script element', 'onload attribute']
     */
    public static function cleaned(string $contents, array $removed): self
    {
        return new self($contents, array_values($removed), '', true);
    }

    /** Cannot be made safe. Do not store it; reject the upload. */
    public static function rejected(string $reason): self
    {
        return new self(null, [], $reason, false);
    }

    public function isRejected(): bool { return $this->contents === null; }

    /** Whether the bytes differ from the input — true only for {@see self::cleaned()}. */
    public function wasModified(): bool { return $this->modified; }

    /**
     * The bytes that are safe to store.
     *
     * @throws \LogicException when the file was rejected. Asking a rejection for
     *         its contents is a programming error, not a runtime condition:
     *         there are no safe bytes to return, and silently handing back the
     *         original would defeat the whole port.
     */
    public function contents(): string
    {
        if ($this->contents === null) {
            throw new \LogicException(
                'This file was rejected and has no safe contents. '
                . 'Check isRejected() before calling contents(). Reason: ' . $this->reason
            );
        }

        return $this->contents;
    }

    /**
     * What was stripped, for the audit trail. Empty unless {@see self::wasModified()}.
     *
     * @return list<string>
     */
    public function removed(): array { return $this->removed; }

    /** Why the file was rejected, otherwise ''. Safe to show to the uploader. */
    public function reason(): string { return $this->reason; }
}
