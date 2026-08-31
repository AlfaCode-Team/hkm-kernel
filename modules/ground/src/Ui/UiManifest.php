<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Ui;

/**
 * A plugin's `ui/ui.json` — the declaration `hkm ui sync` federates from.
 *
 * Absent is legal and common: a plugin with no UI has no ui/ directory at all,
 * and `exists()` is false rather than this throwing. Only a ui.json that is
 * PRESENT and wrong is a finding.
 */
final class UiManifest
{
    private function __construct(
        public readonly string $directory,
        /** @var array<string, mixed> */
        public readonly array $data,
        public readonly bool $exists,
        public readonly ?string $error = null,
    ) {}

    /** @param string $pluginDirectory the directory holding module.json */
    public static function for(string $pluginDirectory): self
    {
        $dir  = rtrim($pluginDirectory, '/') . '/ui';
        $path = $dir . '/ui.json';

        if (!self::isUiDirectory($dir)) {
            return new self($dir, [], false);
        }

        if (!is_file($path)) {
            // A ui/ directory with no ui.json still federates (the sync command
            // falls back to defaults), so this is "exists, undeclared" rather
            // than an error.
            return new self($dir, [], true);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!\is_array($decoded)) {
            return new self($dir, [], true, 'ui.json is not valid JSON.');
        }

        return new self($dir, $decoded, true);
    }

    /**
     * Is this actually a UI directory, or a PHP source directory that a
     * case-insensitive filesystem is pretending is one?
     *
     * macOS and Windows match `Ui/` when asked for `ui/`. A plugin with a
     * `Plugins\X\Ui\` PHP namespace — this package has one — therefore looked
     * like a plugin shipping an undeclared frontend, and `plugin:check` reported
     * a `ui.no-manifest` warning that no edit to the plugin could clear. Worse,
     * the same code produced a DIFFERENT answer on Linux, so a CI gate and a
     * developer's laptop disagreed about whether the plugin was clean.
     *
     * So the directory has to look like a UI: a ui.json, an entry file, or a
     * surface with pages in it. Anything else is a namespace that happens to
     * collide, and is left alone.
     */
    private static function isUiDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        if (is_file($dir . '/ui.json') || is_file($dir . '/index.ts') || is_file($dir . '/index.tsx')) {
            return true;
        }

        return glob($dir . '/*/Pages', GLOB_ONLYDIR) !== [];
    }

    public function path(): string
    {
        return $this->directory . '/ui.json';
    }

    /** The import alias other packages use, e.g. `@user`. */
    public function alias(): string
    {
        return (string) ($this->data['alias'] ?? '');
    }

    public function entry(): string
    {
        return (string) ($this->data['entry'] ?? 'index.ts');
    }

    public function framework(): string
    {
        return (string) ($this->data['framework'] ?? 'react');
    }

    /**
     * surface => path, relative to ui/. The DECLARED map.
     *
     * Note the surface bundler does not read this to find pages — it globs
     * `admin/Pages` and `site/Pages` by convention. A declaration pointing
     * somewhere else therefore federates nothing, which is why it is checked.
     *
     * @return array<string, string>
     */
    public function surfaces(): array
    {
        $surfaces = $this->data['surfaces'] ?? [];

        return \is_array($surfaces) ? array_map('strval', $surfaces) : [];
    }

    /** @return array<string, string> export name => file, relative to ui/ */
    public function exports(): array
    {
        $exports = $this->data['exports'] ?? [];

        return \is_array($exports) ? array_map('strval', $exports) : [];
    }

    /** @return array<string, string> */
    public function dependencies(): array
    {
        $deps = $this->data['dependencies'] ?? [];

        return \is_array($deps) ? array_map('strval', $deps) : [];
    }

    /** True when the plugin ships any page for any face. */
    public function hasPages(): bool
    {
        return $this->pageFiles() !== [];
    }

    /**
     * Every page file the plugin ships, as absolute paths.
     *
     * Read from the CONVENTIONAL locations the surfaces actually glob
     * (`admin/Pages/**`, `site/Pages/**`), not from the declared surfaces map —
     * because that is what determines whether a page can be found at runtime.
     *
     * @return list<string>
     */
    public function pageFiles(): array
    {
        $files = [];

        foreach (['admin', 'site'] as $face) {
            $files = [...$files, ...self::tsxUnder($this->directory . '/' . $face . '/Pages')];
        }

        return $files;
    }

    /** @return list<string> */
    public function pageFilesFor(string $face): array
    {
        return self::tsxUnder($this->directory . '/' . $face . '/Pages');
    }

    /** Does the plugin register sidebar entries? @see ui/admin/nav.ts */
    public function hasAdminNav(): bool
    {
        return is_file($this->directory . '/admin/nav.ts');
    }

    /** @return list<string> every .tsx under a directory, recursively */
    public static function tsxUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() === 'tsx') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
