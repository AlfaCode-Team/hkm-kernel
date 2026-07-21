<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\BufferIO;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the parser's unknown-option handling: a misspelled flag
 * (the real-world `--tsl=both` for `--tls=both`) must FAIL loudly with a non-zero
 * exit, not silently no-op. See Bug 2.
 */
final class UnknownOptionTest extends TestCase
{
    private function command(): AbstractCommand
    {
        return new class extends AbstractCommand {
            public ?string $seenTls = null;
            public bool $handled = false;

            protected function configure(): void
            {
                $this->name = 'edge:apply-fake';
                $this->addOption('tls', '', 'TLS mode', true);
                $this->addOption('dry-run', '', 'Dry run');
                $this->addOption('development', 'd', 'Dev env');
            }

            protected function handle(): int
            {
                $this->handled = true;
                $this->seenTls = $this->option('tls');

                return self::SUCCESS;
            }
        };
    }

    public function test_misspelled_tsl_fails_and_does_not_run_handle(): void
    {
        $cmd = $this->command();
        $io  = new BufferIO();

        $code = $cmd->execute(['--tsl=both'], $io);

        self::assertSame(AbstractCommand::INVALID, $code, 'a typo must exit non-zero');
        self::assertFalse($cmd->handled, 'handle() must not run when an option is unknown');
        self::assertStringContainsString('Unknown option: --tsl', $io->getOutput());
        // A transposition must rank --tls above a same-Levenshtein-distance option
        // (e.g. --all) that happens to be registered earlier.
        self::assertStringContainsString('did you mean --tls?', $io->getOutput());
        self::assertStringNotContainsString('did you mean --all?', $io->getOutput());
    }

    public function test_unknown_long_flag_is_rejected(): void
    {
        $cmd = $this->command();
        $io  = new BufferIO();

        $code = $cmd->execute(['--totally-unknown'], $io);

        self::assertSame(AbstractCommand::INVALID, $code);
        self::assertStringContainsString('Unknown option: --totally-unknown', $io->getOutput());
    }

    public function test_unknown_short_flag_is_rejected(): void
    {
        $cmd = $this->command();
        $io  = new BufferIO();

        $code = $cmd->execute(['-z'], $io);

        self::assertSame(AbstractCommand::INVALID, $code);
        self::assertStringContainsString('Unknown option: -z', $io->getOutput());
    }

    public function test_correct_flags_still_run(): void
    {
        $cmd = $this->command();
        $io  = new BufferIO();

        $code = $cmd->execute(['--tls=both', '--dry-run'], $io);

        self::assertSame(AbstractCommand::SUCCESS, $code);
        self::assertTrue($cmd->handled);
        self::assertSame('both', $cmd->seenTls);
    }

    public function test_global_flags_are_tolerated(): void
    {
        $cmd = $this->command();
        $io  = new BufferIO();

        // Launcher/CLIApplication inject these; a command that never registers
        // them must not choke.
        $code = $cmd->execute(['--no-ansi', '--debug', '--tls=ssl'], $io);

        self::assertSame(AbstractCommand::SUCCESS, $code);
        self::assertSame('ssl', $cmd->seenTls);
    }

    public function test_interactive_terminal_autocorrects_and_runs(): void
    {
        $cmd = $this->command();
        $io  = new BufferIO();
        $io->setUserInputs(['']); // marks the IO interactive (a human at a TTY)

        $code = $cmd->execute(['--tsl=both'], $io);

        self::assertSame(AbstractCommand::SUCCESS, $code, 'a TTY typo is corrected, not fatal');
        self::assertTrue($cmd->handled);
        self::assertSame('both', $cmd->seenTls, 'the =value is carried onto the corrected flag');
        self::assertStringContainsString('interpreting --tsl as --tls', $io->getOutput());
    }

    public function test_interactive_terminal_still_fails_when_no_close_match(): void
    {
        $cmd = $this->command();
        $io  = new BufferIO();
        $io->setUserInputs(['']); // interactive

        $code = $cmd->execute(['--totally-unrelated-flag'], $io);

        self::assertSame(AbstractCommand::INVALID, $code, 'no confident suggestion → still fails');
        self::assertFalse($cmd->handled);
    }

    public function test_registered_short_option_still_matches(): void
    {
        $cmd = $this->command();
        $io  = new BufferIO();

        $code = $cmd->execute(['-d'], $io);

        self::assertSame(AbstractCommand::SUCCESS, $code);
        self::assertTrue($cmd->handled);
    }
}
