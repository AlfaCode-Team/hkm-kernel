<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * SocialAuth — central `social_identities` (CONTROL PLANE).
 *
 * Maps a provider account (provider + provider_user_id) to a central platform
 * user. One row per linked provider account; a user may link several providers.
 * Email/name/avatar are provider snapshots (refreshed on each login) for
 * display — the authoritative identity stays in `users`.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('social_identities')) {
            return;
        }

        $schema->create('social_identities', static function ($t) {
            $t->id();
            $t->string('provider', 32);
            $t->string('provider_user_id', 191);
            $t->char('user_id', 31);
            $t->string('email', 150)->nullable();
            $t->string('name', 120)->nullable();
            $t->string('avatar', 255)->nullable();
            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $t->timestamp('updated_at')->nullable();

            $t->unique(['provider', 'provider_user_id'], 'uniq_provider_account');
            $t->index(['user_id'], 'idx_user');

            $t->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('social_identities');
    }
};
