# php-io-cli — CLI Application Runtime

The `alfacode-team/php-io-cli` package provides a standalone, framework-independent CLI application runtime with interactive terminal components and structured command execution. It powers the kernel's `CliPipeline`.

## Overview

`php-io-cli` is NOT a wrapper around Symfony Console. It is a complete, bottom-up implementation:

- **Independent execution model**: CLIApplication directly parses argv and dispatches to commands
- **Interactive components**: TextInput, Select, MultiSelect, Confirm, DatePicker, ProgressBar, and more — all with full keyboard navigation
- **Clean command base**: extend `AbstractCommand`, implement `configure()` and `handle()`
- **No magic methods**: explicit `addArgument()` and `addOption()` registration with no auto-discovery
- **Zero external dependencies** (except for color output via an internal library)

Symfony Console is an optional dev dependency used only by `ConsoleIO` (a non-TTY fallback); the core is pure PHP.

## Writing a command

All commands extend `AbstractCommand` and implement two methods:

```php
<?php
declare(strict_types=1);
namespace Shop\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

final class GenerateInvoiceCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'invoice:generate';
        $this->description = 'Generate an invoice for a customer';
        $this->help        = 'Generate an invoice PDF and email it to the customer.';
        
        // Arguments (positional, at the end of the command line)
        $this->addArgument('customer_id', 'The customer ID', required: true);
        $this->addArgument('amount', 'Invoice amount', required: false, default: '0.00');
        
        // Options (flags: --name or -n)
        $this->addOption('dry-run', 'd', 'Simulate without creating the PDF');
        $this->addOption('send', 's', 'Email the invoice immediately');
        $this->addOption('template', 't', 'Invoice template to use', acceptsValue: true, default: 'default');
    }

    protected function handle(): int
    {
        $customerId = $this->argument('customer_id');
        $dryRun     = $this->hasOption('dry-run');
        $template   = $this->option('template');
        
        $this->info("Generating invoice for customer {$customerId}...");
        
        // Do work
        
        if ($dryRun) {
            $this->warning('(Dry run — no invoice created)');
            return self::SUCCESS;
        }
        
        $this->success('Invoice generated.');
        return self::SUCCESS;
    }
}
```

### Return codes

```php
return self::SUCCESS;   // 0 — command succeeded
return self::FAILURE;   // 1 — command failed
return self::INVALID;   // 2 — invalid arguments/options
```

### Core methods

| Method | Returns | Purpose |
|---|---|---|
| `$this->argument(name, default)` | `mixed` | Get a positional argument |
| `$this->option(name, default)` | `mixed` | Get an option/flag value |
| `$this->hasOption(name)` | `bool` | Check if a flag was passed |
| `$this->info(message)` | `void` | Print an info line |
| `$this->success(message)` | `void` | Print success (green) |
| `$this->warning(message)` | `void` | Print warning (yellow) to stderr |
| `$this->error(message)` | `void` | Print error (red) to stderr |
| `$this->section(title)` | `void` | Print a section header |
| `$this->newLine(count)` | `void` | Print blank lines |

## Interactive components

Commands can use interactive components to prompt the user:

```php
$name = $this->ask('Your name?', default: 'Guest');
$email = $this->ask('Email?');

$role = $this->select('Choose a role:', ['admin', 'user', 'guest']);

$confirmed = $this->confirm('Proceed?', default: true);

// Progress bar — for long operations
$bar = $this->progressBar('Processing items', 100);
$bar->start();
for ($i = 0; $i < 100; $i++) {
    // Do work
    $bar->advance(1, "Item $i");
}
$bar->finish();

// Spinner — for indeterminate work
$spinner = $this->spinner('Loading...', style: 'dots');
$spinner->start();
// Do work
$spinner->stop();

// Alerts
$this->alertSuccess('Order created', ['ID: 12345', 'Total: $99.99']);
$this->alertError('Payment failed', ['Reason: Card declined', 'Try again or use another card']);
```

## Component inventory

All components live in `AlfacodeTeam\PhpIoCli\Components`.

### TextInput

Free-text input with inline validation, placeholder, and full cursor navigation.

```php
$value = (new TextInput('Username?'))
    ->default('guest')
    ->run();
```

| Method | Argument | Returns | Purpose |
|---|---|---|---|
| `__construct` | `string $question` | — | Create the component |
| `default` | `string $value` | `$this` | Default if user presses Enter |
| `placeholder` | `string $text` | `$this` | Grayed-out hint text |
| `validate` | `callable $fn` | `$this` | Validation function; returns null on success or an error string |
| `run` | — | `string` | Block and return the user input |

Navigation: HOME/END, Left/Right arrow, Backspace, Delete.

### Password

Masked text input with toggle plaintext via TAB and optional strength meter.

```php
$pwd = (new Password('Password?'))
    ->showStrength(true)
    ->run();
```

| Method | Argument | Returns | Purpose |
|---|---|---|---|
| `showStrength` | `bool` | `$this` | Display strength meter |
| `run` | — | `string` | Return masked input |

### NumberInput

Numeric input with arrow-key stepping, min/max clamping, and integer/float mode.

```php
$count = (new NumberInput('How many?'))
    ->integer()
    ->min(1)
    ->max(100)
    ->run();
```

| Method | Argument | Returns | Purpose |
|---|---|---|---|
| `integer` | — | `$this` | Accept integers only (default: float) |
| `min` | `int\|float` | `$this` | Clamp the minimum |
| `max` | `int\|float` | `$this` | Clamp the maximum |
| `default` | `int\|float` | `$this` | Default value |
| `run` | — | `int\|float` | Return the number |

Navigation: Up/Down arrow (step), `[` `]` (jump 10%), HOME/END (min/max).

### Select

Single-selection dropdown with fuzzy filtering and keyboard navigation.

```php
$lang = (new Select('Choose a language:', [
    'en' => 'English',
    'fr' => 'Français',
    'es' => 'Español',
]))->run();
```

| Method | Argument | Returns | Purpose |
|---|---|---|---|
| `__construct` | `string $question, array $choices` | — | Create with label/value map |
| `run` | — | `string` | Return the selected key |

Navigation: Up/Down arrow, type to filter, Enter to select.

### Autocomplete

Text input with live fuzzy-search dropdown suggestions.

```php
$lang = (new Autocomplete('Pick a language:', [
    'PHP', 'Python', 'Go', 'Rust', 'JavaScript'
]))->run();
```

| Method | Argument | Returns | Purpose |
|---|---|---|---|
| `__construct` | `string $question, array $suggestions` | — | Create with label and suggestion list |
| `run` | — | `string` | Return the selected or typed value |

Navigation: Type to filter, Up/Down arrow to select from dropdown, Tab to accept, Enter to confirm.

### MultiSelect

Checkbox-style multi-selection.

```php
$perms = (new MultiSelect('Grant permissions:', [
    'read', 'write', 'delete', 'manage-users'
]))->run();  // returns array of selected keys
```

| Method | Argument | Returns | Purpose |
|---|---|---|---|
| `__construct` | `string $question, array $choices` | — | Create |
| `run` | — | `string[]` | Return selected keys |

Navigation: Up/Down, Space to toggle, Enter to confirm.

### Confirm

Yes/No prompt with a default.

```php
$delete = (new Confirm('Delete permanently?', default: false))->run();
```

| Method | Argument | Returns | Purpose |
|---|---|---|---|
| `__construct` | `string $question, bool $default` | — | Create |
| `run` | — | `bool` | Return true (yes) or false (no) |

### DatePicker

Interactive calendar with month/year navigation.

```php
$date = (new DatePicker('Select a date:'))->run();  // DateTimeImmutable
```

| Method | Argument | Returns | Purpose |
|---|---|---|---|
| `run` | — | `DateTimeImmutable` | Return the selected date |

Navigation: Arrow keys (day), Left/Right (month), Up/Down (year).

### RadioGroup

Radio-button list for mutually exclusive choices (≤ 5 items).

```php
$size = (new RadioGroup('Shirt size:', [
    'S' => 'Small',
    'M' => 'Medium',
    'L' => 'Large',
]))->run();
```

| Method | Argument | Returns | Purpose |
|---|---|---|---|
| `columns` | `int` | `$this` | Layout in N columns (default 1) |
| `run` | — | `string` | Return the selected key |

Navigation: Arrow keys, 1-9 digit shortcuts (first N items).

### SliderInput

Horizontal slider for numeric ranges with keyboard precision.

```php
$volume = (new SliderInput('Volume?'))
    ->min(0)
    ->max(100)
    ->run();
```

| Method | Argument | Returns | Purpose |
|---|---|---|---|
| `min` | `int\|float` | `$this` | Minimum value |
| `max` | `int\|float` | `$this` | Maximum value |
| `default` | `int\|float` | `$this` | Starting value |
| `integer` | — | `$this` | Integer mode (default: float) |
| `run` | — | `int\|float` | Return the value |

Navigation: Left/Right arrow (step), `[` `]` (jump 10%), HOME/END (min/max).

### ProgressBar

Determinate progress indicator with ETA and throughput.

```php
$bar = new ProgressBar('Processing', 100);
$bar->start();
for ($i = 0; $i < 100; $i++) {
    $bar->advance(1, "Item $i");
}
$bar->finish();  // or finish('Done')
```

| Method | Argument | Returns | Purpose |
|---|---|---|---|
| `start` | — | `void` | Display the bar and start timing |
| `advance` | `int $steps, ?string $label` | `void` | Increment; refresh display |
| `finish` | `?string $final` | `void` | Mark complete and show final state |

### SpinnerComponent

Indeterminate spinner for operations of unknown duration.

```php
$spinner = new SpinnerComponent('Uploading...', style: 'dots');
$spinner->start();
// Do work
$spinner->stop();
```

| Method | Argument | Returns | Purpose |
|---|---|---|---|
| `start` | — | `void` | Display the spinner |
| `stop` | — | `void` | Hide it |
| `update` | `string $label` | `void` | Update the label |

Styles: `dots`, `line`, `bars`, `pulse`, `arc`, `bounce`.

### Table

ASCII table with borders and striped rows.

```php
$table = Table::make()
    ->header(['ID', 'Name', 'Email'])
    ->row([1, 'Alice', 'alice@example.com'])
    ->row([2, 'Bob', 'bob@example.com'])
    ->striped()
    ->render();
```

| Method | Argument | Returns | Purpose |
|---|---|---|---|
| `header` | `array` | `$this` | Set column headers |
| `row` | `array` | `$this` | Add a data row |
| `striped` | — | `$this` | Alternate row colors |
| `render` | — | `void` | Print to output |

### Alert

Bordered notification boxes.

```php
Alert::success('File created', ['Size: 1.2 MB', 'Path: /uploads/file.pdf']);
Alert::error('Upload failed', 'Disk quota exceeded');
Alert::warning('Deprecated', 'Use the new API instead');
Alert::info('Tip', 'You can use --dry-run to preview changes');
```

Static methods: `success()`, `error()`, `warning()`, `info()`, `block()`.

## CLIApplication

The underlying application dispatcher. Rarely needed directly; `CliPipeline` handles it:

```php
$app = new CLIApplication('MyApp', '1.0.0');

$app->add(new ListCommand());
$app->add(new GenerateCommand());

$exitCode = $app->run(array_slice($argv, 1));
exit($exitCode);
```

| Method | Argument | Returns | Purpose |
|---|---|---|---|
| `add` | `AbstractCommand ...$commands` | `$this` | Register commands |
| `has` | `string $name` | `bool` | Check if a command exists |
| `get` | `string $name` | `AbstractCommand` | Fetch a registered command |
| `catchExceptions` | `bool` | `$this` | Re-throw exceptions instead of catching (default: true) |
| `withIO` | `IOInterface $io` | `$this` | Inject custom I/O layer |
| `run` | `array $argv` | `int` | Parse and execute; return exit code |

## I/O layers

All output goes through an `IOInterface`:

```php
interface IOInterface
{
    // state
    public function isInteractive(): bool;
    public function isVerbose(): bool;
    public function isVeryVerbose(): bool;
    public function isDebug(): bool;
    public function isDecorated(): bool;

    // output — $messages may be a string or a list of lines
    public function write(string|array $messages, bool $newline = true, int $verbosity = self::NORMAL): void;
    public function writeError(string|array $messages, bool $newline = true, int $verbosity = self::NORMAL): void;
    public function writeRaw(string|array $messages, bool $newline = true, int $verbosity = self::NORMAL): void;
    public function writeErrorRaw(string|array $messages, bool $newline = true, int $verbosity = self::NORMAL): void;
    public function overwrite(string|array $messages, bool $newline = true, ?int $size = null, int $verbosity = self::NORMAL): void;
    public function overwriteError(string|array $messages, bool $newline = true, ?int $size = null, int $verbosity = self::NORMAL): void;

    // input
    public function ask(string $question, mixed $default = null): mixed;
    public function askConfirmation(string $question, bool $default = true): bool;
    public function askAndValidate(string $question, callable $validator, ?int $attempts = null, mixed $default = null): mixed;
    public function askAndHideAnswer(string $question): ?string;
    public function select(string $question, array $choices, mixed $default, bool|int $attempts = false,
                           string $errorMessage = 'Value "%s" is invalid', bool $multiselect = false): int|string|array|bool;
}
```

Three implementations ship:

| Implementation | Use case |
|---|---|
| `ConsoleIO` | The real terminal. `CLIApplication` uses it by default. |
| `BufferIO` | Extends `ConsoleIO`; captures output in memory and lets you script user input. For tests. |
| `NullIO` | Completely silent and non-interactive: writes are no-ops, every question returns its default. |

`CLIApplication` does not switch implementations on its own: it builds a `ConsoleIO` unless you pass one with `->withIO(new BufferIO())`. Interactive components check `isInteractive()` themselves.

## Error handling

Uncaught exceptions in `handle()` are caught and reported:

```php
try {
    return $this->handle();
} catch (Throwable $e) {
    $this->io->error('Command Error: ' . $e->getMessage());
    if ($io->isDebug()) {
        $this->io->write($e->getTraceAsString());
    }
    return self::FAILURE;
}
```

Pass `catchExceptions(false)` to `CLIApplication` to re-throw instead (the kernel does this so `ErrorPipeline` can capture all failures).

## Dependency injection integration

Commands can have constructor dependencies:

```php
class GenerateCommand extends AbstractCommand
{
    public function __construct(
        private readonly DatabasePort $db,
        private readonly MailPort $mail,
    ) {
        parent::__construct();
    }
    
    // ... configure() and handle()
}
```

The kernel's `CliPipeline` instantiates commands through the `CoreContainer`:

```php
$command = $core->make(GenerateCommand::class);
// DatabasePort and MailPort are autowired from the container
```

Commands that cannot be resolved (missing dependencies) are skipped silently so other commands still run.

## Common patterns

### Asking for confirmation before destructive ops

```php
if (!$this->confirm('Delete all records? This cannot be undone.', default: false)) {
    $this->warning('Cancelled.');
    return self::SUCCESS;
}

$this->info('Deleting...');
// Do the deletion
```

### Building a table from query results

```php
$rows = $db->query('SELECT id, name, email FROM users');

$table = $this->table()
    ->header(['ID', 'Name', 'Email'])
    ->striped();

foreach ($rows as $row) {
    $table->row([$row['id'], $row['name'], $row['email']]);
}

$table->render();
```

### Progress indication for batch operations

```php
$total = $db->queryOne('SELECT COUNT(*) as n FROM large_table')['n'];
$bar   = $this->progressBar('Processing', $total);
$bar->start();

$db->query('SELECT * FROM large_table', function ($row) use ($bar) {
    // Process row
    $bar->advance(1);
});

$bar->finish('Complete');
```

## Source

- [modules/php-io-cli/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/modules/php-io-cli)
- [modules/php-io-cli/src/AbstractCommand.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/php-io-cli/src/AbstractCommand.php)
- [modules/php-io-cli/src/CLIApplication.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/php-io-cli/src/CLIApplication.php)
- [modules/php-io-cli/src/Components/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/modules/php-io-cli/src/Components)
