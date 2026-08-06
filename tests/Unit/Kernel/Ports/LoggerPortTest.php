<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LogLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Logger\Infrastructure\FileLogger;
use Plugins\Logger\Infrastructure\NullLogger;

#[CoversClass(LogLevel::class)]
#[CoversClass(FileLogger::class)]
#[CoversClass(NullLogger::class)]
final class LoggerPortTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/hkm-log-' . bin2hex(random_bytes(6)) . '/app.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
        @rmdir(dirname($this->file));
    }

    private function contents(): string
    {
        return is_file($this->file) ? (string) file_get_contents($this->file) : '';
    }

    // ── LogLevel ────────────────────────────────────────────────────────────

    public function test_levels_are_ordered_by_rfc5424_severity(): void
    {
        self::assertTrue(LogLevel::Emergency->passes(LogLevel::Error), 'emergency is more severe than error');
        self::assertFalse(LogLevel::Debug->passes(LogLevel::Error), 'debug is less severe than error');
        self::assertTrue(LogLevel::Error->passes(LogLevel::Error), 'a level always passes its own threshold');
    }

    public function test_an_unknown_level_string_parses_to_debug(): void
    {
        // Never throw on a bad level — losing one line's severity beats losing
        // the operation that was being logged.
        self::assertSame(LogLevel::Debug, LogLevel::parse('not-a-level'));
        self::assertSame(LogLevel::Warning, LogLevel::parse('WARNING'));
    }

    // ── FileLogger ──────────────────────────────────────────────────────────

    public function test_writes_a_line_with_timestamp_level_and_message(): void
    {
        (new FileLogger($this->file))->warning('disk almost full');

        $out = $this->contents();
        self::assertStringContainsString('warning: disk almost full', $out);
        self::assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2}T/', $out);
    }

    public function test_creates_the_log_directory_on_demand(): void
    {
        self::assertDirectoryDoesNotExist(dirname($this->file));

        (new FileLogger($this->file))->info('first line');

        self::assertFileExists($this->file);
    }

    public function test_interpolates_placeholders_from_context(): void
    {
        (new FileLogger($this->file))->error('tenant {tenant} failed over', ['tenant' => 'acme']);

        self::assertStringContainsString('tenant acme failed over', $this->contents());
    }

    public function test_context_is_also_kept_as_structured_json(): void
    {
        (new FileLogger($this->file))->info('query slow', ['ms' => 1420]);

        // Human-readable message AND machine-parseable context on the same line.
        self::assertStringContainsString('{"ms":1420}', $this->contents());
    }

    public function test_a_non_scalar_placeholder_is_left_alone_not_printed_as_Array(): void
    {
        (new FileLogger($this->file))->info('payload {data}', ['data' => ['a' => 1]]);

        $out = $this->contents();
        self::assertStringContainsString('payload {data}', $out, 'token stays literal');
        self::assertStringNotContainsString('Array', $out);
        self::assertStringContainsString('{"data":{"a":1}}', $out, 'value still travels in context');
    }

    public function test_a_throwable_in_context_is_summarised_not_serialised_whole(): void
    {
        (new FileLogger($this->file))->error('boom', ['exception' => new \RuntimeException('kaboom')]);

        $out = $this->contents();
        self::assertStringContainsString('RuntimeException', $out);
        self::assertStringContainsString('kaboom', $out);
    }

    public function test_unencodable_context_does_not_throw(): void
    {
        // Malformed UTF-8 would make json_encode fail. A logger must never take
        // down the operation it was only observing.
        (new FileLogger($this->file))->info('bad bytes', ['raw' => "\xB1\x31"]);

        self::assertStringContainsString('unencodable', $this->contents());
    }

    public function test_records_below_the_threshold_are_dropped(): void
    {
        $logger = new FileLogger($this->file, LogLevel::Warning);

        $logger->debug('noisy');
        $logger->info('also noisy');
        $logger->error('this matters');

        $out = $this->contents();
        self::assertStringNotContainsString('noisy', $out);
        self::assertStringContainsString('this matters', $out);
    }

    public function test_appends_rather_than_truncating(): void
    {
        $logger = new FileLogger($this->file);
        $logger->info('first');
        $logger->info('second');

        self::assertSame(2, substr_count($this->contents(), PHP_EOL));
    }

    // ── NullLogger ──────────────────────────────────────────────────────────

    public function test_null_logger_satisfies_the_port_and_discards(): void
    {
        $logger = new NullLogger();

        self::assertInstanceOf(LoggerPort::class, $logger);

        // Must accept every level without throwing and record nothing.
        $logger->emergency('ignored');
        $logger->log('error', 'ignored', ['k' => 'v']);

        self::assertSame('', $this->contents());
    }
}
