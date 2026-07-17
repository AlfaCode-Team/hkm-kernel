<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * {{STUDLY}} — create the `{{LOWER}}` table.
 *
 * Published to database/migrations/ on `hkm plugins enable {{STUDLY}}` and run
 * by `migrate:run`. On `hkm plugins disable {{STUDLY}}` (with unpublish) the
 * down() below is rolled back before the file is removed. Always write a
 * matching down() — the unpublish rollback depends on it.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('{{LOWER}}', static function ($t) {
            $t->string('id', 64)->primary();
            $t->string('name', 255);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('{{LOWER}}');
    }
};
