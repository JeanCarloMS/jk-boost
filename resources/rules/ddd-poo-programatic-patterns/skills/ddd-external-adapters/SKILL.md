---
name: ddd-external-adapters
description: >-
  Anti-corruption layer patterns for app/Contexts — exchange platform adapters
  (Kucoin/Bingx/Binance), the ExchangeAdapterInterface port, adapter factories,
  request/response Data DTOs, response validators, HTTP client conventions
  (timeouts, retry, layered error handling), websockets, and Telegram notifications.
  Use when integrating an external API, adding an exchange platform or endpoint,
  building an HTTP client, or sending user notifications.
  Complements always-applied rule ddd-poo-programatic-patterns.
---

# DDD External Adapters (Anti-Corruption Layer)

The `ddd-poo-programatic-patterns` master rule is always applied.

## Port and Adapter Structure (Exchanges context)

```text
Exchanges/
├── Domain/
│   ├── Interfaces/ExchangeAdapterInterface.php   ← the port (getAccount, postOrder, getKlines, …)
│   ├── Factories/ExchangeAdapterFactory.php      ← selects adapter by [exchange, marketType]
│   └── Traits/hasExchangeResponseValidator.php   ← shared response validation
└── Infrastructure/
    ├── Data/                                     ← Base request/response Spatie Data (BaseReqPostData, BaseSpotOrderData…)
    ├── Platforms/{Kucoin,Bingx,Binance}/
    │   ├── Adapters/       ← KucoinSpotAdapter implements ExchangeAdapterInterface
    │   ├── RestApi/        ← raw signed HTTP client + platform Response object
    │   ├── Data/           ← platform-specific Data subclasses + Normalizers
    │   └── Websockets/
    ├── Websocket/          ← BaseExchangeWebSocket + ExchangeWebSocketFactory
    └── Health/             ← heartbeat checks (Spatie Health)
```

**Rules:**
- The rest of the app depends **only** on `ExchangeAdapterInterface` and the Base Data types — never on a platform SDK, raw response arrays, or platform Data classes.
- New platform = new `Platforms/{Name}/` tree **including `Adapters/`** + a new arm in `ExchangeAdapterFactory::getExchange()` (and `ExchangeWebSocketFactory` if it streams). A platform without an adapter is unusable (Bingx currently has RestApi/Data but no adapter — complete it before wiring).
- Adapters return **typed Data DTOs built by a `Exchange*DataFactory`**, never raw arrays. If the response needs casts, validation, nested data, or serialization, extend `Spatie\LaravelData\Data`; otherwise a lightweight `final readonly` PHP DTO is enough.

## Adapter Method Skeleton (the repeated convention)

Every adapter endpoint follows: build request Data → call raw client → validate response → map to a typed Data/Result DTO.

```php
public function postOrder(BaseReqPostData $request): BaseSpotOrderData
{
    $responseExchange = $this->order()->post($request->toArray());

    if (! $this->KucoinResponse->setRequest($request)->setResponse($responseExchange)->isSuccess()) {
        $this->KucoinResponse->handleError([
            'function' => __FUNCTION__, 'class' => __CLASS__, 'line' => __LINE__,
        ]); // throws the platform exception with request + response context
    }

    return ExchangeSpotOrderDataFactory::make($this, $this->KucoinResponse->getData());
}
```

- The platform Response object (`KucoinResponse implements ExchangeResponseInterface`) owns success detection (`code == 200000`), error throwing, and platform quirks (e.g. time-desync code `400002` clears the Redis clock-offset so the next request re-syncs).
- `hasExchangeResponseValidator::validateResponseExchange()` is the shared guard — throw `HandleException` on non-success, invoke `callbackDesyncTime()` on desync.
- **No `ds()`/`dump()` debug calls in adapters** — remove on touch.

## Raw HTTP Client Conventions

Signed platform clients (Guzzle, `Platforms/Kucoin/RestApi/Request.php`): HMAC signing, `['http_errors' => false]`, explicit `timeout` (60s for exchange calls), time-sync offset cached in Redis, wrap transport exceptions into the platform exception.

Simple JSON microservice clients follow `TechnicalsV2/Infrastructure/Clients/PyTaApiClient` — the legacy reference implementation returns a decoded array at the raw HTTP edge, but new clients should wrap successful payloads in a response DTO before leaving the client/adapter boundary:

```php
final class PyTaApiClient implements PyTaApiClientInterface
{
    public function sendRequest(string $endpoint, PyTaApiRequestData $data): PyTaApiResponseData
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(2)
                ->timeout(5)
                ->retry([200, 400], throw: false)   // backoff ms; don't throw mid-retry
                ->post($endpoint, $data->toArray());
        } catch (ConnectionException $e) {
            Log::error('PyTaApiClient::sendRequest could not connect', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
            throw new RuntimeException('PyTaApi could not connect', previous: $e);
        }

        if ($response->failed()) { /* Log::warning + throw RuntimeException('invalid response') */ }
        if (! is_array($payload = $response->json())) { /* Log::warning + throw RuntimeException('invalid payload') */ }

        return PyTaApiResponseData::fromPayload($payload);
    }
}
```

**Client rules:**
- Interface in the context (`PyTaApiClientInterface`) + implementation in `Infrastructure/Clients/`; **bind the pair in `TradingServiceProvider::register()`** (verify the binding exists — a commented-out binding means interface injection fatals).
- Timeouts are mandatory: short `connectTimeout` (2s), bounded `timeout`, retry with backoff array and `throw: false`.
- Layered error handling: transport error / HTTP failure / malformed payload each get their own log line (`Class::method` prefix) and a wrapped exception with `previous:`.
- Public client/adapter methods return typed request/response DTOs or Result DTOs, not array shapes. Keep raw arrays local to JSON decoding, signing payloads, and `toArray()` calls.
- Log latency for slow calls (`duration_ms` in context). Never log API keys or signatures.

## Websockets

Long-lived streams extend `BaseExchangeWebSocket implements ExchangeWebSocketInterface` (Ratchet/React) under `Infrastructure/Websocket/`; per-platform sockets in `Platforms/{Name}/Websockets/`. Created via `ExchangeWebSocketFactory::create()`. Pair every stream with a heartbeat health check (`Infrastructure/Health/`, registered in `AppServiceProvider`).

## Notifications (Telegram)

The notification chain: `Notification` class → `TelegramChannel` (Channels context) → `Telegram` transport (Notifications context, extends Telegraph).

```php
// Infrastructure/Notifications/OrderEntryNotification.php
final class OrderEntryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return new TelegramMessage(title: '…', body: new MessageBody(…), footer: '…');
    }
}
```

- Notification classes live in `Infrastructure/Notifications/` of the owning context; they implement `ShouldQueue` and build a `TelegramMessage` VO — no transport logic inside.
- Dispatch through the user: `$baseBot->getUser()->notify(new OrderEntryNotification(...))`, centralized in a service (`NotificationBotService`).
- Before duplicating a notification into another context, check Bots/Signals — several already exist twice; reuse or move to Shared instead.
- Exception-driven alerts don't need a Notification class: `BaseException` already reports to Telegram via its `sendTelegram` flag.

## Adapter Checklist

- [ ] Consumers depend on the Domain interface + Base Data types only
- [ ] Factory arm added; `default` throws `HandleException`
- [ ] Adapter methods: request Data → raw client → response validator → typed Data/Result DTO factory
- [ ] Timeouts + retry-with-backoff on every HTTP client; transport errors wrapped with `previous:`
- [ ] Interface binding present in `TradingServiceProvider`
- [ ] No raw arrays or platform types leaking out of Infrastructure; public returns are typed DTOs
- [ ] No `ds()`/`dump()` leftovers; no secrets in logs
- [ ] Websocket streams have a heartbeat health check
- [ ] Notifications: `ShouldQueue`, `via()` → `TelegramChannel`, message built as `TelegramMessage` VO
