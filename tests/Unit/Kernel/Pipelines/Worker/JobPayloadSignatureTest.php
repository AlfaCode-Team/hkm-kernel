<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Pipelines\Worker;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The queue is an input channel, so the worker has to be able to tell a payload
 * the application produced from one somebody else planted.
 *
 * The signature used to cover `data` ALONE, which left `jobClass` — the field
 * deciding WHICH CODE RUNS — unauthenticated. Capturing one legitimately signed
 * envelope and swapping its class for any other JobContract was enough; no
 * forgery required.
 */
#[CoversClass(JobPayload::class)]
final class JobPayloadSignatureTest extends TestCase
{
    private const SECRET = 'a-server-only-key';

    /** @param array<string, mixed> $overrides */
    private static function payload(array $overrides = [], string $signature = ''): JobPayload
    {
        $fields = [
            'jobId'       => 'job-1',
            'jobClass'    => 'App\\Jobs\\SendInvoice',
            'data'        => ['invoiceId' => 42, 'to' => 'a@b.test'],
            'queue'       => 'emails',
            'attempts'    => 0,
            'maxAttempts' => 3,
            ...$overrides,
        ];

        return new JobPayload(
            jobId:       $fields['jobId'],
            jobClass:    $fields['jobClass'],
            data:        $fields['data'],
            queue:       $fields['queue'],
            attempts:    $fields['attempts'],
            maxAttempts: $fields['maxAttempts'],
            enqueuedAt:  new \DateTimeImmutable('@1700000000'),
            signature:   $signature,
        );
    }

    /** A payload signed exactly as a correct QueuePort adapter would sign it. */
    private static function signed(array $overrides = []): JobPayload
    {
        $unsigned = self::payload($overrides);

        return self::payload($overrides, $unsigned->signatureFor(self::SECRET));
    }

    // ── The happy path ──────────────────────────────────────────────────────

    public function test_a_correctly_signed_payload_verifies(): void
    {
        self::assertTrue(self::signed()->isSignatureValid(self::SECRET));
    }

    public function test_a_payload_signed_with_another_key_does_not_verify(): void
    {
        self::assertFalse(self::signed()->isSignatureValid('a-different-key'));
    }

    // ── The regression: every producer-authored field is covered ────────────

    public function test_swapping_the_job_class_invalidates_a_captured_signature(): void
    {
        $captured = self::signed()->signature();

        // The whole attack: keep the signature, change what runs.
        $tampered = self::payload(['jobClass' => 'App\\Jobs\\GrantAdmin'], $captured);

        self::assertFalse(
            $tampered->isSignatureValid(self::SECRET),
            'jobClass decides which code runs and MUST be signed',
        );
    }

    public function test_tampering_with_the_data_invalidates_the_signature(): void
    {
        $captured = self::signed()->signature();
        $tampered = self::payload(['data' => ['invoiceId' => 99, 'to' => 'attacker@evil.test']], $captured);

        self::assertFalse($tampered->isSignatureValid(self::SECRET));
    }

    public function test_widening_the_retry_budget_invalidates_the_signature(): void
    {
        $captured = self::signed()->signature();
        $tampered = self::payload(['maxAttempts' => 100_000], $captured);

        self::assertFalse($tampered->isSignatureValid(self::SECRET));
    }

    public function test_moving_the_job_to_another_queue_invalidates_the_signature(): void
    {
        $captured = self::signed()->signature();
        $tampered = self::payload(['queue' => 'high-priority'], $captured);

        self::assertFalse($tampered->isSignatureValid(self::SECRET));
    }

    // ── attempts is queue-managed, so it is deliberately NOT signed ─────────

    public function test_a_retry_still_verifies_after_the_driver_increments_attempts(): void
    {
        $captured = self::signed()->signature();
        $retried  = self::payload(['attempts' => 2], $captured);

        // Signing this would break every job on its first retry, since release()
        // increments it. maxAttempts is signed instead, so the budget still holds.
        self::assertTrue($retried->isSignatureValid(self::SECRET));
    }

    // ── Unsigned is never a way past the check ──────────────────────────────

    public function test_an_unsigned_payload_never_verifies(): void
    {
        self::assertFalse(self::payload()->isSignatureValid(self::SECRET));
    }

    public function test_an_empty_secret_never_verifies(): void
    {
        // The CALLER decides whether verification applies at all. Once it does,
        // an empty key must not be a skeleton key.
        self::assertFalse(self::signed()->isSignatureValid(''));
    }

    // ── Canonicalisation ────────────────────────────────────────────────────

    public function test_payload_key_order_does_not_change_the_signature(): void
    {
        // A driver round-tripping the envelope through JSON is under no
        // obligation to preserve key order; an unstable input would make the
        // HMAC reject its own legitimate messages.
        $a = self::payload(['data' => ['b' => 2, 'a' => 1, 'nested' => ['y' => 1, 'x' => 2]]]);
        $b = self::payload(['data' => ['a' => 1, 'nested' => ['x' => 2, 'y' => 1], 'b' => 2]]);

        self::assertSame($a->signatureFor(self::SECRET), $b->signatureFor(self::SECRET));
    }

    public function test_list_order_is_meaningful_and_does_change_the_signature(): void
    {
        $a = self::payload(['data' => ['steps' => ['charge', 'ship']]]);
        $b = self::payload(['data' => ['steps' => ['ship', 'charge']]]);

        self::assertNotSame($a->signatureFor(self::SECRET), $b->signatureFor(self::SECRET));
    }
}
