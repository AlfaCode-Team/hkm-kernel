<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Daemon;

/**
 * Run a command as an ARGUMENT LIST, never as a shell string.
 *
 * `proc_open` takes an array, and that is the whole reason this exists. The
 * alternative — building a command string and letting `/bin/sh` parse it — puts
 * every path through shell quoting, and the paths here are a project root, a
 * config file and an archive member, at least one of which comes out of a
 * plugin's manifest. A project directory with a space in it is ordinary on
 * macOS; one with a `;` or a backtick is not, but nothing stops it, and with a
 * shell in the loop that stops being a path problem and becomes command
 * execution.
 *
 * With an array there is no shell, no word splitting, and no quoting to get
 * right.
 */
final readonly class Process
{
    private function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    /** stderr, or stdout when stderr is empty — whichever says what happened. */
    public function output(): string
    {
        $stderr = trim($this->stderr);

        return $stderr !== '' ? $stderr : trim($this->stdout);
    }

    /**
     * Run $argv and collect its output.
     *
     * Returns a failed Process rather than throwing when the binary cannot be
     * spawned at all, so every call site handles one shape.
     *
     * @param list<string> $argv
     */
    public static function run(array $argv, ?string $cwd = null, int $timeoutSeconds = 120): self
    {
        if ($argv === []) {
            return new self(127, '', 'no command given');
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes   = [];
        $handle  = @proc_open($argv, $descriptors, $pipes, $cwd);

        if (!\is_resource($handle)) {
            return new self(127, '', 'could not execute ' . $argv[0]);
        }

        // Non-blocking reads on both pipes. Reading stdout to EOF first — the
        // obvious shape — deadlocks the moment a command writes more to stderr
        // than the pipe buffer holds: it blocks writing stderr, we block
        // reading stdout, and neither side moves again.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout   = '';
        $stderr   = '';
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $read   = array_filter([$pipes[1], $pipes[2]], 'is_resource');
            $write  = $except = [];

            if ($read === []) {
                break;
            }

            if (@stream_select($read, $write, $except, 0, 200_000) > 0) {
                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 65_536);

                    if ($chunk === false || $chunk === '') {
                        continue;
                    }

                    if ($pipe === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }

            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($handle, 9);
                $stderr .= "\n(timed out after {$timeoutSeconds}s)";
                break;
            }
        }

        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }

        return new self(proc_close($handle), $stdout, $stderr);
    }

    /**
     * Is this command on PATH?
     *
     * PATH is walked in PHP rather than asking the shell. `command -v` is the
     * obvious probe and it is a shell BUILTIN — there is no such binary, so
     * spawning it through an argument list (which is the point of this class)
     * can only ever fail, and the check would report every tool missing.
     * `which` exists but is not guaranteed to be at a fixed path, and on some
     * minimal images is not installed at all.
     */
    public static function exists(string $command): bool
    {
        if (str_contains($command, '/')) {
            return is_file($command) && is_executable($command);
        }

        $path = getenv('PATH');

        if (!\is_string($path) || $path === '') {
            $path = '/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin';
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, '/') . '/' . $command;

            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }
}
