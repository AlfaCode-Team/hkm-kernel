<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Adds owner_id to oauth_clients so a user can self-manage the clients they
 * registered (GET/POST/PUT/DELETE /oauth/clients). Nullable — first-party /
 * CLI-provisioned clients have no owner.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('oauth_clients') || $schema->hasColumn('oauth_clients', 'owner_id')) {
            return;
        }

        $schema->table('oauth_clients', static function ($t) {
            $t->string('owner_id', 64)->nullable()->comment('user_id of the registering user; null = first-party');
            $t->index(['owner_id'], 'idx_oauth_clients_owner');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('oauth_clients') || !$schema->hasColumn('oauth_clients', 'owner_id')) {
            return;
        }

        $schema->table('oauth_clients', static function ($t) {
            $t->dropIndex('idx_oauth_clients_owner');
            $t->dropColumn('owner_id');
        });
    }
};
