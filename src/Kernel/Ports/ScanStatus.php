<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * The persisted outcome of a malware scan.
 *
 * An enum rather than bare strings so a status cannot be misspelled into a value
 * no download gate recognises — and a gate that does not recognise a status
 * fails OPEN, which is the one direction this must never fail.
 *
 * The backed values are EXACTLY the four a consumer already stores, and the
 * column they go in is ten characters wide. Do not add a case whose value is
 * longer than that without widening the column first: a truncated status is a
 * status no gate matches.
 *
 * There is deliberately no `Unavailable` case. A scan that could not be
 * performed — the scanner is down, the socket timed out — has not concluded,
 * which is precisely {@see self::Pending}: the file is withheld from download
 * and the next retry scans it. Inventing a fifth state for it would be an
 * eleven-character value in a ten-character column, and a state every gate
 * would then have to be taught about.
 */
enum ScanStatus: string
{
    /** Queued or inconclusive — not yet known to be safe. Withhold the file. */
    case Pending = 'pending';

    /** Scanned, nothing found. */
    case Clean = 'clean';

    /** Scanned, a threat was found. Never serve the file. */
    case Infected = 'infected';

    /** Scanning is not required for this file. */
    case Skipped = 'skipped';

    /**
     * Whether a file in this state may be served to a client.
     *
     * Only an explicit Clean or Skipped is servable. Everything else — including
     * any case added later — is withheld, so the default for an unknown state is
     * to deny rather than to serve.
     */
    public function isServable(): bool
    {
        return match ($this) {
            self::Clean, self::Skipped => true,
            self::Pending, self::Infected => false,
        };
    }
}
