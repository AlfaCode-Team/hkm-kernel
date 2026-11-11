<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * The outcome of one `ensureCollection()` / `ensureIndex()` call, with enough
 * detail to act on. See {@see VectorOutcome} for why this is not a boolean.
 */
final class VectorResult
{
    private function __construct(
        public readonly VectorOutcome $outcome,
        public readonly string $name,
        public readonly string $detail,
    ) {}

    public static function created(string $name): self
    {
        return new self(VectorOutcome::Created, $name, '');
    }

    public static function building(string $name, string $detail = ''): self
    {
        return new self(VectorOutcome::Building, $name, $detail);
    }

    public static function alreadyExists(string $name): self
    {
        return new self(VectorOutcome::AlreadyExists, $name, '');
    }

    /**
     * $detail must say how the two differ: the remedy (drop and rebuild, or a new
     * collection and a re-embed) is not something to do on a bare "conflict".
     */
    public static function conflict(string $name, string $detail): self
    {
        if ($detail === '') {
            throw new \InvalidArgumentException(
                'A conflict must explain what differs; the remedy cannot be chosen from the word '
                . '"conflict" alone.',
            );
        }

        return new self(VectorOutcome::Conflict, $name, $detail);
    }

    public function isSatisfied(): bool
    {
        return $this->outcome->matchesDefinition();
    }

    public function describe(): string
    {
        return $this->name . ': ' . $this->outcome->value
            . ($this->detail !== '' ? ' (' . $this->detail . ')' : '');
    }
}
