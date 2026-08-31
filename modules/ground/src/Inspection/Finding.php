<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Inspection;

/** One thing the inspector found, with enough context to act on it. */
final readonly class Finding
{
    public const ERROR   = 'error';
    public const WARNING = 'warning';
    public const NOTE    = 'note';

    public function __construct(
        public string $severity,
        /** Stable slug, e.g. 'manifest.solves-drift' — greppable, and safe to allow-list. */
        public string $code,
        public string $message,
        /** What to do about it. Empty when the message already says. */
        public string $fix = '',
        /** Absolute path, when the finding belongs to one file. */
        public string $file = '',
    ) {}

    public static function error(string $code, string $message, string $fix = '', string $file = ''): self
    {
        return new self(self::ERROR, $code, $message, $fix, $file);
    }

    public static function warning(string $code, string $message, string $fix = '', string $file = ''): self
    {
        return new self(self::WARNING, $code, $message, $fix, $file);
    }

    public static function note(string $code, string $message, string $fix = '', string $file = ''): self
    {
        return new self(self::NOTE, $code, $message, $fix, $file);
    }

    /** Errors are what break a boot or a request. Warnings are drift. */
    public function blocking(): bool
    {
        return $this->severity === self::ERROR;
    }
}
