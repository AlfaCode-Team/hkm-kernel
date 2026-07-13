<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/** OAuth2 registered clients (RFC 6749 §2). Control-plane table — central DB. */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('oauth_clients', static function ($t) {
            $t->string('id', 64)->primary();
            $t->string('name', 150);
            $t->string('secret_hash', 255)->nullable(); // null = public client
            $t->text('redirect_uris');                  // JSON list
            $t->text('grant_types');                    // JSON list
            $t->text('scopes')->nullable();             // JSON list (empty = any)
            $t->boolean('confidential')->default(true);
            $t->boolean('revoked')->default(false);
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('oauth_clients');
    }
};
