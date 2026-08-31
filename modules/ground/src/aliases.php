<?php

declare(strict_types=1);

/**
 * Compatibility aliases for the old `Plugins\Ground\…` names.
 *
 * Ground used to be a standalone plugin (`hkm-plugin-ground`), so every test
 * written against it imports `Plugins\Ground\Ground\PluginGroundTestCase` and
 * friends. It is now a kernel MODULE — shipped with the kernel, available to
 * every plugin without a composer require — and the namespace says so.
 *
 * Renaming a base class that other repositories extend is not something to do
 * with a find-and-replace across machines you do not control, so the old names
 * keep working. Each is a real alias, not a subclass: `instanceof` holds, and a
 * test extending the old name IS extending the new class.
 *
 * ─── HOW THIS WORKS ─────────────────────────────────────────────────────────
 *
 * `class_alias` with $autoload = true on the TARGET, registered lazily through
 * an autoloader rather than eagerly at file load. Aliasing all of them eagerly
 * would load the whole harness — including PluginGroundTestCase, which extends
 * PHPUnit's TestCase — into every process that boots a kernel, and PHPUnit is a
 * dev dependency that production does not have.
 *
 * ─── MIGRATING ──────────────────────────────────────────────────────────────
 *
 *   -use Plugins\Ground\Ground\PluginGroundTestCase;
 *   +use AlfacodeTeam\Ground\PluginGroundTestCase;
 *
 *   -use Plugins\Ground\Fakes\FakeQueue;
 *   +use AlfacodeTeam\Ground\Fakes\FakeQueue;
 *
 * There is no deadline. This file is small and costs one string comparison per
 * unresolved class; it can stay indefinitely.
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Plugins\\Ground\\')) {
        return;
    }

    $relative = substr($class, \strlen('Plugins\\Ground\\'));

    // `Plugins\Ground\Ground\PluginGround` → `AlfacodeTeam\Ground\PluginGround`.
    // The doubled segment existed because the package root was the namespace
    // root; inside the module the harness sits directly under the package.
    if (str_starts_with($relative, 'Ground\\')) {
        $relative = substr($relative, \strlen('Ground\\'));
    }

    $target = 'AlfacodeTeam\\Ground\\' . $relative;

    if ($target === $class || (!class_exists($target) && !interface_exists($target) && !trait_exists($target))) {
        return;
    }

    class_alias($target, $class);
});
