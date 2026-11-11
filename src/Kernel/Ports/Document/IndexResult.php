<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document;

/**
 * The outcome of one {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DocumentStorePort::ensureIndex()}
 * call, with enough detail to act on.
 *
 * {@see IndexOutcome} says WHAT happened; this says what it happened to, and on
 * a {@see IndexOutcome::Conflict} what the difference was. The detail is the
 * part a migration runner needs: "the index differs" is not actionable, while
 * "existing index is not unique, requested unique" tells an operator exactly
 * which index to drop.
 */
final class IndexResult
{
    private function __construct(
        public readonly IndexOutcome $outcome,
        public readonly string $name,
        public readonly string $detail,
    ) {}

    /** The index was built and is ready. */
    public static function created(string $name): self
    {
        return new self(IndexOutcome::Created, $name, '');
    }

    /**
     * The index was accepted and is still building.
     *
     * @param string $detail optionally the store's own handle for the build —
     *                       Firestore returns a long-running operation name,
     *                       which is the only way to ask whether it finished
     */
    public static function building(string $name, string $detail = ''): self
    {
        return new self(IndexOutcome::Building, $name, $detail);
    }

    /** An identical index was already present. */
    public static function alreadyExists(string $name): self
    {
        return new self(IndexOutcome::AlreadyExists, $name, '');
    }

    /**
     * An index of this name or key exists and DIFFERS; nothing was changed.
     *
     * $detail must say how it differs, because the remedy — dropping an index
     * in production — is not something anybody should do on a bare "conflict".
     */
    public static function conflict(string $name, string $detail): self
    {
        if ($detail === '') {
            throw new \InvalidArgumentException(
                'An index conflict must explain what differs: the caller has to decide whether to '
                . 'drop and rebuild, and cannot do that from the word "conflict" alone.',
            );
        }

        return new self(IndexOutcome::Conflict, $name, $detail);
    }

    /** Whether the store now matches the definition. False only on a conflict. */
    public function isSatisfied(): bool
    {
        return $this->outcome->matchesDefinition();
    }

    /** A line for a migration log. */
    public function describe(): string
    {
        return $this->name . ': ' . $this->outcome->value
            . ($this->detail !== '' ? ' (' . $this->detail . ')' : '');
    }
}
