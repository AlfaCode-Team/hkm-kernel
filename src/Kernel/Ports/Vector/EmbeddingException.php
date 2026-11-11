<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * An embedding provider failed, or answered with something unusable.
 *
 * Thrown for operational failure only. A caller error — empty text, too many
 * inputs — is an `\InvalidArgumentException` raised before any provider is
 * asked, so it costs nothing and is never retried.
 *
 * The message and `$operation` must never carry an API key, an Authorization
 * header or a request body; a provider adapter holds a credential and an
 * exception message ends up in logs.
 */
class EmbeddingException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $operation = 'embed',
        public readonly ?int $status = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
