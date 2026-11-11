# Servers — Long-running processes

A server is the fourth runtime surface, alongside HTTP, CLI and workers. It differs from the others in how long it lives, not in what it does: a server owns its own loop, holds state for weeks, and ends only when told to.

Use one for a realtime socket server holding open connections, or a bridge consuming a message bus. Do **not** write one as a plain CLI command — a command has nowhere to put "stop gracefully", so each one grows its own signal trap and ceiling, differently.

## Declaring one

A server is declared in `module.json`, never registered in PHP:

```jsonc
"servers": [
  {
    "name":  "realtime",
    "class": "Plugins\\WebSocket\\Infrastructure\\RealtimeSocketServer",
    "why":   "holds the open connections; without it every client is connected to nothing",
    "requires": ["view.rendering"],
    "restart": { "memoryMb": 512, "seconds": 0 }
  }
]
```

| Key | Meaning |
|---|---|
| `name` | What an operator types. Must match `/^[a-z0-9][a-z0-9._-]*/` |
| `class` | Implements `ServerContract` |
| `why` | One sentence an operator reads before deciding to run it |
| `requires` | Extra module domains beyond the module's own |
| `restart` | Ceilings after which the process exits for a supervisor to replace. `0` = off |

`CompileServerManifestStage` compiles these into `server-manifest.php` at boot.

## Running one

```bash
hkm cli server:list          # every declared server, with its `why`
hkm cli server:run realtime  # start one in the foreground
```

A server runs in the **foreground** and exits; a supervisor restarts it. Failing to start is reported through the `ErrorPipeline`, not merely printed — under a supervisor there may be nothing reading the output, and a server that never came up delivers exactly as much as one that crashed.

## The contract

```php
interface ServerContract
{
    public function serve(ServerContext $context): int;
    public function stop(): void;
}
```

`ServerPipeline` supplies what every long-running loop otherwise re-implements: the scoped container, the SIGTERM/SIGINT/SIGQUIT trap, the memory and lifetime ceilings, and the error pipeline.

### ServerContext

```php
$context->shouldStop();          // poll this in your loop
$context->stopReason();          // why, once it is true
$context->container();           // process-lifetime container
$context->newScope();            // a fresh request-scoped container
$context->logger();              // LoggerPort, if bound
$context->option('host', '');    // flags passed to server:run
$context->flag('verbose');
$context->info('listening', ['port' => 8080]);
```

::: danger container() is process-lifetime
`container()` is built **once** for the whole process. A module that rebinds `DatabasePort` per scope — tenancy does exactly this — would be bound at start-up and then serve the wrong tenant for weeks. `newScope()` is the per-message container; use it for anything handled per unit of work.
:::

## Boot-time guarantees

- The **name** must be typeable, or the boot fails. `"Realtime Server"` is two argv tokens and could never be started.
- Two modules claiming one name **fails the boot**; one of them would be unreachable.
- An unknown `requires[]` domain **fails the boot**, exactly like a route's.
- The **class** is checked at run time, not boot. `class_exists()` autoloads, so checking at boot would load every server class on every HTTP request.

## Forking servers behave differently

Measured on **Swoole 6.2.5**, default `SWOOLE_BASE`. These are observations, not predictions:

- **`start()` IS the loop.** The process that called `run()` becomes the MANAGER; workers are forks.
- **The kernel's SIGTERM trap never fires.** `pcntl_signal_get_handler()` still reports it, in the manager and every worker, and it never runs — Swoole takes the signal natively, below PHP's handler table. So `ServerContract::stop()` is never called. Flush and close in `on('shutdown')` / `on('workerStop')` instead.
- **Poll `shouldStop()` from a Swoole timer, in worker 0 only**, then call `shutdown()`. Every worker polling shuts the server down on whichever grew fastest. The shutdown is still graceful: the port is released and no process is orphaned.
- **The stop reason does not cross the fork.** `ServerPipeline` re-polls after `serve()` returns, so `restart.seconds` is still attributed (wall clock is shared). `restart.memoryMb` and a SIGTERM are not, and the report says *"no reason recorded"* rather than inventing one. Check `ServerLimits::hasReason()`.
- **`restart.memoryMb` is not a per-worker recycle** under Swoole — that is Swoole's own `max_request`. Use `restart.seconds` for "replace the whole server".

## Anti-patterns

- **Real work inside `stop()`.** It is called from a signal handler and runs between two arbitrary opcodes of your loop. Set a flag, wake a transport, return.
- **A loop that never polls `shouldStop()`.** Nothing outside the loop gets a turn, so nothing outside it can enforce a ceiling.
- **Daemonising, forking or writing a pidfile from inside `serve()`.** That is the supervisor's job.
- **Reading host and port from argv.** `server:run` carries only the kernel's own ceiling flags. A variable declared in `config[]` is validated at boot; argv is not.
- **Returning non-zero when the server was asked to stop.** Non-zero means it gave up.

## See also

- [Daemons](/background/daemons) — the external half: provisioning a broker or bus a plugin is a client for.
- [Workers & Jobs](/background/workers-and-jobs) — the surface for discrete units of work.
