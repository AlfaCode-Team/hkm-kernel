<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * Something a vector store may or may not be able to do.
 *
 * A capability exists only where the alternative to refusing is a LIE. A store
 * with no exact search cannot honour {@see VectorQuery::exact()}; answering with
 * an approximate result anyway hands the caller a recall guarantee the store
 * never made, so the adapter must say it cannot, not quietly degrade.
 */
enum VectorStoreCapability: string
{
    /** `VectorQuery::exact()` — a search that is not allowed to approximate. */
    case ExactSearch = 'exact_search';

    public function label(): string
    {
        return match ($this) {
            self::ExactSearch => 'exact (non-approximate) search',
        };
    }
}
