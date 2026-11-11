<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Daemon;

use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\ConfigRenderer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonInstaller;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonPaths;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonRegistry;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonSpec;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Downloader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\EnvSeeder;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Exceptions\DaemonException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Platform;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\ServiceUnit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Provisioning a plugin's daemon.
 *
 * THE TESTS THAT CARRY THE WEIGHT ARE THE REFUSALS
 * ------------------------------------------------
 * This mechanism downloads an executable from the internet and configures it to
 * run as a service. Three of its behaviours are the only thing standing between
 * a release tag and arbitrary code running on a server, and each is a test
 * here:
 *
 *   - an unpinned platform is REFUSED, never downloaded unverified;
 *   - a digest mismatch deletes the download and stops;
 *   - an existing config is never overwritten, because it holds the credential
 *     the running daemon is using.
 *
 * The download itself is behind {@see Downloader} so a tampered archive is two
 * lines rather than a hostile server.
 */
#[CoversClass(DaemonSpec::class)]
#[CoversClass(DaemonInstaller::class)]
#[CoversClass(Platform::class)]
#[CoversClass(ConfigRenderer::class)]
#[CoversClass(EnvSeeder::class)]
#[CoversClass(DaemonPaths::class)]
#[CoversClass(DaemonRegistry::class)]
#[CoversClass(ServiceUnit::class)]
final class DaemonProvisioningTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-daemon-' . bin2hex(random_bytes(6));

        if (!mkdir($this->root . '/plugins/Demo', 0o750, true)) {
            self::markTestSkipped('could not create a scratch project');
        }
    }

    protected function tearDown(): void
    {
        // Positively identified: everything created lives under the one
        // directory this test made, and it is removed by that path. Nothing is
        // deleted by exclusion.
        if ($this->root !== '' && is_dir($this->root)) {
            self::rmrf($this->root);
        }
    }

    // ── The manifest contract ───────────────────────────────────────────────

    public function test_a_plugin_with_no_daemon_block_declares_nothing(): void
    {
        self::assertNull(DaemonSpec::fromManifest(['name' => 'demo'], 'demo'));
        self::assertSame([], DaemonRegistry::all($this->root));
    }

    public function test_a_declared_daemon_is_found_by_scanning_plugins(): void
    {
        $this->writeManifest();

        $found = DaemonRegistry::all($this->root);

        self::assertArrayHasKey('demo', $found);
        self::assertSame('demod', $found['demo']['spec']->name);
        self::assertSame($this->root . '/plugins/Demo', $found['demo']['dir']);

        // Found by manifest name, by directory name, and by binary name — a
        // plugin's folder casing is not derivable from its manifest name, so
        // requiring the exact spelling would make the command unusable.
        foreach (['demo', 'Demo', 'DEMO', 'demod'] as $spelling) {
            self::assertNotNull(DaemonRegistry::find($this->root, $spelling), $spelling);
        }
    }

    /**
     * A version range would make two deployments of one commit differ.
     *
     * And the pinned checksum could not possibly match a moving target, so this
     * is refused rather than resolved.
     */
    #[DataProvider('badVersions')]
    public function test_a_non_exact_version_is_refused(string $version): void
    {
        $this->expectException(DaemonException::class);
        $this->expectExceptionMessageMatches('/must be an exact version/');

        DaemonSpec::fromManifest($this->manifest(['version' => $version]), 'demo');
    }

    /** @return iterable<string, array{string}> */
    public static function badVersions(): iterable
    {
        yield 'latest'  => ['latest'];
        yield 'caret'   => ['^2.15'];
        yield 'tilde'   => ['~2.15.0'];
        yield 'wildcard' => ['2.15.*'];
        yield 'v-prefix' => ['v2.15.2'];
    }

    public function test_a_plain_http_url_is_refused(): void
    {
        $this->expectException(DaemonException::class);
        $this->expectExceptionMessageMatches('/must be https/');

        DaemonSpec::fromManifest(
            $this->manifest(['url' => 'http://example.com/d-{version}-{os}-{arch}.tar.gz']),
            'demo',
        );
    }

    public function test_a_checksum_that_is_not_a_sha256_is_refused(): void
    {
        $this->expectException(DaemonException::class);
        $this->expectExceptionMessageMatches('/not 64 hex characters/');

        DaemonSpec::fromManifest(
            $this->manifest(['platforms' => ['linux-amd64' => 'deadbeef']]),
            'demo',
        );
    }

    public function test_an_empty_platform_map_is_refused(): void
    {
        $this->expectException(DaemonException::class);
        $this->expectExceptionMessageMatches('/nothing could ever be verified/');

        DaemonSpec::fromManifest($this->manifest(['platforms' => []]), 'demo');
    }

    public function test_an_unsupported_archive_type_is_refused(): void
    {
        $this->expectException(DaemonException::class);
        $this->expectExceptionMessageMatches('/Only tar\.gz/');

        DaemonSpec::fromManifest($this->manifest(['archive' => 'zip']), 'demo');
    }

    // ── Platform ────────────────────────────────────────────────────────────

    #[DataProvider('platforms')]
    public function test_platform_tokens_are_go_s_not_php_s(string $os, string $machine, string $expected): void
    {
        self::assertSame($expected, Platform::of($os, $machine)->key());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function platforms(): iterable
    {
        yield 'mac intel'   => ['Darwin', 'x86_64', 'darwin-amd64'];
        yield 'mac silicon' => ['Darwin', 'arm64', 'darwin-arm64'];
        yield 'linux intel' => ['Linux', 'x86_64', 'linux-amd64'];
        yield 'linux arm'   => ['Linux', 'aarch64', 'linux-arm64'];
    }

    public function test_an_unknown_architecture_is_refused_rather_than_guessed(): void
    {
        $this->expectException(DaemonException::class);
        $this->expectExceptionMessageMatches('/Exec format error/');

        Platform::of('Linux', 'riscv64');
    }

    public function test_windows_is_refused_with_the_reason(): void
    {
        $this->expectException(DaemonException::class);
        $this->expectExceptionMessageMatches('/Service Control Manager/');

        Platform::of('Windows', 'x86_64');
    }

    public function test_the_supervisor_follows_the_os(): void
    {
        self::assertSame('launchd', Platform::of('Darwin', 'arm64')->supervisor());
        self::assertSame('systemd', Platform::of('Linux', 'x86_64')->supervisor());
    }

    // ── THE REFUSALS ────────────────────────────────────────────────────────

    /**
     * An unpinned platform is refused. There is no unverified path.
     *
     * The tempting shape is "use the digest when one is pinned, otherwise fetch
     * SHA256SUMS / otherwise just install it" — and that branch becomes the
     * branch every un-pinned platform takes, silently, on the machines nobody
     * tested.
     */
    public function test_a_platform_with_no_pinned_digest_is_refused_and_nothing_is_downloaded(): void
    {
        $spec       = DaemonSpec::fromManifest($this->manifest(), 'demo');
        $downloader = new RecordingDownloader('irrelevant');

        self::assertNotNull($spec);
        self::assertFalse($spec->supports(Platform::of('Linux', 'aarch64')));

        try {
            (new DaemonInstaller($downloader))->install($this->root, $spec, Platform::of('Linux', 'aarch64'));
            self::fail('an unpinned platform must not be installed');
        } catch (DaemonException $e) {
            self::assertStringContainsString('no pinned checksum for linux-arm64', $e->getMessage());
            self::assertStringContainsString('is not offered', $e->getMessage());
        }

        self::assertSame([], $downloader->fetched, 'nothing may be downloaded');
    }

    /**
     * A digest mismatch stops the install and deletes the download.
     *
     * The archive is never handed to `tar`, so a tampered one is not even read.
     */
    public function test_a_tampered_archive_is_refused_and_deleted(): void
    {
        $spec = DaemonSpec::fromManifest($this->manifest(), 'demo');
        self::assertNotNull($spec);

        $installer = new DaemonInstaller(new RecordingDownloader('these are not the pinned bytes'));
        $paths     = DaemonPaths::for($this->root, $spec, 'linux');

        try {
            $installer->install($this->root, $spec, Platform::of('Linux', 'x86_64'));
            self::fail('a digest mismatch must stop the install');
        } catch (DaemonException $e) {
            self::assertStringContainsString('Refusing to install', $e->getMessage());
            self::assertStringContainsString('There is no flag to skip this check', $e->getMessage());
        }

        self::assertFalse($paths->isInstalled(), 'no binary may be placed');
        self::assertDirectoryDoesNotExist($paths->dir . '/.download', 'the download is cleaned up');
    }

    // ── The happy path, with a real tarball built here ──────────────────────

    public function test_a_matching_archive_is_unpacked_configured_and_recorded(): void
    {
        $archive = $this->makeTarball('#!/bin/sh' . "\n" . 'echo demod 1.2.3' . "\n");
        $digest  = hash('sha256', $archive);

        $spec = DaemonSpec::fromManifest($this->manifest([
            'platforms' => ['linux-amd64' => $digest],
        ]), 'demo');

        self::assertNotNull($spec);

        $result = (new DaemonInstaller(new RecordingDownloader($archive)))
            ->install($this->root, $spec, Platform::of('Linux', 'x86_64'));

        self::assertTrue($result->binaryInstalled);
        self::assertTrue($result->paths->isInstalled());
        self::assertSame('demod 1.2.3', trim((string) shell_exec(escapeshellarg($result->paths->binary))));

        // 0750: readable and executable by the owner and the group that the
        // web account is in, and nothing to anyone else.
        self::assertSame('0750', substr(sprintf('%o', fileperms($result->paths->binary)), -4));

        // The config was rendered, and the secret is 0600.
        self::assertNotNull($result->configWritten);
        self::assertStringContainsString('port = 7777', (string) file_get_contents($result->configWritten));
        self::assertSame('0600', substr(sprintf('%o', fileperms($result->paths->secretFile)), -4));

        // And it is recorded, so a second run knows it is current.
        $record = json_decode((string) file_get_contents($result->paths->dir . '/installed.json'), true);
        self::assertIsArray($record);
        self::assertSame('1.2.3', $record['version']);
        self::assertSame('linux-amd64', $record['platform']);
        self::assertSame($digest, $record['sha256']);
    }

    /**
     * A second run downloads nothing and rotates nothing.
     *
     * This runs on every deploy, so idempotence is the feature. Minting a new
     * password would leave the daemon's config and the project's `.env`
     * disagreeing while both files still looked right.
     */
    public function test_a_second_run_is_a_no_op_and_keeps_the_same_secret(): void
    {
        $archive    = $this->makeTarball("#!/bin/sh\nexit 0\n");
        $spec       = DaemonSpec::fromManifest($this->manifest([
            'platforms' => ['linux-amd64' => hash('sha256', $archive)],
        ]), 'demo');
        $downloader = new RecordingDownloader($archive);
        $installer  = new DaemonInstaller($downloader);

        self::assertNotNull($spec);

        $first = $installer->install($this->root, $spec, Platform::of('Linux', 'x86_64'));
        self::assertCount(1, $downloader->fetched);

        $second = $installer->install($this->root, $spec, Platform::of('Linux', 'x86_64'));

        self::assertCount(1, $downloader->fetched, 'the second run must not download');
        self::assertFalse($second->binaryInstalled);
        self::assertSame($first->tokens['secret'], $second->tokens['secret'], 'the secret must not rotate');
        self::assertNotEmpty($second->notes);
        self::assertStringContainsString('already installed', $second->notes[0]);
    }

    /** --force re-downloads, and still does not rotate the secret. */
    public function test_force_redownloads_but_still_keeps_the_secret(): void
    {
        $archive    = $this->makeTarball("#!/bin/sh\nexit 0\n");
        $spec       = DaemonSpec::fromManifest($this->manifest([
            'platforms' => ['linux-amd64' => hash('sha256', $archive)],
        ]), 'demo');
        $downloader = new RecordingDownloader($archive);
        $installer  = new DaemonInstaller($downloader);

        self::assertNotNull($spec);

        $first  = $installer->install($this->root, $spec, Platform::of('Linux', 'x86_64'));
        $second = $installer->install($this->root, $spec, Platform::of('Linux', 'x86_64'), force: true);

        self::assertCount(2, $downloader->fetched);
        self::assertTrue($second->binaryInstalled);
        self::assertSame($first->tokens['secret'], $second->tokens['secret']);
    }

    /**
     * An existing config is NEVER overwritten.
     *
     * It holds the password the running daemon is using, plus whatever limits
     * and TLS paths an operator tuned. Replacing it on a deploy would discard
     * that work AND change the credential out from under the `.env`.
     */
    public function test_an_existing_config_is_left_alone_and_the_new_one_goes_beside_it(): void
    {
        $archive   = $this->makeTarball("#!/bin/sh\nexit 0\n");
        $spec      = DaemonSpec::fromManifest($this->manifest([
            'platforms' => ['linux-amd64' => hash('sha256', $archive)],
        ]), 'demo');
        $installer = new DaemonInstaller(new RecordingDownloader($archive));

        self::assertNotNull($spec);

        $first = $installer->install($this->root, $spec, Platform::of('Linux', 'x86_64'));

        file_put_contents((string) $first->configWritten, "# tuned by hand\nport = 9999\n");

        $second = $installer->install($this->root, $spec, Platform::of('Linux', 'x86_64'), force: true);

        self::assertStringContainsString(
            'tuned by hand',
            (string) file_get_contents((string) $second->configWritten),
            'the operator\'s file must survive',
        );
        self::assertFileExists($second->configWritten . '.new');
        self::assertStringContainsString('port = 7777', (string) file_get_contents($second->configWritten . '.new'));
        self::assertStringContainsString('NOT touched', implode(' ', $second->notes));
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    /**
     * An unresolved placeholder stops the write.
     *
     * Leaving `{port}` in place writes literal braces a daemon either rejects
     * at startup or reads as a value; substituting empty makes `port = ""`,
     * which is syntactically valid and means something else entirely.
     */
    public function test_an_unresolved_placeholder_is_an_error(): void
    {
        try {
            ConfigRenderer::render('a = {known}, b = {unknown}, c = {alsogone}', ['known' => '1'], 'test.conf');
            self::fail('should have refused');
        } catch (DaemonException $e) {
            self::assertStringContainsString('{unknown}', $e->getMessage());
            self::assertStringContainsString('{alsogone}', $e->getMessage());
            self::assertStringContainsString('Nothing was written', $e->getMessage());
        }
    }

    public function test_argv_is_rendered_per_argument_not_as_one_string(): void
    {
        self::assertSame(
            ['-c', '/a path/with spaces/x.conf'],
            ConfigRenderer::renderArgv(['-c', '{config}'], ['config' => '/a path/with spaces/x.conf'], 'argv'),
        );
    }

    /** A path with a space survives being shown to a human. */
    public function test_the_printed_command_line_quotes_what_needs_quoting(): void
    {
        $archive = $this->makeTarball("#!/bin/sh\nexit 0\n");
        $spec    = DaemonSpec::fromManifest($this->manifest([
            'platforms' => ['linux-amd64' => hash('sha256', $archive)],
        ]), 'demo');

        self::assertNotNull($spec);

        $result = (new DaemonInstaller(new RecordingDownloader($archive)))
            ->install($this->root, $spec, Platform::of('Linux', 'x86_64'));

        $line = $result->commandLine();

        self::assertStringContainsString('-c', $line);
        self::assertSame(
            $result->command(),
            array_merge([$result->paths->binary], ['-c', $result->paths->config]),
        );
    }

    // ── .env ────────────────────────────────────────────────────────────────

    public function test_env_keys_are_added_once_and_never_modified(): void
    {
        file_put_contents($this->root . '/.env', "APP_NAME=demo\nDEMO_PASS=already-set\n");

        $outcome = EnvSeeder::seed($this->root, 'daemon: demod', [
            'DEMO_URL'  => 'demo://127.0.0.1:7777',
            'DEMO_PASS' => 'freshly-generated',
        ]);

        self::assertSame(EnvSeeder::ADDED, $outcome['DEMO_URL']);
        // KEPT, not UNCHANGED: the existing value DIFFERS from the generated
        // one, which is the only case worth warning about.
        self::assertSame(EnvSeeder::KEPT, $outcome['DEMO_PASS']);

        $env = (string) file_get_contents($this->root . '/.env');

        self::assertStringContainsString('DEMO_URL=demo://127.0.0.1:7777', $env);
        self::assertStringContainsString('DEMO_PASS=already-set', $env);
        // The generated credential must NOT be in the file: a deploy that
        // silently replaced a password is how an application stops
        // authenticating with nothing in the log to explain it.
        self::assertStringNotContainsString('freshly-generated', $env);
    }

    /**
     * A COMMENTED key counts as already present.
     *
     * `hkm plugins enable` writes optional variables as `# KEY=`, and a naive
     * "is there an active KEY=" test would append a second, active line. Most
     * `.env` parsers take the last one, so the file would then disagree with
     * what a reader scrolling from the top sees.
     */
    public function test_a_commented_key_is_treated_as_present(): void
    {
        file_put_contents($this->root . '/.env', "# DEMO_URL=\n#DEMO_PASS=\nexport DEMO_TOKEN=x\n");

        $outcome = EnvSeeder::seed($this->root, 'daemon: demod', [
            'DEMO_URL'   => 'a',
            'DEMO_PASS'  => 'b',
            'DEMO_TOKEN' => 'c',
        ]);

        self::assertSame(
            [EnvSeeder::KEPT, EnvSeeder::KEPT, EnvSeeder::KEPT],
            array_values($outcome),
        );
    }

    public function test_seeding_nothing_new_writes_nothing(): void
    {
        file_put_contents($this->root . '/.env', "DEMO_URL=x\n");
        $before = (string) file_get_contents($this->root . '/.env');

        EnvSeeder::seed($this->root, 'daemon: demod', ['DEMO_URL' => 'y']);

        self::assertSame($before, (string) file_get_contents($this->root . '/.env'));
    }

    /**
     * A key already holding the RIGHT value is reported quietly.
     *
     * This is what a repeat deploy looks like, and it is the common case.
     * Reporting it as a warning — which is what collapsing it into KEPT did —
     * printed one per key per deploy for a file that had not changed.
     */
    public function test_an_identical_existing_value_is_unchanged_not_kept(): void
    {
        file_put_contents(
            $this->root . '/.env',
            "PLAIN=same\nQUOTED=\"same value\"\nSINGLE='same'\nWITHCOMMENT=same # why\nEXPORTED=same\n",
        );

        $outcome = EnvSeeder::seed($this->root, 'daemon: demod', [
            'PLAIN'       => 'same',
            'QUOTED'      => 'same value',
            'SINGLE'      => 'same',
            'WITHCOMMENT' => 'same',
            'EXPORTED'    => 'different',
        ]);

        self::assertSame(EnvSeeder::UNCHANGED, $outcome['PLAIN']);
        self::assertSame(EnvSeeder::UNCHANGED, $outcome['QUOTED'], 'double quotes are stripped');
        self::assertSame(EnvSeeder::UNCHANGED, $outcome['SINGLE'], 'single quotes are stripped');
        self::assertSame(EnvSeeder::UNCHANGED, $outcome['WITHCOMMENT'], 'an inline comment is not part of the value');
        self::assertSame(EnvSeeder::KEPT, $outcome['EXPORTED'], 'a genuine disagreement still warns');
    }

    /** The LAST assignment wins, because that is what .env parsers read. */
    public function test_the_last_assignment_is_the_one_compared(): void
    {
        file_put_contents($this->root . '/.env', "DEMO_URL=first\nDEMO_URL=second\n");

        $outcome = EnvSeeder::seed($this->root, 'daemon: demod', ['DEMO_URL' => 'second']);

        self::assertSame(EnvSeeder::UNCHANGED, $outcome['DEMO_URL']);
    }

    // ── Service units ───────────────────────────────────────────────────────

    /**
     * The systemd unit carries the settings a hand-written one omits.
     *
     * Each of these is a real incident: no `RestartSec` is a crash loop at fork
     * speed, no `LimitNOFILE` refuses the 1025th client as "too many open
     * files", no `ExecReload` turns a config change into a dropped subscriber.
     */
    public function test_the_systemd_unit_has_a_backoff_a_descriptor_limit_and_a_reload(): void
    {
        $unit = ServiceUnit::render($this->installed(Platform::of('Linux', 'x86_64')), 'hkm');

        self::assertStringContainsString('Restart=always', $unit);
        self::assertStringContainsString('RestartSec=2s', $unit);
        self::assertStringContainsString('LimitNOFILE=65536', $unit);
        self::assertStringContainsString('ExecReload=', $unit);
        self::assertStringContainsString('User=hkm', $unit);
        self::assertStringContainsString('ProtectSystem=strict', $unit);
        self::assertStringContainsString('NoNewPrivileges=true', $unit);
        self::assertStringContainsString('TimeoutStopSec=30s', $unit);
    }

    /** With no --user, the unit says to set one rather than defaulting to root. */
    public function test_the_systemd_unit_does_not_silently_run_as_root(): void
    {
        $unit = ServiceUnit::render($this->installed(Platform::of('Linux', 'x86_64')));

        self::assertStringContainsString('# User=hkm', $unit);
        self::assertStringContainsString('not necessary', $unit);
    }

    /**
     * launchd's KeepAlive is a dict, not `<true/>`.
     *
     * A bare true restarts the job on ANY exit including a clean one, so a
     * daemon you stopped comes straight back and looks unkillable.
     */
    public function test_the_launchd_plist_restarts_only_on_failure(): void
    {
        $plist = ServiceUnit::render($this->installed(Platform::of('Darwin', 'arm64')));

        self::assertStringContainsString('<key>KeepAlive</key>', $plist);
        self::assertStringContainsString('<key>SuccessfulExit</key>', $plist);
        self::assertStringContainsString('<false/>', $plist);
        self::assertStringNotContainsString("<key>KeepAlive</key>\n    <true/>", $plist);

        // And it must be parseable XML, or launchctl rejects it with a message
        // about a property list rather than about anything useful.
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($plist);
        libxml_use_internal_errors($previous);

        self::assertNotFalse($document, 'the plist must be valid XML');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function installed(Platform $platform): \AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonResult
    {
        // The archive's member path embeds the platform, so a fixture built
        // for linux cannot stand in for a darwin install — the extraction
        // would fail on a member that is not in the archive.
        $archive = $this->makeTarball("#!/bin/sh\nexit 0\n", $platform->key());
        $spec    = DaemonSpec::fromManifest($this->manifest([
            'platforms' => [$platform->key() => hash('sha256', $archive)],
        ]), 'demo');

        self::assertNotNull($spec);

        return (new DaemonInstaller(new RecordingDownloader($archive)))
            ->install($this->root, $spec, $platform);
    }

    /** @param array<string, mixed> $overrides */
    private function manifest(array $overrides = []): array
    {
        return [
            'name'   => 'demo',
            'daemon' => array_merge([
                'name'      => 'demod',
                'version'   => '1.2.3',
                'why'       => 'the demo plugin is a client for demod.',
                'url'       => 'https://example.test/demod-{version}-{os}-{arch}.tar.gz',
                'member'    => 'demod-{version}-{os}-{arch}/demod',
                'platforms' => ['linux-amd64' => str_repeat('a', 64)],
                'defaults'  => ['port' => '7777'],
                'config'    => ['template' => 'daemon/demod.conf.tmpl', 'target' => 'demod.conf'],
                'service'   => ['args' => ['-c', '{config}'], 'reload' => ['-reload', '{pidfile}']],
                'env'       => ['DEMO_URL' => 'demo://127.0.0.1:{port}', 'DEMO_PASS' => '{secret}'],
            ], $overrides),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function writeManifest(array $overrides = []): void
    {
        file_put_contents(
            $this->root . '/plugins/Demo/module.json',
            (string) json_encode($this->manifest($overrides)),
        );

        mkdir($this->root . '/plugins/Demo/daemon', 0o750, true);
        file_put_contents(
            $this->root . '/plugins/Demo/daemon/demod.conf.tmpl',
            "# demod {version}\nport = {port}\npassword = {secret}\nlog = {logfile}\n",
        );
    }

    /**
     * A real gzipped tar containing one executable at the expected member path.
     *
     * Built with the system `tar` rather than hand-rolled, so the installer is
     * reading an archive produced the same way the real ones are.
     */
    private function makeTarball(string $script, string $platform = 'linux-amd64'): string
    {
        $this->writeManifest();

        $member  = 'demod-1.2.3-' . $platform;
        $staging = $this->root . '/.build/' . $member;

        if (!is_dir($staging)) {
            mkdir($staging, 0o750, true);
        }

        file_put_contents($staging . '/demod', $script);
        chmod($staging . '/demod', 0o755);

        $out = $this->root . '/.build/out.tar.gz';
        exec(
            'tar -czf ' . escapeshellarg($out)
            . ' -C ' . escapeshellarg($this->root . '/.build')
            . ' ' . escapeshellarg($member . '/demod') . ' 2>/dev/null',
            $ignored,
            $status,
        );

        if ($status !== 0 || !is_file($out)) {
            self::markTestSkipped('system tar could not build a fixture archive');
        }

        return (string) file_get_contents($out);
    }

    private static function rmrf(string $path): void
    {
        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;

            if (is_dir($full) && !is_link($full)) {
                self::rmrf($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}
