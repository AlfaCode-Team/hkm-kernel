<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground;

use AlfacodeTeam\Ground\Inspection\PluginLocator;
use AlfacodeTeam\Ground\Ui\UiManifest;

/**
 * `hkm ground <verb>` — the plugin developer's bench.
 *
 *     cd ~/…/PLUGINS/PHP/hkm-plugin-user
 *     hkm ground              # where am I, and what can I do
 *     hkm ground install      # dependencies, from the checkouts next door
 *     hkm ground check .      # is this plugin well-formed
 *     hkm ground serve .      # browse it
 *     hkm ground test         # run its tests
 *
 * Six verbs, no arguments needed. The plugin is the one you are standing in;
 * `.` says so explicitly, and a name reaches a different one.
 *
 * ─── WHY A VERB LAYER AT ALL ────────────────────────────────────────────────
 *
 * Underneath, these are ordinary kernel CLI commands — `plugin:check`,
 * `plugin:probe`, `plugin:serve`, `make:ground-test`. That naming is right
 * INSIDE a project, where they sit beside fifty others and the prefix says
 * whose they are. It is wrong on a bench: while developing a plugin there is
 * nothing to disambiguate from, and the plugin's name is the directory you are
 * already in.
 *
 * Anything this does not recognise is passed through, so the long forms and
 * every flag keep working: `hkm ground plugin:check --json`, `hkm ground list`.
 */
final class Cli
{
    /**
     * @param  list<string> $args everything after the `ground` verb
     */
    public static function run(array $args): int
    {
        $verb = $args[0] ?? '';
        $rest = \array_slice($args, 1);

        return match ($verb) {
            'init'                        => self::init($rest),
            'install', 'i'                => self::install($rest),
            'check'                       => self::kernel(['plugin:check', ...$rest]),
            'probe'                       => self::kernel(['plugin:probe', ...$rest]),
            'serve'                       => self::kernel(['plugin:serve', ...$rest]),
            'dev', 'ui'                   => self::kernel(['plugin:dev', ...$rest]),
            'migrate', 'db'               => self::kernel(['plugin:migrate', ...$rest]),
            'new'                         => self::scaffold($rest),
            'test', 't'                   => self::test($rest),
            '', 'help', '--help', '-h'    => self::status(),
            default                       => self::kernel($args),
        };
    }

    // ── Verbs ─────────────────────────────────────────────────────────────────

    /**
     * `ground init` — make this plugin testable, in one command.
     *
     * Every step is idempotent and reports whether it WROTE or KEPT a file, so
     * running it on a plugin that is already set up is safe and says so rather
     * than overwriting work.
     *
     * The order matters. .gitignore is written FIRST, before anything that
     * could hold a credential exists; dependencies come next because the UI and
     * database steps run through the harness; and the scaffolds go last, when
     * there is something to scaffold against.
     *
     * @param list<string> $args
     */
    private static function init(array $args): int
    {
        $plugin = self::current();

        if ($plugin === null) {
            return self::notAPlugin();
        }

        $dir  = $plugin->directory();
        $init = new Init($dir, $plugin->name());

        self::line();
        self::line("  Initialising {$plugin->name()} for testing");
        self::line();

        // 1. Ignore the local-only paths BEFORE any of them can exist.
        $ignored = $init->ignoreLocalFiles();
        self::line($ignored === []
            ? '  · .gitignore   already covers the local files'
            : '  ✓ .gitignore   ignored ' . \count($ignored) . ' local path(s)');

        // 2. The committed test config.
        $init->phpunitConfig();

        if (!\in_array('--no-ci', $args, true)) {
            $init->ciWorkflow();
        }

        foreach ($init->wrote() as $file) {
            self::line("  ✓ wrote        {$file}");
        }
        foreach ($init->kept() as $file) {
            self::line("  · kept         {$file}");
        }

        // 3. Dependencies. Everything below needs the harness on the autoloader.
        self::line();
        self::line('  Installing dependencies…');
        $code = self::shell(escapeshellarg(\dirname(__DIR__) . '/bin/link-local'), $dir);

        if ($code !== 0) {
            return $code;
        }

        // 4. A first test, only when there is none — never over the author's.
        if (!$init->hasTests()) {
            self::line();
            self::line('  Scaffolding a first test…');
            self::kernel(['make:ground-test', $plugin->name()]);
        }

        // 5. The UI half, for a plugin that ships pages.
        if (UiManifest::for($dir)->exists && !\in_array('--no-ui', $args, true)) {
            self::line();
            self::line('  Setting up the UI tests…');
            self::kernel(['make:ui-test', $plugin->name(), '--config']);
            self::shell('npm install --no-audit --no-fund', $dir . '/ui');
        }

        // 6. The database matrix, for a plugin that ships migrations.
        if ($init->hasMigrations() && !\in_array('--no-db', $args, true)) {
            self::line();
            self::line('  Setting up the database harness…');
            self::kernel(['plugin:migrate', $plugin->name(), '--init']);
        }

        self::line();
        self::line('  Done. `ground test` runs the suite; `ground migrate` runs the migrations.');
        self::line('  Commit tests/ and phpunit.xml. Everything in the .gitignore block is local.');
        self::line();

        return 0;
    }

    /** @param list<string> $args */
    private static function install(array $args): int
    {
        $plugin = self::current();

        if ($plugin === null) {
            return self::notAPlugin();
        }

        $dir = $plugin->directory();

        // PHP first: the UI step scaffolds through the harness, which needs the
        // harness installed.
        $code = self::shell(escapeshellarg(\dirname(__DIR__) . '/bin/link-local'), $dir);

        if ($code !== 0) {
            return $code;
        }

        // Then the UI, but only for a plugin that ships one. Doing it for every
        // plugin would pull ~300 npm packages into repositories with no
        // frontend at all.
        if (UiManifest::for($dir)->exists && !\in_array('--no-ui', $args, true)) {
            self::line();
            self::line('ground: installing the UI test setup…');
            self::kernel(['make:ui-test', $plugin->name(), '--config']);
            self::shell('npm install --no-audit --no-fund', $dir . '/ui');
        }

        self::line();
        self::line('ground: ready. Try `hkm ground check`, `test` or `serve`.');

        return 0;
    }

    /** @param list<string> $args */
    private static function scaffold(array $args): int
    {
        // Named for what it makes, not for the command that makes it.
        $what = array_shift($args) ?? 'test';

        return match ($what) {
            'test', 'ground-test' => self::kernel(['make:ground-test', ...$args]),
            'ui', 'ui-test'       => self::kernel(['make:ui-test', ...$args, '--config']),
            default               => self::fail("ground: `new {$what}`? Try `ground new test` or `ground new ui-test`."),
        };
    }

    /** @param list<string> $args */
    private static function test(array $args): int
    {
        $plugin = self::current();

        if ($plugin === null) {
            return self::notAPlugin();
        }

        $dir    = $plugin->directory();
        $failed = 0;

        if (is_file($dir . '/vendor/bin/phpunit')) {
            $failed |= self::shell(
                'vendor/bin/phpunit ' . implode(' ', array_map('escapeshellarg', $args)),
                $dir,
            );
        } else {
            self::line('ground: no vendor/bin/phpunit — run `hkm ground install` first.');
            $failed = 1;
        }

        // The UI half runs only when it CAN. A plugin with pages but no npm
        // install should be told, not silently reported as fully passing.
        if (is_dir($dir . '/ui/__tests__')) {
            if (is_dir($dir . '/ui/node_modules')) {
                self::line();
                $failed |= self::shell('npx vitest run', $dir . '/ui');
            } else {
                self::line();
                self::line('ground: ui/__tests__ exists but node_modules does not — run `hkm ground install`.');
            }
        }

        return $failed === 0 ? 0 : 1;
    }

    private static function status(): int
    {
        $plugin = self::current();

        self::line();

        if ($plugin === null) {
            $found = array_keys(PluginLocator::fromCwd()->all());

            self::line('  ground — no plugin here.');
            self::line();
            self::line('  cd into a plugin, or name one.');
            self::line($found === [] ? '  (none found nearby)' : '  Nearby: ' . implode(', ', $found));
        } else {
            $dir = $plugin->directory();
            $ui  = UiManifest::for($dir);

            self::line('  ' . $plugin->name() . '  —  ' . ($plugin->solves() ?: 'no domain'));
            self::line();
            self::line('  installed   ' . (is_dir($dir . '/vendor') ? 'yes' : 'no — run `hkm ground install`'));
            self::line('  routes      ' . \count($plugin->allRoutes()));
            self::line('  requires    ' . ($plugin->requires() === [] ? '—' : implode(', ', $plugin->requires())));
            self::line('  ui          ' . ($ui->exists
                ? \count($ui->pageFiles()) . ' page(s)' . (is_dir($dir . '/ui/node_modules') ? '' : ' — not installed')
                : 'none'));
        }

        self::line();
        self::line('  init        everything this plugin needs to be testable, once');
        self::line('  install     dependencies only, from the checkouts next door');
        self::line('  check       static checks — the mistakes a boot cannot catch');
        self::line('  probe       boot it and report what compiled');
        self::line('  serve       browse it at http://127.0.0.1:8321');
        self::line('  migrate     run the migrations on every REAL database (--init to configure)');
        self::line('  test        phpunit, and vitest if there are page tests');
        self::line('  new test    scaffold tests from module.json  (new ui-test for pages)');
        self::line();
        self::line('  A plugin name or `.` picks which plugin; the default is the one you are in.');
        self::line('  Anything else is passed to the kernel CLI (`hkm ground list`).');
        self::line();

        return 0;
    }

    // ── Plumbing ──────────────────────────────────────────────────────────────

    /**
     * Run kernel CLI commands in a throwaway workspace.
     *
     * A ground boots a real kernel, which is what registers the commands — the
     * harness cannot be asked to inspect a plugin without one.
     *
     * @param list<string> $args
     */
    private static function kernel(array $args): int
    {
        $ground = PluginGround::for(Provider::class)->boot();

        try {
            // argv[0] is the script name; CliPipeline::run() slices it off.
            return $ground->kernel()->cli()->run(['ground', ...$args]);
        } finally {
            $ground->destroy();
        }
    }

    private static function current(): ?PluginManifest
    {
        return PluginLocator::fromCwd()->current();
    }

    private static function shell(string $command, string $cwd): int
    {
        passthru('cd ' . escapeshellarg($cwd) . ' && ' . $command, $code);

        return $code;
    }

    private static function line(string $text = ''): void
    {
        fwrite(STDOUT, $text . "\n");
    }

    private static function fail(string $message): int
    {
        fwrite(STDERR, $message . "\n");

        return 1;
    }

    private static function notAPlugin(): int
    {
        return self::fail('ground: this directory is not a plugin.');
    }
}
