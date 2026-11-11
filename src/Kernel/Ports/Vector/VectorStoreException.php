<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * A vector store refused or failed an operation.
 *
 * Not one of the kernel's layered exceptions, for the reason
 * {@see Document\DocumentStoreException} gives: a port is not a layer, and
 * translating to the caller's own exception is the caller's job.
 */
class VectorStoreException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $operation = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unsupported(VectorStoreCapability $capability, string $adapter, string $operation = ''): self
    {
        return new self(
            \sprintf(
                'This vector store does not support %s, so the request was refused rather than '
                . 'silently approximated: %s cannot honour it.',
                $capability->label(),
                $adapter,
            ),
            $operation,
        );
    }

    public static function collectionMissing(string $collection, string $operation = ''): self
    {
        return new self(
            \sprintf('Vector collection "%s" does not exist. Call ensureCollection() first.', $collection),
            $operation,
        );
    }
}
