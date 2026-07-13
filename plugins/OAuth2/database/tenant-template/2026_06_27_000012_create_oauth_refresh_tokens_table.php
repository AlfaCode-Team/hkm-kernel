<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/** Rotating refresh tokens (RFC 6749 §6) with family-based reuse detection. */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('oauth_refresh_tokens', static function ($t) {
            $t->string('id', 64)->primary();
            $t->string('family_id', 64);
            $t->char('token_hash', 64)->unique();
            $t->string('client_id', 64);
            $t->string('user_id', 64);
            $t->text('scopes')->nullable();
            $t->boolean('revoked')->default(false);
            $t->timestamp('expires_at');
            $t->timestamp('created_at')->nullable();

            $t->index(['family_id']);
            $t->index(['client_id']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('oauth_refresh_tokens');
    }
};
