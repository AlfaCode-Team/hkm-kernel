<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('personal_access_tokens', static function ($t) {
            $t->string('id', 64)->primary();
            $t->string('user_id', 64);
            $t->string('name', 255);
            $t->string('token_hash', 64)->unique();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('created_at')->nullable();

            $t->index(['user_id']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('personal_access_tokens');
    }
};
