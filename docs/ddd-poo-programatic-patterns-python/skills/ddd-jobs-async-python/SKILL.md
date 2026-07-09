---
name: ddd-jobs-async-python
description: >-
  Async patterns for src/contexts — thin Celery tasks, queue routing, the transactional
  Outbox pattern, Redis distributed locks, after_commit dispatch,
  idempotency (upsert, aggregate guards), DB transactions, event registration, and
  thin CLI commands. Use when implementing Celery tasks, queue dispatch, bulk operations,
  transactions, idempotency, or long-running background tasks.
  Complements always-applied rule ddd-poo-programatic-patterns-python.
---

# DDD Jobs and Async Processing (Python)

The `ddd-poo-programatic-patterns-python` master rule is always applied.

## Celery Queues (real configuration)

| Queue | Worker | max_retries | Use for |
|---|---|---|---|
| `default` | worker-1 | 1 | General tasks (manual order entry/exit, bot dispatchers) |
| `sync_to_redis` | worker-1 | 1 | Fast Redis projection sync (`DcaSyncDataToRedisTask`, `LoadBotsToRedisTask`) |
| `market` | worker-2 | 3 | Market price events / broadcasts |
| `outbox_triggers` | worker-2 | 3 | Outbox consumers (`ProcessOutboxTriggerTask`) |

All workers use `task_time_limit=60` — keep task work under 60s or split into chunked tasks. Chatty recurring tasks use `task_ignore_result=True` and low log verbosity (`LoadBotsToRedisTask`).

## Task Anatomy (standard)

```python
from __future__ import annotations

from celery import shared_task

from src.contexts.outbox.application.actions.process_outbox_trigger_action import (
    ProcessOutboxTriggerAction,
)


@shared_task(
    bind=True,
    queue="outbox_triggers",
    max_retries=3,
    autoretry_for=(Exception,),
)
def process_outbox_trigger_task(self, outbox_trigger_id: int) -> None:
    action = ProcessOutboxTriggerAction.from_container()
    action.run(outbox_trigger_id)
```

- **`run()` / task body is thin** — resolve and call one Action/Service. Business logic never lives in the Celery task.
- **Task arguments are scalar IDs**, never ORM models or services. If a collaborator can't be injected, resolve from `AppContainer` inside the task and comment why (serialization).
- `max_retries=3` for retryable work on worker-2 queues; tasks on `default`/`sync_to_redis` get 1 attempt.
- `on_failure` / custom `failed()` hook is required for user-facing tasks — send a notification to the requesting user (`RunManualOrderEntryTask` pattern) and log with `Class.method` prefix.
- Tasks are Application-layer citizens: place them in `application/tasks/`. (Legacy tasks under `bots/domain/tasks/` predate this rule — don't add more there.)

## Dispatch After Commit

Any task dispatched after a DB write uses `transaction.on_commit()` so it never runs against uncommitted rows:

```python
outbox_trigger = self.store_outbox_trigger_action.run(outbox_trigger_data)

def _dispatch() -> None:
    process_outbox_trigger_task.delay(outbox_trigger.id)

transaction.on_commit(_dispatch)
```

Same rule from API routes: enqueue only after the session commits.

## Transactional Outbox Pattern (`contexts/outbox/`)

The reliable-dispatch backbone for bot triggers. Reuse it for any "decide now, execute async exactly-once" flow.

**Flow:**
```
Websocket price event
→ MarketPriceUpdatedListener (sync) → CheckPriceUpdatedForTriggerReachedUseCase
→ CycleEntry/Exit/OrderExitBotDispatcher     — acquires Redis lock on the aggregate
→ DispatchBotTriggerOutboxAction             — idempotency guard + persist + enqueue
→ ProcessOutboxTriggerTask (queue outbox_triggers, retries 3, after_commit)
→ ProcessOutboxTriggerAction                 — state machine + delegate + release lock
```

**Producer** (`DispatchBotTriggerOutboxAction`):
1. `find_active_by_aggregate(trigger_type, aggregate_type, aggregate_id)` — if a `Pending`/`Processing` row exists, log and return (de-dupe).
2. `StoreOutboxTriggerAction.run()` inserts the row `status = Pending` with a JSON payload snapshot (trigger DTO + `dispatch.signal_action/lock_key/lock_owner`).
3. `process_outbox_trigger_task.delay(id)` scheduled via `transaction.on_commit()`.

**Consumer** (`ProcessOutboxTriggerAction.run()`):
1. Reload row; if `status == Completed` → return (idempotent replay).
2. `mark_processing()` (sets `processing_at`, clears error).
3. `match` on `trigger_type` → delegate to the Bots context Actions (`RunBotEntryFromTriggerAction` / `RunBotExitFromTriggerAction`); unknown type raises.
4. Success → `mark_completed()`; failure → `mark_failed(str(e))` **and re-raise** so Celery retries.
5. `finally`: release the distributed lock — `lock.release()` (key + owner travel in the payload).

New trigger types: add a `TriggerOutboxTypeEnum` case, a named constructor on `OutboxTriggerData` (`for_xxx_dispatch()`), and a `match` arm in the consumer.

## Uniqueness: Redis Locks, not task deduplication decorators

This codebase does **not** rely on Celery `task_once` or implicit dedup. Concurrency control is explicit:

- **Distributed lock at the dispatcher**: `redis.lock(BotLockKey.make_running_key(f"cycle_id:{id}").get_key(), timeout=settings.trading.bot_running_lock_seconds)` — acquire before deciding; pass `lock_key` + `lock_owner` through the payload; release in the consumer's `finally`.
- **Aggregate guard in the outbox**: `find_active_by_aggregate` prevents a second in-flight trigger for the same cycle/order.

Follow this pattern for new async flows; introduce framework dedup only if a flow genuinely can't carry a lock through its payload.

## Idempotency

- **Status short-circuit**: consumer returns early when the work is already `Completed`.
- **`upsert` with explicit unique keys** for sync flows: `repository.upsert(rows, unique_by=["exchange_id", "coinpair"], update_columns=["base", "quote", "price_precision"])`.
- **`update_or_create`** for single-row sync (`BaseRepository.update_or_create`, `CandleService`).
- Expected "already done" outcomes log at `info`/`warning` and return — they are not exceptions.

## Database Transactions

- Multi-table writes → `with session.begin():` or `session.begin_nested()` **inside the Action**, wrapping only the writes — never external API calls:

```python
# orders/application/actions/simulate_orders_action.py
with self.session.begin():
    created_orders = (
        self._create_random_orders(data, cycle, asset)
        if data.uses_random_values()
        else self._create_manual_orders(data, cycle, asset)
    )
```

- Do not use manual `session.commit()`/`rollback()` scattered across layers in new code (legacy style).
- Single-row inserts don't need a transaction — the outbox producer relies on the atomic insert + `on_commit` + lock instead of wrapping everything.

## Events and Listeners

- Registration is **explicit** in `AppContainer.on_startup()` or an event registry: `register(MarketPriceUpdatedEvent, MarketPriceUpdatedListener)`. No auto-discovery — an unregistered listener never runs.
- A listener may be deliberately synchronous when ordering matters (`MarketPriceUpdatedListener` must process the price before the next tick; it delegates to application logic that dispatches async work further down). Otherwise queue the listener.
- Broadcast events route to the `market` queue when the UI needs realtime pushes (WebSocket/SSE).

## Bulk Processing

Never load unbounded collections: `session.scalars(select(Model).where(...)).yield_per(100)` inside the Action, or dispatch one task per chunk for very large datasets. Keep each task under the 60s Celery time limit.

## CLI Commands and Scheduling

- CLI commands are thin: parse/prompt input, resolve enums, delegate to an Action/Service (legacy commands still delegate to `UseCase` classes — migrate those to Actions on touch).
- Scheduling lives in Celery Beat (`celery_app.conf.beat_schedule`) or APScheduler — long-running market ingestion is driven by websocket services/commands, not cron when latency matters.

## Async Checklist

- [ ] Task body delegates to an Action/Service — no business logic
- [ ] Arguments hold scalar IDs only; queue set in task decorator
- [ ] Correct queue chosen from the Celery table; work fits the 60s timeout
- [ ] `transaction.on_commit()` when dispatched after DB writes
- [ ] Uniqueness via Redis lock + outbox aggregate guard; lock released in `finally`
- [ ] Consumer is idempotent (status short-circuit / upsert)
- [ ] `on_failure` implemented for user-facing tasks (notification + log)
- [ ] `session.begin()` around multi-table writes only
- [ ] Listener registered in `AppContainer.on_startup()`
