---
name: ddd-layer-boundaries-python
description: >-
  DDD layer boundaries for src/contexts — real folder structure, import rules,
  Action vs Service placement, repository + cache placement, DI bindings,
  thin FastAPI/CLI UI, Shared infrastructure reuse, and legacy handler/UseCase→Action
  migration. Use when creating or refactoring bounded contexts, Actions, Repositories,
  API routers, or migrating legacy Command/Query handlers or UseCase classes.
  Complements always-applied rule ddd-poo-programatic-patterns-python.
---

# DDD Layer Boundaries (Python)

The `ddd-poo-programatic-patterns-python` master rule is always applied.

## Layer Import Rules (Strict)

| Layer | Path | Responsibilities | Must NOT import |
|---|---|---|---|
| **Domain** | `domain/` | Entities (pure Python), VOs, Enums, Factories, Pipes, Strategies, domain Services, validators, Exceptions, repository **protocols** | FastAPI, HTTP clients, Celery tasks |
| **Application** | `application/` | Actions, Services (facades), typed Data/Result DTOs, Celery tasks (thin), Listeners | FastAPI routers, HTTP frameworks |
| **Infrastructure** | `infrastructure/` | SQLAlchemy + Cache repositories, exchange Adapters, HTTP clients, Notifications | FastAPI, admin UI |
| **UI** | `src/api/`, `src/cli/`, admin views | Validate, authorize, map DTO, delegate, respond | Business logic, direct SQLAlchemy queries |

**Golden rule:** UI is a thin orchestrator. All business logic lives in Actions, Services, Strategies, and Domain classes.

**SQLAlchemy models live in `src/models/`** (global), not inside contexts. `domain/entities/` hold pure Python entities hydrated via `from_model()` (see `ddd-domain-modeling-python`). Known exception: `OutboxTrigger` lives in `outbox/infrastructure/persistence/models/` — do not replicate that placement for new models.

## Real Folder Structure

```text
src/contexts/{context}/
├── domain/
│   ├── entities/            ← pure Python entities (NOT ORM), from_model()
│   ├── value_objects/       ← immutable VOs, private ctor + static factories
│   ├── enums/               ← StrEnum/IntEnum, XxxEnum suffix
│   ├── exceptions/          ← extend BaseException / HandleException
│   ├── protocols/           ← contracts (XxxProtocol suffix) — single home for protocols
│   ├── factories/           ← static make() + match on enum
│   ├── services/            ← domain services (entity + VO + repository orchestration)
│   ├── validators/          ← Pydantic field validators / custom coercion
│   ├── pipes/               ← sequential filter pipeline
│   ├── strategies/          ← strategy hierarchies (Bots: BaseBot → DcaStrategy…)
│   └── events/              ← domain events
├── application/
│   ├── actions/             ← ALL use cases (atomic or orchestration), Action suffix
│   ├── use_cases/           ← LEGACY (UseCase suffix) — never add here; migrate to Actions on touch
│   ├── services/            ← facades grouping Actions / integration logic
│   ├── data/                ← typed DTOs/Results/DataCollections; Pydantic only when advanced features are needed
│   ├── tasks/               ← thin Celery tasks (delegate to Action/Service)
│   ├── protocols/           ← application service protocols
│   └── listeners/           ← thin event listeners
└── infrastructure/
    ├── persistence/repositories/sqlalchemy/   ← extends BaseRepository
    ├── persistence/repositories/cache/      ← extends BaseCache (Redis decorator)
    ├── notifications/                       ← notification classes (Telegram channel)
    └── services/                            ← infrastructure services
```

Some contexts use `domain/contracts/` or `domain/repositories/` for protocols — **standardize on `domain/protocols/` for new code**; migrate the others opportunistically.

Do **not** use generated `commands/`/`queries/` folders — create `application/{actions,data}` and the Domain folders you need instead.

## Action vs Service Placement

| Class | Folder | Rule |
|---|---|---|
| `VerbNounAction` | `application/actions/` | **Every use case.** Atomic: one public `run()` delegating to one repository or domain-service call. Orchestration: injects repositories, domain services, other Actions; owns transactions, state transitions, try/except, task dispatch. May expose a few related public methods (`dispatch_cycle_entry`, `dispatch_cycle_exit`). No validation (DTO), no business rules (Domain). `run()` inputs and outputs are typed objects whenever data crosses a boundary; use a DTO/Result instead of returning dict/list shapes. |
| `XxxService` | `application/services/` | Facade exposing related Actions to UI (`BotService`), or real integration logic (`OrderEntryService`). Free-form method names. If it implements a protocol, the protocol must declare **all** public methods — no under-specified contracts. |

Legacy note: `VerbNounUseCase` classes in `application/use_cases/` are the same pattern under an old name — treat them as atomic Actions, never create new ones, migrate on touch (see below).

If an Action grows past ~100 lines, extract smaller Actions, a Strategy, or a domain Service.

## Repository + Cache Placement

- **SQLAlchemy repository**: `infrastructure/persistence/repositories/sqlalchemy/xxx_repository.py`, `extends BaseRepository`, passes its model via `super().__init__(Xxx)`. Inherited API: `all()`, `get(id)`, `save()`, `save_from_dict()`, `create_new_from_data()`, `create_new_from_dict()`, `create_new_instance_from_*()` (build without saving), `delete()`, `upsert()`, `update_or_create()`. Add context-specific query methods on top — don't rewrite CRUD.
- **Cache repository**: `infrastructure/persistence/repositories/cache/xxx_cache.py`, `extends BaseCache`, injects its sibling SQLAlchemy repository and a cache key. `get()` wraps the repo in `cache.remember` with TTL from `settings.trading.cache.*`.
- Consumers inject the **concrete** repository (dominant practice) or the Cache decorator when read-through caching is wanted. Extract a Protocol only when a second implementation exists — and bind it in `AppContainer`.

## DI Binding Conventions

All protocol bindings are centralized in `src/container.py` (`AppContainer.register()`) (`ValidatorProtocol`, `Logger`, `Telegram`, `AssetServiceProtocol`, …). There are **no per-context containers**.

- Type-hint a Protocol **only if** the binding exists in `AppContainer` — otherwise resolution fails at runtime. Add the binding in the same change that introduces the Protocol.
- Never resolve dependencies with manual `Container()` calls inside Domain/Application constructors. Allowed pragmatically: FastAPI `Depends(get_xxx_service)` at router boundaries and Celery task `run()` when constructor DI breaks serialization.

## Thin UI Layer (FastAPI / CLI)

UI classes have exactly five responsibilities: **Validate → Authorize → Map to DTO → Delegate → Respond**.

```python
# ✅ FastAPI route delegating — no business logic
@router.post("/bots/{bot_id}/manual-entry")
def entry_manually(
    bot_id: int,
    payload: ManualEntryRequest,
    user: User = Depends(get_current_user),
) -> ManualEntryResponse:
    ProcessManualOrderEntryTask.delay(
        bot_id,
        payload.order_amount,
        payload.order_amount_type.value,
        user.id,
    )

    return ManualEntryResponse(message="Order entry queued")
```

```python
# ❌ Business logic in router — must go to an Action
@router.post("/bots/{bot_id}/manual-entry")
def entry_manually(bot_id: int, payload: ManualEntryRequest) -> dict:
    exchange = ExchangeAdapterFactory.make(bot.exchange, bot.account, MarketTypeEnum.SPOT)
    balance = exchange.get_funds_by_account_coin(...)  # 40 more lines
```

- `Depends(get_xxx_service)` inside route handlers is acceptable UI glue; the resolved class must still contain zero UI knowledge.
- API routers live in `src/api/routers/`; queries for list endpoints may use SQLAlchemy scopes, but any calculation (PnL, stats) delegates to a Service (`OrderUnrealizedPnlService`, `BotStatsService`).

## Legacy Migration: CQRS and UseCases → Actions

Two legacy generations coexist; both migrate to Actions. Migrate opportunistically — one class per touching change, no bulk renames.

**CQRS pairs** (`application/commands/` + `application/queries/`): `XCommand` + `XCommandHandler.handle()` dispatched through a bus. **Never add new ones.** When touching one:

1. Create `VerbNounAction` with `run()` taking a typed Data DTO — not a Command object — and returning a typed DTO/Entity/VO/Result/DataCollection instead of a dict/list when more than a scalar outcome is needed.
2. Move the handler body into `run()`; check first whether a UseCase duplicate already exists — use that as the source and retire both into one Action.
3. Replace call sites: `command_bus.dispatch(XCommand(...))` / `query_bus.ask(XQuery(...))` → direct constructor injection of the Action.
4. Delete the Command/Query + Handler pair. When the last handler is gone, the bus infrastructure can be removed.

**UseCases** (`application/use_cases/`, `VerbNounUseCase`): same pattern as an atomic Action with an old suffix. When touching one:

1. Rename `VerbNounUseCase` → `VerbNounAction` and move the file to `application/actions/`, keeping the public `run()` signature intact.
2. Normalize while moving: `@final`, type hints, entry method named `run()` (legacy outliers using `execute()`/`__call__()` — rename them and their call sites).
3. Update all imports/injections — facade Services (`BotService`, `CycleService`) are the main consumers.

Note: `bot_stats/domain/queries/` may contain raw `.sql` view definitions — not CQRS, leave it alone.

## Domain Events

- Register listeners **explicitly** in `AppContainer.on_startup()` or an event registry — there is no auto-discovery. An unregistered listener is dead code.
- Dispatch after state is persisted. Listeners are thin — delegate to an Action.
- Do not use events for sequential steps in the same use case — call the next Action directly.

## Layer Checklist

- [ ] Class is in the correct layer folder; protocols in `domain/protocols/`
- [ ] No forbidden imports for that layer
- [ ] Public Application methods use typed DTO/Entity/VO/Result/DataCollection return contracts, not raw dicts/lists
- [ ] UI delegates — zero business logic in router/CLI closures
- [ ] Repository extends `BaseRepository`; cache decorator extends `BaseCache`
- [ ] Type-hinted protocols are bound in `AppContainer`
- [ ] No new Command/Query/Handler or UseCase classes; touched legacy migrated to Actions
- [ ] Reused `BaseRepository` / `BaseException` / `Logger` / Shared enums
