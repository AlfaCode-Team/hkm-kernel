<?php

declare(strict_types=1);

namespace AlfacodeTeam\Pools\Exception;

/** A {@see \AlfacodeTeam\Pools\Group} was asked for a pool it does not hold. */
final class UnknownPoolException extends \RuntimeException
{
    /** @param list<string> $known */
    public static function named(string $name, array $known): self
    {
        return new self(sprintf(
            'No pool named [%s]. Registered: %s.',
            $name,
            $known === [] ? '(none)' : implode(', ', $known),
        ));
    }
}
