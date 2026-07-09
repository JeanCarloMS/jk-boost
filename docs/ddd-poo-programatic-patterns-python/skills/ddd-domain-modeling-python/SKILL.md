---
name: ddd-domain-modeling-python
description: >-
  Domain layer building blocks for src/contexts — pure Entities (from_model),
  ValueObjects with private constructors and invariant validation, StrEnum/IntEnum,
  Pydantic validators, static match-based Factories, the Pipeline/Pipes
  pattern, domain Services, and the BaseException hierarchy. Use when creating or
  refactoring Entities, VOs, Enums, validators, Factories, Pipes, Strategies, or
  domain exceptions. Complements always-applied rule ddd-poo-programatic-patterns-python.
---

# DDD Domain Modeling (Python)

The `ddd-poo-programatic-patterns-python` master rule is always applied.

## Entities — pure Python, hydrated from ORM

Entities in `domain/entities/` are **not SQLAlchemy models** (those live in `src/models/`). They hydrate from models and carry business behavior. Two styles coexist; **use the Pydantic style for new entities**:

```python
# ✅ New entities: Pydantic based (like CandleEntity, StrategyEntity, BotLogEntity)
from __future__ import annotations

from typing import final

from pydantic import BaseModel, ConfigDict, Field

from src.contexts.signals.domain.enums.signal_action_enum import SignalActionEnum
from src.models.signal import Signal


@final
class SignalEntity(BaseModel):
    model_config = ConfigDict(frozen=True)

    id: int | None = None
    action: SignalActionEnum | None = None
    coinpair: str | None = None

    @classmethod
    def from_model(cls, signal: Signal) -> SignalEntity:
        return cls.model_validate(signal, from_attributes=True)
```

The older hand-rolled style (`OrderEntity`, `CycleEntity`: `BaseEntityProtocol` + `to_dict()`, private `_id` excluded from `to_dict()`, enum coercion in `__init__`) is valid legacy — keep its conventions when editing those files, but don't start new entities that way.

**Entity rules:**
- Named constructors express the source: `from_model()`, `from_order_data()`, `from_base_spot_order_data()`.
- Business behavior lives on the entity: `OrderEntity.calculate_profit()`, `CycleEntity.exit_cycle()`, `deactivate()`, `merge()`.
- **No SQLAlchemy queries inside entities** (`session.query(Order).where(...)` inside an entity is a defect — move to the Repository) and **no logger resolution in constructors** — pass collaborators in or log from the calling Action.
- A strategy/aggregate is not an entity: classes extending `BaseBot` belong under `domain/strategies/`, not `domain/entities/`.

## Value Objects — immutable, validated at construction

Two real styles; pick by intent:

**Guarded scalar** (`Money`, `OrderPrice`, `OrderAmount`, `CoinpairVO`, `PriceVO`) — private constructor + static factories + invariants that raise domain exceptions:

```python
from __future__ import annotations

from dataclasses import dataclass
from typing import final

from src.contexts.orders.domain.exceptions.invalid_money_exception import InvalidMoneyException


@final
@dataclass(frozen=True, slots=True)
class Money:
    amount: float
    currency: str | None = None
    decimals: int = 8

    def __post_init__(self) -> None:
        if self.amount < 0:
            raise InvalidMoneyException("Amount cannot be negative")

    @classmethod
    def from_float(cls, amount: float | None, currency: str | None = None) -> Money | None:
        if amount is None:
            return None
        return cls(amount=amount, currency=currency)

    def add(self, other: Money) -> Money:
        self._assert_same_currency(other)
        return Money(
            amount=self.amount + other.amount,
            currency=self.currency,
            decimals=self.decimals,
        )
```

- Multiple named factories per intent: `OrderAmount.from_quote()/from_base()/from_percent()/from_amount_type()`; `CoinpairVO.from_string()` normalizes + regex-validates.
- Helpers by domain meaning: `PriceVO.is_infinite()/to_redis_score()`, `OrderPrice.instant_buy_limit()`.
- Prefer `@final` + `@dataclass(frozen=True, slots=True)` for new VOs.

**Column-mirror detail VOs** (`CycleSellDetailsVO`, `PnLVO`, `TotalDetailsVO`) — `BaseValueObjectProtocol` + `to_dict()`, `frozen` dataclass or Pydantic frozen model, a `FIELDS` whitelist, `from_model()`/`from_data()` factories, immutable arithmetic (`PnLVO.add_pnl_vo()` returns a new instance).

**VO rules:** never mutate, never query the DB, never resolve services. Every invariant is checked at construction and raises a context exception.

## Enums

Use `enum.StrEnum` or `enum.IntEnum`, suffix `Enum`, in `domain/enums/` (shared ones in `shared/domain/enums/`). Standard helper set — add only what the enum actually needs:

- `label() -> str` via `match self` for UI display; `labels()` / `to_array()` for selects.
- `for_select()` / `for_rule_in()` driven by `settings.trading.*` when options are environment-dependent (`ExchangeAvailableEnum`).
- Tolerant parsing: `from_name_or_value()` when input may be a name or a backing value (`SignalActionEnum`).
- Behavior helpers where they belong to the concept: `BotStatusEnum.toggle()`, `equals()`.

## Validators (Pydantic)

`domain/validators/` holds coercion/validation helpers used with `@field_validator` or `@model_validator` on Data/Entity properties. Three established uses:

1. **id → ORM model** (`BotValidator`, `CycleValidator`, `StrategyValidator`): `session.get(Bot, value) or raise ValueError(...)`.
2. **value → VO/enum** (`PriceVOValidator`, `SignalActionEnumValidator`).
3. **string normalization** (`CoinpairValidator` delegating to `AssetService.cast_string_to_coinpair`).

Do not confuse with SQLAlchemy column types or ORM hybrid properties. API caveat: returning a VO in a response schema may need explicit serialization — use `model_serializer` when the UI expects a primitive.

## Factories — static `make()` + `match` on enum

```python
# bots/domain/factories/bot_factory.py
return match strategy_type:
    case BotTypeEnum.DCA:
        return DcaStrategy(user, account, exchange_adapter, bot, ...)
    case BotTypeEnum.SIGNAL:
        return SignalBotEntity(user, account, exchange_adapter, bot, ...)
    case _:
        raise HandleException(f"Invalid strategy type: {strategy_type.name}")
```

- Factories create Strategies, Adapters, Checkers, Indicators — variant selection, not entity persistence.
- `default` arm **always raises** `HandleException` — never return `None` silently.
- **Return instances typed by Protocol** (`BotProtocol`, `BaseIndicator`) — not class-name strings (checker factories returning `::class` strings are legacy; don't copy that).
- When a factory must return structured metadata alongside the instance, introduce a typed Result DTO instead of returning a dict.
- Two-level selection is fine when the variant depends on runtime state (`SignalEntryCheckerFactorySelector` picks a factory by active-order count, the factory picks the checker by enum).
- Name the method `make()` for new factories (legacy uses `create`/`get_exchange`/`get_checker`).

## Pipeline Pattern (Pipes)

Sequential filters/validations before an operation use a lightweight pipeline. The passable is the aggregate; each pipe either calls `next_(passable)` or **short-circuits by raising `HandleException`** with an appropriate `log_level`.

```python
# Orchestration — bots/domain/strategies/spot/dca/dca_strategy.py
def run_entry_pipeline(self) -> None:
    pipeline = Pipeline([
        BotEnabledFilterPipe(),
        StrategyEnabledFilterPipe(),
        CycleEnabledFilterPipe(),
        BuyEnabledFilterPipe(),
        CandleBodySizeFilter(),
        PriceLimitFilter(),
        BudgetDayFilter(),
        CountOrdersDayFilter(),
    ])
    pipeline.process(self, lambda bot: SignalEntryCheckerAnalyzer(bot).run(self.get_cycle()))
```

```python
# A filter pipe — bots/domain/pipes/filters/bot_enabled_filter_pipe.py
@final
class BotEnabledFilterPipe:
    def handle(self, bot: BaseBot, next_: Callable[[BaseBot], None]) -> None:
        if not bot.bot.enabled and signal_action != SignalActionEnum.SELL_ALL:
            raise HandleException(
                "BotEnabledFilterPipe: Bot is disabled",
                log_level=LogLevelEnum.WARNING,
            )

        next_(bot)
```

- Place pipes in `domain/pipes/` (subfolder per family: `filters/`, `validation/`).
- One condition per pipe; the pipe name states the rule (`BudgetDayFilter`).
- Pipes may enrich the passable (`entity.set_order(order)`) before passing it on.
- Use a Pipeline when ≥3 sequential guards exist or variants share guard subsets; for 1-2 checks, plain early returns in the Action are simpler.

## Domain Services

`domain/services/` orchestrate Entities + VOs + Repositories for logic that doesn't belong to one entity (`CycleDomainService.exit_cycle/refresh_cycle`). Constructor-injected typed dependencies (repository + `Logger`). Name them by concept — prefer `XxxDomainService` when a `XxxService` facade already exists in Application to avoid collisions.

Domain services return typed domain objects (Entity, VO, Protocol implementation, or Result DTO). Do not return dicts/lists for multi-value outcomes; create a small DTO/result object so downstream Application code remains fully typed.

## Exceptions

- Base: `shared/domain/exceptions/base_exception.py` — constructor flags `body`, `footer`, `send_telegram`, `save_exception`, `save_stack`, `log_level`; `report()` logs via `Logger` and optionally notifies Telegram; `to_response()` returns JSON for API errors.
- `HandleException(BaseException)` is the default throwable for guard failures and factory `default` arms.
- Context-specific exceptions extend `BaseException` (or `HandleException`): `NoActiveOrderException(HandleException)`. **Never extend raw `Exception`** and never re-implement Telegram reporting in the exception (that logic exists once in `BaseException` — migrate legacy offenders on touch).
- Expected outcomes ("already processed", "no active order") → `log_level=LogLevelEnum.WARNING` or an early return with a log — not an ERROR-level exception.

## Domain Checklist

- [ ] Entity is pure Python with `from_model()`; no SQLAlchemy queries or container resolution inside
- [ ] VO is `@final` (`frozen` preferred), private ctor + static factories, invariants raise
- [ ] Enum is backed, `Enum` suffix, helpers only as needed
- [ ] Validator placed in `domain/validators/` or as Pydantic `@field_validator`
- [ ] Factory: static `make()`, `match` on enum, `default` raises `HandleException`, returns Protocol type
- [ ] Domain service/factory multi-value returns are DTO/Result objects, not dicts/lists
- [ ] Pipes short-circuit by raising; pipeline orchestrated in the Strategy/Action
- [ ] Exceptions extend `BaseException`/`HandleException`
- [ ] Strategies/aggregates under `domain/strategies/`, not `entities/`
