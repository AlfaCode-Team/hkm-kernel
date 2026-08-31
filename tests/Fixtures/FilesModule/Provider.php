<?php

declare(strict_types=1);

namespace Tests\Fixtures\FilesModule;

/**
 * Fixture: a module that declares helper files EXPLICITLY in module.json.
 * ManifestReader locates module.json by reflecting on the Provider's file, so
 * the class has to be real and sit beside a real module.json.
 */
final class Provider
{
}
