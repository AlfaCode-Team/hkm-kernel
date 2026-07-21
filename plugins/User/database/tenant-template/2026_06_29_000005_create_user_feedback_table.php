<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * User — create the TENANT-scoped `user_feedback` table.
 *
 * Applied per-tenant DB by `tenant:migrate`. `user_id` is a soft reference to
 * central `users.id` — no cross-DB foreign key.
 *
 * Captures in-app feedback submissions. `feedback_id` is the PUBLIC opaque id
 * handed back to the client (never the auto-increment `id`), so we don't leak
 * row counts. Many feedback rows per user → user_id is a non-unique index.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('user_feedback', static function ($t) {
            $t->id();

            $t->char('user_id', 31)
                ->comment('Soft ref to central users.user_id (ULID) — no cross-DB FK');

            $t->char('feedback_id', 36)
                ->comment('Public opaque ID (UUID) returned to the client');
            $t->string('category', 60)->nullable()
                ->comment('search_browsing|messaging|payments|hosting|app_performance|feature_request|other');
            $t->unsignedTinyInteger('rating')->nullable()
                ->comment('1-5 star rating');
            $t->text('message');
            $t->string('status', 20)->default('received')
                ->comment('received|acknowledged|resolved');

            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            // Public id is globally unique + the client-facing lookup key.
            $t->unique(['feedback_id'], 'uniq_feedback_id');
            // List a user's submissions; triage by status.
            $t->index(['user_id'], 'idx_feedback_user');
            $t->index(['status'], 'idx_feedback_status');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('user_feedback');
    }
};
