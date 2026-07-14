<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Auth — central `auth_sessions` (CONTROL PLANE).
 *
 * Server-side device-session registry for stateful (web) logins — the GDA port
 * of the old __DEV__ user_sessions tracking. One row per logged-in device; only
 * the SHA-256 of the session token is persisted. Rows are validated on every
 * authenticated request (revoked/expired ⇒ the browser session dies even if its
 * cookie is still live), extended by rolling refresh, and listed/revoked through
 * GET/DELETE /auth/sessions ("see & sign out my devices").
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('auth_sessions')) {
            return;
        }

        $schema->create('auth_sessions', static function ($t) {
            $t->id();
            $t->char('session_id', 32)->comment('public id (list/revoke API) — not the token');
            $t->char('user_id', 31)
                ->comment('Soft ref to central users.user_id (ULID) — no cross-DB FK');
            $t->char('token_hash', 64)->comment('SHA-256 of the session token — never store raw');
            $t->char('fingerprint', 64)->nullable()->comment('SHA-256 device fingerprint captured at login');
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 191)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $t->unique(['session_id'], 'uniq_session_id');
            $t->unique(['token_hash'], 'uniq_token_hash');
            $t->index(['user_id', 'revoked_at'], 'idx_user_active');


            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('auth_sessions');
    }
};
