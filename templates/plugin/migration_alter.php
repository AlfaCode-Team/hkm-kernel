<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * {{STUDLY}} — change the existing `{{LOWER}}` table.
 *
 * Scaffolded from a migration name that alters rather than creates, so this
 * opens the table instead of creating it — running create() against a table
 * that already exists would fail on every environment that has it.
 *
 * Published to database/migrations/ on `hkm plugins enable {{STUDLY}}` and run
 * by `migrate:run`. Always write a matching down(): `hkm plugins disable`
 * rolls it back before removing the file, and a down() that does not undo up()
 * leaves the schema half-changed.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->table('{{LOWER}}', static function ($t) {
            // $t->string('widget_id', 64)->nullable();
            // $t->index('widget_id');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->table('{{LOWER}}', static function ($t) {
            // $t->dropColumn('widget_id');
        });
    }
};
