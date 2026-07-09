---
name: ddd-testing
description: >-
  Testing standards for app/Contexts — Pest 4, suite layout, Mockery + Laravel fakes,
  factories, coverage priorities for Actions, ValueObjects, Repositories,
  Jobs, and Filament. Use when writing, updating, or reviewing tests, or when
  refactoring with test coverage requirements.
  Complements always-applied rule ddd-poo-programatic-patterns.
---

# DDD Testing Standards

The `ddd-poo-programatic-patterns` master rule is always applied.

## Suite Configuration (as wired)

- `tests/Pest.php` binds `TestCase` + `RefreshDatabase` to the **Feature** suite only. Do not re-declare `uses(RefreshDatabase::class)` inside Feature files — it's already global.
- **Unit tests run on plain PHPUnit** — no container, no DB, no facades. If a unit test needs the Laravel app (rare — prefer moving it to Feature), opt in with `uses(TestCase::class)` in that file.
- Run: `php artisan test --compact --filter=<ClassName>` or `php artisan test --compact tests/Feature/<Context>/`.

### Test environment isolation (mandatory — never hit the real DB)

Feature tests use `RefreshDatabase`, which **drops and re-runs all migrations**. If tests load your local `.env` (MySQL, SQL Server, etc.), **you will wipe production-like data**. This app uses three layers of protection — keep all of them in place:

#### 1. `.env.testing` (required, committed)

Every Laravel app in this repo **must** have a root `.env.testing` file. When `APP_ENV=testing`, Laravel loads `.env.testing` **instead of** `.env`.

Minimum DB settings (never point at a real server):

```dotenv
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
DB_FOREIGN_KEYS=true
DB_URL=
```

Also use in-memory / array drivers for side effects: `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `SESSION_DRIVER=array`. Copy other non-secret defaults from `.env.example` as needed — **never** copy real DB hosts, `DB_URL`, or API credentials from `.env`.

If `.env.testing` is missing, PHPUnit falls back to `.env` and Feature tests can destroy your local database.

#### 2. `phpunit.xml` (required overrides)

PHPUnit must force the testing environment **before** the app boots. Keep these `<env>` entries (do not remove or weaken them):

| Variable | Value | Why |
|---|---|---|
| `APP_ENV` | `testing` | Triggers `.env.testing` load |
| `DB_CONNECTION` | `sqlite` | No MySQL/SQL Server |
| `DB_DATABASE` | `:memory:` | Ephemeral DB per process |
| `DB_URL` | `` (empty) | Clears any DSN from env files |
| `CACHE_STORE` | `array` | No Redis/filesystem cache bleed |
| `QUEUE_CONNECTION` | `sync` | No real queue workers |
| `MAIL_MAILER` | `array` | No outbound mail |
| `SESSION_DRIVER` | `array` | No session persistence |

`bootstrap="tests/bootstrap.php"` must stay set so cached config cannot override the above.

#### 3. `tests/bootstrap.php` + `tests/TestCase.php` (defense in depth)

- **`tests/bootstrap.php`** — deletes `bootstrap/cache/config.php` and `bootstrap/cache/routes-v7.php` before autoload. Stale config cache is a common cause of tests silently using the real `database.default`.
- **`tests/TestCase.php`** — `enforceTestingDatabaseConfiguration()` runs on app boot and again in `beforeRefreshingDatabase()`. It **throws** unless `database.default === sqlite` and `database.connections.sqlite.database === :memory:`. Never remove or bypass this guard.

`RefreshDatabase` runs migrations against that in-memory sqlite only — seed data created by migrations (e.g. the Kucoin exchange row) is available; anything else needs factories or explicit creation.

**Troubleshooting:** if tests refuse to run with "Refusing to run database migrations…", run `php artisan config:clear` and confirm `.env.testing` + `phpunit.xml` still force sqlite `:memory:`.

## Layout

Mirror `app/Contexts` one level deep: `tests/Unit/<Context>/...` and `tests/Feature/<Context>/...`.

⚠️ Never nest the suite name twice — `tests/Feature/Feature/...` and `tests/Unit/Unit/...` exist as legacy scaffolding mistakes; place new tests at the correct depth and never add `expect(true)->toBeTrue()` placeholder files.

## Mandatory Coverage

Every new or refactored class in Application/Domain layers gets tests before the task is done:

| Class type | Suite | What to assert |
|---|---|---|
| **Action (atomic)** | Unit | Delegates to repository/domain service with the right arguments, typed return object |
| **Action (orchestration)** | Feature (uses DB/container) | Happy path, idempotency guard (skip path), failure marks state + rethrows, job dispatched (`Queue::fake`) |
| **ValueObject** | Unit | Invariants throw on invalid input, factories, arithmetic returns new instances, `toArray()` |
| **Entity** | Unit | `fromModel()`/`from()` mapping, business methods (`exitCycle`, `calculateProfit`) |
| **Factory** | Unit | Correct variant per enum case, `default` throws `HandleException` |
| **Strategy / Trader** | Feature | Behavior per variant with mocked collaborators |
| **Pipe** | Unit | Passes valid aggregate to `$next`, throws `HandleException` on guard failure |
| **DTO / Result DTO** | Unit or Feature | Constructor typing, `from()`/named constructors, casts, validation rules when using Spatie Data, no array-shape return contract |
| **Repository** | Feature | Custom query methods return expected rows (not inherited BaseRepository CRUD) |
| **Job** | Feature | Delegates to Action; `failed()` notifies |
| **Filament page/action** | Feature | Delegation + authorization, via `livewire()` helper |

Legacy `UC` classes are tested exactly like atomic Actions — write the test when migrating one to `Application/Actions/`.

Current gaps to close when touching these areas: ValueObjects, Actions (and legacy UCs), Repositories, and Filament interactions have **no** existing coverage — add tests when you modify them.

## Conventions (from the existing suite)

**Naming:** `it('...')` describing behavior, not methods. One language per file (existing tests are mixed English/Spanish — English preferred for new files).

```php
it('skips dispatch when an active outbox already exists for the aggregate');
it('throws InvalidMoneyException when amount is negative');
```

**Mocking — Mockery + `afterEach(fn () => Mockery::close())`:**

```php
$repository = Mockery::mock(CycleRepositoryInterface::class);
$repository->shouldReceive('get')->once()->with(5)->andReturn($cycle);
$repository->shouldReceive('saveFromArray')
    ->withArgs(fn (Model $m, array $data) => $data['status'] === CycleStatusEnum::CLOSED->value)
    ->andReturn($cycle);
```

- `Mockery::on()` / `Mockery::type()` / `withArgs()` for argument matching.
- `Mockery::mock('overload:'.MarketRepository::class)` / `'alias:'.ExchangeAdapterFactory::class` **only** for legacy static factories that can't be injected — don't design new code to need this.
- Hand-rolled doubles (anonymous classes extending `BaseBot`/`BaseSymbolsData`) are acceptable for abstract aggregates that Mockery handles poorly.
- When testing multi-value outcomes, assert the concrete DTO/Result class and typed properties instead of asserting associative array keys. Array assertions are reserved for explicit serialization boundaries such as `toArray()` or HTTP JSON payloads.

**Laravel fakes alongside Mockery:**

```php
Queue::fake();
app(DispatchBotTriggerOutboxAction::class)->run($data);
Queue::assertPushed(ProcessOutboxTriggerJob::class, fn ($job) => $job->outboxTriggerId === $trigger->id);
```

- HTTP boundaries: `Http::fake([...])` + `Http::preventStrayRequests()` + `Http::assertSent()` (see `RsiIndicatorTest`) — never hit a real exchange or the PyTaApi microservice.
- Model events: wrap factory creation that would fire observers/events in `Bot::withoutEvents(fn () => Bot::factory()->create(...))`.

**Data setup:** prefer model factories; explicit `Model::query()->create([...])` is fine for reference rows. Rely on `RefreshDatabase` for isolation — do not hand-roll `try/finally` cleanup with hard-coded ids.

## Unit Test Example (ValueObject — the highest-value gap)

```php
it('rejects negative amounts', function () {
    Money::fromFloat(-1.0, 'USDT');
})->throws(InvalidMoneyException::class);

it('adds money of the same currency into a new instance', function () {
    $a = Money::fromFloat(1.5, 'USDT');
    $b = Money::fromFloat(2.5, 'USDT');

    expect($a->add($b)->toFloat())->toBe(4.0)
        ->and($a->toFloat())->toBe(1.5); // original untouched
});
```

## Refactoring Test Workflow

1. **Before restructuring:** write characterization tests for current behavior if none exist (this is the common case here — most classes are untested).
2. **During:** update tests alongside code — never leave broken tests.
3. **After:** `php artisan test --compact --filter=<affected>` must pass.

## What NOT to Test

- Framework internals (Eloquent, Filament rendering) or inherited `BaseRepository` CRUD.
- Third-party SDK behavior — fake the HTTP boundary or mock the Adapter interface.
- Private methods directly — test through the public `run()`. (Reflection into Filament internals like `formatConfigState` is a last resort for untestable static config — prefer extracting the logic to a testable Service.)
- Trivial getters.

## Test Checklist

- [ ] `.env.testing` exists with `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` (never uses `.env` for DB)
- [ ] `phpunit.xml` keeps sqlite `:memory:` overrides and `bootstrap="tests/bootstrap.php"`
- [ ] `tests/TestCase.php` guard (`enforceTestingDatabaseConfiguration`) intact
- [ ] Correct suite: Feature = needs DB/container; Unit = pure PHP
- [ ] No duplicated suite folder (`Feature/Feature`), no placeholder tests
- [ ] Action: happy path + skip/idempotency + failure path
- [ ] Multi-value returns are asserted as DTO/Result objects, not associative arrays
- [ ] VO/Entity/Factory/Pipe covered when touched
- [ ] Interface mocks in Unit; `Queue::fake`/`Http::fake` + `preventStrayRequests` at boundaries
- [ ] `Mockery::close()` in `afterEach` where Mockery is used
- [ ] Behavior-driven `it()` names, one language per file
- [ ] `php artisan test --compact --filter=<ClassName>` passes
- [ ] No tests deleted without explicit approval
