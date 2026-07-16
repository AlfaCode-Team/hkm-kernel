<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Production hardening for personal access tokens:
 *   - expires_at : optional absolute expiry so PATs are not immortal (NIST-aligned).
 *   - abilities  : JSON-encoded scope list driving Identity.permissions at auth time.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('personal_access_tokens')) {
            return;
        }

        $schema->table('personal_access_tokens', static function ($t) use ($schema) {
            if (!$schema->hasColumn('personal_access_tokens', 'expires_at')) {
                $t->timestamp('expires_at')->nullable();
            }
            if (!$schema->hasColumn('personal_access_tokens', 'abilities')) {
                $t->text('abilities')->nullable();
            }
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('personal_access_tokens')) {
            return;
        }

        $schema->table('personal_access_tokens', static function ($t) use ($schema) {
            if ($schema->hasColumn('personal_access_tokens', 'expires_at')) {
                $t->dropColumn('expires_at');
            }
            if ($schema->hasColumn('personal_access_tokens', 'abilities')) {
                $t->dropColumn('abilities');
            }
        });
    }
};
