<?php

declare(strict_types=1);

namespace Tests\Unit\Project\Bootstrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Project\Bootstrap\EntryHelpers;

/**
 * proj.json is read by six helpers per request; the decode must be shared
 * without ever serving an edit late.
 */
#[CoversClass(EntryHelpers::class)]
final class ProjJsonCacheTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/hkm-projjson-' . bin2hex(random_bytes(6));
        mkdir($this->project, 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->project . '/proj.json');
        @rmdir($this->project);
        $this->resetMemo();
    }

    private function resetMemo(): void
    {
        (new \ReflectionProperty(EntryHelpers::class, 'projJsonMemo'))->setValue(null, []);
    }

    /** @param array<string, mixed> $data */
    private function write(array $data, ?int $mtime = null): void
    {
        $file = $this->project . '/proj.json';
        file_put_contents($file, json_encode($data));
        clearstatcache(true, $file);
        if ($mtime !== null) {
            touch($file, $mtime);
            clearstatcache(true, $file);
        }
    }

    private function handlerOf(): ?string
    {
        $routes = EntryHelpers::projectRoutes($this->project);

        return $routes[0]['handler'] ?? null;
    }

    private function route(string $handler): array
    {
        return ['routes' => [['method' => 'GET', 'path' => '/', 'handler' => $handler]]];
    }

    public function test_an_edit_in_the_same_second_with_the_same_size_is_picked_up(): void
    {
        // Same length, same second: the stat identity cannot tell these apart,
        // so a freshly written file must be judged by its bytes.
        $this->write($this->route('Ctrl@aaa'));
        self::assertSame('Ctrl@aaa', $this->handlerOf());

        $this->write($this->route('Ctrl@bbb'));
        self::assertSame('Ctrl@bbb', $this->handlerOf());
    }

    public function test_an_edit_that_moves_the_mtime_is_picked_up_on_the_next_call(): void
    {
        $old = time() - 60;
        $this->write($this->route('Ctrl@aaa'), $old);
        self::assertSame('Ctrl@aaa', $this->handlerOf());

        // Same size, older file re-stamped: identity changes, decode is redone.
        $this->write($this->route('Ctrl@bbb'), $old + 30);
        self::assertSame('Ctrl@bbb', $this->handlerOf());
    }

    public function test_a_settled_file_is_served_from_the_memo_until_it_changes(): void
    {
        $old = time() - 60;
        $this->write($this->route('Ctrl@aaa'), $old);
        self::assertSame('Ctrl@aaa', $this->handlerOf());
        self::assertSame('Ctrl@aaa', $this->handlerOf());

        $memo = (new \ReflectionProperty(EntryHelpers::class, 'projJsonMemo'))->getValue();
        self::assertCount(1, $memo);
        self::assertSame(1, count($memo), 'one entry per project file, not per call');
    }

    public function test_every_helper_reads_the_same_file_version(): void
    {
        $this->write([
            'routes'      => [['method' => 'GET', 'path' => '/', 'handler' => 'C@a']],
            'domains'     => ['Hkmvote.local'],
            'essentials'  => ['tenancy.routing'],
            'routePolicy' => ['disable' => ['GET /x'], 'only' => ['GET /']],
        ]);

        self::assertCount(1, EntryHelpers::projectRoutes($this->project));
        self::assertSame(['hkmvote.local'], EntryHelpers::projectDomains($this->project));
        self::assertSame(['tenancy.routing'], EntryHelpers::projectEssentials($this->project));
        self::assertSame(['GET /x'], EntryHelpers::projectRoutePolicy($this->project));
        self::assertSame(['GET /'], EntryHelpers::projectRouteAllowPolicy($this->project));
    }

    public function test_a_missing_file_yields_empty_answers(): void
    {
        self::assertSame([], EntryHelpers::projectRoutes($this->project));
        self::assertSame([], EntryHelpers::projectRouteGroups($this->project));
        self::assertSame([], EntryHelpers::projectDomains($this->project));
        self::assertSame([], EntryHelpers::projectEssentials($this->project));
        self::assertSame([], EntryHelpers::projectRoutePolicy($this->project));
        self::assertSame([], EntryHelpers::projectRouteAllowPolicy($this->project));
    }

    public function test_invalid_json_yields_empty_answers_without_throwing(): void
    {
        file_put_contents($this->project . '/proj.json', '{"routes": [');
        clearstatcache();

        self::assertSame([], EntryHelpers::projectRoutes($this->project));
        self::assertSame([], EntryHelpers::projectDomains($this->project));
    }

    public function test_apcu_serves_a_new_process_and_still_picks_up_an_edit(): void
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            self::markTestSkipped('APCu is not enabled in this PHP process (run with -d apc.enable_cli=1 to exercise it).');
        }

        $old = time() - 60;
        $this->write($this->route('Ctrl@aaa'), $old);
        self::assertSame('Ctrl@aaa', $this->handlerOf());

        // A new request: the per-process memo is gone, APCu is not.
        $this->resetMemo();
        self::assertTrue(apcu_exists('hkm:proj-json:' . $this->project . '/proj.json'));
        self::assertSame('Ctrl@aaa', $this->handlerOf());

        $this->resetMemo();
        $this->write($this->route('Ctrl@bbb'), $old + 30);
        self::assertSame('Ctrl@bbb', $this->handlerOf(), 'an edit must beat the APCu entry');
    }
}
