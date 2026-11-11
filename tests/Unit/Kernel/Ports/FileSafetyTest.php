<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SanitizedFile;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ScanStatus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ScanVerdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The value types behind FileSanitizerPort and FileScannerPort.
 *
 * These carry the decisions a consumer is NOT supposed to have to make — what a
 * failed scan means, whether a status may be served, whether bytes changed — so
 * the tests are mostly about the dangerous-to-conflate pairs rather than about
 * getters.
 */
#[CoversClass(ScanStatus::class)]
#[CoversClass(ScanVerdict::class)]
#[CoversClass(SanitizedFile::class)]
final class FileSafetyTest extends TestCase
{
    // ── ScanStatus ───────────────────────────────────────────────────────────

    /**
     * Only an explicit Clean or Skipped is servable; anything else is withheld.
     */
    #[DataProvider('servableStatuses')]
    public function test_only_concluded_safe_statuses_are_servable(ScanStatus $status, bool $servable): void
    {
        $this->assertSame($servable, $status->isServable());
    }

    /** @return iterable<string, array{ScanStatus, bool}> */
    public static function servableStatuses(): iterable
    {
        yield 'clean is servable'       => [ScanStatus::Clean, true];
        yield 'skipped is servable'     => [ScanStatus::Skipped, true];
        yield 'pending is withheld'     => [ScanStatus::Pending, false];
        yield 'infected is withheld'    => [ScanStatus::Infected, false];
    }

    /**
     * The backed values are persisted in a `scan_status` column declared as
     * string(10). A case whose value exceeds that is silently truncated by the
     * driver, and a truncated status matches no download gate — which fails
     * OPEN. This guards the column width, not the spelling.
     */
    public function test_every_status_value_fits_the_persisted_column(): void
    {
        foreach (ScanStatus::cases() as $case) {
            $this->assertLessThanOrEqual(
                10,
                strlen($case->value),
                "ScanStatus::{$case->name} = '{$case->value}' exceeds the 10-character scan_status column. "
                . 'Widen the column in a migration before adding it.'
            );
        }
    }

    /** The persisted vocabulary is a contract with existing rows — it may grow, never change. */
    public function test_persisted_vocabulary_is_stable(): void
    {
        $this->assertSame(
            ['pending', 'clean', 'infected', 'skipped'],
            array_map(static fn (ScanStatus $c): string => $c->value, ScanStatus::cases()),
        );
    }

    // ── ScanVerdict ──────────────────────────────────────────────────────────

    public function test_clean_verdict_concluded_and_servable(): void
    {
        $verdict = ScanVerdict::clean();

        $this->assertTrue($verdict->isClean());
        $this->assertFalse($verdict->isInfected());
        $this->assertFalse($verdict->isUnavailable());
        $this->assertTrue($verdict->concluded());
        $this->assertSame(ScanStatus::Clean, $verdict->status());
        $this->assertTrue($verdict->status()->isServable());
        $this->assertSame('', $verdict->threat());
    }

    public function test_infected_verdict_carries_the_signature_and_is_withheld(): void
    {
        $verdict = ScanVerdict::infected('Eicar-Test-Signature');

        $this->assertTrue($verdict->isInfected());
        $this->assertFalse($verdict->isClean());
        $this->assertTrue($verdict->concluded());
        $this->assertSame(ScanStatus::Infected, $verdict->status());
        $this->assertFalse($verdict->status()->isServable());
        $this->assertSame('Eicar-Test-Signature', $verdict->threat());
    }

    /**
     * THE load-bearing behaviour. An unreachable scanner learned nothing about
     * the file, so the verdict is neither clean nor infected: it persists as
     * Pending, stays unservable, and a retry will scan it again.
     *
     * Collapsing this into clean() would open the door during a scanner
     * restart; collapsing it into infected() would quarantine every upload
     * taken during one.
     */
    public function test_unavailable_scan_is_pending_not_a_safety_judgement(): void
    {
        $verdict = ScanVerdict::unavailable('clamd socket timeout after 5s');

        $this->assertTrue($verdict->isUnavailable());
        $this->assertFalse($verdict->concluded());
        $this->assertFalse($verdict->isClean());
        $this->assertFalse($verdict->isInfected());
        $this->assertSame(ScanStatus::Pending, $verdict->status());
        $this->assertFalse($verdict->status()->isServable());
        $this->assertSame('clamd socket timeout after 5s', $verdict->reason());
        $this->assertSame('', $verdict->threat());
    }

    // ── SanitizedFile ────────────────────────────────────────────────────────

    public function test_unchanged_file_keeps_its_bytes_and_reports_no_modification(): void
    {
        $file = SanitizedFile::unchanged('<svg></svg>');

        $this->assertFalse($file->isRejected());
        $this->assertFalse($file->wasModified());
        $this->assertSame('<svg></svg>', $file->contents());
        $this->assertSame([], $file->removed());
        $this->assertSame('', $file->reason());
    }

    /**
     * A caller that treats `cleaned` as `unchanged` stores the ORIGINAL bytes it
     * was just handed a replacement for — the exact bug the sanitiser exists to
     * prevent. wasModified() is what makes the two distinguishable.
     */
    public function test_cleaned_file_exposes_new_bytes_and_what_was_removed(): void
    {
        $file = SanitizedFile::cleaned('<svg></svg>', ['script element', 'onload attribute']);

        $this->assertFalse($file->isRejected());
        $this->assertTrue($file->wasModified());
        $this->assertSame('<svg></svg>', $file->contents());
        $this->assertSame(['script element', 'onload attribute'], $file->removed());
    }

    /** removed() is always a list, even when the caller passed a sparse array. */
    public function test_cleaned_file_normalises_removed_to_a_list(): void
    {
        $file = SanitizedFile::cleaned('ok', [3 => 'a', 7 => 'b']);

        $this->assertSame(['a', 'b'], $file->removed());
    }

    public function test_rejected_file_reports_its_reason(): void
    {
        $file = SanitizedFile::rejected('SVG references an external entity');

        $this->assertTrue($file->isRejected());
        $this->assertFalse($file->wasModified());
        $this->assertSame('SVG references an external entity', $file->reason());
    }

    /**
     * Asking a rejection for contents is a programming error, not a runtime
     * condition. Returning the original bytes here would defeat the port
     * entirely, so it throws instead.
     */
    public function test_rejected_file_refuses_to_hand_back_contents(): void
    {
        $file = SanitizedFile::rejected('SVG references an external entity');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('rejected and has no safe contents');

        $file->contents();
    }
}
