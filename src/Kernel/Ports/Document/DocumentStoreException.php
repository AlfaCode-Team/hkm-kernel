<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document;

/**
 * A document store refused or failed an operation.
 *
 * Deliberately NOT one of the kernel's layered exceptions
 * (`RepositoryException`, `GatewayException`): a port is not a layer, and a
 * document store sits in a different place in different applications — a
 * repository for one module, a gateway for another. Translating it to the
 * layer's own exception is the CALLER's job, exactly as a repository translates
 * `\PDOException` from {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort}.
 *
 * Extends `\RuntimeException` for the same reason
 * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\PubSubPort} throws one:
 * the kernel's own ports do not impose a hierarchy on their adapters.
 */
class DocumentStoreException extends \RuntimeException
{
    /**
     * @param string $operation what was attempted, e.g. 'find' or 'ensureIndex'
     */
    public function __construct(
        string $message,
        public readonly string $operation = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * An adapter was asked for a capability it does not have.
     *
     * A distinct constructor because this is the one failure that is a
     * PROGRAMMING error rather than an operational one: it cannot be retried,
     * it will fail identically forever, and the fix is a different migration or
     * a different store. The message therefore names both the feature and the
     * adapter, since the same migration may be correct against one store and
     * impossible against another.
     */
    public static function unsupported(
        DocumentStoreCapability $capability,
        string $adapter,
        string $operation = '',
    ): self {
        return new self(
            \sprintf(
                'This document store does not support %s, so the request was refused rather than '
                . 'silently skipped: %s cannot enforce it. An unenforced constraint is worse than a '
                . 'missing one, because the application goes on assuming it holds. Either use a store '
                . 'that supports it, or enforce the invariant in the application and drop it from the '
                . 'definition.',
                $capability->label(),
                $adapter,
            ),
            $operation,
        );
    }
}
