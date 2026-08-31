<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/** A migration that undoes itself completely — the shape a plugin should ship. */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('widgets', static function ($t) {
            $t->id();
            $t->string('name', 100);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->drop('widgets');
    }
};
