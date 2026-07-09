# DDD + POO + Programmatic Patterns (Master — Python)

Stack: Python 3.12+, FastAPI, SQLAlchemy 2 (async optional), Pydantic v2, Celery + Redis, pytest, Ruff.
Run CLI commands directly: `uv run pytest ...`, `uv run ruff check --fix`, `uv run celery -A app.worker worker`.

## Satellite Skills (load when relevant)

| Skill | Activate when |
|---|---|
| `ddd-layer-boundaries-python` | Creating/refactoring contexts, layer placement, repositories, thin UI, legacy handler→Action migration |
| `ddd-domain-modeling-python` | Entities, ValueObjects, Enums, validators, Factories, Pipeline/Pipes, domain exceptions |
| `ddd-jobs-async-python` | Celery tasks, queues, Outbox pattern, Redis locks, transactions, idempotency |
| `ddd-external-adapters-python` | Exchange adapters, HTTP clients, webhooks, Telegram notifications, anti-corruption layer |
| `ddd-logging-python` | Adding logging, creating log channels, LoggerContext/LoggerProtocol, replicating Shared logging infra |
| `ddd-testing-python` | Writing or updating tests, refactoring with coverage |

## Core Architecture

- DDD modular monolith: bounded contexts in `src/contexts/{context}/{domain,application,infrastructure}`.
- **SQLAlchemy models live in `src/models/`** (global): `Bot`, `Cycle`, `Order`, `Asset`, `Strategy`, `Signal`, `Candle`, `Exchange`…
- **`domain/entities/` are pure Python objects, NOT ORM models** — they hydrate from models via `from_model()`.
- **The Action pattern is the single entry point for every use case.** One class = one business operation: `VerbNounAction` in `application/actions/` with a single public `run()` method (`run()`, not `execute()` — the codebase standardizes on `run()`).
  - An Action can be **atomic** (delegate to one repository or domain-service call) or an **orchestrator** (coordinate repositories, domain services, other Actions, transactions, state transitions, task dispatch).
  - Actions contain no validation (DTO's job) and no business rules (Domain's job) — they orchestrate.
  - **Service** (`application/services/`): facade grouping related Actions behind one API for UI/consumers, or genuine integration logic (`OrderEntryService`).
- **Legacy handlers and `*UseCase` classes are deprecated.** Never create new `Command`/`Query`/`*Handler` classes, bus dispatchers, or `VerbNounUseCase` classes. Existing legacy classes keep working — when touching one, migrate it to an Action (see `ddd-layer-boundaries-python`).
- Scaffold new contexts with a project CLI (`make-context <Name>`) or manually; add missing folders per `ddd-layer-boundaries-python`.

## Design Patterns (Priority)

| Pattern | Use when |
|---|---|
| **Action** | **Default for every use case** — atomic (delegate to one repository/domain service) or multi-step orchestration (entry/exit flows, outbox dispatch, simulation). |
| **Repository + Cache** | Data access. SQLAlchemy repo extends `BaseRepository`; Redis decorator extends `BaseCache`. |
| **Service (facade)** | Group related Actions behind one interface for UI consumption. |
| **Factory** | Variant creation via static `make()` + `match` on enum; `default` arm raises `HandleException`. |
| **Strategy** | Variant behavior (`BaseBot` → `DcaStrategy`/`SignalBotEntity`; indicators → `BaseIndicator`). |
| **Pipeline (Pipes)** | Sequential filters/validations before an operation; pipes short-circuit by raising. |
| **Adapter** | External APIs behind Domain protocols (`ExchangeAdapterProtocol`). |
| **DTO (Data)** | Typed data crossing layers and return contracts — return objects, not dicts/lists; use Pydantic `BaseModel` when advanced mapping/validation/serialization is needed, lightweight dataclasses otherwise. |
| **Value Object** | Immutable domain value: private constructor, static factories, invariant validation. |
| **Outbox** | Reliable async side effects after persistence (see `ddd-jobs-async-python`). |

Common stacks: Action + Repository (+ nested Actions) · Strategy + Factory + Pipeline · Adapter + Factory + Data.

## Hard Rules (never violate)

- **No new CQRS or UseCases** — Actions instead; migrate handlers and `UseCase` classes on touch.
- **No raw dicts/lists** for domain data between classes or function returns — return typed DTOs, VOs, Entities, Results, or typed collections. Dicts/lists are only acceptable at true framework/transport/persistence boundaries (`to_dict()`, `model_dump()`, JSON payloads, DB writes).
- **No business logic** in FastAPI routers, CLI commands, or admin views — delegate to an Action/Service.
- **No SQLAlchemy queries inside Entities/VOs/Domain services** — persistence goes through Repositories.
- **No direct instantiation of collaborators in Domain or Application `__init__`** — container/DI injection only. Exceptions: FastAPI `Depends()` at the router boundary and Celery task `run()` when constructor DI breaks serialization — resolve there and document why.
- **No magic strings/numbers** — `StrEnum`/`IntEnum` (`XxxEnum`) or `settings` (`settings.trading.*`).
- **Exceptions extend `BaseException`** (or raise `HandleException`) — never raw `Exception`, never re-implement Telegram reporting, never silent `except Exception`.
- **New classes:** type hints on all public APIs, prefer `@final` decorator, constructor with typed private attributes (`self._repo: CycleRepository`), explicit return types.
- **No debug leftovers** — no `breakpoint()`, `pprint()`, `print()` debug calls, or commented-out blocks in committed code.
- **No over-engineering** — simplest sufficient pattern; extract a `Protocol` only when a second implementation appears, and bind it in `AppContainer`.

## SOLID + Dependency Injection

Single responsibility per class · Open/closed via Strategy/Factory/Pipeline · Liskov honored · Small protocols that match their implementations · Dependency inversion via container.

- Constructor injection with typed private attributes — always.
- Protocol → implementation bindings are centralized in `AppContainer.register()`. **If you type-hint a Protocol, verify the binding exists there** — several legacy protocols are type-hinted but unbound.
- Injecting a concrete Repository is acceptable (dominant practice); extract the Protocol when a second implementation appears.
- Celery task constructors take **scalar IDs only** (serialization); collaborators arrive via task method injection or `Depends`-style resolution in `run()`.

## Reuse Shared Infrastructure (`src/contexts/shared/`)

| Component | Path | Use for |
|---|---|---|
| `BaseRepository` / `BaseRepositoryProtocol` | `shared/domain/repositories/` | All SQLAlchemy repositories |
| `BaseCache` | `shared/domain/repositories/` | Redis cache decorator over a repository |
| `BaseException` / `HandleException` | `shared/domain/exceptions/` | All exceptions (Logger + Telegram reporting built in) |
| `Logger` | `src/contexts/logger/` | All logging — inject, don't use bare `logging.getLogger()` in domain code |
| `ExecutionContext` | `shared/application/support/` | Trace-id / execution context |
| `BaseCustomData` | `shared/application/` | Lightweight DTO/VO `to_dict()` when Pydantic is unnecessary |
| `validate_fields` decorator | `shared/domain/validators/` | Attribute-driven DTO validation |
| `BaseTriggerData` | `shared/application/data/` | Per-strategy trigger DTOs |

## Logging (mandatory)

- **Standard pattern (channel-based, see `ddd-logging-python`):** consumers inject the Shared `LoggerProtocol` (stdlib `logging` compatible, `shared/domain/logging/`) — never bare `logging.getLogger()` with hardcoded channel names in domain code. Entry points (CLI command/Celery task/event listener) inject `LoggerContext` and call `set_channel('<flow>')` once; each process/flow has its own rotating log channel/file in logging config.
- Message prefix is always `ClassName.method_name`; context dict keys are snake_case domain ids (`bot_id`, `cycle_id`, `order_id`).
- Log at: Action start/end, factory/strategy selection, external calls (with `duration_ms`), state transitions, skipped operations, failures. Exceptions extending `BaseException` self-report (Logger + Telegram) — don't double-log around them. Never log secrets, API keys, or tokens.
- Legacy note: files already using `Logger.save(...)` keep that convention; new Shared logging infrastructure follows `ddd-logging-python`.

## Exceptions and Results

- Raise `HandleException` (or a context exception extending `BaseException`) with `log_level` set for severity.
- Expected business outcomes (skipped, already processed, already exists) → return early with a log entry or a typed Result DTO — not an exception and not a shape dict.
- Document `Raises` in public Application method docstrings.

## Naming

- PascalCase classes, snake_case methods and modules, snake_case DB columns. Entry method is `run()`.
- Suffixes: `Action`, `Service`, `Data`, `DataCollection`, `VO`, `Entity`, `Repository`, `Cache`, `Factory`, `Strategy`, `Adapter`, `Pipe`, `Enum`, `Task`, `Exception`, `Protocol`. (`UseCase` is a legacy suffix — never use it for new classes.)
- Actions: verb + noun — `RunBotEntryFromTriggerAction`, `SimulateOrdersAction`, `ProcessOutboxTriggerAction`.
- Code identifiers and log messages in English; keep any existing Spanish docstrings consistent within their file.

## DTO Standards

- Location: `application/data/` inside the context; `Data` suffix for DTOs and `ResultData` / intent-specific names for results.
- Functions should prefer returning a typed DTO object over `dict`/`list`. If a method currently needs multiple values, create a small DTO/result object instead of documenting a dict shape.
- Choose DTO implementation by need:
  - Extend Pydantic `BaseModel` when the DTO needs validation, field/model validators, aliasing, nested models, ORM hydration, or API serialization.
  - Use a lightweight `@final` + `@dataclass(frozen=True, slots=True)` when it only carries internal data and does not need Pydantic features.
- Typed fields always (no untyped `Any` without reason), enum-typed fields with custom validators where needed.
- Static named constructors expressing intent: `from_model()`, `for_cycle_entry_dispatch()`, `from_exchange_symbol()`.
- Validation rules belong in the DTO (`@field_validator`, `model_validator`) — not in Actions.
- Typed collections use a `DataCollection` wrapper or `list[SomeData]` with explicit typing; collection-returning methods should return that typed collection, not `list[dict[str, Any]]`.

## Definition of Done

- [ ] Correct layer and folder — see `ddd-layer-boundaries-python`
- [ ] Logic in Actions; UI, Celery tasks, and CLI commands are thin
- [ ] DTOs/VOs/Entities/typed collections — no dict/list payloads between classes or as return contracts
- [ ] `run()` method, `@final` where applicable, typed constructor
- [ ] No new CQRS/UseCases; touched legacy handlers and UseCases migrated to Actions
- [ ] Logger injected; logs at critical points with `Class::method` prefix
- [ ] Exceptions extend `BaseException`/`HandleException`
- [ ] Tests passing — see `ddd-testing-python`
- [ ] Tasks/transactions/locks correct — see `ddd-jobs-async-python`
- [ ] `ruff check` clean; Shared infra reused; no over-engineering

## Minimal Example

```python
from __future__ import annotations

from dataclasses import dataclass
from typing import final

from src.contexts.cycles.application.actions.get_cycle_active_orders_action import (
    GetCycleActiveOrdersAction,
)
from src.contexts.cycles.application.data.exit_cycle_data import ExitCycleData
from src.contexts.cycles.domain.entities.cycle_entity import CycleEntity
from src.contexts.cycles.infrastructure.persistence.repositories.cycle_repository import (
    CycleRepository,
)
from src.contexts.logger.logger import Logger


@final
@dataclass(frozen=True, slots=True)
class ExitCycleAction:
    cycle_repository: CycleRepository
    get_cycle_active_orders_action: GetCycleActiveOrdersAction
    logger: Logger

    def run(self, data: ExitCycleData) -> CycleEntity:
        self.logger.save("ExitCycleAction.run Starting", {"cycle_id": data.cycle_id})

        cycle = self.cycle_repository.get(data.cycle_id)
        orders = self.get_cycle_active_orders_action.run(cycle)

        cycle_entity = CycleEntity.from_model(cycle).exit_cycle(orders)
        self.cycle_repository.save_from_dict(cycle, cycle_entity.to_dict())

        self.logger.save("ExitCycleAction.run Done", {"cycle_id": data.cycle_id})

        return cycle_entity
```
