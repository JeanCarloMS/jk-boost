---
name: ddd-layer-boundaries
description: >-
  DDD layer boundaries for app/Contexts — real folder structure, import rules,
  Action vs Service placement, repository + cache placement, DI bindings,
  thin Filament/Livewire UI, Shared infrastructure reuse, and legacy CQRS/UseCase→Action
  migration. Use when creating or refactoring bounded contexts, Actions, Repositories,
  Filament/Livewire pages, or migrating legacy Command/Query handlers or UC classes.
  Complements always-applied rule ddd-poo-programatic-patterns.
---

# DDD Layer Boundaries

The `ddd-poo-programatic-patterns` master rule is always applied.

## Layer Import Rules (Strict)

| Layer | Path | Responsibilities | Must NOT import |
|---|---|---|---|
| **Domain** | `Domain/` | Entities (pure PHP), VOs, Enums, Factories, Pipes, Strategies, domain Services, Casters, Exceptions, repository **interfaces** | HTTP, Filament, Livewire, queued Jobs |
| **Application** | `Application/` | Actions, Services (facades), typed Data/Result DTOs, Jobs (thin), Listeners | Filament, Livewire, HTTP Controllers |
| **Infrastructure** | `Infrastructure/` | Eloquent + Cache repositories, exchange Adapters, HTTP clients, Notifications | Filament, Livewire |
| **UI** | `app/Filament/{Admin,UserPanel}`, Controllers, Livewire | Validate, authorize, map DTO, delegate, respond | Business logic, direct Eloquent queries |

**Golden rule:** UI is a thin orchestrator. All business logic lives in Actions, Services, Strategies, and Domain classes.

**Eloquent models live in `app/Models/`** (global), not inside contexts. `Domain/Entities/` hold pure PHP entities hydrated via `fromModel()` (see `ddd-domain-modeling`). Known exception: `OutboxTrigger` lives in `Outbox/Infrastructure/Persistence/Models/` — do not replicate that placement for new models.

## Real Folder Structure

```text
app/Contexts/{Context}/
├── Domain/
│   ├── Entities/            ← pure PHP entities (NOT Eloquent), fromModel()
│   ├── ValueObjects/        ← immutable VOs, private ctor + static factories
│   ├── Enums/               ← backed enums, XxxEnum suffix
│   ├── Exceptions/          ← extend BaseException / HandleException
│   ├── Interfaces/          ← contracts (XxxInterface suffix) — single home for interfaces
│   ├── Factories/           ← static make() + match on enum
│   ├── Services/            ← domain services (entity + VO + repository orchestration)
│   ├── Casters/             ← Spatie Data Cast implementations
│   ├── Pipes/               ← Laravel Pipeline filters
│   ├── Strategies/          ← strategy hierarchies (Bots: BaseBot → DcaStrategy…)
│   └── Events/              ← domain events
├── Application/
│   ├── Actions/             ← ALL use cases (atomic or orchestration), Action suffix
│   ├── UseCases/            ← LEGACY (UC suffix) — never add here; migrate to Actions on touch
│   ├── Services/            ← facades grouping Actions / integration logic
│   ├── Data/                ← typed DTOs/Results/DataCollections; Spatie Data only when advanced features are needed
│   ├── Jobs/                ← thin queue jobs (delegate to Action/Service)
│   ├── Interfaces/          ← application service contracts
│   └── Listeners/           ← thin event listeners
└── Infrastructure/
    ├── Persistence/Repositories/Eloquent/   ← extends BaseRepository
    ├── Persistence/Repositories/Cache/      ← extends BaseCache (Redis decorator)
    ├── Notifications/                       ← Laravel Notifications (Telegram channel)
    └── Services/                            ← infrastructure services
```

Some contexts use `Domain/Contracts/` (Account) or `Domain/Repositories/` (Orders) for interfaces — **standardize on `Domain/Interfaces/` for new code**; migrate the others opportunistically.

`php artisan make:context <Name>` scaffolds only a subset (`Application/{Services,Commands,Queries}`, `Domain/{Enums,Entities}`, `Infrastructure/Persistence/Repositories/{Cache,Eloquent}`). Do **not** use the generated `Commands/`/`Queries/` folders — create `Application/{Actions,Data}` and the Domain folders you need instead.

## Action vs Service Placement

| Class | Folder | Rule |
|---|---|---|
| `VerbNounAction` | `Application/Actions/` | **Every use case.** Atomic: one public `run()` delegating to one repository or domain-service call. Orchestration: injects repositories, domain services, other Actions; owns transactions, state transitions, try/catch, job dispatch. May expose a few related public methods (`dispatchCycleEntry`, `dispatchCycleExit`). No validation (DTO), no business rules (Domain). `run()` inputs and outputs are typed objects whenever data crosses a boundary; use a DTO/Result instead of returning array shapes. |
| `XxxService` | `Application/Services/` | Facade exposing related Actions to UI (`BotService`), or real integration logic (`OrderEntryService`). Free-form method names. If it implements an interface, the interface must declare **all** public methods — no under-specified contracts. |

Legacy note: `VerbNounUC` classes in `Application/UseCases/` are the same pattern under an old name — treat them as atomic Actions, never create new ones, migrate on touch (see below).

If an Action grows past ~100 lines, extract smaller Actions, a Strategy, or a domain Service.

## Repository + Cache Placement

- **Eloquent repository**: `Infrastructure/Persistence/Repositories/Eloquent/XxxRepository.php`, `extends BaseRepository`, passes its model via `parent::__construct(new Xxx())`. Inherited API: `all()`, `get(int $id)`, `save()`, `saveFromArray()`, `createNewFromData(Data)`, `createNewFromArray()`, `createNewInstanceFrom*()` (build without saving), `delete()`, `upsert(array $data, array $uniqueBy, array $updateColumns)`, `updateOrCreate()`. Add context-specific query methods on top — don't rewrite CRUD.
- **Cache repository**: `Infrastructure/Persistence/Repositories/Cache/XxxCache.php`, `extends BaseCache`, injects its sibling Eloquent repository and a cache key. `get()` wraps the repo in `Cache::remember` with TTL from `config('trading.cache.*')`.
- Consumers inject the **concrete** repository (dominant practice) or the Cache decorator when read-through caching is wanted. Extract an interface only when a second implementation exists — and bind it in `TradingServiceProvider`.

## DI Binding Conventions

All interface bindings are centralized in `app/Providers/TradingServiceProvider::register()` (`ValidatorInterface`, `Logger`, `Telegram`, `AssetServiceInterface`, …). There are **no per-context service providers**.

- Type-hint an interface **only if** the binding exists in `TradingServiceProvider` — otherwise autowiring fails at runtime. Add the binding in the same change that introduces the interface.
- Never resolve dependencies with `app()`/`resolve()` inside Domain/Application constructors. Allowed pragmatically: Filament/Livewire closures (`->state(fn () => app(XxxService::class)->…)`) and Job `handle()` when constructor DI breaks serialization.

## Thin UI Layer (Filament/Livewire)

UI classes have exactly five responsibilities: **Validate → Authorize → Map to DTO → Delegate → Respond**.

```php
// ✅ Filament action delegating — no business logic
Action::make('entryManually')
    ->action(function (Bot $record, array $data): void {
        RunManualOrderEntryJob::dispatch(
            $record->id,
            (float) $data['order_amount'],
            OrderAmountTypeEnum::from($data['order_amount_type']),
            auth()->id(),
        )->afterCommit();

        Notification::make()->title('Order entry queued')->success()->send();
    });
```

```php
// ❌ Business logic in Filament — must go to an Action
->action(function (Bot $record, array $data): void {
    $exchange = ExchangeAdapterFactory::getExchange($record->exchange, $record->account, MarketTypeEnum::SPOT);
    $balance = $exchange->getFundsByAccountCoin(...); // 40 more lines
});
```

- `app(XxxService::class)` inside table/form closures is acceptable UI glue; the resolved class must still contain zero UI knowledge.
- Filament pages/tables live in `app/Filament/{Admin,UserPanel}/`; queries for tables may use Eloquent scopes, but any calculation (PnL, stats) delegates to a Service (`OrderUnrealizedPnlService`, `BotStatsService`).

## Legacy Migration: CQRS and UseCases → Actions

Two legacy generations coexist; both migrate to Actions. Migrate opportunistically — one class per touching change, no bulk renames.

**CQRS pairs** (`Application/Commands/` + `Application/Queries/` in Assets, Bots, Orders): `XCommand` + `XCommandHandler::handle()` dispatched through `CommandBus`/`QueryBus` (`Shared/Application/Support/Bus/`, auto-discovered). **Never add new ones.** When touching one:

1. Create `VerbNounAction` with `run()` taking a typed Data DTO — not a Command object — and returning a typed DTO/Entity/VO/Result/DataCollection instead of an array when more than a scalar outcome is needed.
2. Move the handler body into `run()`; check first whether a UC duplicate already exists (`UpsertAssetsFromSymbolsDataUC`, `GetAssetDetailsUC` already superseded their handlers — use that as the source and retire both into one Action).
3. Replace call sites: `$this->commandBus->dispatch(new XCommand(...))` / `$this->queryBus->ask(new XQuery(...))` → direct constructor injection of the Action.
4. Delete the Command/Query + Handler pair. When the last handler is gone, `Shared/Application/Support/Bus/` and the unused duplicate `Shared/Application/Support/CQRS/` can be removed.

**UseCases** (`Application/UseCases/`, `VerbNounUC`, ~60 classes): same pattern as an atomic Action with an old suffix. When touching one:

1. Rename `VerbNounUC` → `VerbNounAction` and move the file to `Application/Actions/`, keeping the public `run()` signature intact.
2. Normalize while moving: `declare(strict_types=1)`, `final class`, entry method named `run()` (two legacy outliers use `execute()`/`__invoke()` — rename them and their call sites).
3. Update all imports/injections — facade Services (`BotService`, `CycleService`) are the main consumers.

Note: `BotStats/Domain/Queries/` contains raw `.sql` view definitions — not CQRS, leave it alone.

## Domain Events

- Register listeners **explicitly** in `TradingServiceProvider::boot()` via `Event::listen(XEvent::class, XListener::class)` — there is no EventServiceProvider or auto-discovery. An unregistered listener is dead code.
- Dispatch after state is persisted. Listeners are thin — delegate to an Action.
- Do not use events for sequential steps in the same use case — call the next Action directly.

## Layer Checklist

- [ ] Class is in the correct layer folder; interfaces in `Domain/Interfaces/`
- [ ] No forbidden imports for that layer
- [ ] Public Application methods use typed DTO/Entity/VO/Result/DataCollection return contracts, not raw arrays
- [ ] UI delegates — zero business logic in Filament/Livewire/Controller closures
- [ ] Repository extends `BaseRepository`; cache decorator extends `BaseCache`
- [ ] Type-hinted interfaces are bound in `TradingServiceProvider`
- [ ] No new Command/Query/Handler or UC classes; touched legacy migrated to Actions
- [ ] Reused `BaseRepository` / `BaseException` / `Logger` / Shared enums
