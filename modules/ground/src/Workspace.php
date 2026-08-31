<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground;

/**
 * A throwaway base+project root for one booted ground.
 *
 * The kernel writes compiled manifests under Paths::cache('manifests/'), which
 * resolves beneath the ACTIVE PROJECT root. Booting a plugin against the real
 * project root would therefore overwrite that project's route/service/config
 * manifests with a manifest describing only the plugin under test — and leave
 * them that way after the test process exits. Every ground gets its own
 * directory instead, so a test run cannot corrupt a working application.
 *
 * The directory is removed on destroy(). Set GROUND_KEEP_WORKSPACE=true to keep
 * it: when a boot fails, the compiled manifests are usually the fastest way to
 * see what the kernel actually thought the plugin declared.
 */
final class Workspace
{
    private bool $destroyed = false;

    private function __construct(
        public readonly string $root,
    ) {}

    /**
     * Create an empty workspace with the directory layout the kernel expects.
     *
     * @param string $label appears in the directory name — use the plugin under
     *                      test so a kept workspace is identifiable.
     */
    public static function create(string $label = 'plugin'): self
    {
        $parent = (string) (env('GROUND_WORKSPACE_DIR') ?: sys_get_temp_dir());
        $slug   = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $label) ?: 'plugin';

        // random_bytes rather than uniqid(): parallel PHPUnit processes create
        // workspaces in the same parent directory within the same microsecond.
        $root = rtrim($parent, '/') . '/hkm-ground-' . $slug . '-' . bin2hex(random_bytes(6));

        foreach (['', '/var', '/var/cache', '/var/cache/manifests', '/var/logs', '/userdata', '/config'] as $dir) {
            $path = $root . $dir;
            if (!is_dir($path) && !@mkdir($path, 0o700, true) && !is_dir($path)) {
                throw new \RuntimeException("Ground workspace could not be created at [{$path}].");
            }
        }

        return new self($root);
    }

    public function path(string $append = ''): string
    {
        return $append === '' ? $this->root : $this->root . '/' . ltrim($append, '/');
    }

    /** Compiled manifests written by the boot pipeline, for inspection after a boot. */
    public function manifest(string $file): array
    {
        $path = $this->path('var/cache/manifests/' . ltrim($file, '/'));

        return is_file($path) ? (array) require $path : [];
    }

    /**
     * Remove the workspace. Honours GROUND_KEEP_WORKSPACE, and is safe to call
     * more than once (a test's tearDown and a shutdown handler may both fire).
     */
    public function destroy(): void
    {
        if ($this->destroyed) {
            return;
        }
        $this->destroyed = true;

        $keep = env('GROUND_KEEP_WORKSPACE');
        if ($keep === true || $keep === '1' || $keep === 'true') {
            return;
        }

        self::removeTree($this->root);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // SKIP_DOTS only; symlinks are unlinked rather than followed, so a
        // plugin that stored a link to somewhere real cannot widen the delete.
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
