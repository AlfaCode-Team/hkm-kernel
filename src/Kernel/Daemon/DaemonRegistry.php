<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Daemon;

use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Exceptions\DaemonException;

/**
 * Which installed plugins declare a daemon.
 *
 * FOUND BY SCANNING `plugins/`, NOT FROM THE COMPILED MANIFEST
 * -----------------------------------------------------------
 * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Support\Plugins} answers "is
 * this module registered?" from the service manifest, which is the right
 * question for request-time code and the wrong one here, for two reasons:
 *
 *  - it gives a `solves` domain and a name, not a DIRECTORY, and the directory
 *    is where the config template lives. A plugin's folder casing is not
 *    derivable from its manifest name;
 *  - a plugin that is installed but not yet ENABLED still needs its daemon
 *    provisioned — that is the normal order of a deploy, and reporting "no
 *    daemon declared" because the bootstrap has not been edited yet would send
 *    somebody looking in the wrong place.
 *
 * So this reads the manifests on disk. It is a handful of small JSON files,
 * read by a CLI command, once.
 */
final class DaemonRegistry
{
    /**
     * Every plugin directory in the project, in a stable order.
     *
     * @return list<string>
     */
    public static function pluginDirectories(string $projectRoot): array
    {
        $root = rtrim($projectRoot, '/') . '/plugins';

        if (!is_dir($root)) {
            return [];
        }

        $entries = scandir($root);
        $out     = [];

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            // A plugin is routinely a SYMLINK into a central store, so is_dir()
            // has to be allowed to follow it; filtering links out would hide
            // every plugin on a machine that installs them centrally.
            if (is_dir($root . '/' . $entry)) {
                $out[] = $root . '/' . $entry;
            }
        }

        sort($out);

        return $out;
    }

    /**
     * Declared daemons, keyed by the plugin's manifest name.
     *
     * A plugin whose `daemon` block is MALFORMED is reported rather than
     * skipped: a silently ignored block means the daemon is never provisioned
     * and the deployment is one process short, which is the failure this whole
     * mechanism exists to prevent.
     *
     * @return array<string, array{spec: DaemonSpec, dir: string}>
     * @throws DaemonException on a malformed block
     */
    public static function all(string $projectRoot): array
    {
        $found = [];

        foreach (self::pluginDirectories($projectRoot) as $directory) {
            $manifestPath = $directory . '/module.json';
            $json         = @file_get_contents($manifestPath);

            if (!\is_string($json)) {
                continue;
            }

            $manifest = json_decode($json, true);

            if (!\is_array($manifest)) {
                continue;
            }

            /** @var array<string, mixed> $manifest */
            $name = \is_string($manifest['name'] ?? null) && $manifest['name'] !== ''
                ? (string) $manifest['name']
                : basename($directory);

            $spec = DaemonSpec::fromManifest($manifest, $name);

            if ($spec !== null) {
                $found[$name] = ['spec' => $spec, 'dir' => $directory];
            }
        }

        return $found;
    }

    /**
     * One plugin's daemon, or null.
     *
     * @return array{spec: DaemonSpec, dir: string}|null
     * @throws DaemonException
     */
    public static function find(string $projectRoot, string $plugin): ?array
    {
        $wanted = strtolower(trim($plugin));

        foreach (self::all($projectRoot) as $name => $entry) {
            if (strtolower($name) === $wanted
                || strtolower(basename($entry['dir'])) === $wanted
                || strtolower($entry['spec']->name) === $wanted) {
                return $entry;
            }
        }

        return null;
    }
}
