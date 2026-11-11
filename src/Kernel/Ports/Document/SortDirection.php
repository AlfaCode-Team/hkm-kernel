<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document;

/** The direction of one {@see DocumentQuery} ordering. */
enum SortDirection: string
{
    case Asc  = 'asc';
    case Desc = 'desc';

    public function isDescending(): bool
    {
        return $this === self::Desc;
    }
}
