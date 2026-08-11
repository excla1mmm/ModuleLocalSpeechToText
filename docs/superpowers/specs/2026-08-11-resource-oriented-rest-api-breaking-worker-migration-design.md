# Resource-Oriented REST API and Breaking Worker Migration Design

## Статус и область действия

Эта спецификация задаёт единый breaking-переход для:

- `ModuleLocalSpeechToText`;
- `ModuleAISupervisor`;
- `LocalSTTWorkerMac`;
- `AISupervisorWorkerMac`;
- STT-части `MIKOAIWorkerWin`.

Старый Python-клиент `moduleSTT-worker` намеренно не мигрируется и после обновления модуля становится несовместимым. Планируемая AI-часть Windows worker должна сразу реализовывать новый контракт и не должна содержать legacy-маршруты.

Изменение выпускается как worker API contract version `2`. Product/module version и worker API version остаются независимыми: совместимость определяется только целочисленным contract version, а `module_version` и `app_version` передаются для диагностики. Каждый продукт увеличивает свою обычную release/build version по принятой в его репозитории схеме; искусственно выравнивать версии STT module, AI module, macOS и Windows приложений не требуется.

## Цель

Исправить две связанные архитектурные проблемы:

1. Swagger объединяет разные сущности каждого модуля в одну секцию и визуально скрывает ресурсные границы.
2. Worker API организован как набор RPC-команд `:register`, `:getNext`, `:submitResult`, `:heartbeat` и других действий вместо отдельных REST-ресурсов с корректной HTTP-семантикой.

После перехода:

- Swagger группирует операции по сущностям;
- `Worker` имеет полноценный management CRUD;
- захват задания выполняется изменяющим методом `POST`, а не `GET`;
- lease, recording, result, failure, partial result и voice analytics представлены отдельными ресурсами;
- транскрипции остаются отдельными read-only ресурсами;
- старые маршруты никогда не выполняют бизнес-логику;
- старый worker получает явную ошибку обновления от нового модуля;
- новый worker получает явную ошибку обновления модуля от старого модуля;
- после обновления обеих сторон существующий токен, `worker_uid`, локальные настройки и модели продолжают использоваться.

## Не-цели

- Поддержка одновременной работы worker API v1 и v2.
- Автоматический fallback нового приложения на старые маршруты.
- Сохранение возможности обработки заданий старым worker после обновления модуля.
- Изменение формата `PBXApiResult`.
- Изменение `worker_uid`, `job_id`, `lease_token`, STT timestamps, AI evidence references или текущих result payloads, кроме обязательной версии протокола и новых REST-путей.
- Объединение STT и AI worker в одно приложение или общий runtime.
- Ротация сохранённых worker API keys только из-за перехода на v2.

## Исходное состояние

### Local Speech to Text

Модуль уже имеет разные контроллеры и пути для `workers`, `jobs`, `transcripts`, `transcript events`, `call transcripts` и `call transcript events`, но каждый контроллер использует один тег `Local Speech To Text`.

Worker contract v1 использует:

```text
POST /workers:register
GET  /workers:getProcessingSettings
GET  /jobs:getNext
GET  /jobs/{id}:download
POST /jobs/{id}:submitResult
POST /jobs/{id}:submitError
POST /jobs/{id}:heartbeat
POST /jobs/{id}:release
```

`GET /jobs:getNext` изменяет состояние: выбирает задание, создаёт lease и закрепляет задание за worker. Это нарушает safe-семантику HTTP GET.

### AI Supervisor

Модуль имеет отдельные контроллеры для `audit`, `calls`, `connection`, `dashboard`, `health`, `imports`, `jobs`, `logs`, `settings` и `workers`, но каждый использует один тег `AI Supervisor`.

Worker contract v1 использует:

```text
POST /workers:register
GET  /jobs:getNext
GET  /jobs/{id}:downloadRecording
POST /jobs/{id}:heartbeat
POST /jobs/{id}:release
POST /jobs/{id}:submitPartial
POST /jobs/{id}:submitVoiceAnalytics
POST /jobs/{id}:submitResult
POST /jobs/{id}:submitError
```

Контроллер `Jobs` одновременно обслуживает management queue, worker lease protocol, запись, partials, voice analytics и итоговые результаты. `Connection` смешивает состояние соединения с CRUD жизненным циклом API keys. `Calls` смешивает основную сущность звонка с review, workflow, notes и history.

## Рассмотренные варианты

### 1. Только разделить Swagger-теги

Минимальный и безопасный diff, но он исправляет только отображение. Worker по-прежнему не имеет CRUD, `GET /jobs:getNext` продолжает менять состояние, а Jobs остаётся RPC-шлюзом. Вариант отклонён.

### 2. Удалить старые маршруты и возвращать обычный 404

Runtime legacy API действительно исчезает, но старые workers воспринимают 404/405 как сетевую или временную несовместимость и могут бесконечно переподключаться. Пользователь не получает понятного указания, какой компонент обновить. Вариант отклонён.

### 3. Новый ресурсный API, обязательное согласование версии и отказные legacy-заглушки

Выбранный вариант. Новый worker сначала проверяет контракт. Старые worker-маршруты остаются зарегистрированными только как tombstone handlers и всегда отвечают `426 Upgrade Required`, не вызывая processors и services. Административные legacy-маршруты отвечают `410 Gone`. Это не обратная совместимость: ни одна операция v1 не может завершиться успешно.

## Версионирование и согласование контракта

Платформенный префикс MikoPBX REST остаётся `/pbxcore/api/v3`. Число `3` описывает REST API платформы, а worker contract version `2` описывает независимый контракт между модулем и приложением.

Каждый модуль добавляет read-only singleton:

```text
GET /pbxcore/api/v3/<module>/worker-api-contract
```

Успешный `PBXApiResult.data` содержит:

```json
{
  "contract": "mikopbx-worker-api",
  "api_version": 2,
  "module": "ModuleLocalSpeechToText",
  "module_version": "1.45",
  "capabilities": ["worker-crud", "job-leases", "job-recordings", "job-results"]
}
```

Для AI Supervisor `module` и `capabilities` отражают AI-контракт, включая `job-partials` и `job-voice-analytics`.

Все worker-only запросы v2, кроме получения контракта, обязаны передавать:

```text
X-MikoPBX-Worker-API-Version: 2
```

Модуль проверяет заголовок до выполнения бизнес-логики. Отсутствующая, нечисловая или неподдерживаемая версия возвращает HTTP 426.

Новый worker не хранит список fallback-маршрутов. Согласованная версия может сохраняться только как диагностическое состояние; источником истины на каждом reconnect остаётся ответ модуля.

## Ошибка несовместимости

Новый модуль отвечает старому worker или клиенту с неверной версией:

```http
HTTP/1.1 426 Upgrade Required
Upgrade: MikoPBX-Worker-API/2
Cache-Control: no-store
Content-Type: application/json
```

```json
{
  "success": false,
  "data": {
    "error_code": "worker_upgrade_required",
    "module": "ModuleLocalSpeechToText",
    "module_version": "1.45",
    "required_worker_api_version": 2
  },
  "messages": {
    "error": ["Update the worker application to a release that supports worker API version 2"]
  }
}
```

Для нового worker против старого модуля `worker-api-contract` возвращает 404/405. Приложение классифицирует это только как `module_upgrade_required`, прекращает регистрацию и polling и показывает пользователю требование обновить соответствующий модуль до релиза с worker API version `2`. Оно не проверяет `workers:register` и не пробует `jobs:getNext`.

Ошибки авторизации `401/403`, временные `5xx`, сетевые ошибки и `module_upgrade_required` остаются разными категориями. Несовместимость не должна попадать в бесконечный reconnect backoff как временная ошибка.

## Новый Worker CRUD

Публичным стабильным идентификатором worker становится `worker_uid`; внутренний numeric `id` остаётся в payload и базе данных, но не является обязательным знанием приложения.

Оба модуля реализуют одну ресурсную ветку:

```text
GET    /workers
POST   /workers
GET    /workers/{worker_uid}
PUT    /workers/{worker_uid}
PATCH  /workers/{worker_uid}
DELETE /workers/{worker_uid}
```

Семантика:

- `GET /workers` — административный список с пагинацией и фильтрами `status`, `worker_uid`, `api_version`, `app_version`;
- `POST /workers` — административное создание, возвращает `201` и `409` при конфликте UID;
- `GET /workers/{worker_uid}` — административная detail-запись;
- `PUT /workers/{worker_uid}` — идемпотентная регистрация или полная замена worker representation;
- `PATCH /workers/{worker_uid}` — административное частичное изменение разрешённых полей;
- `DELETE /workers/{worker_uid}` — удаление worker без удаления его исторических job/result данных.

Restricted worker API key может выполнять только `PUT` для собственного `worker_uid`. Он не может получать список, читать чужого worker, выполнять admin PATCH или DELETE. Связь `worker_uid` с token ID проверяется до записи, как и в текущей регистрации.

`DELETE` возвращает `409 worker_has_active_lease`, если worker владеет активным заданием. Удаление отвязывает worker от API key, но не отзывает сам key автоматически; жизненный цикл keys управляется отдельным ресурсом.

STT processing settings становятся отдельным read-only ресурсом:

```text
GET /worker-processing-settings/{worker_uid}
```

Worker key может читать только собственный профиль. Получение профиля сохраняет текущую атомарную семантику `has_settings_changed`.

## Новый worker job protocol

### Общие ресурсы STT и AI

```text
POST   /job-leases
PATCH  /job-leases/{job_id}
DELETE /job-leases/{job_id}
GET    /job-recordings/{job_id}
PUT    /job-results/{job_id}
PUT    /job-failures/{job_id}
```

Семантика:

- `POST /job-leases` атомарно захватывает следующее задание для переданного `worker_uid` и возвращает `job: null` при пустой очереди;
- `PATCH /job-leases/{job_id}` продлевает lease и сохраняет текущую late-heartbeat/idempotency семантику;
- `DELETE /job-leases/{job_id}` освобождает lease без расхода processing attempt;
- `GET /job-recordings/{job_id}` отдаёт только запись задания, принадлежащего вызывающему worker и действующему lease;
- `PUT /job-results/{job_id}` идемпотентно создаёт или подтверждает единственный финальный результат задания;
- `PUT /job-failures/{job_id}` идемпотентно фиксирует ошибку текущей попытки и применяет существующую retry/max-attempts политику.

`worker_uid` и `lease_token` остаются обязательными данными изменяющих запросов. `lease_token` не помещается в URL и не должен попадать в access logs. Существующие ownership checks по `worker_uid`, `job_id`, token binding и constant-time сравнению `lease_token` сохраняются.

`job-recordings` сохраняет same-origin, realpath, file-size, duration, Range и download-token ограничения текущих модулей.

### Дополнительные AI-ресурсы

```text
PUT   /job-partials/{job_id}
PATCH /job-voice-analytics/{job_id}
```

`PUT /job-partials/{job_id}` сохраняет текущую детерминированную идентичность partial key, cache context, bounded storage и повторное использование map-reduce partials. `PATCH /job-voice-analytics/{job_id}` изменяет только разрешённую часть финального результата и сохраняет текущие schema/evidence checks.

## Management Jobs API

Worker protocol отделяется от административного управления очередью.

Оба модуля предоставляют, где применимо:

```text
GET    /jobs
GET    /jobs/{id}
POST   /jobs
PATCH  /jobs/{id}
PATCH  /jobs
DELETE /jobs/{id}
DELETE /jobs
```

- `GET` читает очередь и detail;
- `POST` создаёт/ставит задание в очередь;
- item `PATCH` выполняет разрешённый административный переход, например retry/reset в `pending`;
- collection `PATCH` выполняет bulk transition по `ids` или документированному фильтру;
- item `DELETE` удаляет или отменяет одно допустимое задание;
- collection `DELETE` заменяет `jobs:clear` и требует явный query-фильтр состояний. Пустой фильтр запрещён; DELETE request body не используется.

Worker restricted keys не имеют доступа к management Jobs API. Автоматические scanner/import services могут продолжать создавать задания через внутренние PHP services без HTTP round-trip.

## Transcripts и call transcripts

Транскрипции являются generated immutable/read-only сущностями. Для соблюдения REST не требуется искусственно добавлять им полный CRUD. Их runtime-маршруты уже разделены по сущностям и сохраняются без изменения:

```text
GET /transcripts
GET /transcripts/{result_id}
GET /transcripts/events
GET /call-transcripts/{aggregate_id}
GET /call-transcripts/{aggregate_id}?revision={revision}
GET /call-transcripts/events
```

Удаление транскрипций остаётся обязанностью retention service. Arbitrary POST/PUT/PATCH transcript payloads не публикуются.

## AI Supervisor resource cleanup

### Worker API keys

Сущность `connection` заменяется:

```text
GET    /worker-api-keys
POST   /worker-api-keys
DELETE /worker-api-keys/{id}
```

Plaintext key возвращается только один раз в `POST`. `GET` никогда не возвращает secret. `DELETE` отвязывает workers и соблюдает текущие компенсационные операции между module DB и Core API key storage.

### Calls, review, workflow и notes

```text
GET   /calls
GET   /calls/{id}
PATCH /calls/{id}
PATCH /calls
GET   /call-workflow-events
POST  /call-notes
GET   /call-notes
```

`PATCH /calls/{id}` изменяет только разрешённые review/workflow поля. Collection PATCH заменяет `calls:bulkWorkflow` и требует непустой список IDs. Notes являются append-only сущностями; history является read-only event collection. Evidence, STT segment references и immutable history не изменяются через Call PATCH.

### Imports и audit

```text
POST /imports
GET  /imports/{id}
POST /audit-runs
GET  /audit-runs/{id}
```

`POST /imports` является единственным способом запуска импорта и заменяет дублирующий `imports:run`. Audit reprocess моделируется как создаваемый run, а не как команда на read-model `/audit`.

`settings`, `dashboard`, `health`, `logs` и основной read-only `/audit` сохраняют отдельные resource paths и корректные методы.

## OpenAPI-группировка

Ни один модуль больше не использует один общий tag для всех контроллеров.

STT использует как минимум:

```text
Local STT Workers
Local STT Jobs
Local STT Recordings
Local STT Transcripts
Local STT Call Transcripts
```

AI Supervisor использует:

```text
AI Supervisor Workers
AI Supervisor Worker API Keys
AI Supervisor Jobs
AI Supervisor Job Results
AI Supervisor Calls
AI Supervisor Imports
AI Supervisor Audit
AI Supervisor Settings
AI Supervisor Dashboard
AI Supervisor Health
AI Supervisor Logs
```

MikoPBX Core преобразует английское имя в `rest_tag_*`. Каждый получившийся key добавляется и переводится во все поддерживаемые module message catalogs. Swagger не должен показывать raw `rest_tag_*`. Contract tests, которые сейчас требуют один tag для всех controllers, удаляются или заменяются точным mapping `resource -> tag`.

## Поведение legacy-маршрутов после обновления

### Worker contract v1

Все прежние worker routes остаются только в отдельном legacy rejection controller. Handler:

1. не вызывает старый Processor;
2. не читает и не изменяет Job/Worker/Result модели;
3. не продлевает и не освобождает lease;
4. не скачивает запись;
5. не сохраняет result, partial, voice analytics или error;
6. всегда возвращает HTTP 426 с `worker_upgrade_required`.

Это касается всех перечисленных выше STT/AI worker routes v1, включая каждый resource-level `{id}:...` action.

### Старые административные action routes

Удалённые `jobs:enqueue`, `jobs:retryFailed`, `jobs:requeueStale`, `jobs:clear`, `calls:*`, `connection:*`, `imports:run`, `audit:reprocess` и их эквиваленты отвечают:

```http
HTTP/1.1 410 Gone
Cache-Control: no-store
```

с `error_code = api_route_removed` и полем `replacement_path`. Они не являются compatibility adapters и никогда не перенаправляют запрос автоматически.

Legacy rejection routes не публикуются как рабочие операции в Swagger/OpenAPI. Они перечисляются только в release/migration documentation и проверяются отдельными executable contract tests.

## Миграция ModuleLocalSpeechToText при обновлении

`PbxExtensionSetup::installDB()` выполняет идемпотентную миграцию под существующим setup backup/restore guard:

1. Добавляет в `m_STTWorker`:
   - `api_version INTEGER NOT NULL DEFAULT 1`;
   - `app_version TEXT NOT NULL DEFAULT ''`;
   - `updated_at INTEGER NOT NULL DEFAULT 0`.
2. Существующие workers получают `api_version = 1`, `status = offline`, `has_settings_changed = 1` и сохраняют `worker_uid`, numeric ID, API key binding и историю.
3. Все задания со статусом `processing` и активным legacy lease атомарно возвращаются в `pending`:
   - `worker_id = NULL`;
   - `lease_token = NULL`;
   - `lease_expires_at = NULL`;
   - attempt counter не увеличивается;
   - диагностическая причина фиксируется как `worker_api_migration` без утечки token.
4. Готовые, skipped и terminal jobs/results/transcripts не изменяются.
5. Worker API key IDs сохраняются. Их path permissions пересобираются под v2 resources; executable allowlist полностью исключает выполнение v1 processors.
6. В `m_STTSetting` записывается marker `worker_api_contract_migration_v2 = 1` только после успешного завершения транзакции.
7. Повторный запуск setup видит marker и всё равно проверяет наличие колонок, permissions и отсутствие legacy active leases; операция остаётся идемпотентной.
8. После commit планируется reload REST metadata/cache и перезапуск module workers стандартным lifecycle механизмом.

## Миграция ModuleAISupervisor при обновлении

`PbxExtensionSetup::installDB()` выполняет аналогичную идемпотентную миграцию:

1. Добавляет в `m_AISupervisorWorker`:
   - `api_version INTEGER NOT NULL DEFAULT 1`;
   - при отсутствии — `updated_at INTEGER NOT NULL DEFAULT 0`.
2. Сохраняет существующие `app_version`, capabilities, model, API key binding и worker history, но выставляет `api_version = 1` и `status = offline`.
3. Все `running` jobs с legacy lease возвращаются в `pending` без увеличения attempts; `worker_id`, `lease_token` и `lease_expires_at` очищаются.
4. Сохранённые partial results, transcript cache, cache fingerprints и reusable reduce/chunk data не удаляются. Новый worker может продолжить их использовать после повторного claim при совпадении текущего cache context.
5. Transcript refs и analysis pipeline projections синхронизируются с состоянием `pending`; готовые результаты не пересчитываются.
6. API key IDs сохраняются, permissions пересобираются под v2 worker resources.
7. В `m_AISupervisorSetting` фиксируется `worker_api_contract_migration_v2 = 1` после успешной транзакции.
8. Повторный setup безопасно повторяет reconciliation и не сбрасывает jobs, уже захваченные worker v2.

Миграция различает v1 и v2 lease по `worker.api_version`. После marker нельзя массово сбрасывать все running jobs: разрешено сбрасывать только lease, принадлежащие worker с `api_version < 2` или отсутствующим worker.

## Миграция приложений worker

Обновление приложений не переносит данные между STT и AI и не создаёт shared state.

Каждое приложение сохраняет:

- API token в существующем Keychain service;
- PBX address и API base prefix;
- стабильный `worker_uid`;
- выбранные модели и model cache;
- пользовательские настройки polling/heartbeat/runtime;
- безопасные локальные логи и history, если их schema остаётся совместимой.

Приложение удаляет legacy fallback code и заменяет клиентские методы:

```text
register               -> PUT /workers/{worker_uid}
getProcessingSettings  -> GET /worker-processing-settings/{worker_uid}
getNext                -> POST /job-leases
heartbeat              -> PATCH /job-leases/{job_id}
release                -> DELETE /job-leases/{job_id}
download               -> GET /job-recordings/{job_id}
submitResult           -> PUT /job-results/{job_id}
submitError            -> PUT /job-failures/{job_id}
submitPartial          -> PUT /job-partials/{job_id}
submitVoiceAnalytics   -> PATCH /job-voice-analytics/{job_id}
```

Startup/reconnect flow v2:

1. Получить `worker-api-contract`.
2. Проверить `api_version == 2` и module ID; `module_version` используется только для диагностики.
3. Выполнить `PUT /workers/{worker_uid}` с `app_version`, capabilities/model/platform и обязательным version header.
4. Для STT получить processing settings.
5. Начать polling через `POST /job-leases`.
6. Сохранять текущие heartbeat, reconnect, wake, network recovery и saved run-intent гарантии только для совместимой пары.

Если приложение обновляется во время задания, штатное завершение старой версии пытается освободить текущий lease. Если модуль уже обновлён и отвечает 426, приложение прекращает дальнейшие submit/release попытки: module migration уже вернула legacy lease в очередь. После запуска v2 worker получает задание заново. Duplicate final writes по старому пути невозможны, потому что tombstone не вызывает result service.

`MIKOAIWorkerWin` обновляет client, contract tests и compatibility document одновременно. Старые Python workers не получают client migration и остаются заблокированными с 426.

## Порядок релиза и пользовательского обновления

Релизы modules и workers с worker API version `2` публикуются в одном release window. Рекомендуемый пользовательский порядок:

1. Остановить worker или дождаться завершения текущего задания.
2. Обновить `ModuleLocalSpeechToText` и/или `ModuleAISupervisor` до релиза с worker API v2.
3. Убедиться, что UI показывает старые workers как `incompatible/offline`, а очередь не содержит legacy active leases.
4. Обновить соответствующие worker-приложения до релиза с worker API v2.
5. Запустить приложения и проверить успешное contract negotiation, регистрацию v2 и polling.

Компоненты разрешено обновлять в обратном порядке, но до обновления второй стороны обработка намеренно недоступна:

- old worker + new module -> HTTP 426, требуется worker update;
- new worker + old module -> локальная ошибка `module_upgrade_required`, требуется module update;
- new worker + new module -> нормальная работа;
- old worker + old module -> прежняя работа только до установки любого компонента с worker API v2.

Никакого окна, в котором old worker продолжает обработку через new module, нет.

## UI и диагностика

Модульные страницы workers показывают:

- worker UID и имя;
- app version;
- worker API version;
- compatible/incompatible;
- online/offline;
- last seen;
- ссылку на инструкцию обновления без secret или token query parameters.

426 и contract negotiation failures логируются структурированно с module, worker UID, remote address category и версиями, но без API key, Authorization header и lease token. Повторяющиеся legacy requests rate-limit/log-deduplicate, чтобы старый worker не заполнял журнал.

Worker UI показывает отдельные невременные состояния:

- `Update STT module to a release that supports worker API version 2`;
- `Update AI Supervisor module to a release that supports worker API version 2`;
- `Update this worker application to a release that supports worker API version 2` — текст из 426;
- `Worker API version is not supported` для неизвестной будущей версии.

## Безопасность и авторизация

- Существующие localhost/Bearer requirements сохраняются.
- Contract endpoint не раскрывает secrets и может быть доступен аутентифицированному client до регистрации worker.
- Restricted worker key получает только собственный `PUT /workers/{uid}`, собственные settings и worker job protocol resources.
- Management CRUD, списки workers/jobs, bulk PATCH/DELETE, API key lifecycle, imports и audit runs запрещены restricted worker keys.
- Все job mutations сохраняют ownership и lease validation.
- Recording download сохраняет assigned-worker, lease, path, origin, size, duration и token checks.
- One-time API keys остаются write-only после creation response.
- Legacy tombstones проходят authentication/rate limiting, но не требуют существования job/worker и не раскрывают, существует ли запрошенный ID.

## Проверки и contract matrix

### Module migrations

- fresh install создаёт v2 schema без legacy marker ambiguity;
- update с v1 workers добавляет columns и помечает их incompatible/offline;
- active legacy leases возвращаются в очередь без увеличения attempts;
- terminal jobs/results не изменяются;
- AI partials/cache сохраняются;
- API keys и worker UID сохраняются;
- повторный setup идемпотентен;
- rollback setup snapshot восстанавливает БД при ошибке до commit.

### REST и OpenAPI

- каждый resource имеет отдельный tag и переведённый `rest_tag_*` во всех locales;
- Worker management реализует list/create/detail/update/patch/delete;
- `POST /job-leases` является единственным worker способом захватить job;
- ни один GET не изменяет queue/lease state;
- new routes используют `PBXApiResult` и точные schemas;
- restricted worker permission matrix разрешает только собственные resources;
- все legacy worker routes возвращают 426 и не изменяют БД;
- все удалённые admin action routes возвращают 410 и replacement path;
- old processors недостижимы через HTTP routing;
- OpenAPI не публикует tombstones как рабочие или deprecated operations; migration errors описаны в release documentation.

### Worker clients

- client source и tests не содержат `workers:register`, `workers:getProcessingSettings`, `jobs:getNext`, `:download`, `:downloadRecording`, `:heartbeat`, `:release`, `:submit*`;
- startup всегда начинает с contract negotiation;
- 404/405 contract endpoint на старом модуле показывает module update required и не запускает fallback;
- 426 о старом worker останавливает polling/reconnect loop как incompatibility;
- 401/403, network и 5xx сохраняют отдельную существующую обработку;
- registration, empty poll, claim, recording, heartbeat, release, result, error и AI partial/voice flows работают на v2;
- Keychain token, UID, settings и model cache переживают app update;
- reconnect, wake, network recovery и saved run intent сохраняются после успешного negotiation.

### Live upgrade scenarios

В Docker/PBX окружении проверяются четыре сочетания old/new, update с активным STT lease, update с активным AI lease и partials, повторный module update, worker restart после обновления и отсутствие business mutations на каждом legacy path.

## Rollback

Breaking release не обещает бесшовный mixed-version rollback.

- Откат модуля до v1 при worker v2 останавливает worker с `module_upgrade_required`.
- Откат приложения до v1 при module v2 получает 426.
- Для полного rollback пользователь синхронно откатывает модуль и приложение.
- Jobs, requeued во время migration, не возвращаются к старым lease tokens; это намеренная защита от stale ownership.
- Добавленные columns могут оставаться в SQLite при code rollback, но старый код не должен зависеть от их отсутствия.
- API keys не ротируются, поэтому после согласованного rollback не требуется повторно вводить token.

## Предполагаемые области изменений

### ModuleLocalSpeechToText

- `Lib/RestAPI/*` — новые resource controllers/processors/actions/data structures и legacy rejection controller;
- `Lib/Services/WorkerApiKeyService.php` — v2 permissions и reconciliation;
- `Lib/LocalSpeechToTextMain.php` и queue/model services — перенос существующей логики под новые actions без ослабления контрактов;
- `Models/STTWorker.php`, `Models/STTJob.php`;
- `Setup/PbxExtensionSetup.php`;
- `Messages/*.php`, OpenAPI catalogs, README;
- REST, OpenAPI, migration, permission, lease и legacy rejection tests.

### ModuleAISupervisor

- `Lib/RestAPI/*` — resource split, CRUD и rejection controllers;
- `Lib/Services/AISupervisorApiKeyService.php`, worker/queue/result services;
- `Models/AISupervisorWorker.php`, `Models/AIAnalysisJob.php`;
- `Setup/PbxExtensionSetup.php`;
- React API adapter для management routes;
- `Messages/OpenAPI/*.php`, README;
- REST, schema, migration, permission, queue, partial, evidence и legacy rejection tests.

### Worker applications

- `LocalSTTWorker/Services/PBXClient.swift` и lifecycle/error UI/tests/docs;
- `AISupervisorWorker/Services/AISupervisorClient.swift` и lifecycle/error UI/tests/docs;
- `MIKOAIWorkerWin/src/MIKOAIWorker.Stt/Pbx/*`, contract tests и compatibility docs.

## Критерии приёмки

Изменение считается завершённым только когда одновременно выполнено следующее:

1. Swagger показывает отдельные сущностные секции, а не одну группу на модуль.
2. Worker CRUD доступен администраторам и изолирован для restricted keys.
3. Worker v2 проходит весь job lifecycle только через resource-oriented routes.
4. Старый worker против нового модуля всегда получает 426 до любой mutation.
5. Новый worker против старого модуля не использует fallback и показывает требование обновления модуля.
6. Module update атомарно инвалидирует legacy registrations и requeues legacy leases без расхода attempts.
7. API keys, UID, настройки и модели сохраняются между обновлениями.
8. AI partials и STT/AI completed results не теряются при migration.
9. Все repository suites, package checks, worker contract tests и release builds проходят.
10. Live four-way compatibility matrix подтверждает отсутствие скрытой обратной совместимости.
