---
name: ddd-testing-python
description: >-
  Testing standards for src/contexts — pytest, suite layout, unittest.mock + httpx/responses
  fakes, factories, coverage priorities for Actions, ValueObjects, Repositories,
  Celery tasks, and API routers. Use when writing, updating, or reviewing tests, or when
  refactoring with test coverage requirements.
  Complements always-applied rule ddd-poo-programatic-patterns-python.
---

# DDD Testing Standards (Python)

The `ddd-poo-programatic-patterns-python` master rule is always applied.

## Suite Configuration (as wired)

- `tests/conftest.py` provides `db_session`, `test_client`, and `app_container` fixtures for the **integration** suite. Do not re-declare `db_session` inside integration files — it's already global when marked with `@pytest.mark.integration`.
- **Unit tests run without DB/container** — no SQLAlchemy session, no Redis, no Celery broker. If a unit test needs the app (rare — prefer moving it to integration), opt in with `@pytest.mark.integration`.
- Test env (`pyproject.toml` / `pytest.ini`): sqlite `:memory:` or test Postgres, `CELERY_TASK_ALWAYS_EAGER=true`, `CACHE_URL=memory://`, mail backend in-memory. DB fixtures run migrations — seed data created by migrations is available; anything else needs factories or explicit creation.
- Run: `uv run pytest -q -k <ClassName>` or `uv run pytest -q tests/integration/<context>/`.

## Layout

Mirror `src/contexts` one level deep: `tests/unit/<context>/...` and `tests/integration/<context>/...`.

⚠️ Never nest the suite name twice — `tests/integration/integration/...` exists as legacy scaffolding mistakes; place new tests at the correct depth and never add `assert True` placeholder files.

## Mandatory Coverage

Every new or refactored class in Application/Domain layers gets tests before the task is done:

| Class type | Suite | What to assert |
|---|---|---|
| **Action (atomic)** | Unit | Delegates to repository/domain service with the right arguments, typed return object |
| **Action (orchestration)** | Integration (uses DB/container) | Happy path, idempotency guard (skip path), failure marks state + re-raises, task dispatched (`celery_app.conf.task_always_eager = True` + assert) |
| **ValueObject** | Unit | Invariants raise on invalid input, factories, arithmetic returns new instances, `to_dict()` |
| **Entity** | Unit | `from_model()`/`from()` mapping, business methods (`exit_cycle`, `calculate_profit`) |
| **Factory** | Unit | Correct variant per enum case, `default` raises `HandleException` |
| **Strategy / Trader** | Integration | Behavior per variant with mocked collaborators |
| **Pipe** | Unit | Passes valid aggregate to `next_`, raises `HandleException` on guard failure |
| **DTO / Result DTO** | Unit or Integration | Constructor typing, `from()`/named constructors, validators/validation rules when using Pydantic, no dict/list-shape return contract |
| **Repository** | Integration | Custom query methods return expected rows (not inherited BaseRepository CRUD) |
| **Celery task** | Integration | Delegates to Action; `on_failure` notifies |
| **API router** | Integration | Delegation + authorization, via `test_client` |

Legacy `UseCase` classes are tested exactly like atomic Actions — write the test when migrating one to `application/actions/`.

Current gaps to close when touching these areas: ValueObjects, Actions (and legacy UseCases), Repositories, and API interactions have **no** existing coverage — add tests when you modify them.

## Conventions (from the existing suite)

**Naming:** `def test_<behavior>()` describing behavior, not methods. One language per file (existing tests may be mixed English/Spanish — English preferred for new files).

```python
def test_skips_dispatch_when_an_active_outbox_already_exists_for_the_aggregate():
    ...

def test_raises_invalid_money_exception_when_amount_is_negative():
    ...
```

**Mocking — `unittest.mock` + `pytest` fixtures:**

```python
from unittest.mock import MagicMock, create_autospec

def test_exit_cycle_closes_cycle(repository: MagicMock) -> None:
    repository = create_autospec(CycleRepositoryProtocol, instance=True)
    repository.get.return_value = cycle
    repository.save_from_dict.return_value = cycle

    action = ExitCycleAction(
        cycle_repository=repository,
        get_cycle_active_orders_action=get_orders_action,
        logger=logger,
    )
    result = action.run(ExitCycleData(cycle_id=5))

    repository.get.assert_called_once_with(5)
    repository.save_from_dict.assert_called_once()
    assert result.status == CycleStatusEnum.CLOSED
```

- `unittest.mock.ANY` / `call()` / `assert_called_with()` for argument matching.
- `patch("module.Class")` **only** for legacy static factories that can't be injected — don't design new code to need this.
- Hand-rolled doubles (subclasses of `BaseBot`/`BaseSymbolsData`) are acceptable for abstract aggregates that `MagicMock` handles poorly.
- When testing multi-value outcomes, assert the concrete DTO/Result class and typed attributes instead of asserting dict keys. Dict/list assertions are reserved for explicit serialization boundaries such as `to_dict()`, `model_dump()`, or HTTP JSON payloads.

**Framework fakes alongside mocks:**

```python
def test_dispatches_outbox_task(celery_app, mocker):
    celery_app.conf.task_always_eager = True
    dispatch_action = container.resolve(DispatchBotTriggerOutboxAction)
    dispatch_action.run(data)
    # assert side effects / DB state instead of only task queue when eager mode runs inline
```

- HTTP boundaries: `httpx.MockTransport` / `respx` + `assert_called` — never hit a real exchange or the PyTaApi microservice.
- Model events: wrap factory creation that would fire signals in `without_events(lambda: BotFactory.create(...))`.

**Data setup:** prefer factories (`factory_boy` or project factories); explicit `session.add(Model(...))` is fine for reference rows. Rely on transaction rollback fixtures for isolation — do not hand-roll cleanup with hard-coded ids.

## Unit Test Example (ValueObject — the highest-value gap)

```python
import pytest

from src.contexts.orders.domain.exceptions.invalid_money_exception import InvalidMoneyException
from src.contexts.orders.domain.value_objects.money import Money


def test_rejects_negative_amounts() -> None:
    with pytest.raises(InvalidMoneyException):
        Money.from_float(-1.0, "USDT")


def test_adds_money_of_the_same_currency_into_a_new_instance() -> None:
    a = Money.from_float(1.5, "USDT")
    b = Money.from_float(2.5, "USDT")

    assert a.add(b).to_float() == 4.0
    assert a.to_float() == 1.5  # original untouched
```

## Refactoring Test Workflow

1. **Before restructuring:** write characterization tests for current behavior if none exist (this is the common case here — most classes are untested).
2. **During:** update tests alongside code — never leave broken tests.
3. **After:** `uv run pytest -q -k <affected>` must pass.

## What NOT to Test

- Framework internals (SQLAlchemy, FastAPI rendering) or inherited `BaseRepository` CRUD.
- Third-party SDK behavior — fake the HTTP boundary or mock the Adapter protocol.
- Private methods directly — test through the public `run()`. (Reflection into admin internals is a last resort for untestable static config — prefer extracting the logic to a testable Service.)
- Trivial getters.

## Test Checklist

- [ ] Correct suite: Integration = needs DB/container; Unit = pure Python
- [ ] No duplicated suite folder (`integration/integration`), no placeholder tests
- [ ] Action: happy path + skip/idempotency + failure path
- [ ] Multi-value returns are asserted as DTO/Result objects, not dicts/lists
- [ ] VO/Entity/Factory/Pipe covered when touched
- [ ] Protocol mocks in Unit; `httpx.MockTransport`/`respx` at HTTP boundaries
- [ ] Behavior-driven `test_*` names, one language per file
- [ ] `uv run pytest -q -k <ClassName>` passes
- [ ] No tests deleted without explicit approval
