<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/** Single-use authorization codes (RFC 6749 §4.1). Only the code hash is stored. */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('oauth_auth_codes', static function ($t) {
            $t->string('id', 64)->primary();
            $t->char('code_hash', 64)->unique();
            $t->string('client_id', 64);
            $t->string('user_id', 64);
            $t->text('redirect_uri');
            $t->text('scopes')->nullable();
            $t->string('code_challenge', 128)->nullable();
            $t->string('code_challenge_method', 10)->nullable();
            $t->string('nonce', 255)->nullable();
            $t->boolean('consumed')->default(false);
            $t->timestamp('expires_at');
            $t->timestamp('created_at')->nullable();

            $t->index(['client_id']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('oauth_auth_codes');
    }
};
