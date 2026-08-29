<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker;

// ─── JobPayload ───────────────────────────────────────────────────────────────

/**
 * A dequeued unit of work, exactly as the queue handed it over.
 *
 * ── THE SIGNATURE COVERS THE WHOLE ENVELOPE ──────────────────────────────────
 *
 * A queue is an input channel like any other. Whoever can write to it is, in
 * effect, calling into the application — so the worker must be able to tell a
 * payload the application produced from one somebody else planted.
 *
 * The signature therefore covers every PRODUCER-AUTHORED field:
 *
 *     HMAC_SHA256( secret, jobId | jobClass | queue | maxAttempts | canonical(data) )
 *
 * Signing `data` ALONE — as this class used to — left `jobClass` unauthenticated,
 * which is the field that decides WHICH CODE RUNS. An attacker who captured one
 * legitimately signed envelope could keep the signature, swap the class for any
 * other JobContract in the application, and have the worker execute it. Nothing
 * about that requires forging a signature, only reusing one.
 *
 * `attempts` is deliberately NOT signed: it is a QUEUE-managed counter that the
 * driver increments on every release(), so covering it would invalidate the
 * signature on a job's first retry. `maxAttempts` IS signed, so the retry budget
 * the producer set cannot be widened in transit.
 *
 * `data` is canonicalised (keys sorted, recursively) before signing, because a
 * driver that round-trips the envelope through JSON is under no obligation to
 * preserve key order — and an unstable input makes an HMAC reject its own
 * legitimate messages.
 *
 * VERIFICATION IS OFF UNTIL A SECRET IS CONFIGURED. See
 * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Kernel::withWorkerSecret()};
 * with no secret the worker runs every payload it is given, which is the
 * historical behaviour and is only safe when the queue itself is trusted.
 */
final readonly class JobPayload
{
    public function __construct(
        private string             $jobId,
        private string             $jobClass,
        private array              $data,
        private string             $queue,
        private int                $attempts,
        private int                $maxAttempts,
        private \DateTimeImmutable $enqueuedAt,
        private string             $signature,
    ) {}

    public function jobId(): string              { return $this->jobId; }
    public function jobClass(): string           { return $this->jobClass; }
    public function data(): array                { return $this->data; }
    public function queue(): string              { return $this->queue; }
    public function attempts(): int              { return $this->attempts; }
    public function maxAttempts(): int           { return $this->maxAttempts; }
    public function enqueuedAt(): \DateTimeImmutable { return $this->enqueuedAt; }
    public function signature(): string          { return $this->signature; }

    /**
     * The signature this payload SHOULD carry — what a QueuePort adapter stamps
     * onto the envelope at push() time.
     *
     * Producing and verifying through the same method is the point: two
     * hand-rolled HMACs that must agree forever is how signing quietly stops
     * working.
     */
    public function signatureFor(string $secret): string
    {
        return self::sign(
            $secret,
            $this->jobId,
            $this->jobClass,
            $this->data,
            $this->queue,
            $this->maxAttempts,
        );
    }

    /**
     * Sign an envelope's producer-authored fields.
     *
     * @param array<string, mixed> $data
     */
    public static function sign(
        string $secret,
        string $jobId,
        string $jobClass,
        array $data,
        string $queue,
        int $maxAttempts,
    ): string {
        return hash_hmac('sha256', implode('|', [
            $jobId,
            $jobClass,
            $queue,
            (string) $maxAttempts,
            self::canonical($data),
        ]), $secret);
    }

    /**
     * Whether this payload was signed with $secret.
     *
     * An empty secret or an empty signature is NEVER valid: the caller decides
     * whether verification applies at all, and once it does, "unsigned" must not
     * be a way past it.
     */
    public function isSignatureValid(string $secret): bool
    {
        if ($secret === '' || $this->signature === '') {
            return false;
        }

        return hash_equals($this->signatureFor($secret), $this->signature);
    }

    public function hasExceededMaxAttempts(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }

    /**
     * A stable string for an arbitrary payload array — keys sorted at every
     * depth so encoding order cannot change the signed material.
     *
     * Falls back to serialize() if the data is not JSON-encodable (a resource,
     * NAN, malformed UTF-8). Signing SOMETHING deterministic is what matters;
     * throwing here would turn an odd payload into a worker crash.
     *
     * @param array<array-key, mixed> $data
     */
    private static function canonical(array $data): string
    {
        $sorted = self::sortRecursive($data);

        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? serialize($sorted) : $json;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function sortRecursive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortRecursive($value);
            }
        }

        // Lists keep their order — it is meaningful. Only associative keys are
        // sorted, because there their order is an encoding artefact.
        if (!array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }
}
