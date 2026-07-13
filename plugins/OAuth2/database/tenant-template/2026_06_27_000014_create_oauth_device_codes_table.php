<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/** Device authorization grant requests (RFC 8628). Device code stored hashed. */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('oauth_device_codes', static function ($t) {
            $t->string('id', 64)->primary();
            $t->char('device_code_hash', 64)->unique();
            $t->string('user_code', 20)->unique();
            $t->string('client_id', 64);
            $t->text('scopes')->nullable();
            $t->string('status', 16)->default('pending');
            $t->string('user_id', 64)->nullable();
            $t->integer('interval_seconds')->default(5);
            $t->timestamp('last_polled_at')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('created_at')->nullable();

            $t->index(['client_id']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('oauth_device_codes');
    }
};
