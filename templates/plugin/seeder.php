<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Driver\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Seeder\SeederInterface;

/**
 * {{STUDLY}}Seeder — populate the `{{LOWER}}` table with baseline data.
 *
 * Published to database/seeders/ on enable; run with `db:seed`. Uses the raw
 * driver (execute/insert/fetchAll) — no query builder, maximum speed.
 */
final class {{STUDLY}}Seeder implements SeederInterface
{
    public function run(DatabaseDriverInterface $db): void
    {
        // $db->insert('{{LOWER}}', [
        //     'id'   => bin2hex(random_bytes(16)),
        //     'name' => 'Example {{STUDLY}}',
        // ]);
    }

    /** @return string[] */
    public function getDependencies(): array
    {
        return [];
    }
}
