# php-io-cli — Standalone CLI Runtime Reference

> **Package:** `alfacode-team/php-io-cli`
> **Namespace:** `AlfacodeTeam\PhpIoCli\`
> **Location:** `modules/php-io-cli/`
> **PHP:** 8.2+  **Runtime deps:** `psr/log: ^3.0` only
>
> `php-io-cli` is a **completely standalone CLI framework**. Symfony Console is an optional
> dev dependency used only as a non-TTY fallback inside `ConsoleIO`/`BufferIO` —
> `AbstractCommand`, `CLIApplication`, and all interactive components have zero Symfony dependency.

---

## Architecture

```
CLIApplication
    └── AbstractCommand              ← extend this for every command
          ├── IOInterface            ← unified I/O contract (extends PSR-3 LoggerInterface)
          │     ├── ConsoleIO        ← real TTY; delegates to reactive components on TTY,
          │     │                      falls back to Symfony QuestionHelper on pipes/CI
          │     ├── BufferIO         ← in-memory capture for testing
          │     └── NullIO           ← silent, returns defaults (daemons / CI)
          └── Components
                ├── Interactive: TextInput · Password · NumberInput · Confirm
                │               Select · MultiSelect · Autocomplete · DatePicker
                │               RadioGroup · SliderInput
                └── Display:    Table · Alert · ProgressBar · SpinnerComponent
                      └── AbstractPrompt → ILifecycle (mount / render / update / destroy)
                            ├── State      — reactive key-value store with watchers
                            ├── Input      — key binding dispatcher
                            ├── Renderer   — scroll windowing, cursor management
                            └── Terminal   — raw mode, escape sequences, cross-platform
```

---

## AbstractCommand — Base Class

Every module command extends `AbstractCommand`. It is standalone — it does NOT wrap any
Symfony class.

```php
use AlfacodeTeam\PhpIoCli\AbstractCommand;

final class GenerateMonthlyInvoicesCommand extends AbstractCommand
{
    // ── Constructor: inject GDA services (if bound in CoreContainer) ──
    public function __construct(
        private readonly InvoiceServiceContract $invoices,
    ) {}

    // ── configure(): declare metadata, arguments, options ────────────
    protected function configure(): void
    {
        $this->name        = 'invoice:generate-monthly';
        $this->description = 'Generate monthly invoices for all active clients';

        $this->addArgument('month',    'Target month (Y-m)', required: false, default: null);
        $this->addOption('dry-run', 'd', 'Simulate — no invoices created');
        $this->addOption('tenant',  't', 'Restrict to a single tenant', acceptsValue: true);
    }

    // ── handle(): business logic, returns POSIX exit code ────────────
    protected function handle(): int
    {
        $month  = $this->argument('month', date('Y-m'));
        $dryRun = $this->hasOption('dry-run');
        $tenant = $this->option('tenant');

        $this->section('Invoice Generation');
        $this->info("Month: {$month}" . ($dryRun ? ' [dry-run]' : ''));

        if (!$this->confirm('Proceed?')) {
            $this->muted('Aborted.');
            return self::SUCCESS;
        }

        $bar = $this->progressBar('Generating', 0); // indeterminate
        $bar->start();

        try {
            $result = $this->invoices->generateMonthly(
                new GenerateMonthlyInvoicesDTO(month: $month, dryRun: $dryRun, tenantId: $tenant)
            );
        } catch (\Throwable $e) {
            $bar->finish('Failed');
            $this->alertError('Generation failed', [$e->getMessage()]);
            return self::FAILURE;
        }

        $bar->finish('Done');
        $this->alertSuccess("Generated {$result->created} invoices", ["Skipped: {$result->skipped}"]);
        return self::SUCCESS;
    }
}
```

### Exit codes

| Constant | Value | Meaning |
|---|---|---|
| `self::SUCCESS` | `0` | Completed normally |
| `self::FAILURE` | `1` | Command failed |
| `self::INVALID` | `2` | Bad input / missing required argument |

### configure() — argument and option registration

```php
// Positional argument
$this->addArgument(
    name:        'environment',
    description: 'Target environment',
    required:    true,
    default:     null,
);

// Boolean flag: --force / -f
$this->addOption('force', 'f', 'Skip confirmation prompts');

// Value-accepting option: --tag=v1.0 or --tag v1.0
$this->addOption('tag', 't', 'Git tag to deploy', acceptsValue: true, default: 'latest');
```

### handle() — reading input

```php
$env    = $this->argument('environment');        // string|null
$force  = $this->hasOption('force');             // bool
$tag    = $this->option('tag', 'latest');        // mixed (with fallback default)
```

### handle() — output helpers

```php
$this->info('Connecting…');                      // cyan
$this->success('Done.');                         // ✔ green
$this->warning('Disk at 80%.');                  // ! yellow (stderr)
$this->error('Connection refused.');             // ✘ red   (stderr)
$this->muted('Skipped — already exists.');       // dim gray

$this->section('Build Pipeline');               // bold cyan heading + underline rule
$this->newLine(2);

// Alert boxes
$this->alertSuccess('Deployed!', ['v2.4.1', 'eu-west-1']);
$this->alertError('Build failed', ['See /var/log/build.log']);
$this->alertWarning('Rate limit at 80%');
$this->alertInfo('New version: 3.0.0');
```

### handle() — component factory shortcuts

```php
$name    = $this->ask('Project name');
$env     = $this->select('Target', ['prod', 'staging', 'dev']);
$ok      = $this->confirm('Continue?');
$bar     = $this->progressBar('Installing', total: 10);  // total=0 → indeterminate
$spin    = $this->spinner('Compiling');
$table   = $this->table();
```

### $hidden flag

```php
protected bool $hidden = true; // hides from `list` output (still executable)
```

---

## CLIApplication — Entry Point

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

(new AlfacodeTeam\PhpIoCli\CLIApplication('MyPlatform', '2.1.0'))
    ->discoverCommands(__DIR__ . '/composer.json')  // reads extra.php-io-cli.commands
    ->add(new SomeExtraCommand())                   // explicit registration
    ->run();
```

### Built-in commands

| Command | Behaviour |
|---|---|
| `list` | All registered commands grouped by namespace (segment before `:`) |
| `help <cmd>` | Detailed usage for a specific command |
| `version` | Application name and version |

### Global flags

| Flag | Effect |
|---|---|
| `--no-ansi` | Disable all ANSI color |
| `--debug` / `-d` | Debug verbosity + `[MiB/s]` timing prefix |

### Not-found handling

Levenshtein distance ≤ 3 against all registered names. On a real TTY with multiple
matches an interactive `Select` picker is shown. Otherwise up to 3 suggestions are
printed to stderr.

### Testing / custom IO

```php
$app->withIO(new BufferIO());     // swap before run()
$app->catchExceptions(false);     // rethrow instead of swallowing (tests)
```

### Composer command discovery

Add to your **project's** `composer.json` (not the library's):

```json
{
  "extra": {
    "php-io-cli": {
      "commands": [
        "App\\Commands\\MigrateCommand",
        "Plugins\\Task\\Infrastructure\\Commands\\TaskListCommand"
      ]
    }
  }
}
```

Classes that are absent, abstract, or not an `AbstractCommand` subclass are silently
skipped (logged under `--debug`).

---

## Interactive Components

All implement `IPromptComponent::run(): mixed`. Call `->run()` to start the reactive
loop and block until the user submits. Raw terminal mode is enabled and restored
automatically, including on `Ctrl+C`.

### TextInput

```php
use AlfacodeTeam\PhpIoCli\Components\TextInput;

$host = (new TextInput('Database host'))
    ->placeholder('localhost')
    ->default('127.0.0.1')
    ->validate(fn(string $v): ?string =>
        filter_var($v, FILTER_VALIDATE_IP) || $v === 'localhost' ? null : 'Invalid hostname'
    )
    ->run(); // string
```

Keys: printable = insert; `←/→` = move cursor; `HOME/END` = jump; `Backspace/Delete` = delete; `Enter` = submit.

### Password

```php
use AlfacodeTeam\PhpIoCli\Components\Password;

$secret = (new Password('Encryption key'))->showStrength()->run(); // string
```

Keys: printable = append; `Backspace` = delete last; `TAB` = toggle plaintext/masked; `Enter` = submit.

Strength: length ≥ 8, ≥ 12, uppercase, digit, special — one point each → `Very weak` to `Strong`.

### NumberInput

```php
use AlfacodeTeam\PhpIoCli\Components\NumberInput;

$port = (new NumberInput('Port'))->min(1)->max(65535)->default(8080)->step(1)->integer()->run(); // int
```

Keys: digits/`-`/`.` = append; `Backspace` = delete; `↑/↓` = step; `Enter` = submit (validates range).

### Confirm

```php
use AlfacodeTeam\PhpIoCli\Components\Confirm;

$ok = (new Confirm('Overwrite?', default: false))->run(); // bool
```

Keys: `y/Y` = yes; `n/N` = no; `←/→` = toggle; `Enter` = confirm.

### Select

Fuzzy-filter single-selection with scroll window (8 items visible).

```php
use AlfacodeTeam\PhpIoCli\Components\Select;

$region = (new Select('Deploy region', ['eu-west-1', 'us-east-1', 'ap-southeast-1']))->run(); // string
```

Keys: printable = fuzzy filter; `↑/↓` = navigate; `Backspace` = delete filter char; `Enter` = confirm.

### MultiSelect

```php
use AlfacodeTeam\PhpIoCli\Components\MultiSelect;

$features = (new MultiSelect('Enable features', ['Auth', 'API Gateway', 'Queue', 'Scheduler']))->run(); // string[]
```

Keys: `↑/↓` = navigate; `Space` = toggle; `Enter` = confirm.

### Autocomplete

```php
use AlfacodeTeam\PhpIoCli\Components\Autocomplete;

$pkg = (new Autocomplete('Package', $allPackages))->maxSuggestions(8)->run(); // string
```

Keys: printable = type/filter; `↑/↓` = navigate dropdown; `TAB` = fill suggestion; `Enter` = confirm.

### DatePicker

```php
use AlfacodeTeam\PhpIoCli\Components\DatePicker;

$date = (new DatePicker('Release date'))->run(); // DateTimeImmutable
```

Keys: `←/→` = prev/next day; `↑/↓` = prev/next week; `[/]` = prev/next month; `t` = today; `Enter` = confirm.

### RadioGroup *(not in module README — fully implemented)*

Best for ≤ 5 mutually exclusive choices. Renders all options at once (no scroll).

```php
use AlfacodeTeam\PhpIoCli\Components\RadioGroup;

$size = (new RadioGroup('T-shirt size', ['S', 'M', 'L', 'XL', 'XXL']))
    ->default('M')
    ->columns(3)     // render side-by-side
    ->run();          // string
```

Keys: `↑/↓/←/→` = move focus; `1-9` = jump to position; `Enter` = confirm.

### SliderInput *(not in module README — fully implemented)*

Horizontal ASCII progress-bar slider for numeric ranges.

```php
use AlfacodeTeam\PhpIoCli\Components\SliderInput;

$volume = (new SliderInput('Volume', min: 0, max: 100))
    ->step(5)->default(50)->integer()->run(); // int

$rate = (new SliderInput('Tax rate', min: 0.0, max: 1.0))
    ->step(0.01)->default(0.2)->run(); // float
```

Keys: `←/→` = one step; `[/]` = jump 10% of range; `HOME/END` = jump to extremes; `Enter` = submit (snaps to step).

---

## Display Components

### Table

```php
use AlfacodeTeam\PhpIoCli\Components\Table;
use AlfacodeTeam\PhpIoCli\Depends\Colors;

Table::make()
    ->headers(['Service', 'Status', 'Latency'])
    ->rows([
        ['api-gateway',  Colors::wrap('healthy',  Colors::GREEN),  '12 ms'],
        ['auth-service', Colors::wrap('degraded', Colors::YELLOW), '340 ms'],
    ])
    ->style('box')          // 'box' | 'bold' | 'compact' | 'minimal'
    ->align([2 => 'right'])
    ->striped()
    ->render();
```

Column widths are measured on the **stripped** (ANSI-free) string — color codes never corrupt alignment.

### Alert

```php
use AlfacodeTeam\PhpIoCli\Components\Alert;

Alert::success('Deployed!',      ['Version: 2.4.1', 'Region: eu-west-1']);
Alert::error('Migration failed', ['Error: duplicate key on users.email']);
Alert::warning('Quota at 80%',  ['Resets in 4 h']);
Alert::info('Maintenance 02:00–04:00 UTC');
Alert::block('FATAL', 'Emergency shutdown required'); // solid background
```

### ProgressBar

```php
use AlfacodeTeam\PhpIoCli\Components\ProgressBar;

// Determinate — shows ETA + throughput
$bar = new ProgressBar('Processing', total: 1000);
$bar->start();
foreach ($records as $r) { process($r); $bar->advance(); }
$bar->finish('All done');

// Indeterminate — bounce animation (total = 0)
$bar = new ProgressBar('Waiting for lock');
$bar->start();
// ... work ...
$bar->finish('Lock acquired');

// Fluent config
$bar->width(60)->fill('▓')->empty('░');

// advance(0) = redraw only (use in Shell::run() tick for animated steps)
$bar->advance(0);
```

### SpinnerComponent

```php
use AlfacodeTeam\PhpIoCli\Components\SpinnerComponent;

$spin = new SpinnerComponent('Connecting', style: 'dots'); // dots|line|bars|pulse|arc|bounce
$spin->start();
$result = doSlowWork();
$result->ok() ? $spin->stop('Connected') : $spin->fail('Refused');
```

---

## Shell Execution

`Shell::run()` uses `proc_open` + `stream_select()` (≤50 ms poll) to drain stdout and stderr
simultaneously — no pipe deadlock possible. A `$tick` callback fires on every poll cycle,
making it composable with progress animations.

```php
use AlfacodeTeam\PhpIoCli\Depends\Shell;

$result = Shell::run(
    command: 'composer install --no-interaction',
    tick:    function (string $lastLine, bool $isStderr): void {
        // called every ≤50 ms
    },
    env: ['COMPOSER_NO_INTERACTION' => '1'],
    cwd: '/var/www/app',
);

if ($result->failed()) {
    echo $result->errors();   // all stderr joined
    exit($result->exitCode);
}
echo $result->output();      // all stdout joined
```

`Shell::capture()` — convenience for quick value reads:

```php
$branch = Shell::capture('git rev-parse --abbrev-ref HEAD', cwd: $root); // string|null
```

**ShellResult API:**

```php
$result->ok()               // exitCode === 0
$result->failed()           // exitCode !== 0
$result->exitCode           // int
$result->output()           // stdout joined with PHP_EOL
$result->errors()           // stderr joined with PHP_EOL
$result->meaningfulErrors() // non-empty stderr lines as string[]
$result->stdout             // string[] raw lines
$result->stderr             // string[] raw lines
```

### Shell + ProgressBar pattern (canonical for animated steps)

```php
protected function handle(): int
{
    $bar = $this->progressBar('Deploying', total: 4);
    $bar->start();

    $result = Shell::run(
        'composer install --no-dev',
        tick: fn() => $bar->advance(0), // redraw without incrementing
        cwd:  $this->projectRoot(),
    );

    if ($result->failed()) {
        $bar->finish('Aborted');
        $this->alertError('composer install failed', $result->meaningfulErrors());
        return self::FAILURE;
    }
    $bar->advance(); // step 1 done — bar moves forward

    $this->generateConfig();
    $bar->advance(); // step 2 done

    $bar->finish('Deployment complete');
    return self::SUCCESS;
}
```

> Never instantiate two `ProgressBar` instances simultaneously — their `moveCursorUp()`
> calls interfere and produce interleaved frames. Pass a single instance by reference into helpers.

---

## I/O Layer

### IOInterface

`IOInterface` extends PSR-3 `LoggerInterface` — severity levels map to ANSI-themed output.

```php
// Verbosity levels
$io->write('Always',          verbosity: IOInterface::NORMAL);
$io->write('With --verbose',  verbosity: IOInterface::VERBOSE);
$io->write('With -vv',        verbosity: IOInterface::VERY_VERBOSE);
$io->write('With --debug',    verbosity: IOInterface::DEBUG);

// Interactive
$io->ask('Name', default: 'world');
$io->askConfirmation('Sure?', default: true);
$io->askAndValidate('Email', fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : throw new \RuntimeException('Invalid'));
$io->askAndHideAnswer('Password');
$io->select('Env', ['prod', 'staging'], default: 'staging');
$io->select('Features', ['Auth', 'API'], default: 0, multiselect: true);
```

### ConsoleIO

Real-terminal implementation. On a TTY (`posix_isatty(STDIN) === true`) every interactive
method delegates to the reactive component. On non-TTY (CI, pipes) it falls back to Symfony
`QuestionHelper`.

```php
use AlfacodeTeam\PhpIoCli\ConsoleIO;
use Symfony\Component\Console\Helper\{HelperSet, QuestionHelper};
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$io = new ConsoleIO(new ArgvInput(), new ConsoleOutput(), new HelperSet([new QuestionHelper()]));
$io->enableDebugging(microtime(true)); // prepends [MiB/s] to every line
```

### BufferIO

In-memory for testing. `getOutput()` strips all ANSI sequences + backspace chars.

```php
use AlfacodeTeam\PhpIoCli\BufferIO;

$io = new BufferIO();
$io->setUserInputs(['n']); // simulated keystrokes — one string per prompt
$command->execute(['my-module'], $io);
assertStringContainsString('Aborted', $io->getOutput());
```

### NullIO

Silent — every write is a no-op, every interactive method returns its `$default`.

```php
$io = new NullIO();
$io->ask('Name', 'fallback');         // 'fallback'
$io->askConfirmation('Sure?', true);  // true
$io->write('ignored');                // no-op
```

---

## Colors Utility

```php
use AlfacodeTeam\PhpIoCli\Depends\Colors;

Colors::wrap('text', Colors::BOLD);
Colors::wrap('text', [Colors::BOLD, Colors::CYAN]);
Colors::success('Done');           // ✔ green bold
Colors::error('Failed');           // ✘ red bold
Colors::warning('Caution');        // ! yellow bold
Colors::info('Note');              // cyan
Colors::muted('Skipped');          // dim gray
Colors::hex('#e94560', 'Alert!');  // true-color
Colors::line('text', [Colors::GREEN, Colors::BOLD]); // print line directly
Colors::strip($ansiString);        // returns plain string (for width measurement / testing)
Colors::enable();                  // force on
Colors::disable();                 // force off (also triggered by --no-ansi)
```

Auto-detects environment: `NO_COLOR`, `FORCE_COLOR`, Windows VT100, Unix TTY.

---

## Internals — Reactive Lifecycle

Every interactive component extends `Component → AbstractPrompt`:

```
run()
 ├── Terminal::enableRaw()
 ├── mount() → setup()     ← wire State + Input bindings
 └── loop:
       ├── render()         ← draw current frame to terminal
       ├── readKey()        ← block until keypress
       └── update()         ← dispatch key → Input bindings → mutate State
           → State watchers fire → context.markDirty() → re-render next cycle
```

On `CTRL+C`: `handleCancel()` prints cancellation and exits cleanly.
On exception: `handleError()` prints error then rethrows.
`destroy()` + `Terminal::disableRaw()` run in a `finally` — terminal always restored.

### State

```php
use AlfacodeTeam\PhpIoCli\Depends\State;

$state = new State(['count' => 0]);
$state->count = 5;                         // magic set
echo $state->count;                        // magic get
$state->batch(['index' => 0, 'done' => false]); // single-notification batch
$state->increment('index', max: 9);        // clamps at max
$state->decrement('index');                // clamps at 0
$state->toggle('selected', 'Auth');        // add if absent, remove if present
$state->watch('index', fn($new, $old, $s) => ...);
```

### Input Bindings

```php
use AlfacodeTeam\PhpIoCli\Depends\Input;

$input = new Input();
$input->bind('ENTER', fn($s) => $this->stop());
$input->bind(['y', 'Y'], fn($s) => $s->confirmed = true);
$input->fallback(function ($s, $key): void {
    if (Key::isPrintable($key)) { $s->value .= $key; }
});
$input->unbind('ESC');
```

Normalized key names: `UP`, `DOWN`, `LEFT`, `RIGHT`, `HOME`, `END`, `ENTER`, `TAB`,
`ESC`, `BACKSPACE`, `DELETE`, `CTRL_C`, `CTRL_D`, printable chars as-is.

### Hooks (Event Bus)

```php
use AlfacodeTeam\PhpIoCli\Hooks;

$hooks = new Hooks();
$hooks->on('submit', function (mixed $value, string $event, Hooks $hooks): void { ... });
$hooks->once('mount', fn() => $this->loadDefaults()); // auto-unsubscribes after first fire
$hooks->dispatch('submit', $resolvedValue);
$handled = $hooks->dispatchUntil('validate', $inputValue); // Chain of Responsibility
$hooks->off('render', $handler); // unsubscribe specific handler
$hooks->off('render');           // remove all handlers for event
```

Standard lifecycle events: `mount`, `render`, `update`, `submit`, `destroy`.

---

## Testing Commands

```php
use AlfacodeTeam\PhpIoCli\BufferIO;
use PHPUnit\Framework\TestCase;

class GenerateInvoicesCommandTest extends TestCase
{
    public function test_generates_successfully(): void
    {
        $io = new BufferIO();
        $io->setUserInputs(['y']); // answer the confirm() prompt

        $cmd  = new GenerateMonthlyInvoicesCommand(new FakeInvoiceService());
        $exit = $cmd->execute(['2025-01'], $io);

        $this->assertSame(AbstractCommand::SUCCESS, $exit);
        $this->assertStringContainsString('Generated', $io->getOutput());
    }

    public function test_aborts_on_decline(): void
    {
        $io = new BufferIO();
        $io->setUserInputs(['n']);

        $exit = (new GenerateMonthlyInvoicesCommand(new FakeInvoiceService()))
            ->execute([], $io);

        $this->assertSame(AbstractCommand::SUCCESS, $exit);
        $this->assertStringContainsString('Aborted', $io->getOutput());
    }
}
```

`getOutput()` returns ANSI-stripped, backspace-cleaned plain text — safe for
`assertStringContainsString()` regardless of terminal formatting.

---

## Component Inventory

| Component | Namespace | Type | Returns |
|---|---|---|---|
| `TextInput` | `Components\` | Interactive | `string` |
| `Password` | `Components\` | Interactive | `string` |
| `NumberInput` | `Components\` | Interactive | `int\|float` |
| `Confirm` | `Components\` | Interactive | `bool` |
| `Select` | `Components\` | Interactive | `string` |
| `MultiSelect` | `Components\` | Interactive | `string[]` |
| `Autocomplete` | `Components\` | Interactive | `string` |
| `DatePicker` | `Components\` | Interactive | `DateTimeImmutable` |
| `RadioGroup` | `Components\` | Interactive | `string` |
| `SliderInput` | `Components\` | Interactive | `int\|float` |
| `Table` | `Components\` | Display | void |
| `Alert` | `Components\` | Display | void |
| `ProgressBar` | `Components\` | Display | void |
| `SpinnerComponent` | `Components\` | Display | void |
| `Colors` | `Depends\` | Utility | varies |
| `Shell` / `ShellResult` | `Depends\` | Utility | `ShellResult` |
| `State` | `Depends\` | Reactive | — |
| `Input` | `Depends\` | Reactive | — |
| `Terminal` | `Depends\` | Driver | — |
| `Hooks` | root | Event bus | — |

> `RadioGroup` and `SliderInput` are fully implemented but not documented in the
> module's own README. Use them freely — they follow the same lifecycle contract.

---

## What MUST NOT Happen With php-io-cli

```
✗ Extending Symfony Console Command — extend AbstractCommand only
✗ Using InputInterface / OutputInterface inside handle() — use $this->argument(), $this->option(), $this->info() etc.
✗ Using $output->writeln('<info>...') — use $this->info(), $this->success(), $this->warning(), $this->error()
✗ Using CommandContract, Arguments, or Output from Cli/ — all @deprecated
✗ Instantiating two ProgressBar instances simultaneously — cursor interference
✗ Calling $bar->advance() inside Shell::run() tick — use advance(0) to redraw only
✗ Binding DomainContext into CLIApplication or AbstractCommand — it rides on Request only
✗ Direct instantiation of AbstractCommand in module Provider — use $cli->command(ClassName::class)
```
