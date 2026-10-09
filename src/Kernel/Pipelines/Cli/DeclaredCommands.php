<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\CLIApplication;

/**
 * The commands modules DECLARE in module.json `commands[]` that Provider::boot()
 * did not register.
 *
 * CompileCommandManifestStage has always compiled `commands[]` into
 * command-manifest.php, but nothing read it, so a command declared there and not
 * ALSO registered in boot() silently did not exist. Entries are skipped when:
 *
 *   • boot() already registered the class or the name — boot() always wins;
 *   • the handler is not a loadable AbstractCommand class (the bare-name
 *     inventory form, e.g. "migrate:run");
 *   • the constructor takes ANY parameter, optional ones included. Building it
 *     from the CoreContainer would wire it to the CORE bindings — the container
 *     fills an optional typed parameter rather than leaving its default — and a
 *     module that deliberately builds its command from a scoped container (e.g.
 *     against a central connection rather than the project's DatabasePort)
 *     would get a working-looking command on the wrong database. Commands with
 *     dependencies stay boot()'s job, as they always were.
 *
 * A class of its own, not CliPipeline methods, so this code is compiled only
 * when a CLI process needs it: every entry point constructs CliPipeline, the
 * HTTP one included. CliPipeline loads it lazily too — run() skips it when the
 * requested command is already registered, so running an ordinary command
 * reads no manifest.
 */
final class DeclaredCommands
{
    /**
     * Add every qualifying declared command to the application.
     *
     * @param list<string>                             $registeredClasses command classes boot() registered
     * @param \Closure(class-string<AbstractCommand>): ?AbstractCommand $instantiate CliPipeline's builder
     */
    public static function register(CLIApplication $app, array $registeredClasses, \Closure $instantiate): void
    {
        foreach (self::pending(array_flip($registeredClasses), $app->has(...)) as $class) {
            $command = $instantiate($class);
            if ($command !== null && !$app->has($command->getName())) {
                $app->add($command);
            }
        }
    }

    /**
     * @param array<string, mixed>   $registered   command classes boot() registered, as keys
     * @param \Closure(string): bool $isRegistered whether a command NAME is already taken
     * @return list<class-string<AbstractCommand>>
     */
    public static function pending(array $registered, \Closure $isRegistered): array
    {
        $pending = [];

        foreach (ManifestReader::readCompiled('command-manifest.php') as $name => $entry) {
            $class = is_array($entry) ? (string) ($entry['handler'] ?? '') : '';

            if ($class === '' || isset($registered[$class]) || isset($pending[$class]) || $isRegistered((string) $name)) {
                continue;
            }
            if (!class_exists($class) || !is_subclass_of($class, AbstractCommand::class) || self::takesParameters($class)) {
                continue;
            }

            $pending[$class] = true;
        }

        /** @var list<class-string<AbstractCommand>> */
        return array_keys($pending);
    }

    private static function takesParameters(string $class): bool
    {
        try {
            $ctor = (new \ReflectionClass($class))->getConstructor();
        } catch (\ReflectionException) {
            return true;
        }

        return $ctor !== null && $ctor->getNumberOfParameters() > 0;
    }
}
