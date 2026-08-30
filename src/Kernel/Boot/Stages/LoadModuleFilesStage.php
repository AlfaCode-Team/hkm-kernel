<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;

/**
 * Requires every module helper file compiled by {@see CompileModuleFilesStage}.
 *
 * RUNS ON EVERY BUILD — cached or not.
 * -------------------------------------
 * This is the whole reason it is a separate stage from the compiler. Global
 * FUNCTIONS live in the PHP process, not in a manifest on disk, so a boot that
 * skips the compile phase because BOOT_CACHE says the manifests are current
 * must still require the files. Folding this into the compile stage would mean
 * that turning BOOT_CACHE on — an optimisation flag — silently stopped loading
 * helper functions, and the failure would surface far away, as an undefined
 * function inside a plugin.
 *
 * Reading the compiled list keeps the cached path cheap: one small manifest,
 * instead of re-reading every module.json and composer.json to rediscover paths
 * that have not changed.
 *
 * `require_once`, so an entry point that builds the kernel more than once in a
 * process (tests, a Swoole worker re-reading config) redefines nothing.
 */
final class LoadModuleFilesStage implements BootStageContract
{
    public function run(): void
    {
        /** @var list<string> $files */
        $files = ManifestReader::readCompiled('files-manifest.php');

        foreach ($files as $file) {
            // A path compiled on a previous boot can legitimately vanish — a
            // plugin removed between deploys with a stale cache still on disk.
            // Skipping keeps that a recoverable state: the next compile drops
            // the entry. A file that is genuinely required and genuinely
            // missing already failed at compile time, which is where a missing
            // declared file is supposed to be caught.
            if (is_string($file) && is_file($file)) {
                require_once $file;
            }
        }
    }
}
