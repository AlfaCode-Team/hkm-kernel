<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Auth — central `refresh_tokens` (CONTROL PLANE).
 *
 * The revocable long-lived counterpart to the short-lived access JWT. Only the
 * SHA-256 of the token is persisted. `family_id` groups a rotation lineage for
 * one-time-use reuse detection. `tenant_id` is an optional scope hint baked into
 * the paired access token's `tnt` claim (NOT re-verified on refresh — tenant
 * seat checks live in the Tenancy selection flow).
 *
 * Relocated from Plugins\Tenancy: refresh tokens are an authentication concern,
 * so they belong to Auth. Includes family_id from the outset (was a follow-up
 * migration in the old Tenancy location).
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('refresh_tokens')) {
            return; // pre-existing (e.g. migrated under the old Tenancy owner)
        }

        $schema->create('refresh_tokens', static function ($t) {
            $t->id();
            $t->char('token_id', 31);
            $t->char('family_id', 31)->comment('rotation lineage for reuse detection');
            $t->char('user_id', 31);
            $t->char('token_hash', 64)->comment('SHA-256 of the refresh token — never store raw');
            $t->char('tenant_id', 31)->nullable()->comment('scope hint for the tnt claim; not re-verified');
            $t->string('device', 191)->nullable()->comment('UA / device label');
            $t->string('ip', 45)->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $t->unique(['token_id'], 'uniq_token_id');
            $t->unique(['token_hash'], 'uniq_token_hash');
            $t->index(['user_id', 'revoked_at'], 'idx_user_active');
            $t->index(['family_id'], 'idx_family');

            $t->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('refresh_tokens');
    }
};
