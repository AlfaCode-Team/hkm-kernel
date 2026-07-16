<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/** Registered, grantable scopes (the consent-bound permission catalogue). */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('oauth_scopes', static function ($t) {
            $t->string('id', 150)->primary(); // the scope identifier, e.g. "profile"
            $t->string('description', 255)->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('oauth_scopes');
    }
};
