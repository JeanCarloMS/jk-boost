---
name: ddd-logging
description: >-
  Channel-based logging pattern — PSR-3 LoggerInterface + ChannelLogger delegating to a
  scoped LoggerContext, CustomSingleLogger (Monolog rotating files), one log channel/file
  per process or flow, channel switching at entry points only. Use when adding logging to
  Actions/Services/handlers, creating a new log channel, wiring logging in a new project,
  or replicating the Shared logging infrastructure.
---

# DDD Logging (Channel-based)

The `ddd-poo-programatic-patterns` master rule is always applied.

**Pattern goal:** Application/Domain classes log through a PSR-3 interface with **zero knowledge of channels**; each process/flow (sync command, integration, module) writes to its **own rotating log file**; the **entry point decides the channel once** and everything downstream follows.

```
Entry point (Command/Job/Listener)          Consumers (Action/Service/Handler)
  LoggerContext::setChannel('sync_n4')  →     LoggerInterface->info(...)
                                                    │
                                              ChannelLogger  →  Log::channel(context->channel())
                                                    │
                                              config/logging.php  →  storage/logs/sync_n4.log (rotating)
```

## Components — `Shared/Domain/Logging/`

Four small classes + one provider. Replicate them verbatim in new projects.

**1. `LoggerInterface`** — the domain contract; just PSR-3:

```php
declare(strict_types=1);

namespace App\Contexts\Shared\Domain\Logging;

use Psr\Log\LoggerInterface as PsrLoggerInterface;

/**
 * Application logger contract resolved through LoggerContext channel switching.
 */
interface LoggerInterface extends PsrLoggerInterface {}
```

**2. `ChannelLogger`** — delegates every log call to the currently active channel:

```php
declare(strict_types=1);

namespace App\Contexts\Shared\Domain\Logging;

use Illuminate\Support\Facades\Log;
use Psr\Log\AbstractLogger;

/**
 * PSR logger that delegates to the channel currently set on LoggerContext.
 */
final class ChannelLogger extends AbstractLogger implements LoggerInterface
{
    public function __construct(
        private readonly LoggerContext $context,
    ) {}

    public function log($level, $message, array $context = []): void
    {
        Log::channel($this->context->channel())->log($level, $message, $context);
    }
}
```

**3. `LoggerContext`** — scoped holder of the active channel:

```php
declare(strict_types=1);

namespace App\Contexts\Shared\Domain\Logging;

/**
 * Holds the active log channel for the current request or console execution.
 */
final class LoggerContext
{
    private string $channel;

    public function __construct()
    {
        $this->channel = (string) config('logging.default');
    }

    public function setChannel(string $channel): void
    {
        $this->channel = $channel;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    /**
     * Temporarily switches channel for a callback, then restores the previous channel.
     */
    public function runWithChannel(string $channel, callable $callback): mixed
    {
        $previous = $this->channel;
        $this->channel = $channel;

        try {
            return $callback();
        } finally {
            $this->channel = $previous;
        }
    }
}
```

**4. `CustomSingleLogger`** — Monolog factory for `driver: custom` channels; gives every channel daily rotation:

```php
declare(strict_types=1);

namespace App\Contexts\Shared\Domain\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;

final class CustomSingleLogger
{
    public function __invoke(array $config): Logger
    {
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s',
        );

        $handler = new RotatingFileHandler(
            $config['path'] ?? storage_path('logs/laravel.log'),
            (int) ($config['days'] ?? 14),   // retention comes from config, NOT env() at runtime
            Level::Debug,
            true,
            0777,
            true,
        );

        $handler->setFormatter($formatter);

        return (new Logger('single'))->pushHandler($handler);
    }
}
```

> Retention (`days`) is read from the channel config — put `'days' => env('LOG_DAILY_DAYS', 14)` in `config/logging.php`, never call `env()` inside the factory (it returns `null` when config is cached).

## Service Provider

```php
declare(strict_types=1);

namespace App\Providers;

use App\Contexts\Shared\Domain\Logging\ChannelLogger;
use App\Contexts\Shared\Domain\Logging\LoggerContext;
use App\Contexts\Shared\Domain\Logging\LoggerInterface;
use Illuminate\Support\ServiceProvider;

class LoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(LoggerContext::class);

        $this->app->bind(LoggerInterface::class, ChannelLogger::class);
    }
}
```

Register it in `bootstrap/providers.php`. **`scoped()` is deliberate** — one `LoggerContext` per request/console run/queued job, so a channel set in one job never leaks into the next (Octane/Horizon-safe). Do not change it to `singleton()`.

## `config/logging.php` — one channel per process/flow

Every process (sync command, integration, module) gets its own channel + file, all rotating via `CustomSingleLogger`:

```php
'channels' => [
    'single' => [
        'driver' => 'custom',
        'via' => App\Contexts\Shared\Domain\Logging\CustomSingleLogger::class, // log rotate
        'path' => storage_path('logs/laravel.log'),
        'level' => env('LOG_LEVEL', 'debug'),
        'days' => env('LOG_DAILY_DAYS', 14),
        'replace_placeholders' => true,
    ],

    'sync_n4' => [
        'driver' => 'custom',
        'via' => App\Contexts\Shared\Domain\Logging\CustomSingleLogger::class,
        'path' => storage_path('logs/sync_n4.log'),
        'level' => env('LOG_LEVEL', 'debug'),
        'days' => env('LOG_DAILY_DAYS', 14),
        'replace_placeholders' => true,
    ],
    // tran_to_n4, sync_extranet, pre_gate, ... one per flow
],
```

Channel naming: snake_case matching the process (`sync_n4`, `tran_to_n4`, `sync_extranet`).

## Usage Rules

**Consumers** (Actions, Services, handlers, domain services) inject `LoggerInterface` and never know the channel:

```php
final class PostN4EventHandler
{
    public function __construct(
        private readonly N4SoapClient $n4SoapClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(PostN4EventCommand $command): ?N4WebServiceResponse
    {
        $this->logger->info('PostN4EventHandler::handle Request', ['event' => $command->eventId()]);
        // ...
    }
}
```

**Entry points** (console Commands, Jobs, Listeners) inject `LoggerContext` and set the channel **first thing**:

```php
final class GetShippersFromN4 extends Command
{
    public function __construct(
        private readonly LoggerContext $loggerContext,
        private readonly SyncN4ShippersService $syncN4ShippersService,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->loggerContext->setChannel('sync_n4');   // everything downstream logs here

        $this->syncN4ShippersService->sync();

        $this->info('Shippers from N4 synchronized successfully');
    }
}
```

**Multi-phase flows** switch per phase (`setChannel` between phases, like `SyncExtranetAndN4`), or use `runWithChannel()` when the switch must be temporary:

```php
$this->loggerContext->runWithChannel('tran_to_n4', fn () => $this->transferService->run());
// previous channel restored automatically, even on exceptions
```

**Hard rules:**

- Domain/Application classes depend on `LoggerInterface` (PSR-3) — never on the `Log::` facade, never on a channel name.
- Channel selection happens **only at entry points** (Command/Job/Listener/Controller) — a Service/Action calling `setChannel()` is a smell.
- Message prefix stays `ClassName::methodName`; context arrays with snake_case domain ids.
- Without `setChannel()`, logging falls back to `config('logging.default')` — safe default.
- New process/flow = new channel entry in `config/logging.php` + one `setChannel()` at its entry point. Nothing else changes.

## Adding This Pattern to a Project

1. Copy the 4 classes into `app/Contexts/Shared/Domain/Logging/`.
2. Create `App\Providers\LoggingServiceProvider` and register it in `bootstrap/providers.php`.
3. Convert `config/logging.php` channels to `driver: custom` + `via: CustomSingleLogger` (per-channel `path` + `days`).
4. New code injects `LoggerInterface`; entry points inject `LoggerContext`.
5. Projects with a legacy logger (e.g. a `Logger::save()` helper): keep the old convention inside files that already use it; route **new** code through this pattern and migrate on touch.

## Logging Checklist

- [ ] Consumer injects `LoggerInterface` — no `Log::` facade, no channel knowledge
- [ ] Entry point sets the channel once via `LoggerContext::setChannel()`
- [ ] Temporary switches use `runWithChannel()` (restores in `finally`)
- [ ] `LoggerContext` bound as `scoped()`, `LoggerInterface` → `ChannelLogger`
- [ ] Each process/flow has its own channel + rotating file (`via: CustomSingleLogger`)
- [ ] `days` from channel config, not `env()` at runtime
- [ ] Messages prefixed `ClassName::methodName`; no secrets/tokens in context
