<?php

declare(strict_types=1);

namespace Tests\Feature\Kernel;

use AlfacodeTeam\PhpIoCli\BufferIO;
use AlfacodeTeam\PhpServicePlatform\Commands\Spec\SpecGenerateCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileRouteManifestStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The command, run for real: compile a manifest with the actual boot stage, then
 * execute the command against it and parse the file it wrote.
 *
 * A unit test of the generator proves the transform. This proves the thing a
 * unit test cannot — that the command finds the manifest where the kernel
 * actually writes it, resolves its options, creates its output directory, and
 * emits JSON a parser accepts.
 */
#[CoversClass(SpecGenerateCommand::class)]
final class SpecGenerateCommandTest extends TestCase
{
    private string $root;

    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-speccmd-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousProject = Paths::project();
        Paths::setBase($this->root);
        Paths::setProject($this->root);
    }

    protected function tearDown(): void
    {
        Paths::setProject($this->previousProject);

        $this->removeTree($this->root);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }

    private function compileManifest(): void
    {
        (new CompileRouteManifestStage(
            [],
            projectRoutes: [
                ['method' => 'GET', 'path' => '/things', 'handler' => 'App\\ThingController@index', 'name' => 'things.index'],
                ['method' => 'POST', 'path' => '/things', 'handler' => 'App\\ThingController@create', 'name' => 'things.create', 'filters' => ['auth']],
                ['method' => 'GET', 'path' => '/things/{id:num}', 'handler' => 'App\\ThingController@show', 'name' => 'things.show'],
            ],
            reader: new ManifestReader(),
        ))->run();
    }

    /** @param list<string> $tokens */
    /**
     * Execute the command, capturing BOTH output channels.
     *
     * BufferIO catches what goes through the IOInterface, but php-io-cli's
     * display components — Table, Alert — write to STDOUT directly rather than
     * through the injected IO. Without an output buffer those escape, and
     * phpunit.xml sets beStrictAboutOutputDuringTests, so every test here would
     * be reported risky for output it did not intend to emit.
     *
     * @param list<string> $tokens
     * @return array{int, string}
     */
    private function runCommand(array $tokens): array
    {
        $io = new BufferIO();

        ob_start();

        try {
            $status = (new SpecGenerateCommand())->execute($tokens, $io);
        } finally {
            $printed = (string) ob_get_clean();
        }

        return [$status, $printed . $io->getOutput()];
    }

    public function test_it_writes_a_parseable_document_to_the_default_location(): void
    {
        $this->compileManifest();

        [$status] = $this->runCommand([]);

        $this->assertSame(0, $status);

        $expected = Paths::var('openapi.json');
        $this->assertFileExists($expected);

        /** @var array<string, mixed> $spec */
        $spec = json_decode((string) file_get_contents($expected), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('/things', $spec['paths']);
        $this->assertArrayHasKey('/things/{id}', $spec['paths']);
        $this->assertSame('things.index', $spec['paths']['/things']['get']['operationId']);
    }

    public function test_the_output_path_is_honoured_and_its_directory_created(): void
    {
        $this->compileManifest();

        [$status] = $this->runCommand(['--output', 'build/docs/openapi.json']);

        $this->assertSame(0, $status);
        $this->assertFileExists($this->root . '/build/docs/openapi.json');
    }

    public function test_info_and_server_options_reach_the_document(): void
    {
        $this->compileManifest();

        [$status] = $this->runCommand([
            '--title', 'Billing API',
            '--api-version', '2.1.0',
            '--server', 'https://api.example.com/',
        ]);

        $this->assertSame(0, $status);

        /** @var array<string, mixed> $spec */
        $spec = json_decode((string) file_get_contents(Paths::var('openapi.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Billing API', $spec['info']['title']);
        $this->assertSame('2.1.0', $spec['info']['version']);
        // The trailing slash is trimmed — a server URL with one produces '//path'
        // in every generated client.
        $this->assertSame([['url' => 'https://api.example.com']], $spec['servers']);
    }

    public function test_excluded_prefixes_are_omitted(): void
    {
        (new CompileRouteManifestStage(
            [],
            projectRoutes: [
                ['method' => 'GET', 'path' => '/things', 'handler' => 'App\\ThingController@index'],
                ['method' => 'GET', 'path' => '/mock/echo', 'handler' => 'App\\MockController@echo'],
            ],
            reader: new ManifestReader(),
        ))->run();

        [$status] = $this->runCommand(['--exclude', 'mock']);

        $this->assertSame(0, $status);

        /** @var array<string, mixed> $spec */
        $spec = json_decode((string) file_get_contents(Paths::var('openapi.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('/things', $spec['paths']);
        $this->assertArrayNotHasKey('/mock/echo', $spec['paths']);
    }

    /**
     * The failure a developer will actually hit: running this on a tree whose
     * cache was cleared. It must say what is missing and how to fix it, not
     * stack-trace.
     */
    public function test_a_missing_manifest_fails_with_guidance_rather_than_an_exception(): void
    {
        [$status, $output] = $this->runCommand([]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('route manifest', strtolower($output));
        $this->assertFileDoesNotExist(Paths::var('openapi.json'));
    }
}
