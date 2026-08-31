<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground;

/**
 * What a command did: its exit code and everything it wrote.
 *
 * Output is captured through php-io-cli's BufferIO, which is what
 * `CLIApplication::withIO()` exists for. One caveat is structural and worth
 * knowing before writing an assertion: the reactive COMPONENTS (Table, Alert,
 * ProgressBar, Spinner) write straight to STDOUT rather than through the
 * injected IO, so a table's rows do NOT appear in `output()`. Assert on the
 * exit code and on the lines a command writes with info()/error()/success();
 * for tabular output, assert on the data the command was given instead.
 */
final class CliResult
{
    public function __construct(
        public readonly int $exitCode,
        private readonly string $output,
    ) {}

    public function output(): string
    {
        return $this->output;
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return $this->exitCode !== 0;
    }

    /** Does the output contain this text? Whitespace-insensitive at the edges. */
    public function sees(string $text): bool
    {
        return str_contains($this->output, $text);
    }

    /** @return list<string> non-empty output lines, trimmed */
    public function lines(): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $this->output)),
            static fn(string $line): bool => $line !== '',
        ));
    }

    /** Decode the output as JSON — for commands with a --json flag. */
    public function json(): array
    {
        $decoded = json_decode(trim($this->output), true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function describe(): string
    {
        return "exit code: {$this->exitCode}\n"
            . 'output: ' . ($this->output === '' ? '(nothing)' : "\n" . $this->output);
    }

    public function __toString(): string
    {
        return $this->describe();
    }
}
