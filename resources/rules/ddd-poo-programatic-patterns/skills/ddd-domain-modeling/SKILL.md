---
name: ddd-domain-modeling
description: >-
  Domain layer building blocks for app/Contexts — pure Entities (fromModel),
  ValueObjects with private constructors and invariant validation, backed Enums,
  Spatie Data Casters, static match-based Factories, the Laravel Pipeline/Pipes
  pattern, domain Services, and the BaseException hierarchy. Use when creating or
  refactoring Entities, VOs, Enums, Casters, Factories, Pipes, Strategies, or
  domain exceptions. Complements always-applied rule ddd-poo-programatic-patterns.
---

# DDD Domain Modeling

The `ddd-poo-programatic-patterns` master rule is always applied.

## Entities — pure PHP, hydrated from Eloquent

Entities in `Domain/Entities/` are **not Eloquent models** (those live in `app/Models/`). They hydrate from models and carry business behavior. Two styles coexist; **use the Spatie Data style for new entities**:

```php
// ✅ New entities: Spatie Data based (like CandleEntity, StrategyEntity, BotLogEntity)
final class SignalEntity extends Data implements BaseEntityInterface
{
    public function __construct(
        public ?int $id,
        #[WithCast(SignalActionEnumCast::class)]
        public ?SignalActionEnum $action,
        public ?string $coinpair,
    ) {}

    public static function fromModel(Signal $signal): self
    {
        return self::from($signal);
    }
}
```

The older hand-rolled style (`OrderEntity`, `CycleEntity`: `implements BaseEntityInterface` + `use ArrayableTrait`, private `$id` so it is excluded from `toArray()`, enum coercion in the constructor) is valid legacy — keep its conventions when editing those files, but don't start new entities that way.

**Entity rules:**
- Named constructors express the source: `fromModel()`, `fromOrderData()`, `fromBaseSpotOrderData()`.
- Business behavior lives on the entity: `OrderEntity::calculateProfit()`, `CycleEntity::exitCycle()`, `deactivate()`, `merge()`.
- **No Eloquent queries inside entities** (`Order::where(...)` inside an entity is a defect — move to the Repository) and **no `app(Logger::class)` in constructors** — pass collaborators in or log from the calling Action.
- A strategy/aggregate is not an entity: classes extending `BaseBot` belong under `Domain/Strategies/`, not `Domain/Entities/`.

## Value Objects — immutable, validated at construction

Two real styles; pick by intent:

**Guarded scalar** (`Money`, `OrderPrice`, `OrderAmount`, `CoinpairVO`, `PriceVO`) — private constructor + static factories + invariants that throw domain exceptions:

```php
final class Money
{
    private function __construct(
        private readonly float $amount,
        private readonly ?string $currency = null,
        private readonly int $decimals = 8,
    ) {
        if ($amount < 0) {
            throw new InvalidMoneyException('Amount cannot be negative');
        }
    }

    public static function fromFloat(?float $amount, ?string $currency = null): ?self { /* ... */ }

    public function add(self $other): self  // operations return NEW instances
    {
        $this->assertSameCurrency($other);
        return new self($this->amount + $other->amount, $this->currency, $this->decimals);
    }
}
```

- Multiple named factories per intent: `OrderAmount::fromQuote()/fromBase()/fromPercent()/fromAmountType()`; `CoinpairVO::fromString()` normalizes + regex-validates.
- Helpers by domain meaning: `PriceVO::isInfinite()/toRedisScore()`, `OrderPrice::instantBuyLimit()`.
- Prefer `final readonly class` (like `PriceVO`) for new VOs.

**Column-mirror detail VOs** (`CycleSellDetailsVO`, `PnLVO`, `TotalDetailsVO`) — `implements BaseValueObjectInterface` + `ArrayableTrait`, `public readonly` promoted props, a `public static array $fields` whitelist, `fromModel()`/`fromData()` factories, immutable arithmetic (`PnLVO::addPnlVO()` returns a new instance).

**VO rules:** never mutate, never query the DB, never resolve services. Every invariant is checked in the constructor and throws a context exception.

## Enums

Backed enums (`: int` or `: string`), suffix `Enum`, in `Domain/Enums/` (shared ones in `Shared/Domain/Enums/`). Standard helper set — add only what the enum actually needs:

- `label(): string` via `match($this)` for UI display; `labels()` / `toArray()` for selects.
- `forSelect()` / `forRuleIn()` driven by `config('trading.*')` when options are environment-dependent (`ExchangeAvailableEnum`).
- Tolerant parsing: `fromNameOrValue()` when input may be a name or a backing value (`SignalActionEnum`).
- Behavior helpers where they belong to the concept: `BotStatusEnum::toggle()`, `equals()`.

## Casters (Spatie Data)

`Domain/Casters/` holds `Spatie\LaravelData\Casts\Cast` implementations used with `#[WithCast(...)]` on Data/Entity properties. Three established uses:

1. **id → Eloquent model** (`BotCast`, `CycleCast`, `StrategyCast`): `Bot::where('id', $value)->first() ?? throw new InvalidArgumentException(...)`.
2. **value → VO/enum** (`PriceVOCast`, `SignalActionEnumCast`).
3. **string normalization** (`CoinpairCast` delegating to `AssetService::castStringToCoinpair`).

Do not confuse with Eloquent `CastsAttributes` (model attribute casts). Livewire caveat: an Eloquent cast returning a VO breaks Livewire/Filament modal dehydration — `OrderPnlRealizedVOCast` deliberately returns a `float` for that reason.

## Factories — static `make()` + `match` on enum

```php
// Bots/Domain/Factories/BotFactory.php
return match ($strategyType) {
    BotTypeEnum::DCA    => new DcaStrategy($user, $account, $exchangeAdapter, $bot, ...),
    BotTypeEnum::SIGNAL => new SignalBotEntity($user, $account, $exchangeAdapter, $bot, ...),
    default => throw new HandleException("Invalid strategy type: {$strategyType->name}"),
};
```

- Factories create Strategies, Adapters, Checkers, Indicators — variant selection, not entity persistence.
- `default` arm **always throws** `HandleException` — never return null silently.
- **Return instances typed by interface** (`BotInterface`, `BaseIndicator`) — not class-name strings (the checker factories returning `::class` strings are legacy; don't copy that).
- Two-level selection is fine when the variant depends on runtime state (`SignalEntryCheckerFactorySelector` picks a factory by active-order count, the factory picks the checker by enum).
- Name the method `make()` for new factories (legacy uses `create`/`getExchange`/`getChecker`).

## Pipeline Pattern (Pipes)

Sequential filters/validations before an operation use `Illuminate\Pipeline\Pipeline`. The passable is the aggregate; each pipe either calls `$next($passable)` or **short-circuits by throwing `HandleException`** with an appropriate `logLevel`.

```php
// Orchestration — Bots/Domain/Strategies/Spot/Dca/DcaStrategy.php
app(Pipeline::class)
    ->send($this)                        // the BaseBot aggregate
    ->through([
        BotEnabledFilterPipe::class,
        StrategyEnabledFilterPipe::class,
        CycleEnabledFilterPipe::class,
        BuyEnabledFilterPipe::class,
        CandleBodySizeFilter::class,     // strategy-specific risk filters
        PriceLimitFilter::class,
        BudgetDayFilter::class,
        CountOrdersDayFilter::class,
    ])
    ->then(fn ($bot) => (new SignalEntryCheckerAnalyzer($bot))->run($this->getCycle()));
```

```php
// A filter pipe — Bots/Domain/Pipes/Filters/BotEnabledFilterPipe.php
final class BotEnabledFilterPipe
{
    public function handle(BaseBot $bot, Closure $next)
    {
        if (! $bot->bot->enabled && $signalAction != SignalActionEnum::SELL_ALL) {
            throw new HandleException(
                'BotEnabledFilterPipe: Bot is disabled',
                logLevel: LogLevelEnum::WARNING,   // expected outcome — not an error
            );
        }

        return $next($bot);
    }
}
```

- Place pipes in `Domain/Pipes/` (subfolder per family: `Filters/`, `Validation/`).
- One condition per pipe; the pipe name states the rule (`BudgetDayFilter`).
- Pipes may enrich the passable (`$entity->setOrder($order)`) before passing it on.
- Use a Pipeline when ≥3 sequential guards exist or variants share guard subsets; for 1-2 checks, plain early returns in the Action are simpler.

## Domain Services

`Domain/Services/` orchestrate Entities + VOs + Repositories for logic that doesn't belong to one entity (`CycleDomainService::exitCycle/refreshCycle`). Constructor-injected `readonly` dependencies (repository + `Logger`). Name them by concept — prefer `XxxDomainService` when a `XxxService` facade already exists in Application to avoid collisions.

## Exceptions

- Base: `Shared/Domain/Exceptions/BaseException` — constructor flags `body`, `footer`, `sendTelegram`, `saveException`, `saveStack`, `logLevel`; `report()` logs via `Logger` and optionally notifies Telegram; `render()` returns JSON for `api/*`.
- `HandleException extends BaseException` is the default throwable for guard failures and factory `default` arms.
- Context-specific exceptions extend `BaseException` (or `HandleException`): `NoActiveOrderException extends HandleException`. **Never extend raw `\Exception`** and never re-implement Telegram reporting in the exception (that logic exists once in `BaseException` — `InvalidMoneyException`, `InsufficientBalanceException` are legacy offenders; migrate on touch).
- Expected outcomes ("already processed", "no active order") → `logLevel: LogLevelEnum::WARNING` or an early return with a log — not an ERROR-level exception.

## Domain Checklist

- [ ] Entity is pure PHP with `fromModel()`; no Eloquent queries or `app()` inside
- [ ] VO is `final` (`readonly` preferred), private ctor + static factories, invariants throw
- [ ] Enum is backed, `Enum` suffix, helpers only as needed
- [ ] Caster placed in `Domain/Casters/`, implements Spatie `Cast`
- [ ] Factory: static `make()`, `match` on enum, `default` throws `HandleException`, returns interface type
- [ ] Pipes short-circuit by throwing; pipeline orchestrated in the Strategy/Action
- [ ] Exceptions extend `BaseException`/`HandleException`
- [ ] Strategies/aggregates under `Domain/Strategies/`, not `Entities/`
