<?php

declare(strict_types=1);

namespace Tests\Fixtures\ComposerFilesModule;

/**
 * Fixture: a module that declares NOTHING in module.json and instead relies on
 * its own composer.json `autoload.files` — the real shape of every plugin
 * written before the kernel had a `files` key. Composer never processes it
 * (plugins are symlinked and reached through the PSR-4 Plugins\ map), so the
 * kernel honouring it is what makes those plugins work unchanged.
 */
final class Provider
{
}
