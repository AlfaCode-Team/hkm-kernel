<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader, ManifestWriter};

/**
 * Reads servers[] from every module.json → server-manifest.php.
 *
 * WHY A SERVER IS DECLARED AND NOT DISCOVERED
 * -------------------------------------------
 * A deployment that is missing a server shows exactly one symptom: everything
 * appears to work and nothing arrives. No request fails, no job is lost, no log
 * line says anything — the realtime clients simply sit there. The same failure
 * as a missing daemon, and it is why `daemon[]` exists; this is its in-process
 * half, for the servers written in PHP rather than fetched as a binary.
 *
 * Declaring them makes the set ENUMERABLE: `server:list` answers "what
 * long-running processes does this deployment need", which is a question an
 * operator otherwise answers by reading every plugin's README.
 *
 * WHAT IS CHECKED HERE, AND WHAT IS DELIBERATELY NOT
 * --------------------------------------------------
 * Checked: the shape, the name's spelling, the uniqueness of the name across
 * modules, and that every extra `requires[]` domain is one some registered
 * module solves. Each of those is a typo whose only other symptom is a server
 * that cannot be started or starts without something it needs.
 *
 * NOT checked: that the class exists. `class_exists()` autoloads, so verifying
 * it here would load every server class — and its whole import graph — on every
 * HTTP request, to catch a mistake that fails loudly and immediately the first
 * time anyone runs the server. {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server\ServerPipeline::run()}
 * reports it there, naming the module whose manifest to fix.
 */
final class CompileServerManifestStage implements BootStageContract
{
    /**
     * A name an operator has to type, and that must not be mistaken for an
     * option. Lower-case because a surface whose name differs only by case
     * between a README and a service unit is a support ticket.
     */
    private const NAME = '/^[a-z0-9][a-z0-9._-]*$/';

    /** @param list<class-string> $moduleClasses */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {
    }

    public function run(): void
    {
        $servers = [];
        $domains = $this->registeredDomains();

        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $this->reader->read($moduleClass);
            $declared = $manifest['servers'] ?? [];

            if (!\is_array($declared)) {
                throw new BootException(
                    "[{$moduleClass}] declares \"servers\" as "
                    . get_debug_type($declared) . '; it must be a list of objects.',
                );
            }

            foreach ($declared as $server) {
                if (!\is_array($server)) {
                    throw new BootException(
                        "[{$moduleClass}] has a servers[] entry that is "
                        . get_debug_type($server) . '. Each entry must be an object with at least '
                        . '"name" and "class" — the name is what an operator types to start it, so it '
                        . 'cannot be derived from the class.',
                    );
                }

                $name = $this->name($server, $moduleClass);

                if (isset($servers[$name])) {
                    throw new BootException(
                        \sprintf(
                            'Two modules declare a server named [%s]: [%s] and [%s]. The name is what '
                            . '`server:run %s` resolves, so one of them would be unreachable — rename one.',
                            $name,
                            (string) $servers[$name]['module'],
                            $moduleClass,
                            $name,
                        ),
                    );
                }

                $class = $server['class'] ?? null;

                if (!\is_string($class) || trim($class) === '') {
                    throw new BootException(
                        "Server [{$name}] in [{$moduleClass}] declares no \"class\". It must name a class "
                        . 'implementing ServerContract, with the doubled backslashes JSON needs for a '
                        . 'namespace.',
                    );
                }

                $servers[$name] = [
                    'class'    => trim($class),
                    'module'   => $moduleClass,
                    'solves'   => \is_string($manifest['solves'] ?? null) ? $manifest['solves'] : '',
                    'requires' => $this->requires($server, $name, $moduleClass, $domains),
                    'why'      => \is_string($server['why'] ?? null) ? $server['why'] : '',
                    'restart'  => self::restart($server['restart'] ?? null),
                ];
            }
        }

        ManifestWriter::write('server-manifest.php', $servers);
    }

    /**
     * @param array<string, mixed> $server
     * @param class-string         $moduleClass
     */
    private function name(array $server, string $moduleClass): string
    {
        $name = $server['name'] ?? null;

        if (!\is_string($name) || trim($name) === '') {
            throw new BootException(
                "[{$moduleClass}] has a servers[] entry with no \"name\". The name is the one an "
                . 'operator types (`server:run <name>`) and the key the manifest is indexed by.',
            );
        }

        $name = trim($name);

        if (preg_match(self::NAME, $name) !== 1) {
            throw new BootException(
                \sprintf(
                    'Server name [%s] in [%s] is not usable on a command line. Use lower-case letters, '
                    . 'digits, and . _ or - after the first character — a name with a space, an upper-case '
                    . 'letter or a leading dash either cannot be typed as one argument or is read as an '
                    . 'option.',
                    $name,
                    $moduleClass,
                ),
            );
        }

        return $name;
    }

    /**
     * Extra module domains this server needs beyond its own module's.
     *
     * Validated against the registered modules for the same reason a route's
     * requires[] is: an unknown domain silently loads nothing, and the server
     * starts without the capability it asked for.
     *
     * @param array<string, mixed>  $server
     * @param class-string          $moduleClass
     * @param array<string, string> $domains domain => declaring module
     * @return list<string>
     */
    private function requires(array $server, string $name, string $moduleClass, array $domains): array
    {
        $declared = $server['requires'] ?? [];

        if (!\is_array($declared)) {
            throw new BootException(
                "Server [{$name}] in [{$moduleClass}] declares \"requires\" as "
                . get_debug_type($declared) . '; it must be a list of module domains.',
            );
        }

        $resolved = [];

        foreach ($declared as $domain) {
            if (!\is_string($domain) || trim($domain) === '') {
                continue;
            }

            $domain = trim($domain);

            if (!isset($domains[$domain])) {
                throw new BootException(
                    \sprintf(
                        'Server [%s] in [%s] requires module domain [%s], which no registered module '
                        . 'solves. Registered domains: %s.',
                        $name,
                        $moduleClass,
                        $domain,
                        $domains === [] ? '(none)' : implode(', ', array_keys($domains)),
                    ),
                );
            }

            $resolved[$domain] = $domain;
        }

        return array_values($resolved);
    }

    /**
     * The restart policy, normalised.
     *
     * Both ceilings are advisory and both default to OFF. A ceiling the kernel
     * invented would surface as a server that exits every few hours for no
     * reason the operator declared, which is indistinguishable from a crash.
     *
     * @return array{memoryMb: int, seconds: int}
     */
    private static function restart(mixed $restart): array
    {
        if (!\is_array($restart)) {
            return ['memoryMb' => 0, 'seconds' => 0];
        }

        return [
            'memoryMb' => max(0, (int) ($restart['memoryMb'] ?? 0)),
            'seconds'  => max(0, (int) ($restart['seconds'] ?? 0)),
        ];
    }

    /**
     * Every domain a registered module solves, mapped to the module.
     *
     * @return array<string, string>
     */
    private function registeredDomains(): array
    {
        $domains = [];

        foreach ($this->moduleClasses as $moduleClass) {
            $solves = $this->reader->read($moduleClass)['solves'] ?? null;

            if (\is_string($solves) && $solves !== '') {
                $domains[$solves] = $moduleClass;
            }
        }

        return $domains;
    }
}
