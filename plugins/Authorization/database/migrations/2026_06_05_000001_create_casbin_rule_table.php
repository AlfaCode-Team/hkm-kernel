<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('casbin_rule', static function ($t) {
            $t->id();
            $t->string('ptype', 32);
            $t->string('v0', 255)->nullable();
            $t->string('v1', 255)->nullable();
            $t->string('v2', 255)->nullable();
            $t->string('v3', 255)->nullable();
            $t->string('v4', 255)->nullable();
            $t->string('v5', 255)->nullable();

            $t->index(['ptype']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('casbin_rule');
    }
};
