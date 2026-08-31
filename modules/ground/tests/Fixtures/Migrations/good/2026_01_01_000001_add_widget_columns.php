<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * TWO columns and an index in one table() call.
 *
 * This is the shape that used to compile to MySQL's batched
 * `ALTER TABLE t ADD COLUMN a, ADD COLUMN b, ADD KEY …` on every driver, which
 * SQLite rejects outright. Keeping it as a fixture is how that stays fixed.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->table('widgets', static function ($t) {
            $t->string('colour', 30)->nullable();
            $t->integer('weight')->nullable();
            $t->index(['colour'], 'idx_widgets_colour');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->table('widgets', static function ($t) {
            $t->dropIndex('idx_widgets_colour');
            $t->dropColumn('colour');
            $t->dropColumn('weight');
        });
    }
};
