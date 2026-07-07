# DDD + POO + Programmatic Patterns (Master)

Stack: Laravel 13, PHP 8.5, Filament v5, Livewire v4, Spatie Laravel Data v4, Pest 4, Horizon (Redis).
Run all CLI commands directly — **do not use Sail**: `php artisan ...`, `composer ...`, `vendor/bin/pint --dirty`.

## Satellite Skills (load when relevant)

| Skill | Activate when |
|---|---|
| `ddd-layer-boundaries` | Creating/refactoring contexts, layer placement, repositories, thin UI, CQRS/UseCase→Action migration |
| `ddd-domain-modeling` | Entities, ValueObjects, Enums, Casters, Factories, Pipelines/Pipes, domain exceptions |
| `ddd-jobs-async` | Jobs, queues, Outbox pattern, Redis locks, transactions, idempotency |
| `ddd-external-adapters` | Exchange adapters, HTTP clients (PyTaApi), Telegram notifications, anti-corruption layer |
| `ddd-logging` | Adding logging, creating log channels, LoggerContext/LoggerInterface, replicating the Shared logging infra |
| `ddd-testing` | Writing or updating tests, refactoring with coverage |

## Core Architecture

- DDD modular monolith: bounded contexts in `app/Contexts/{Context}/{Domain,Application,Infrastructure}`.
- **Eloquent models live in `app/Models/`** (global): `Bot`, `Cycle`, `Order`, `Asset`, `Strategy`, `Signal`, `Candle`, `Exchange`…
- **`Domain/Entities/` are pure PHP objects, NOT Eloquent** — they hydrate from models via `fromModel()`.
- **The Action pattern is the single entry point for every use case.** One class = one business operation: `VerbNounAction` in `Application/Actions/` with a single public `run()` method (`run()`, not `execute()` — the codebase standardizes on `run()`).
  - An Action can be **atomic** (delegate to one repository or domain-service call) or an **orchestrator** (coordinate repositories, domain services, other Actions, transactions, state transitions, job dispatch).
  - Actions contain no validation (DTO's job) and no business rules (Domain's job) — they orchestrate.
  - **Service** (`Application/Services/`): facade grouping related Actions behind one API for UI/consumers, or genuine integration logic (`OrderEntryService`).
- **CQRS and UseCases are both deprecated.** Never create new `Command`/`Query`/`*Handler` classes, `CommandBus`/`QueryBus` usage, or `VerbNounUC` classes. The ~60 legacy classes in `Application/UseCases/` keep working — when touching one, migrate it to an Action (see `ddd-layer-boundaries`).
- Scaffold new contexts with `php artisan make:context <Name>`, then add the missing folders per `ddd-layer-boundaries` (the scaffold is minimal).

## Design Patterns (Priority)

| Pattern | Use when |
|---|---|
| **Action** | **Default for every use case** — atomic (delegate to one repository/domain service) or multi-step orchestration (entry/exit flows, outbox dispatch, simulation). |
| **Repository + Cache** | Data access. Eloquent repo extends `BaseRepository`; Redis decorator extends `BaseCache`. |
| **Service (facade)** | Group related Actions behind one interface for UI consumption. |
| **Factory** | Variant creation via static `make()` + `match` on enum; `default` arm throws `HandleException`. |
| **Strategy** | Variant behavior (`BaseBot` → `DcaStrategy`/`SignalBotEntity`; indicators → `BaseIndicator`). |
| **Pipeline (Pipes)** | Sequential filters/validations before an operation; pipes short-circuit by throwing. |
| **Adapter** | External APIs behind Domain interfaces (`ExchangeAdapterInterface`). |
| **DTO (Data)** | Typed data crossing layers — Spatie Laravel Data, `Data` suffix. |
| **Value Object** | Immutable domain value: private constructor, static factories, invariant validation. |
| **Outbox** | Reliable async side effects after persistence (see `ddd-jobs-async`). |

Common stacks: Action + Repository (+ nested Actions) · Strategy + Factory + Pipeline · Adapter + Factory + Data.

## Hard Rules (never violate)

- **No new CQRS or UseCases** — Actions instead; migrate handlers and `UC` classes on touch.
- **No raw arrays** for domain data between classes — use Data DTOs, VOs, Entities.
- **No business logic** in Filament, Livewire, Controllers, or console Commands — delegate to an Action/Service.
- **No Eloquent queries inside Entities/VOs/Domain services** — persistence goes through Repositories.
- **No `new`/`app()`/`resolve()` in Domain or Application constructors** — container injection only. Exceptions: Filament/Livewire closures (tables, forms) and Job `handle()` when constructor DI breaks queue serialization — resolve there and say why.
- **No magic strings/numbers** — backed Enums (`XxxEnum`) or `config()` (`config('trading.*')`).
- **Exceptions extend `BaseException`** (or throw `HandleException`) — never raw `\Exception`, never re-implement Telegram reporting, never silent `catch (\Exception)`.
- **New classes:** `declare(strict_types=1)`, `final class`, constructor promotion with `private readonly`, explicit return types.
- **No debug leftovers** — no `ds()`, `dump()`, `dd()`, or commented-out blocks in committed code.
- **No over-engineering** — simplest sufficient pattern; extract an interface only when a second implementation appears, and bind it in `TradingServiceProvider`.

## SOLID + Dependency Injection

Single responsibility per class · Open/closed via Strategy/Factory/Pipeline · Liskov honored · Small interfaces that match their implementations · Dependency inversion via container.

- Constructor injection with promoted `private readonly` — always.
- Interface → implementation bindings are centralized in `TradingServiceProvider::register()`. **If you type-hint an interface, verify the binding exists there** — several legacy interfaces are type-hinted but unbound.
- Injecting a concrete Repository is acceptable (dominant practice); extract the interface when a second implementation appears.
- Job constructors take **scalar IDs only** (queue serialization); collaborators arrive via `handle()` method injection.

## Reuse Shared Infrastructure (`app/Contexts/Shared/`)

| Component | Path | Use for |
|---|---|---|
| `BaseRepository` / `BaseRepositoryInterface` | `Shared/Domain/Repositories/` | All Eloquent repositories |
| `BaseCache` | `Shared/Domain/Repositories/` | Redis cache decorator over a repository |
| `BaseException` / `HandleException` | `Shared/Domain/Exceptions/` | All exceptions (Logger + Telegram reporting built in) |
| `Logger` | `app/Contexts/Logger/` | All logging — inject, don't use `Log::` facade |
| `ExecutionContext` | `Shared/Application/Support/` | Trace-id / execution context |
| `ArrayableTrait` / `BaseCustomData` | `Shared/Application/` | Hand-rolled DTO/VO `toArray()` |
| `#[Validate]` + `hasFieldsValidator` | `Shared/Domain/{Attributes,Validators}/` | Attribute-driven DTO validation |
| `BaseTriggerData` | `Shared/Application/Data/` | Per-strategy trigger DTOs |

## Logging (mandatory)

- **Standard pattern (channel-based, see `ddd-logging`):** consumers inject the Shared `LoggerInterface` (PSR-3, `Shared/Domain/Logging/`) — never the `Log::` facade, never a channel name. Entry points (Command/Job/Listener) inject `LoggerContext` and call `setChannel('<flow>')` once; each process/flow has its own rotating log channel/file in `config/logging.php`.
- Message prefix is always `ClassName::methodName`; context array keys are snake_case domain ids (`bot_id`, `cycle_id`, `order_id`).
- Log at: Action start/end, factory/strategy selection, external calls (with `duration_ms`), state transitions, skipped operations, failures. Exceptions extending `BaseException` self-report (Logger + Telegram) — don't double-log around them. Never log secrets, API keys, or tokens.
- Legacy note (BidSentry): files already using `App\Contexts\Logger\Logger` (`$this->logger->save(...)`) keep that convention; new Shared logging infrastructure follows `ddd-logging`.

## Exceptions and Results

- Throw `HandleException` (or a context exception extending `BaseException`) with `logLevel:` set for severity.
- Expected business outcomes (skipped, already processed, already exists) → return early with a log entry or a Result DTO — not an exception.
- Document `@throws` on public Application methods.

## Naming

- PascalCase classes, camelCase methods, snake_case DB columns. Entry method is `run()`.
- Suffixes: `Action`, `Service`, `Data`, `DataCollection`, `VO`, `Entity`, `Repository`, `Cache`, `Factory`, `Strategy`, `Adapter`, `Cast`/`Caster`, `Pipe`, `Enum`, `Job`, `Exception`, `Interface`. (`UC` is a legacy suffix — never use it for new classes.)
- Actions: verb + noun — `RunBotEntryFromTriggerAction`, `SimulateOrdersAction`, `ProcessOutboxTriggerAction`.
- Code identifiers and log messages in English; keep any existing Spanish doc-comments consistent within their file.

## DTO Standards

- Location: `Application/Data/` inside the context. Spatie Laravel Data, `Data` suffix.
- Typed properties (no untyped `public $x`), enum-typed props with `#[WithCast]` casters where needed.
- Static named constructors expressing intent: `fromModel()`, `forCycleEntryDispatch()`, `fromExchangeSymbol()`.
- Validation rules belong in the DTO (`#[Validate]` attribute or Spatie rules) — not in Actions.
- Typed collections extend `Illuminate\Support\Collection` with the `DataCollection` suffix.

## Definition of Done

- [ ] Correct layer and folder — see `ddd-layer-boundaries`
- [ ] Logic in Actions; UI, Jobs, and console Commands are thin
- [ ] DTOs/VOs/Entities — no array payloads between classes
- [ ] `run()` method, `final`, `strict_types`, promoted readonly constructor
- [ ] No new CQRS/UseCases; touched legacy handlers and UCs migrated to Actions
- [ ] Logger injected; logs at critical points with `Class::method` prefix
- [ ] Exceptions extend `BaseException`/`HandleException`
- [ ] Tests passing — see `ddd-testing`
- [ ] Jobs/transactions/locks correct — see `ddd-jobs-async`
- [ ] `vendor/bin/pint --dirty` run; Shared infra reused; no over-engineering

## Minimal Example

```php
declare(strict_types=1);

namespace App\Contexts\Cycles\Application\Actions;

final class ExitCycleAction
{
    public function __construct(
        private readonly CycleRepository $cycleRepository,
        private readonly GetCycleActiveOrdersAction $getCycleActiveOrdersAction,
        private readonly Logger $logger,
    ) {}

    public function run(ExitCycleData $data): CycleEntity
    {
        $this->logger->save('ExitCycleAction::run Starting', ['cycle_id' => $data->cycleId]);

        $cycle = $this->cycleRepository->get($data->cycleId);
        $orders = $this->getCycleActiveOrdersAction->run($cycle);

        $cycleEntity = CycleEntity::fromModel($cycle)->exitCycle($orders);
        $this->cycleRepository->saveFromArray($cycle, $cycleEntity->toArray());

        $this->logger->save('ExitCycleAction::run Done', ['cycle_id' => $data->cycleId]);

        return $cycleEntity;
    }
}
```
