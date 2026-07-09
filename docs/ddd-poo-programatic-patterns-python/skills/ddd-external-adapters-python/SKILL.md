---
name: ddd-external-adapters-python
description: >-
  Anti-corruption layer patterns for src/contexts — exchange platform adapters
  (Kucoin/Bingx/Binance), the ExchangeAdapterProtocol port, adapter factories,
  request/response Data DTOs, response validators, HTTP client conventions
  (timeouts, retry, layered error handling), websockets, and Telegram notifications.
  Use when integrating an external API, adding an exchange platform or endpoint,
  building an HTTP client, or sending user notifications.
  Complements always-applied rule ddd-poo-programatic-patterns-python.
---

# DDD External Adapters (Anti-Corruption Layer — Python)

The `ddd-poo-programatic-patterns-python` master rule is always applied.

## Port and Adapter Structure (Exchanges context)

```text
exchanges/
├── domain/
│   ├── protocols/exchange_adapter_protocol.py   ← the port (get_account, post_order, get_klines, …)
│   ├── factories/exchange_adapter_factory.py      ← selects adapter by [exchange, market_type]
│   └── mixins/has_exchange_response_validator.py  ← shared response validation
└── infrastructure/
    ├── data/                                     ← Base request/response Pydantic Data (BaseReqPostData, BaseSpotOrderData…)
    ├── platforms/{kucoin,bingx,binance}/
    │   ├── adapters/       ← KucoinSpotAdapter implements ExchangeAdapterProtocol
    │   ├── rest_api/       ← raw signed HTTP client + platform Response object
    │   ├── data/           ← platform-specific Data subclasses + normalizers
    │   └── websockets/
    ├── websocket/          ← BaseExchangeWebSocket + ExchangeWebSocketFactory
    └── health/             ← heartbeat checks
```

**Rules:**
- The rest of the app depends **only** on `ExchangeAdapterProtocol` and the Base Data types — never on a platform SDK, raw response dicts, or platform Data classes.
- New platform = new `platforms/{name}/` tree **including `adapters/`** + a new arm in `ExchangeAdapterFactory.make()` (and `ExchangeWebSocketFactory` if it streams). A platform without an adapter is unusable — complete it before wiring.
- Adapters return **typed Data DTOs built by an `Exchange*DataFactory`**, never raw dicts. If the response needs validation, validators, aliases, nested models, ORM hydration, or API serialization, use Pydantic `BaseModel`; otherwise a lightweight frozen dataclass DTO is enough.

## Adapter Method Skeleton (the repeated convention)

Every adapter endpoint follows: build request Data → call raw client → validate response → map to a typed Data/Result DTO.

```python
def post_order(self, request: BaseReqPostData) -> BaseSpotOrderData:
    response_exchange = self.order().post(request.to_dict())

    if not self.kucoin_response.set_request(request).set_response(response_exchange).is_success():
        self.kucoin_response.handle_error({
            "function": "post_order",
            "class": self.__class__.__name__,
        })  # raises the platform exception with request + response context

    return ExchangeSpotOrderDataFactory.make(self, self.kucoin_response.get_data())
```

- The platform Response object (`KucoinResponse implements ExchangeResponseProtocol`) owns success detection (`code == 200000`), error raising, and platform quirks (e.g. time-desync code `400002` clears the Redis clock-offset so the next request re-syncs).
- `has_exchange_response_validator.validate_response_exchange()` is the shared guard — raise `HandleException` on non-success, invoke `callback_desync_time()` on desync.
- **No `breakpoint()`/`print()` debug calls in adapters** — remove on touch.

## Raw HTTP Client Conventions

Signed platform clients (`httpx` or `requests`, `platforms/kucoin/rest_api/request.py`): HMAC signing, `raise_for_status=False`, explicit `timeout` (60s for exchange calls), time-sync offset cached in Redis, wrap transport exceptions into the platform exception.

Simple JSON microservice clients follow `technicals_v2/infrastructure/clients/py_ta_api_client.py` — the legacy reference implementation returns a decoded dict at the raw HTTP edge, but new clients should wrap successful payloads in a response DTO before leaving the client/adapter boundary:

```python
from __future__ import annotations

import logging
from typing import final

import httpx

logger = logging.getLogger(__name__)


@final
class PyTaApiClient:
    def __init__(self, client: httpx.Client) -> None:
        self._client = client

    def send_request(self, endpoint: str, data: PyTaApiRequestData) -> PyTaApiResponseData:
        try:
            response = self._client.post(
                endpoint,
                json=data.to_dict(),
                timeout=httpx.Timeout(5.0, connect=2.0),
            )
        except httpx.ConnectError as exc:
            logger.error(
                "PyTaApiClient.send_request could not connect",
                extra={"endpoint": endpoint, "error": str(exc)},
            )
            raise RuntimeError("PyTaApi could not connect") from exc

        if response.is_error:
            logger.warning("PyTaApiClient.send_request invalid response", extra={"status": response.status_code})
            raise RuntimeError("PyTaApi invalid response")

        payload = response.json()
        if not isinstance(payload, dict):
            logger.warning("PyTaApiClient.send_request invalid payload")
            raise RuntimeError("PyTaApi invalid payload")

        return PyTaApiResponseData.from_payload(payload)
```

**Client rules:**
- Protocol in the context (`PyTaApiClientProtocol`) + implementation in `infrastructure/clients/`; **bind the pair in `AppContainer.register()`** (verify the binding exists — a commented-out binding means protocol injection fails).
- Timeouts are mandatory: short connect timeout (2s), bounded read timeout, retry with backoff on transient errors.
- Layered error handling: transport error / HTTP failure / malformed payload each get their own log line (`Class.method` prefix) and a wrapped exception with `from exc`.
- Public client/adapter methods return typed request/response DTOs or Result DTOs, not dict/list shapes. Keep raw dicts/lists local to JSON decoding, signing payloads, and `to_dict()`/`model_dump()` calls.
- Log latency for slow calls (`duration_ms` in extra). Never log API keys or signatures.

## Websockets

Long-lived streams extend `BaseExchangeWebSocket(ExchangeWebSocketProtocol)` (`websockets` / `asyncio`) under `infrastructure/websocket/`; per-platform sockets in `platforms/{name}/websockets/`. Created via `ExchangeWebSocketFactory.create()`. Pair every stream with a heartbeat health check (`infrastructure/health/`, registered in startup hooks).

## Notifications (Telegram)

The notification chain: `Notification` class → `TelegramChannel` (Channels context) → `Telegram` transport (Notifications context).

```python
# infrastructure/notifications/order_entry_notification.py
@final
@dataclass(frozen=True, slots=True)
class OrderEntryNotification:
    title: str
    body: MessageBody
    footer: str

    def via(self) -> list[type[NotificationChannel]]:
        return [TelegramChannel]

    def to_telegram(self) -> TelegramMessage:
        return TelegramMessage(title=self.title, body=self.body, footer=self.footer)
```

- Notification classes live in `infrastructure/notifications/` of the owning context; they build a `TelegramMessage` VO — no transport logic inside.
- Dispatch through the user: `base_bot.get_user().notify(OrderEntryNotification(...))`, centralized in a service (`NotificationBotService`).
- Before duplicating a notification into another context, check Bots/Signals — several may already exist twice; reuse or move to Shared instead.
- Exception-driven alerts don't need a Notification class: `BaseException` already reports to Telegram via its `send_telegram` flag.

## Adapter Checklist

- [ ] Consumers depend on the Domain protocol + Base Data types only
- [ ] Factory arm added; `default` raises `HandleException`
- [ ] Adapter methods: request Data → raw client → response validator → typed Data/Result DTO factory
- [ ] Timeouts + retry-with-backoff on every HTTP client; transport errors wrapped with `from exc`
- [ ] Protocol binding present in `AppContainer`
- [ ] No raw dicts/lists or platform types leaking out of Infrastructure; public returns are typed DTOs
- [ ] No `breakpoint()`/`print()` leftovers; no secrets in logs
- [ ] Websocket streams have a heartbeat health check
- [ ] Notifications: async dispatch, `via()` → `TelegramChannel`, message built as `TelegramMessage` VO
