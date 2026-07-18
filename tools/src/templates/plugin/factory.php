<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Seeder\Factory\EntityFactory;
use AlfaCode\LetMigrate\Seeder\Factory\FakeData;

/**
 * {{STUDLY}}Factory — generate fake `{{LOWER}}` rows for seeders / tests.
 *
 * Published to database/factories/ on enable.
 */
return EntityFactory::for('{{LOWER}}')
    ->definition(fn(FakeData $f, int $i) => [
        'id'   => $i + 1,
        'name' => $f->name(),
    ])
    ->locale('en_US')
    ->count(10);
