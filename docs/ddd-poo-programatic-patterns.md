# Patrones DDD + POO + Programáticos

Resumen de los patrones usados por la regla `resources/rules/ddd-poo-programatic-patterns`.

## Arquitectura y Capas

- **DDD modular monolith**: organiza el sistema en bounded contexts dentro de `app/Contexts/{Context}` para separar responsabilidades por dominio sin partir la aplicación en microservicios.
- **Bounded Context**: agrupa lenguaje, reglas, Actions, entidades, repositorios e infraestructura alrededor de una capacidad de negocio concreta.
- **Layer Boundaries**: separa `Domain`, `Application`, `Infrastructure` y `UI`; cada capa tiene imports permitidos y responsabilidades claras.
- **Thin UI**: Filament, Livewire, Controllers y Commands solo validan, autorizan, mapean DTOs, delegan y responden; no contienen lógica de negocio.
- **Global Eloquent Models**: los modelos Eloquent viven en `app/Models/`; el dominio usa entidades PHP puras hidratadas desde esos modelos.
- **CQRS/UseCase to Action Migration**: `Command`, `Query`, `Handler` y `*UC` son legacy; al tocarlos se migran a `Action` con método `run()`.

## Aplicación

- **Action**: punto único de entrada para cada caso de uso. Puede ser atómico o coordinar varios pasos, repositorios, domain services, transacciones y jobs.
- **Service Facade**: agrupa Actions relacionadas detrás de una API simple para UI u otros consumidores; no reemplaza a las Actions como caso de uso.
- **DTO / Data**: transporta datos tipados entre capas. Se priorizan objetos DTO sobre arrays; se usa Spatie Data cuando hacen falta casts, validación, hidratación o serialización avanzada.
- **Result DTO**: representa resultados de negocio esperados como "skipped", "already processed" o respuestas multi-valor, evitando arrays asociativos.
- **DataCollection**: colección tipada para listas de DTOs; evita contratos como `array<int, array{...}>`.
- **Dependency Injection**: dependencias por constructor con `private readonly`; interfaces solo cuando existe una segunda implementación o un contrato estable y su binding está registrado.

## Dominio

- **Entity**: objeto PHP puro con identidad y comportamiento de negocio; se hidrata desde Eloquent con `fromModel()` y no consulta la base de datos.
- **Value Object**: valor inmutable y validado al construirse; expresa conceptos del dominio como dinero, precio, monto o coinpair.
- **Enum**: reemplaza magic strings/numbers con casos tipados y helpers de dominio o UI cuando hacen falta.
- **Domain Service**: coordina entidades, VOs y repositorios cuando una regla no pertenece naturalmente a una sola entidad.
- **Factory**: crea variantes mediante `make()` y `match` sobre enums; el `default` siempre lanza `HandleException`.
- **Strategy**: encapsula comportamientos variantes, por ejemplo distintos tipos de bot, indicadores o traders.
- **Pipeline / Pipes**: aplica filtros o validaciones secuenciales; cada pipe evalúa una condición y puede cortar el flujo lanzando excepción.
- **Caster**: transforma valores hacia enums, VOs o modelos al hidratar DTOs/Data con Spatie Laravel Data.
- **Domain Events**: comunican eventos de dominio después de persistir cambios; los listeners se registran explícitamente y delegan a Actions.
- **Domain Exceptions**: errores de dominio extienden `BaseException` o `HandleException`, con logging y reporte centralizados.

## Persistencia e Infraestructura

- **Repository**: concentra acceso a datos. Los repositorios Eloquent extienden `BaseRepository` y agregan solo consultas específicas del contexto.
- **Cache Decorator**: envuelve un repositorio con Redis usando `BaseCache`, manteniendo la misma intención de lectura con caché transparente.
- **Adapter / Anti-Corruption Layer**: encapsula APIs externas detrás de interfaces de dominio para que el resto del sistema no dependa de SDKs, formatos crudos o detalles de plataforma.
- **Port Interface**: contrato de dominio para infraestructura externa, como `ExchangeAdapterInterface`.
- **Response Data Factory**: convierte respuestas externas validadas en DTOs tipados del sistema.
- **HTTP Client Boundary**: maneja timeouts, retries, errores de transporte, payloads inválidos y logs; los arrays crudos quedan dentro del borde HTTP.
- **Webhook/Websocket Adapter**: centraliza conexiones largas o callbacks externos y los acompaña con health checks.
- **Notification Adapter**: construye mensajes como VOs o DTOs y delega el transporte a canales como Telegram.

## Async, Jobs y Consistencia

- **Thin Job**: un Job solo recibe IDs escalares, selecciona queue y delega a una Action/Service en `handle()`.
- **Dispatch After Commit**: los jobs disparados después de escribir en DB usan `afterCommit()` para no leer datos sin confirmar.
- **Transactional Outbox**: persiste una intención de trabajo y luego la consume async, dando confiabilidad a side effects.
- **Outbox Producer/Consumer**: el productor guarda un trigger idempotente; el consumidor marca processing/completed/failed y delega la operación real.
- **Redis Distributed Lock**: usa `Cache::lock()` para evitar trabajo concurrente sobre el mismo agregado y libera el lock en `finally`.
- **Idempotency Guard**: evita duplicados con estados, búsquedas de triggers activos, `upsert` o `updateOrCreate`.
- **Database Transaction**: agrupa escrituras multi-tabla con `DB::transaction()` dentro de la Action, sin envolver llamadas externas.
- **Bulk Processing**: procesa datos grandes con `chunkById()` o jobs por lote para evitar cargar colecciones sin límite.
- **Thin Console Command**: comandos de consola parsean input y delegan a Actions/Services; no contienen lógica de negocio.

## Logging y Observabilidad

- **Channel-Based Logging**: cada flujo escribe en su propio canal/log file rotativo, decidido en el entry point.
- **LoggerInterface**: contrato PSR-3 inyectado en Application/Domain para evitar dependencia directa de `Log::`.
- **ChannelLogger**: logger que delega al canal activo del `LoggerContext`.
- **LoggerContext**: mantiene el canal activo por request, comando o job; permite `setChannel()` y `runWithChannel()`.
- **CustomSingleLogger**: factory de Monolog para canales custom con rotación diaria.
- **Structured Context**: logs con prefijo `ClassName::methodName` y claves snake_case como `bot_id`, `cycle_id` u `order_id`.

## Testing

- **Pest Feature Tests**: cubren flujos que necesitan DB, container, queues, Filament o integración Laravel.
- **Plain Unit Tests**: prueban dominio y aplicación sin DB, facades ni container cuando sea posible.
- **Action Tests**: validan delegación, argumentos, resultados tipados, idempotencia y caminos de fallo.
- **VO/Entity/Factory/Pipe Tests**: cubren invariantes, factories, comportamiento de negocio y short-circuit por excepción.
- **Mockery Doubles**: mocks de interfaces/repositorios para pruebas unitarias; alias/overload solo para legacy estático no inyectable.
- **Laravel Fakes**: `Queue::fake`, `Http::fake`, `Http::preventStrayRequests` y similares para no tocar servicios reales.
- **DTO Assertions**: los tests de resultados multi-valor verifican DTOs/Result objects y propiedades tipadas, no keys de arrays.
- **Characterization Tests**: antes de refactorizar legacy sin cobertura, se documenta el comportamiento actual con tests.

## Reglas Transversales

- **Strict Typing**: clases nuevas con `declare(strict_types=1)`, `final class`, constructor promovido `private readonly` y return types explícitos.
- **No Raw Arrays Between Classes**: arrays solo en límites reales como `toArray()`, payload HTTP, JSON o escritura a DB.
- **No Magic Values**: strings y números de negocio se reemplazan por enums o config.
- **No Debug Leftovers**: no `ds()`, `dump()`, `dd()` ni bloques comentados.
- **No Over-Engineering**: se extrae una abstracción solo cuando reduce complejidad real o existe una segunda implementación.
