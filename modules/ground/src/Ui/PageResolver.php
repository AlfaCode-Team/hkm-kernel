<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Ui;

/**
 * Resolve a Pageflow component NAME to the page file that would serve it —
 * using the surface's own rule, not an approximation of it.
 *
 * This is the one check that closes the widest hole in this stack. A controller
 * returning `render('Auth/Login', 'admin')` type-checks, boots, compiles, passes
 * every PHP test and returns HTTP 200. If no page file matches, the FIRST time
 * anyone finds out is a blank screen in a browser and this in the console:
 *
 *     Page "Auth/Login" not found under Pages/
 *
 * The surface resolves it with exactly one line (src/surfaces/<name>/index.tsx):
 *
 *     const key = Object.keys(pages).find((k) => k.endsWith(`/${name}.tsx`));
 *
 * over these globs, in this order — which is why a project page of the same
 * name wins, and why the ADMIN surface can also serve a plugin's site pages:
 *
 *   admin   ./Pages/**    /plugins/&#42;/admin/Pages/**    /plugins/&#42;/site/Pages/**
 *   project ./Pages/**    /plugins/&#42;/site/Pages/**
 *
 * `endsWith` is reproduced literally, including its consequence: `User/Index`
 * matches `.../Admin/User/Index.tsx` too, because the suffix is all that is
 * compared. Resolving "more correctly" here would report a page missing that
 * the browser finds, which is worse than the bug being checked for.
 *
 * Paths are read from the plugin SOURCE trees (`plugins/&#42;/ui/...`), not from
 * `frontend/plugins/<slug>/`, so the check works before `hkm ui sync` has ever
 * run. The mirror preserves the structure below `ui/`, so the suffixes match.
 */
final class PageResolver
{
    /**
     * Which faces each SCAFFOLDED surface globs, after the project's own pages.
     *
     * Read from the two surfaces the frontend template ships. A project may add
     * more ("copy src/surfaces/admin → src/surfaces/<name>"), and a copy keeps
     * whatever globs it was copied from — so for any other name the convention
     * default below is a GUESS. {@see facesFor()} reads the real answer out of
     * the surface's index.tsx when a frontend root is available, and
     * {@see withFaces()} sets it explicitly.
     */
    private const SURFACE_FACES = [
        'admin'   => ['admin', 'site'],
        'project' => ['site'],
    ];

    /** @var list<string>|null explicit face override for the next add */
    private ?array $faces = null;

    /** @var list<string> absolute page paths, in resolution order */
    private array $candidates = [];

    /** @var list<string> */
    private array $roots = [];

    private function __construct() {}

    /**
     * Resolve against ONE plugin's own pages.
     *
     * The default for a plugin's test suite: it runs inside the plugin repo,
     * where no project and no sibling plugins exist. Widen it with
     * withProject()/withPlugins() when the test is about an override.
     */
    public static function forPlugin(string $pluginDirectory, string $surface = 'admin'): self
    {
        $resolver = new self();
        $resolver->addPluginDirectory($pluginDirectory, $surface);

        return $resolver;
    }

    /** Resolve against nothing yet — build it up with the with*() methods. */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Add a project's own surface pages. They are searched FIRST, which is what
     * makes a project page override a plugin's.
     */
    public function withProject(string $frontendRoot, string $surface = 'admin'): self
    {
        $dir = rtrim($frontendRoot, '/') . '/src/surfaces/' . $surface . '/Pages';

        $this->roots[]    = $dir;
        $this->candidates = [...UiManifest::tsxUnder($dir), ...$this->candidates];

        return $this;
    }

    /** Add every plugin under a `plugins/` directory. */
    public function withPlugins(string $pluginsRoot, string $surface = 'admin'): self
    {
        foreach (glob(rtrim($pluginsRoot, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $this->addPluginDirectory($dir, $surface);
        }

        return $this;
    }

    public function withPluginDirectory(string $pluginDirectory, string $surface = 'admin'): self
    {
        $this->addPluginDirectory($pluginDirectory, $surface);

        return $this;
    }

    /**
     * The file that would serve this component, or null.
     *
     * @param string $name a Pageflow component name, e.g. 'Auth/Login'
     */
    public function resolve(string $name): ?string
    {
        $suffix = '/' . trim($name, '/') . '.tsx';

        foreach ($this->candidates as $path) {
            if (str_ends_with($path, $suffix)) {
                return $path;
            }
        }

        return null;
    }

    public function resolves(string $name): bool
    {
        return $this->resolve($name) !== null;
    }

    /**
     * Every component name reachable through this resolver, derived from the
     * page files — for a failure message that suggests the near miss.
     *
     * @return list<string>
     */
    public function componentNames(): array
    {
        $names = [];

        foreach ($this->candidates as $path) {
            // The name is everything after the last `Pages/` segment, minus .tsx
            // — the same shape the server passes to render().
            if (preg_match('#/Pages/(.+)\.tsx$#', $path, $matches) === 1) {
                $names[$matches[1]] = true;
            }
        }

        $list = array_keys($names);
        sort($list);

        return $list;
    }

    /** @return list<string> the directories searched, for diagnostics */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     * The closest known component names to one that did not resolve.
     *
     * A missing page is almost always a typo or a case difference, and printing
     * the three nearest names turns a blank screen into an obvious fix.
     *
     * @return list<string>
     */
    public function nearest(string $name, int $limit = 3): array
    {
        $names = $this->componentNames();
        if ($names === []) {
            return [];
        }

        $scored = [];
        foreach ($names as $candidate) {
            $scored[$candidate] = levenshtein(strtolower($name), strtolower($candidate));
        }
        asort($scored);

        return \array_slice(array_keys($scored), 0, $limit);
    }

    /**
     * Set the faces a surface globs, overriding the convention default.
     *
     * Call it BEFORE the with*() methods — it applies to directories added
     * after it, which is what makes a custom surface resolvable at all.
     *
     * @param list<string> $faces e.g. ['portal', 'site']
     */
    public function withFaces(array $faces): self
    {
        $this->faces = array_values($faces);

        return $this;
    }

    /**
     * The faces a surface ACTUALLY globs, read from its index.tsx.
     *
     * Removes the guess for a custom surface: the file states its own globs, and
     * `import.meta.glob("/plugins/*<slash>admin/Pages/**")` is unambiguous. Returns
     * null when the file is absent, so the caller can fall back to convention.
     *
     * @return list<string>|null
     */
    public static function facesFor(string $frontendRoot, string $surface): ?array
    {
        $index = rtrim($frontendRoot, '/') . '/src/surfaces/' . $surface . '/index.tsx';
        if (!is_file($index)) {
            return null;
        }

        $source = (string) file_get_contents($index);

        // Only the plugin globs name a face; "./Pages/**" is the project's own
        // and is added separately by withProject().
        if (preg_match_all('#import\.meta\.glob\(\s*["\']/plugins/\*/([^/]+)/Pages#', $source, $matches) === 0) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /** Surfaces a project has scaffolded. @return list<string> */
    public static function surfacesIn(string $frontendRoot): array
    {
        $dirs = glob(rtrim($frontendRoot, '/') . '/src/surfaces/*', GLOB_ONLYDIR) ?: [];

        return array_values(array_map('basename', $dirs));
    }

    private function addPluginDirectory(string $pluginDirectory, string $surface): void
    {
        $ui = rtrim($pluginDirectory, '/') . '/ui';

        foreach ($this->faces ?? self::SURFACE_FACES[$surface] ?? [$surface, 'site'] as $face) {
            $dir = $ui . '/' . $face . '/Pages';
            if (!is_dir($dir)) {
                continue;
            }

            $this->roots[]    = $dir;
            $this->candidates = [...$this->candidates, ...UiManifest::tsxUnder($dir)];
        }
    }
}
