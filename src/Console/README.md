# Console

The console layer provides a small command kernel, command runner, input parsing, and styled output helpers.

## Define a Command

```php
use Myxa\Console\Command;

final class HelloCommand extends Command
{
    protected string $signature = 'hello';
    protected string $description = 'Say hello';

    protected function handle(): int
    {
        $this->output->success('Hello from Myxa')->icon();

        return self::SUCCESS;
    }
}
```

## Register Commands

```php
use Myxa\Console\ConsoleKernel;

$kernel = new ConsoleKernel(commands: [
    HelloCommand::class,
]);

exit($kernel->handle($argv));
```

## Output Helpers

```php
$this->output->info('Import started')->icon();
$this->output->table(['ID', 'Email'], $rows);
$this->output->progressBar(25, 100);
```

## Notes

- command classes extend `Command`
- `ConsoleKernel` wires commands into the runner
- output helpers support formatted messages, tables, and progress bars
