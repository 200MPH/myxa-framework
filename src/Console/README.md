# Console

The console layer provides a small command kernel, command runner, input parsing, and styled output helpers.

## Define a Command

```php
use Myxa\Console\Command;

final class HelloCommand extends Command
{
    public function name(): string
    {
        return 'hello';
    }

    public function description(): string
    {
        return 'Say hello';
    }

    protected function handle(): int
    {
        $this->success('Hello from Myxa')->icon();

        return self::SUCCESS;
    }
}
```

## Arguments and Options

Use `parameters()` for positional arguments and `options()` for long-form `--options`.

```php
use Myxa\Console\Command;
use Myxa\Console\InputArgument;
use Myxa\Console\InputOption;

final class GreetCommand extends Command
{
    public function name(): string
    {
        return 'greet';
    }

    public function description(): string
    {
        return 'Greets a user with optional flags.';
    }

    public function parameters(): array
    {
        return [
            new InputArgument('name', 'Who should be greeted?', hint: 'Name'),
            new InputArgument('team', 'Optional team name', required: false, default: 'Guests'),
        ];
    }

    public function options(): array
    {
        return [
            new InputOption('title', 'Greeting title', acceptsValue: true, required: false, default: 'Friend'),
            new InputOption('uppercase', 'Render the greeting in uppercase'),
        ];
    }

    protected function handle(): int
    {
        $message = sprintf(
            'Hello %s %s from %s',
            $this->option('title'),
            $this->parameter('name'),
            $this->parameter('team'),
        );

        if ($this->option('uppercase')) {
            $message = strtoupper($message);
        }

        $this->info($message)->icon();

        return self::SUCCESS;
    }
}
```

Example usage:

```bash
php app.php greet Chevy
php app.php greet Chevy Core --title=Captain
php app.php greet Chevy Core --title=Captain --uppercase
```

## Reading Input

Inside `handle()`, use:

- `$this->parameter('name')` for positional values
- `$this->option('title')` for options
- `$this->input()->parameters()` to get all positional input
- `$this->input()->options()` to get all parsed options

## Format Text

Console messages use a fluent builder.

```php
$this->output('Plain message');
$this->info('Import started')->icon();
$this->success('Import finished')->icon()->bold();
$this->warning('Dry-run mode')->underline();
$this->error('Import failed')->icon()->bold();
```

Available formatting helpers:

- `success()`
- `warning()`
- `error()`
- `info()`
- `bold()`
- `underline()`
- `strike()`
- `icon()`
- `send()`

Messages are rendered automatically when the pending message goes out of scope, but you can force immediate output with `->send()`.

## Tables and Progress

```php
$this->table(
    ['ID', 'Email'],
    [
        ['ID' => 1, 'Email' => 'john@example.com'],
        ['ID' => 2, 'Email' => 'jane@example.com'],
    ],
);

$startedAt = microtime(true);

foreach (range(1, 100) as $index) {
    $this->progressBar($index, 100, startedAt: $startedAt);
}

$this->progressText('Importing', 25, 100, startedAt: microtime(true));
```

## Register Commands

```php
use Myxa\Console\ConsoleKernel;

final class Kernel extends ConsoleKernel
{
    protected function commands(): iterable
    {
        return [
            HelloCommand::class,
            GreetCommand::class,
        ];
    }
}

$kernel = new Kernel();

exit($kernel->handle($argv));
```

## Notes

- command classes implement `CommandInterface`; extending `Command` is the easiest path
- commands can be registered as instances or class names
- command constructors can use container-resolved dependencies
- options are long-form only, like `--title=value`
- output helpers support formatted messages, tables, and progress bars
