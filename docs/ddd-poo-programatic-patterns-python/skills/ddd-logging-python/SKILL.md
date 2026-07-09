---
name: ddd-logging-python
description: >-
  Channel-based logging pattern — LoggerProtocol + ChannelLogger delegating to a
  scoped LoggerContext, TimedRotatingFileHandler (stdlib rotating files), one log channel/file
  per process or flow, channel switching at entry points only. Use when adding logging to
  Actions/Services/handlers, creating a new log channel, wiring logging in a new project,
  or replicating the Shared logging infrastructure.
---

# DDD Logging (Channel-based — Python)

The `ddd-poo-programatic-patterns-python` master rule is always applied.

**Pattern goal:** Application/Domain classes log through a stdlib-compatible protocol with **zero knowledge of channels**; each process/flow (sync command, integration, module) writes to its **own rotating log file**; the **entry point decides the channel once** and everything downstream follows.

```
Entry point (CLI/Celery task/Listener)          Consumers (Action/Service/Handler)
  LoggerContext.set_channel('sync_n4')    →     LoggerProtocol.info(...)
                                                      │
                                                ChannelLogger  →  logging.getLogger(context.channel())
                                                      │
                                                logging.config  →  logs/sync_n4.log (rotating)
```

## Components — `shared/domain/logging/`

Four small classes + one container registration. Replicate them verbatim in new projects.

**1. `LoggerProtocol`** — the domain contract; stdlib `logging.Logger` compatible:

```python
from __future__ import annotations

import logging
from typing import Protocol


class LoggerProtocol(Protocol):
    def debug(self, msg: str, *args: object, **kwargs: object) -> None: ...
    def info(self, msg: str, *args: object, **kwargs: object) -> None: ...
    def warning(self, msg: str, *args: object, **kwargs: object) -> None: ...
    def error(self, msg: str, *args: object, **kwargs: object) -> None: ...
    def exception(self, msg: str, *args: object, **kwargs: object) -> None: ...
```

**2. `ChannelLogger`** — delegates every log call to the currently active channel:

```python
from __future__ import annotations

import logging
from typing import final

from src.contexts.shared.domain.logging.logger_context import LoggerContext


@final
class ChannelLogger:
    def __init__(self, context: LoggerContext) -> None:
        self._context = context

    def _logger(self) -> logging.Logger:
        return logging.getLogger(self._context.channel())

    def log(self, level: int, msg: str, *args: object, **kwargs: object) -> None:
        self._logger().log(level, msg, *args, **kwargs)

    def info(self, msg: str, *args: object, **kwargs: object) -> None:
        self._logger().info(msg, *args, **kwargs)

    # ... debug, warning, error, exception mirror stdlib
```

**3. `LoggerContext`** — scoped holder of the active channel:

```python
from __future__ import annotations

from collections.abc import Callable
from contextvars import ContextVar
from typing import Any, final

from src.settings import settings


_channel_var: ContextVar[str] = ContextVar("log_channel", default=settings.logging.default_channel)


@final
class LoggerContext:
    def set_channel(self, channel: str) -> None:
        _channel_var.set(channel)

    def channel(self) -> str:
        return _channel_var.get()

    def run_with_channel(self, channel: str, callback: Callable[[], Any]) -> Any:
        token = _channel_var.set(channel)
        try:
            return callback()
        finally:
            _channel_var.reset(token)
```

> `ContextVar` is deliberate — one `LoggerContext` value per request/CLI run/Celery task, so a channel set in one task never leaks into the next (async-safe). Do not replace it with a process-global mutable string.

**4. `RotatingChannelHandler`** — factory for per-channel `TimedRotatingFileHandler`; gives every channel daily rotation:

```python
from __future__ import annotations

import logging
from logging.handlers import TimedRotatingFileHandler
from pathlib import Path
from typing import final


@final
class RotatingChannelHandler:
    def __call__(self, channel: str, config: dict[str, object]) -> logging.Handler:
        path = Path(str(config.get("path", "logs/app.log")))
        path.parent.mkdir(parents=True, exist_ok=True)

        handler = TimedRotatingFileHandler(
            filename=path,
            when="midnight",
            backupCount=int(config.get("days", 14)),
            encoding="utf-8",
        )
        handler.setFormatter(logging.Formatter(
            "[%(asctime)s] %(name)s.%(levelname)s: %(message)s",
            datefmt="%Y-%m-%d %H:%M:%S",
        ))
        return handler
```

> Retention (`days`) is read from the channel config in `settings` — put `LOG_DAILY_DAYS` in env and load it at startup, never read `os.environ` inside the handler factory after config is frozen.

## Container Registration

```python
from src.contexts.shared.domain.logging.channel_logger import ChannelLogger
from src.contexts.shared.domain.logging.logger_context import LoggerContext
from src.contexts.shared.domain.logging.logger_protocol import LoggerProtocol


def register_logging(container: Container) -> None:
    container.register(LoggerContext, scope="request")  # one per request/task
    container.register(LoggerProtocol, ChannelLogger)
```

Call `register_logging()` during `AppContainer` setup. **`scope="request"` is deliberate** — one `LoggerContext` per request/CLI run/queued task. Do not change it to singleton.

## Logging config — one channel per process/flow

Every process (sync command, integration, module) gets its own logger name + file, all rotating via `RotatingChannelHandler`:

```python
LOGGING_CHANNELS = {
    "default": {
        "path": "logs/app.log",
        "level": "DEBUG",
        "days": 14,
    },
    "sync_n4": {
        "path": "logs/sync_n4.log",
        "level": "DEBUG",
        "days": 14,
    },
    # tran_to_n4, sync_extranet, pre_gate, ... one per flow
}
```

Channel naming: snake_case matching the process (`sync_n4`, `tran_to_n4`, `sync_extranet`).

Wire channels at startup:

```python
for name, cfg in LOGGING_CHANNELS.items():
    logger = logging.getLogger(name)
    logger.addHandler(RotatingChannelHandler()(name, cfg))
    logger.setLevel(cfg["level"])
    logger.propagate = False
```

## Usage Rules

**Consumers** (Actions, Services, handlers, domain services) inject `LoggerProtocol` and never know the channel:

```python
@final
@dataclass(frozen=True, slots=True)
class PostN4EventHandler:
    n4_soap_client: N4SoapClient
    logger: LoggerProtocol

    def handle(self, command: PostN4EventCommand) -> N4WebServiceResponse | None:
        self.logger.info(
            "PostN4EventHandler.handle Request",
            extra={"event": command.event_id()},
        )
        # ...
```

**Entry points** (CLI commands, Celery tasks, listeners) inject `LoggerContext` and set the channel **first thing**:

```python
@final
@dataclass(frozen=True, slots=True)
class GetShippersFromN4Command:
    logger_context: LoggerContext
    sync_n4_shippers_service: SyncN4ShippersService

    def run(self) -> None:
        self.logger_context.set_channel("sync_n4")  # everything downstream logs here
        self.sync_n4_shippers_service.sync()
```

**Multi-phase flows** switch per phase (`set_channel` between phases, like `SyncExtranetAndN4`), or use `run_with_channel()` when the switch must be temporary:

```python
self.logger_context.run_with_channel("tran_to_n4", lambda: self.transfer_service.run())
# previous channel restored automatically, even on exceptions
```

**Hard rules:**

- Domain/Application classes depend on `LoggerProtocol` — never on bare `logging.getLogger("hardcoded")`, never on a channel name string.
- Channel selection happens **only at entry points** (CLI/Celery task/Listener/router middleware) — a Service/Action calling `set_channel()` is a smell.
- Message prefix stays `ClassName.method_name`; context dicts/extra with snake_case domain ids.
- Without `set_channel()`, logging falls back to `settings.logging.default_channel` — safe default.
- New process/flow = new channel entry in logging config + one `set_channel()` at its entry point. Nothing else changes.

## Adding This Pattern to a Project

1. Copy the 4 classes into `src/contexts/shared/domain/logging/`.
2. Register bindings in `AppContainer.register_logging()`.
3. Define per-channel paths in `settings` / `LOGGING_CHANNELS` with `RotatingChannelHandler`.
4. New code injects `LoggerProtocol`; entry points inject `LoggerContext`.
5. Projects with a legacy logger (e.g. a `Logger.save()` helper): keep the old convention inside files that already use it; route **new** code through this pattern and migrate on touch.

## Logging Checklist

- [ ] Consumer injects `LoggerProtocol` — no hardcoded `getLogger()`, no channel knowledge
- [ ] Entry point sets the channel once via `LoggerContext.set_channel()`
- [ ] Temporary switches use `run_with_channel()` (restores in `finally`)
- [ ] `LoggerContext` scoped per request/task, `LoggerProtocol` → `ChannelLogger`
- [ ] Each process/flow has its own channel + rotating file
- [ ] `days` from channel config, not `os.environ` at runtime
- [ ] Messages prefixed `ClassName.method_name`; no secrets/tokens in extra
