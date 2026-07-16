<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * User — add stored-hash email-verification token columns to `users`.
 *
 * A public self-signup is NOT authenticated when it later clicks the emailed
 * confirmation link, so email verification CANNOT be identity-gated. Instead we
 * email a single random token and store only its SHA-256 hash here (never the
 * raw token — a DB leak must not let an attacker confirm arbitrary accounts).
 * The token is one-time (cleared on use) and time-boxed (expiry column).
 *
 * SCOPE: central `users` table (identity). Separate NEW migration so the base
 * table migration is never mutated after it has been applied.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->table('users', static function ($t) {
            // SHA-256 (64 hex chars) of the emailed verification token. NULL once
            // the email is verified or before any token is issued.
            $t->char('email_verification_token_hash', 64)->nullable()
                ->comment('SHA-256 of the emailed verification token — never the raw token');

            // Hard expiry for the pending token; a link past this is rejected.
            $t->timestamp('email_verification_expires_at')->nullable()
                ->comment('When the pending verification token stops being valid');

            // Single-row lookup by token hash on the public verify endpoint.
            $t->index(['email_verification_token_hash'], 'idx_email_verif_token');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->table('users', static function ($t) {
            $t->dropIndex('idx_email_verif_token');
            $t->dropColumn('email_verification_token_hash');
            $t->dropColumn('email_verification_expires_at');
        });
    }
};
