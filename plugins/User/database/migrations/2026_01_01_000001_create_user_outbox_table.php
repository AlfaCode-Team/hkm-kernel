<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Transactional outbox for the User domain.
 *
 * Integration events are written into this table INSIDE the same transaction
 * that mutates the user, then relayed to the EventBus by `user:outbox:relay`.
 * This guarantees at-least-once delivery even if the process dies between
 * commit and dispatch — the classic outbox pattern.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('user_outbox', static function ($t) {
            // --- Primary key ------------------------------------------------
            // WHAT:  Auto-increment BIGINT surrogate key.
            // WHY:   Cheap monotonic row identity; the relay updates a row by
            //        this `id` after dispatch (see OutboxRelay).
            // WHERE: Internal only — never leaves the table.
            $t->id();

            // --- Event id ---------------------------------------------------
            // WHAT:  UUID identifying this specific event instance.
            // WHY:   The consumer-side idempotency key: at-least-once delivery
            //        means a message can arrive twice, so consumers dedupe on
            //        this value. CHAR(36) = canonical UUID string length.
            // WHERE: Written by OutboxRepository; carried in the dispatched event so
            //        downstream handlers can skip duplicates. uniq_event_id below.
            $t->char('event_id', 36)->comment('UUID — idempotency key for consumers');

            // --- Event name -------------------------------------------------
            // WHAT:  The integration event's logical name (e.g. user.registered).
            // WHY:   Lets the relay/consumers route by type without decoding the
            //        payload. 100 chars comfortably fits any dotted event name.
            // WHERE: Set from IntegrationEventContract::name() by OutboxRepository.
            $t->string('event_name', 100)->comment('e.g. user.registered');

            // --- Event version ----------------------------------------------
            // WHAT:  Schema version of the payload (default '1.0').
            // WHY:   Payloads evolve; the version lets consumers handle old and
            //        new shapes side by side during a rollout.
            // WHERE: Set from the event's version(); read by consumers.
            $t->string('event_version', 16)->default('1.0');

            // --- Payload ----------------------------------------------------
            // WHAT:  The full event body as JSON.
            // WHY:   Self-contained message — everything a consumer needs travels
            //        with the row, so the relay never re-queries the domain.
            //        JSON column keeps it queryable and driver-portable.
            // WHERE: Encoded from IntegrationEventContract::payload() on write.
            $t->json('payload');

            // --- Dispatch status --------------------------------------------
            // WHAT:  Delivery state: 0=pending, 1=dispatched, 2=failed.
            // WHY:   Drives the relay loop — it claims pending rows, marks them
            //        dispatched on success, or parks them as failed after the
            //        max attempts. tinyint(unsigned) is the cheapest enum.
            // WHERE: OutboxRelay SELECTs WHERE status = 0 and UPDATEs it; first
            //        column of idx_status_occurred for the pending scan.
            $t->tinyInteger('status')->unsigned()->default(0)
                ->comment('0=pending,1=dispatched,2=failed');

            // --- Attempts ---------------------------------------------------
            // WHAT:  How many delivery attempts have been made.
            // WHY:   Powers retry budgeting — once it hits the relay's max, the
            //        row is parked as failed (status 2) instead of looping.
            // WHERE: Incremented by OutboxRelay on each dispatch attempt.
            $t->unsignedInteger('attempts')->default(0);

            // --- Last error -------------------------------------------------
            // WHAT:  Truncated text of the most recent delivery failure.
            //        Nullable — empty until something fails.
            // WHY:   Diagnostics for stuck/failed rows without external logging.
            // WHERE: Written by OutboxRelay when an attempt throws.
            $t->text('last_error')->nullable();

            // --- Occurred at ------------------------------------------------
            // WHAT:  When the domain event actually happened.
            // WHY:   The ordering key so events relay in the order they occurred
            //        (oldest-first), preserving causal order for consumers.
            // WHERE: Set on write; second column of idx_status_occurred.
            $t->timestamp('occurred_at');

            // --- Dispatched at ----------------------------------------------
            // WHAT:  When the event was successfully relayed. Nullable until then.
            // WHY:   Marks completion and gives delivery-latency visibility.
            // WHERE: Stamped by OutboxRelay on successful dispatch.
            $t->timestamp('dispatched_at')->nullable();

            // --- Created at -------------------------------------------------
            // WHAT:  Row insertion time (DB default).
            // WHY:   Audit of when the outbox record was written, independent of
            //        occurred_at. Always populated even on raw inserts.
            // WHERE: Read for auditing; never set by hand.
            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            // --- Constraints & indexes --------------------------------------
            // uniq_event_id: guarantees one row per event instance (no double
            //   write inside the producing transaction) and dedupes on replay.
            $t->unique(['event_id'], 'uniq_event_id');
            // idx_status_occurred: composite index for the relay's hot query —
            //   "pending rows, oldest first" — so it never scans the whole table.
            $t->index(['status', 'occurred_at'], 'idx_status_occurred');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('user_outbox');
    }
};
