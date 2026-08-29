<?php

declare(strict_types=1);

namespace Tests\Fixtures\JobModule;

/**
 * Fixture module for CompileJobManifestStage tests.
 *
 * ManifestReader locates module.json by reflecting on the Provider's file, so a
 * test that exercises job declarations needs a real class beside a real
 * module.json.
 */
final class Provider
{
}
