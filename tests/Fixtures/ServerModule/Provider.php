<?php

declare(strict_types=1);

namespace Tests\Fixtures\ServerModule;

/**
 * Fixture module for the server surface.
 *
 * ManifestReader locates module.json by reflecting on the Provider's file, so a
 * test that exercises servers[] declarations needs a real class beside a real
 * module.json.
 */
final class Provider
{
}
