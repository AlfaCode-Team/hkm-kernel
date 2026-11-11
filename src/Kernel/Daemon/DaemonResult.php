<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Daemon;

/**
 * What an install actually did.
 *
 * Returned rather than printed, so the command owns every word the operator
 * sees and the installer can be tested without capturing output. `notes`
 * carries the things that are neither failures nor silent successes — a config
 * left untouched, a binary already current — which are precisely the outcomes a
 * bare "done" would hide.
 */
final readonly class DaemonResult
{
    /**
     * @param array<string, string> $tokens        resolved template values; the
     *                                             secret is in here, so this
     *                                             must not be logged wholesale
     * @param ?string               $configWritten path, or null when the plugin
     *                                             ships no template
     * @param list<string>          $notes
     */
    public function __construct(
        public DaemonSpec $spec,
        public Platform $platform,
        public DaemonPaths $paths,
        public array $tokens,
        public bool $binaryInstalled,
        public ?string $configWritten,
        public array $notes,
    ) {}

    /**
     * The env values this daemon wants seeded, rendered.
     *
     * @return array<string, string>
     */
    public function env(): array
    {
        $out = [];

        foreach ($this->spec->env as $key => $template) {
            $out[$key] = ConfigRenderer::render($template, $this->tokens, "daemon.env.{$key}");
        }

        return $out;
    }

    /**
     * The argv a supervisor should run, binary first.
     *
     * @return list<string>
     */
    public function command(): array
    {
        return array_merge(
            [$this->paths->binary],
            ConfigRenderer::renderArgv($this->spec->serve, $this->tokens, 'daemon.service.args'),
        );
    }

    /**
     * The same command, quoted for a human to paste.
     *
     * Quoted properly rather than space-joined: a project path with a space in
     * it is ordinary on macOS, and a copied command that silently splits it
     * into two arguments fails with a message about the wrong file.
     */
    public function commandLine(): string
    {
        return implode(' ', array_map(
            static fn (string $part): string => preg_match('/^[A-Za-z0-9._\/:=-]+$/', $part) === 1
                ? $part
                : "'" . str_replace("'", "'\\''", $part) . "'",
            $this->command(),
        ));
    }
}
