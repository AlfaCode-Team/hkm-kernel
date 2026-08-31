<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/** Creates a table its down() forgets — the defect the rollback half catches. */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('orphans', static function ($t) {
            $t->id();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Deliberately empty.
    }
};
