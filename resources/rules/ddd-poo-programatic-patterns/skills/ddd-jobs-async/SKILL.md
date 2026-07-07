---
name: ddd-jobs-async
description: >-
  Async patterns for app/Contexts — thin queue Jobs, Horizon queues, the transactional
  Outbox pattern, Redis distributed locks (Cache::lock), afterCommit dispatch,
  idempotency (upsert, aggregate guards), DB transactions, event registration, and
  thin console commands. Use when implementing Jobs, queue dispatch, bulk operations,
  transactions, idempotency, or long-running background tasks.
  Complements always-applied rule ddd-poo-programatic-patterns.
---

# DDD Jobs and Async Processing

The `ddd-poo-programatic-patterns` master rule is always applied.

## Horizon Queues (real configuration)

| Queue | Supervisor | tries | Use for |
|---|---|---|---|
| `default` | supervisor-1 | 1 | General jobs (manual order entry/exit, bot dispatchers) |
| `sync_to_redis` | supervisor-1 | 1 | Fast Redis projection sync (`DcaSyncDataToRedisJob`, `LoadBotsToRedisJob`) |
| `market` | supervisor-2 | 3 | Market price events / broadcasts |
| `outbox_triggers` | supervisor-2 | 3 | Outbox consumers (`ProcessOutboxTriggerJob`) |

All supervisors run `timeout: 60` — keep job work under 60s or split into chunked jobs. Chatty recurring jobs implement Horizon's `Silenced` interface (`LoadBotsToRedisJob`).

## Job Anatomy (standard)

```php
final class ProcessOutboxTriggerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $outboxTriggerId,   // scalar IDs ONLY — queue-serialization safe
    ) {
        $this->onQueue('outbox_triggers');
    }

    public function handle(ProcessOutboxTriggerAction $action): void   // collaborators via method injection
    {
        $action->run($this->outboxTriggerId);
    }
}
```

- **`handle()` is thin** — inject and call one Action/Service. Business logic never lives in the Job.
- **Constructor takes scalar IDs**, never models or services. If a collaborator can't be method-injected, resolve with `app()` inside `handle()` and comment why (serialization).
- `$tries = 3` for retryable work on supervisor-2 queues; jobs on `default`/`sync_to_redis` get 1 attempt from the supervisor.
- `failed(Throwable $e)` is required for user-facing jobs — send a Filament DB notification to the requesting user (`RunManualOrderEntryJob` pattern) and log with `Class::method` prefix.
- Jobs are Application-layer citizens: place them in `Application/Jobs/`. (Legacy jobs under `Bots/Domain/Jobs/` and strategy subtrees predate this rule — don't add more there.)

## Dispatch After Commit

Any job dispatched after a DB write uses `->afterCommit()` so it never runs against uncommitted rows:

```php
$outboxTrigger = $this->storeOutboxTriggerAction->run($outboxTriggerData);
ProcessOutboxTriggerJob::dispatch($outboxTrigger->id)->afterCommit();
```

Same rule from Filament actions: `RunManualOrderEntryJob::dispatch(...)->afterCommit()`.

## Transactional Outbox Pattern (`Contexts/Outbox/`)

The reliable-dispatch backbone for bot triggers. Reuse it for any "decide now, execute async exactly-once" flow.

**Flow:**
```
Websocket price event
→ MarketPriceUpdatedListener (sync) → CheckPriceUpdatedForTriggerReachedUC
→ CycleEntry/Exit/OrderExitBotDispatcher     — acquires Cache::lock on the aggregate
→ DispatchBotTriggerOutboxAction             — idempotency guard + persist + enqueue
→ ProcessOutboxTriggerJob (queue outbox_triggers, tries 3, afterCommit)
→ ProcessOutboxTriggerAction                 — state machine + delegate + release lock
```

**Producer** (`DispatchBotTriggerOutboxAction`):
1. `findActiveByAggregate(triggerType, aggregateType, aggregateId)` — if a `Pending`/`Processing` row exists, log and return (de-dupe).
2. `StoreOutboxTriggerAction::run()` inserts the row `status = Pending` with a JSON payload snapshot (trigger DTO + `dispatch.signal_action/lock_key/lock_owner`).
3. `ProcessOutboxTriggerJob::dispatch($id)->afterCommit()`.

**Consumer** (`ProcessOutboxTriggerAction::run()`):
1. Reload row; if `status === Completed` → return (idempotent replay).
2. `markProcessing()` (sets `processing_at`, clears error).
3. `match` on `trigger_type` → delegate to the Bots context Actions (`RunBotEntryFromTriggerAction` / `RunBotExitFromTriggerAction`); unknown type throws.
4. Success → `markCompleted()`; failure → `markFailed($e->getMessage())` **and rethrow** so Horizon retries.
5. `finally`: release the distributed lock — `Cache::restoreLock($lockKey, $lockOwner)->release()` (key + owner travel in the payload).

New trigger types: add a `TriggerOutboxTypeEnum` case, a named constructor on `OutboxTriggerData` (`forXxxDispatch()`), and a `match` arm in the consumer.

## Uniqueness: Redis Locks, not ShouldBeUnique

This codebase does **not** use `ShouldBeUnique`/`WithoutOverlapping`. Concurrency control is explicit:

- **Distributed lock at the dispatcher**: `Cache::lock(BotLockKey::makeRunningKey("cycle_id:{$id}")->getKey(), config('trading.bot_running_lock_seconds'))` — acquire before deciding; pass `lock_key` + `lock_owner` through the payload; release in the consumer's/job's `finally` with `Cache::restoreLock(...)->release()`.
- **Aggregate guard in the outbox**: `findActiveByAggregate` prevents a second in-flight trigger for the same cycle/order.

Follow this pattern for new async flows; introduce `ShouldBeUnique` only if a flow genuinely can't carry a lock through its payload.

## Idempotency

- **Status short-circuit**: consumer returns early when the work is already `Completed`.
- **`upsert` with explicit unique keys** for sync flows: `$repository->upsert($rows, ['exchange_id', 'coinpair'], ['base', 'quote', 'price_precision'])` (`BaseRepository::upsert`).
- **`updateOrCreate`** for single-row sync (`BaseRepository::updateOrCreate`, `CandleService`, `AssociateAssetToBotUC`).
- Expected "already done" outcomes log at `info`/`WARNING` and return — they are not exceptions.

## Database Transactions

- Multi-table writes → `DB::transaction(fn () => ...)` (closure form) **inside the Action**, wrapping only the writes — never external API calls:

```php
// Orders/Application/Actions/SimulateOrdersAction.php
$createdOrders = DB::transaction(function () use ($data, $cycle, $asset): Collection {
    return $data->usesRandomValues()
        ? $this->createRandomOrders($data, $cycle, $asset)
        : $this->createManualOrders($data, $cycle, $asset);
});
```

- Do not use manual `DB::beginTransaction()/commit()/rollBack()` in new code (legacy `CreateStrategyUC` style).
- Single-row inserts don't need a transaction — the outbox producer relies on the atomic insert + `afterCommit` + lock instead of wrapping everything.

## Events and Listeners

- Registration is **explicit** in `TradingServiceProvider::boot()`: `Event::listen(MarketPriceUpdatedEvent::class, MarketPriceUpdatedListener::class)`. No auto-discovery — an unregistered listener never runs.
- A listener may be deliberately synchronous when ordering matters (`MarketPriceUpdatedListener` must process the price before the next tick; it delegates to application logic that dispatches async work further down). Otherwise queue the listener.
- Broadcast events set `public $queue = 'market'` and implement `ShouldBroadcastNow` when the UI needs realtime pushes.

## Bulk Processing

Never load unbounded collections: `Model::query()->where(...)->chunkById(100, fn (Collection $rows) => ...)` inside the Action, or dispatch one job per chunk for very large datasets. Keep each job under the 60s Horizon timeout.

## Console Commands and Scheduling

- Console commands are thin: parse/prompt input, resolve enums, delegate to an Action/Service (legacy commands like `SyncCacheMarket` still delegate to `UC` classes — migrate those to Actions on touch).
- Scheduling lives in `routes/console.php` (`Schedule::command(...)`) — there is no Console Kernel. Long-running market ingestion is driven by websocket services/commands, not cron.

## Async Checklist

- [ ] Job `handle()` delegates to an Action/Service — no business logic
- [ ] Constructor holds scalar IDs only; queue set in constructor (`onQueue`)
- [ ] Correct queue chosen from the Horizon table; work fits the 60s timeout
- [ ] `->afterCommit()` when dispatched after DB writes
- [ ] Uniqueness via `Cache::lock` + outbox aggregate guard; lock released in `finally`
- [ ] Consumer is idempotent (status short-circuit / upsert)
- [ ] `failed()` implemented for user-facing jobs (Filament notification + log)
- [ ] `DB::transaction` closure form around multi-table writes only
- [ ] Listener registered in `TradingServiceProvider::boot()`
