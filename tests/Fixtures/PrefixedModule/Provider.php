<?php

declare(strict_types=1);

namespace Tests\Fixtures\PrefixedModule;

/**
 * Fixture module for CompileRouteManifestStage tests.
 *
 * ManifestReader locates module.json by reflecting on the Provider's file, so a
 * test that exercises module-level route declarations (routePrefix, routeFilters)
 * needs a real class beside a real module.json.
 */
final class Provider
{
}
