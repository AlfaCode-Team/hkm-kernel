<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader, ManifestWriter};
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * Compiles the translation resolution map that the I18n plugin's Translator
 * consumes.
 *
 * WHY THIS EXISTS
 * ---------------
 * The Translator used to take a SINGLE directory, so the only catalogue that
 * could ever be loaded was the one the I18n plugin itself shipped. A plugin had
 * nowhere to register its own messages, and APP_LANG_PATH replaced the directory
 * rather than adding to it — so pointing it at a project catalogue silently
 * removed the plugin's. The practical effect was that every user-facing string
 * outside that one file had to be hard-coded in whatever language it was written
 * in, which is why none of the plugins were translatable.
 *
 * DETERMINISTIC PRIORITY MODEL
 * ----------------------------
 * Identical to views by design — a platform with two different override models
 * is a platform nobody can predict. Every source declares a numeric `priority`,
 * LOWER wins (searched first):
 *
 *   - PROJECT lang paths default to priority 0  → always highest precedence.
 *   - PLUGIN lang paths default to priority 100 → fallbacks below the project.
 *
 * So a project ALWAYS overrides a plugin's wording for the same key by default,
 * which is what lets a deployment reword a plugin's copy without forking it. A
 * plugin may preempt the project only by explicitly declaring a lower (e.g.
 * negative) priority — the same single, opt-in escape hatch views allow.
 *
 * Plain keys ('validation.required') resolve down the global cascade;
 * 'namespace::group.key' targets one plugin's catalogue while still allowing a
 * project override, so two plugins can both define 'messages.title' without
 * colliding.
 *
 * MERGING, NOT SHADOWING
 * ----------------------
 * The Translator merges group files across the cascade rather than taking the
 * first hit wholesale. Overriding one key in a 40-key group must not require
 * copying the other 39 — that is how translations silently rot when the plugin
 * adds a key the project's copy never gets.
 *
 * Declaration shapes (module.json "lang" / proj.json "lang"):
 *   "lang": "lang"                                        // shorthand
 *   "lang": { "path": "lang", "namespace": "user",
 *             "priority": 100, "global": true }
 *   "lang": [ { ... }, { ... } ]                           // several sources
 *
 * Output (lang-manifest.php):
 *   [ 'global'     => [ '/abs/project/lang', '/abs/plugin/lang', ... ],
 *     'namespaces' => [ 'user' => [ '/abs/plugin/user/lang', ... ] ] ]
 *
 * NOTE: this deliberately mirrors CompileViewManifestStage rather than sharing
 * code with it. The common cascade logic is worth extracting, but that stage
 * currently has no test coverage, and refactoring a shipped boot stage blind is
 * a poor trade against duplicating a well-understood 100 lines. Extract once
 * both are covered.
 */
final class CompileLangManifestStage implements BootStageContract
{
    /** Project catalogue root used when proj.json declares nothing. */
    private const PROJECT_DEFAULT = 'resources/lang';

    /**
     * @param list<class-string> $moduleClasses
     */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {}

    public function run(): void
    {
        /** @var list<array{path:string,namespace:?string,priority:int,global:bool,order:int}> $entries */
        $entries = [];
        $order = 0;

        // ── PROJECT sources (priority 0 by default — highest precedence) ──────
        foreach ($this->projectSources() as $src) {
            $entries[] = $this->normalise($src, Paths::project(), defaultPriority: 0, order: $order++);
        }

        // ── PLUGIN sources (priority 100 by default — fallbacks) ──────────────
        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $this->reader->read($moduleClass);
            if (!isset($manifest['lang'])) {
                continue;
            }
            $moduleDir = $this->moduleDir($moduleClass);
            $namespaceDefault = (string) ($manifest['name'] ?? '');
            foreach ($this->toList($manifest['lang']) as $src) {
                $entries[] = $this->normalise(
                    $src,
                    $moduleDir,
                    defaultPriority: 100,
                    order: $order++,
                    defaultNamespace: $namespaceDefault,
                );
            }
        }

        // Stable sort by priority (LOWER first), ties broken by declaration order.
        usort($entries, static fn(array $a, array $b): int =>
            $a['priority'] <=> $b['priority'] ?: $a['order'] <=> $b['order']);

        $global = [];
        $namespaces = [];
        foreach ($entries as $entry) {
            $dir = $entry['path'];
            if ($dir === '' || !is_dir($dir)) {
                continue; // a declared-but-missing dir never breaks boot
            }
            if ($entry['global'] && !in_array($dir, $global, true)) {
                $global[] = $dir;
            }
            if ($entry['namespace'] !== null && $entry['namespace'] !== '') {
                $ns = $entry['namespace'];
                $namespaces[$ns] ??= [];
                if (!in_array($dir, $namespaces[$ns], true)) {
                    $namespaces[$ns][] = $dir;
                }
            }
        }

        ManifestWriter::write('lang-manifest.php', [
            'global' => $global,
            'namespaces' => $namespaces,
        ]);
    }

    /**
     * Project-declared catalogue sources: proj.json "lang", then the
     * conventional default when nothing is declared.
     *
     * @return list<string|array<string,mixed>>
     */
    private function projectSources(): array
    {
        $sources = [];

        $projJson = Paths::project('proj.json');
        if (is_file($projJson)) {
            $decoded = json_decode((string) file_get_contents($projJson), true);
            if (is_array($decoded) && isset($decoded['lang'])) {
                $sources = $this->toList($decoded['lang']);
            }
        }

        if ($sources === []) {
            $sources[] = self::PROJECT_DEFAULT;
        }

        return $sources;
    }

    /**
     * @param string|array<string,mixed> $src
     * @return array{path:string,namespace:?string,priority:int,global:bool,order:int}
     */
    private function normalise(
        string|array $src,
        string $baseDir,
        int $defaultPriority,
        int $order,
        ?string $defaultNamespace = null,
    ): array {
        if (is_string($src)) {
            $src = ['path' => $src];
        }
        if (!isset($src['path']) || !is_string($src['path']) || trim($src['path']) === '') {
            throw new BootException('Invalid "lang" declaration: each source needs a non-empty "path".');
        }

        $path = $src['path'];
        $abs = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            ? rtrim($path, '/\\')
            : rtrim($baseDir, '/') . '/' . ltrim($path, '/');

        $resolved = realpath($abs);

        return [
            'path' => $resolved !== false ? $resolved : $abs,
            'namespace' => isset($src['namespace']) ? (string) $src['namespace'] : $defaultNamespace,
            'priority' => isset($src['priority']) ? (int) $src['priority'] : $defaultPriority,
            'global' => isset($src['global']) ? (bool) $src['global'] : true,
            'order' => $order,
        ];
    }

    /**
     * @param mixed $lang
     * @return list<string|array<string,mixed>>
     */
    private function toList(mixed $lang): array
    {
        if (is_string($lang)) {
            return [$lang];
        }
        if (!is_array($lang)) {
            return [];
        }
        // Associative single declaration vs. a list of declarations.
        if (isset($lang['path']) || array_keys($lang) !== range(0, count($lang) - 1)) {
            return [$lang];
        }
        return array_values($lang);
    }

    /** @param class-string $moduleClass */
    private function moduleDir(string $moduleClass): string
    {
        $file = (new \ReflectionClass($moduleClass))->getFileName();
        if ($file === false) {
            throw new BootException("Cannot locate source file for [{$moduleClass}].");
        }
        return dirname($file);
    }
}
