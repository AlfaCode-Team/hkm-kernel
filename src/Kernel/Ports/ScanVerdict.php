<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * The result of one {@see FileScannerPort::scan()} call.
 *
 * A verdict rather than a thrown exception, for the same reason
 * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityLayerContract}
 * returns one: this code path sees hostile input as a matter of routine, and
 * routine outcomes are not exceptions. An infected file is a normal answer.
 *
 * THREE OUTCOMES, NOT TWO. `clean` and `infected` are the obvious pair; the
 * third, `unavailable`, is the one that matters operationally. A scanner is a
 * separate daemon that can be down, overloaded, or mid-restart, and the two
 * tempting ways to collapse that into the pair are both wrong:
 *
 *   - treat an outage as CLEAN and a clamd restart becomes an open door;
 *   - treat an outage as INFECTED and a clamd restart quarantines every
 *     upload the business took during it.
 *
 * So an outage is neither. It maps to {@see ScanStatus::Pending} — the file is
 * withheld from download and the next retry scans it — and carries a reason for
 * the log. The caller does not have to decide; {@see self::status()} already has.
 */
final class ScanVerdict
{
    private function __construct(
        private readonly ScanStatus $status,
        private readonly string $threat,
        private readonly string $reason,
        private readonly bool $concluded,
    ) {}

    /** The scanner ran and found nothing. */
    public static function clean(): self
    {
        return new self(ScanStatus::Clean, '', '', true);
    }

    /**
     * The scanner ran and found a threat.
     *
     * @param string $threat the scanner's own signature name, for the audit
     *                       trail. Never shown to the uploader: naming the
     *                       signature tells someone probing which payloads get
     *                       through, the same reason a WAF 403 does not name
     *                       the rule that fired.
     */
    public static function infected(string $threat): self
    {
        return new self(ScanStatus::Infected, $threat, '', true);
    }

    /**
     * The scanner could not be reached or did not answer.
     *
     * NOT a safety judgement — nothing was learned about the file. It stays
     * Pending so it is withheld and re-scanned later.
     */
    public static function unavailable(string $reason): self
    {
        return new self(ScanStatus::Pending, '', $reason, false);
    }

    /** The status to persist. Safe to write straight to a `scan_status` column. */
    public function status(): ScanStatus { return $this->status; }

    /** Whether the scanner actually reached a decision about this file. */
    public function concluded(): bool { return $this->concluded; }

    public function isClean(): bool { return $this->status === ScanStatus::Clean; }
    public function isInfected(): bool { return $this->status === ScanStatus::Infected; }
    public function isUnavailable(): bool { return !$this->concluded; }

    /** Signature name on an infected verdict, otherwise ''. For logs and audit only. */
    public function threat(): string { return $this->threat; }

    /** Why the scan did not conclude, otherwise ''. For logs only. */
    public function reason(): string { return $this->reason; }
}
