# Независимый аудит: Worker API v2 (resource-oriented breaking migration)

Дата: 2026-08-13
Аудитор: независимая сессия Claude Code (без доверия к отчётам/ledger/commit messages предыдущих сессий)

## 0. Итоговый статус

**LOCALLY VERIFIED — LIVE PBX MATRIX PENDING**

Все четыре worktree прошли независимо запущенные fresh test/build/package pipeline без единого failure. В процессе аудита обнаружен и уже исправлен (в отдельном, независимо verified commit) один настоящий cross-project контрактный дефект в ModuleAISupervisor (см. §5). Других подтверждённых Critical/Important дефектов не найдено. Live PBX matrix не запускалась — изолированный test PBX недоступен в этой среде.

## 1. Обнаруженные worktree/branch/HEAD/merge-base

Проверено независимо через `git worktree list --porcelain`, `git rev-parse`, `git merge-base`, `git status --short` — без доверия к значениям, заявленным в исходном задании.

| Repo | Worktree | Branch | HEAD (итоговый) | Merge-base | Cleanliness |
|---|---|---|---|---|---|
| ModuleLocalSpeechToText | `.worktrees/worker-api-v2-stt` | `feature/worker-api-v2-stt` | `bc8f9ead145a1180ceffb5f1962fb5a2c9e060a8` (не менялся) | `3415f0bfcb6e074e4dd0ec3ff029355f6f4fbb57` ✓ совпадает с develop HEAD | clean на всём протяжении аудита |
| ModuleAISupervisor | `.worktrees/worker-api-v2-ai` | `feature/worker-api-v2-ai` | `c1c996cf39665c95a9e82739949ac284fc9ee40c` (изменился в ходе аудита, см. §5) | `ed40df24302a0658ea8f43d9d19b63590a341e18` ✓ | clean после нового commit |
| LocalSTTWorkerMac | `.worktrees/worker-api-v2-stt-mac` | `feature/worker-api-v2-stt-mac` | `07990ad7c6f540bc1f32b953a70610eea97f8062` (не менялся) | `48eaebde00802bec4fad7034b5b9b8e8a30f6608` ✓ | clean на всём протяжении аудита |
| AISupervisorWorkerMac | `.worktrees/worker-api-v2-ai-mac` | `feature/worker-api-v2-ai-mac` | `c5affd3fb11029c576f2df3f8ecf538d692efdf1` (изменился в ходе аудита, см. §5) | `27c687878d56ecf39347a15d584a4fc3418f5261` ✓ | clean после нового commit |

**Важное расхождение с заданием:** на момент старта аудита фактический HEAD `AISupervisorWorkerMac` был `e409cd6a963d88f3d0d314d9d86aee62af939161` — на 2 коммита впереди заявленного в задании `31e6cae97962cb0c84232a3dfc83904b088dec98` (подтверждено как предок, не расхождение истории). Заявленные в задании значения не были приняты на веру — реальное состояние проверено независимо через `git rev-parse`/`git merge-base --is-ancestor`.

**Обнаруженный во время аудита конкурентный писатель:** в процессе Stage 1/2 audit зафиксировано появление НОВЫХ commits в `ModuleAISupervisor` (`c1c996c`) и `AISupervisorWorkerMac` (`c5affd3`) поверх ранее верифицированного HEAD — worktree `AISupervisorWorkerMac` был пойман в момент как `M` (uncommitted), так через секунды осел в новый commit. Пользователь подтвердил: это не конкурентная сессия, а уже завершённая работа под тем же git identity (`excla1mmm <egbeket@gmail.com>`, `Co-Authored-By: Claude`). Аудит продолжен против нового HEAD как финального состояния; Stage 2 fresh pipeline перезапущен для обоих repo против новых commits (см. §5, §6).

## 2. Проверка запрещённого MikoPBX Core

```
Core HEAD: 361dd80b054622ef874f2ee52a5bfd9511651859  (совпадает с ожидаемым)
git status --short (Core): только untracked .agents/ .codex/ AGENTS.md SECURITY_AUDIT_2026-07-09.md
git diff (tracked files): пусто
```

Core не изменялся ни разу за время аудита (перепроверено в начале и в конце). Untracked-файлы, посторонние для Worker API v2, не тронуты. Core branch/worktree/commit не создавались. `PBXApiResult`, `BaseController`, authentication/permission checker, RouterProvider не менялись — подтверждено отсутствием tracked diff в Core вообще.

Подтверждено, что все 4 модуля/воркера используют существующий Core-префикс `/pbxcore/api/v3/...` **единообразно** (это Core REST framework versioning, а не Worker API version) — не спутано с Worker API v2 семантикой, не введён `/v4` или иной посторонний префикс.

## 3. Первоначальный audit matrix (Stage 1, выборочная глубокая верификация)

Полное построчное покрытие всех ~20 разделов задания в рамках одной сессии физически недостижимо (154+202+файлов diff, ~9000+26000 строк изменений только в PHP-модулях, плюс два Swift-проекта). Ниже — результат целевой глубокой проверки (чтение реального кода, не только прохождения тестов) по пунктам, явно отмеченным в задании как наиболее часто путаемые или требующие доказательства помимо grep/тестов.

| # | Проверка | Статус | Файл:строка | Метод верификации |
|---|---|---|---|---|
| 1 | `WorkerApiContract::requireVersion()` вызывается первым, до любых domain/DB эффектов | PASS | `Lib/RestAPI/JobRecordings/Processor.php`, `Lib/RestAPI/JobLeases/Processor.php`, `Lib/RestAPI/JobVoiceAnalytics/Processor.php` (оба модуля) | Прямое чтение исходников |
| 2 | `GET /worker-api-contract` — точный payload, без требования version header | PASS | `Lib/RestAPI/WorkerApiContract/Processor.php` + `DataStructure.php` (оба модуля) | Прямое чтение исходников + fresh test |
| 3 | Malformed version headers (`2x`,`2.1`,`3`) не считаются v2 | PASS | AI: `Lib/Services/WorkerApiContract.php` regex `^(?:0|[1-9][0-9]*)$` + exact int equality; STT: strict `===` string compare | Прямое чтение исходников + fresh regression test (`check-worker-api-v2-error-code-contract.php`) |
| 4 | Ownership transport: JSON body для lease-операций, headers-only для recording | PASS | STT: `Lib/RestAPI/JobLeases/Processor.php` (body `worker_uid`/`lease_token`) vs `Lib/RestAPI/JobRecordings/Processor.php` (headers `_worker_uid_header`/`_lease_token_header`); AI: `JobRecordings/Processor.php` явно **отклоняет** body/query ownership (403) и требует 3 заголовка (`WORKER_UID_HEADER`, `JOB_LEASE_TOKEN_HEADER`, `RECORDING_DOWNLOAD_TOKEN_HEADER`) | Прямое чтение исходников |
| 5 | `file_url` строго `/job-recordings/{job_id}`, без префикса/query/token/origin | PASS | `Lib/RestAPI/JobLeases/Actions/CreateAction.php:24`, `Lib/Services/AIAnalysisWorkerPayloadBuilder.php:433` (`'/job-recordings/' . $jobId`) | Прямое чтение исходников |
| 6 | Swift-клиенты валидируют `file_url` строгим равенством до построения запроса | PASS | `LocalSTTWorkerMac/.../PBXClient.swift:444-449` (`recordingURL(for:)`, exact-match guard), `AISupervisorWorkerMac/.../AISupervisorClient.swift:362-363` (идентичный паттерн) | Прямое чтение исходников (оба воркера) |
| 7 | Voice Analytics — PATCH, не PUT | PASS | AI `Lib/RestAPI/JobVoiceAnalytics/Controller.php`: `#[HttpMapping(mapping: ['PATCH' => ['patch']], ...)]` | Прямое чтение исходников |
| 8 | Legacy worker action routes (register/getNext/download/submitResult/submitError/heartbeat/release/getProcessingSettings) — 426, никакого domain dispatch | PASS | STT `Lib/RestAPI/Workers/Processor.php:41-42`, `Lib/RestAPI/Jobs/Processor.php` (все legacy actions → `LegacyRouteRejector::worker()` до какого-либо service call) | Прямое чтение исходников |
| 9 | Legacy admin routes — 410 + точный `replacement_path`, без mutation | PASS | AI `Lib/RestAPI/Calls/Controller.php:179-191`, `Processor.php:43-47` (`workflow`,`note`,`history`,`bulkWorkflow`,`review` → `LegacyRouteRejector::removed(...)`) | Прямое чтение исходников |
| 10 | Migration: атомарность, idempotency, rollback, no-attempts-increment, exact-v2-untouched | PASS | STT `Lib/Services/WorkerApiV2Migration.php` (marker пишется последним внутри уже открытой транзакции, требует активную транзакцию); AI `Lib/Services/AISupervisorWorkerApiV2Migration.php` (`BEGIN IMMEDIATE`, double-checked marker после захвата write-lock, `EXACT_V2_PREDICATE` с `typeof()=integer` защитой от malformed, test-only `failureInjector` seam для forced-failure rollback proof) | Прямое чтение исходников + fresh `check-worker-api-v2-migration-sqlite.php` (STT: `rollback_verified: true`, `idempotency_hash` present) |
| 11 | `module_version`/`min_pbx_version`/module versions | PASS | `module.json`: STT `1.45`/`2025.1.1`, AI `1.73`/`2025.1.1` | Прямое чтение файлов |
| 12 | OpenAPI operationId уникальность | PASS | Нет дублей ни в одном модуле | `grep`-скан всех `operationId:` во всех Controller.php |
| 13 | Compatibility-state отделено от credentials-state в negotiation | PASS | STT-mac `PBXClient.swift:168-214` (`negotiateWorkerAPIContract`): 426/service-failure classification строго до 401/403 branch | Прямое чтение исходников |

**Не проверено с той же глубиной (полагались на прошедшие fresh test-suite, см. §4, но НЕ перечитан построчно каждый файл):** полная concurrency-стресс-проверка вне SQLite unit-тестов (реальная многопроцессная гонка не воспроизводилась вручную сверх того, что покрывают `check-*-concurrency-sqlite.php`/`check-*-race-sqlite.php`), полное покрытие translation keys across всех ~30 locale-файлов в обоих модулях (полагались на `check-openapi-translations.php`), Management Jobs Core fallback edge cases (`POST /jobs/{id}` → 405, malformed float/bool coercion) — код не перечитан построчно, только подтверждено прохождение `check-jobs-management-crud-v2.php`/`check-management-admin-authorization.php`. Эти пункты помечены **CANNOT VERIFY построчно в рамках этой сессии** — доказательная база ограничена прохождением специализированных, явно названных тестов, а не независимым воспроизведением.

## 4. Свежие команды (Stage 2) — точные результаты

Все запуски — с нуля, в отдельных background-процессах, без переиспользования кэшированных прошлых результатов. `ripgrep` не был установлен в системе (см. §7) — переустановлен перед финальными прогонами shell-контрактов macOS-воркеров.

### ModuleLocalSpeechToText (HEAD `bc8f9ead145a1180ceffb5f1962fb5a2c9e060a8`)
```
php Tests/run-all.php            → All module contract tests passed   EXIT:0
scripts/package-module.sh        → ModuleLocalSpeechToText.1.45.zip
  SHA-256: 9c75b436ac6a656ae11f5be47038068f7a50cfa7b554a0a4178e269fa98c9cf7
  188 files, 2 544 773 bytes; unzip -t: no errors
  hygiene: нет .git/Tests/docs/react-app/scripts/node_modules/runtime DB/logs
git diff --check                 → EXIT:0 (clean)
git status --short               → EXIT:0 (clean)
```

### ModuleAISupervisor (финальный HEAD `c1c996cf39665c95a9e82739949ac284fc9ee40c`, перезапущено после появления нового commit)
```
php Tests/run-all.php            → 126 contract checks passed         EXIT:0
npm test -- --run (react-app)    → tests 194, pass 194, fail 0        EXIT:0
npm run build (react-app)        → vite build succeeded (47 modules, 670.12 kB / gzip 179.64 kB)
scripts/package-module.sh        → ModuleAISupervisor.1.73.zip
  SHA-256 (финальный): 9edbaaa1c711e063575237913ade5a50c1c1ef8405fe49296b029881c6456d97
  448 files, 5 220 273 bytes; unzip -t: no errors
  hygiene: нет .git/Tests/docs/react-app/scripts/node_modules/runtime DB/logs;
  содержит свежесобранный bundled React asset (public/assets/js/vendor/ai-supervisor.bundle.js)
git diff --check                 → EXIT:0 (clean)
git status --short               → EXIT:0 (clean)
Примечание: npm audit сообщил 5 vulnerabilities (1 low/1 moderate/3 high) в зависимостях —
  дефект зависимостей, не введён Worker API v2 diff, зафиксирован как pre-existing/deferred (Minor).
```

### LocalSTTWorkerMac (HEAD `07990ad7c6f540bc1f32b953a70610eea97f8062`)
```
check-repository.sh              → Repository hygiene OK              EXIT:0
run-shell-contracts.sh           → 17/17 контрактов OK (после установки ripgrep) EXIT:0
xcodebuild test (Debug)          → ** TEST SUCCEEDED **                EXIT:0
xcodebuild build (Release)       → ** BUILD SUCCEEDED **               EXIT:0
Info.plist (built .app)          → CFBundleShortVersionString=1.7, CFBundleVersion=34
hygiene (.app)                   → нет .exe/windows/.py/логов/секретов/sqlite
git diff --check                 → EXIT:0 (clean)
git status --short               → EXIT:0 (clean)
```

### AISupervisorWorkerMac (финальный HEAD `c5affd3fb11029c576f2df3f8ecf538d692efdf1`, перезапущено после появления нового commit)
```
check-repository.sh              → Repository hygiene OK              EXIT:0
run-shell-contracts.sh           → 17/17 контрактов OK (после установки ripgrep) EXIT:0
xcodebuild test (Debug)          → Executed 147 tests, 0 failures; ** TEST SUCCEEDED ** EXIT:0
xcodebuild build (Release)       → ** BUILD SUCCEEDED **               EXIT:0
Info.plist (built .app)          → CFBundleShortVersionString=1.7, CFBundleVersion=34
hygiene (.app)                   → нет .exe/windows/.py/логов/секретов/sqlite
git diff --check                 → EXIT:0 (clean)
git status --short               → EXIT:0 (clean)
```

**Первый прогон (до фикса окружения) дал ложный failure** в `check-ai-only-boundary-contract.sh` ("AI Supervisor Worker bundle identifier is incorrect") и ранний обрыв STT-mac `run-shell-contracts.sh` (exit 127) — оба вызваны отсутствием реального бинарника `rg` в системе (см. §7), не дефектом кода. После установки `ripgrep` оба прогона переисполнены с нуля и дали чистый результат.

## 5. Findings

| # | Категория | Severity | Файл | Статус |
|---|---|---|---|---|
| 1 | Cross-project envelope key mismatch: AI-модуль отдавал `error` вместо `error_code` в 426/410 envelope, тогда как Swift-клиент (`WorkerUpgradeRequiredPayload`) декодирует именно `error_code` → 426 ошибочно классифицировался как `.unsupportedAPIVersion` вместо `.workerUpgradeRequired`, оператор видел неверное сообщение | **Critical** (обнаружен уже исправленным) | `Lib/RestAPI/Common/LegacyRouteRejector.php`, `Lib/Services/WorkerApiContract.php` (ModuleAISupervisor) | **FIXED** commit `c1c996cf39665c95a9e82739949ac284fc9ee40c` "fix(worker-api): emit error_code in v2 compatibility envelopes", независимая regression test `check-worker-api-v2-error-code-contract.php` создана и проходит; перепроверено данным аудитом свежим прогоном (§4) |
| 2 | `raw_response` не был bounded к серверному 200000-byte (`strlen`) лимиту в Character-семантике Swift, что для multi-byte текста могло превышать лимит и получать server-side rejection | **Important** (обнаружен уже исправленным) | `AISupervisorWorker/Core/Models.swift` (AISupervisorWorkerMac) | **FIXED** commit `c5affd3fb11029c576f2df3f8ecf538d692efdf1` "fix(result): bound raw_response to the module's 200000-byte limit", byte-accurate truncation на scalar boundary, 2 новых regression theста подтверждают UTF-8 byte-семантику; перепроверено данным аудитом свежим прогоном (§4) |
| 3 | Отсутствие ripgrep в системном окружении вызывало ложные failures в shell-контрактах обоих macOS-воркеров | Environment gap (не дефект кода) | n/a | **FIXED** установкой `ripgrep` через `brew install ripgrep` (вне аудируемых repo, не затрагивает Core/feature branches) |
| 4 | `npm audit`: 5 vulnerabilities (1 low/1 moderate/3 high) в зависимостях `react-app` ModuleAISupervisor | Minor | `react-app/package-lock.json` | **Deferred** — pre-existing dependency issue, не введено Worker API v2 diff, вне scope этого исправления без отдельной авторизации на апгрейд зависимостей |

Собственных Critical/Important дефектов, обнаруженных и исправленных этим аудитом (а не найденных уже исправленными), — **ноль**. Оба Critical/Important finding (##1-2) оказались уже устранены отдельными, корректно протестированными commits до того, как аудит успел бы их независимо зафиксировать как открытые — проверено повторным fresh test/build/package прогоном против финального HEAD.

## 6. RED/GREEN evidence для findings #1-2

Эти исправления не были сделаны в рамках Stage 3 данного аудита (они уже существовали в history worktree на момент фиксации финального HEAD, см. §1). RED/GREEN evidence взято из diff самих commits, verified путём независимого fresh test run (§4), а не принято на веру:

- Finding #1: тест `check-worker-api-v2-error-code-contract.php` (новый файл, 115 строк) явно проверяет `error_code` ключ и `!array_key_exists('error', ...)` — до исправления (`git show c1c996c^:Lib/Services/WorkerApiContract.php`) код использовал `'error' =>`, что дало бы RED на этом тесте. GREEN подтверждён fresh-прогоном `php Tests/run-all.php` (126/126 checks passed) в данном аудите.
- Finding #2: тесты `testEncodedRawResponseIsBoundedToServerByteLimit` и `testEncodedRawResponseBoundCountsUTF8BytesNotCharacters` (новые, `AISupervisorClientV2ContractTests.swift`) явно проверяют UTF-8 byte length ≤ 200000 и отсутствие replacement-character corruption на scalar boundary. GREEN подтверждён fresh xcodebuild test run в данном аудите (147/147 tests passed, 0 failures).

## 7. Package hygiene, versions, package artifacts

| Module | Version | min_pbx_version | Package | SHA-256 | Files | Size |
|---|---|---|---|---|---|---|
| ModuleLocalSpeechToText | 1.45 | 2025.1.1 | `ModuleLocalSpeechToText.1.45.zip` | `9c75b436ac6a656ae11f5be47038068f7a50cfa7b554a0a4178e269fa98c9cf7` | 188 | 2 544 773 B |
| ModuleAISupervisor | 1.73 | 2025.1.1 | `ModuleAISupervisor.1.73.zip` | `9edbaaa1c711e063575237913ade5a50c1c1ef8405fe49296b029881c6456d97` | 448 | 5 220 273 B |

| macOS worker | Marketing | Build | Test | Release build |
|---|---|---|---|---|
| LocalSTTWorkerMac | 1.7 | 34 | SUCCEEDED | SUCCEEDED |
| AISupervisorWorkerMac | 1.7 | 34 | SUCCEEDED (147/147) | SUCCEEDED |

## 8. Windows/Python scope

Windows STT Worker и Python STT Worker не затронуты ни в одном из четырёх worktree — не найдено ни одной ссылки, файла, диффа, связанного с этими компонентами. `.app`-бандлы обоих macOS-воркеров проверены на отсутствие `.exe`/`.py`/`windows`-артефактов — не найдено.

## 9. Подтверждение неизменности Core

- `git status --short` в Core: только 4 предсуществующих untracked файла (`.agents/`, `.codex/`, `AGENTS.md`, `SECURITY_AUDIT_2026-07-09.md`), не относящихся к Worker API v2 — не тронуты.
- `git diff` по tracked files Core: пусто (нет изменений).
- Core HEAD не менялся: `361dd80b054622ef874f2ee52a5bfd9511651859` в начале и в конце аудита.
- Ни одного Core branch/worktree/commit не создано этим аудитом.

## 10. Подтверждение отсутствия push/merge/release

За весь аудит не выполнено ни одного `git push`, `git merge`, `git tag`, `git rebase`, `git reset --hard`, `git clean` ни в одном из пяти репозиториев. Единственное write-действие вне git — `brew install ripgrep` (системный dev-инструмент, не затрагивает ни один из аудируемых репозиториев).

## 11. Deferred / Live PBX

- **Live PBX matrix (§18 задания, пункты 1-12) не выполнена** — изолированный test PBX недоступен в этой среде; `miko-rest-api-check` не запускался.
- Полная concurrency chaos-проверка сверх существующих SQLite-based unit tests не проводилась.
- Построчная проверка translation-ключей across всех ~30 locale-файлов в обоих модулях не проводилась независимо — доказательство ограничено прохождением `check-openapi-translations.php`.
- npm audit vulnerabilities (5, ModuleAISupervisor react-app) — deferred, pre-existing, вне scope без отдельной авторизации.

## 12. Итоговый вердикт

**LOCALLY VERIFIED — LIVE PBX MATRIX PENDING**

Обоснование: все четыре fresh full pipeline прошли (включая перезапуск двух repo против HEAD, изменившегося в процессе аудита); оба Release build прошли с корректными версиями 1.7/34; оба module package созданы и верифицированы (integrity + hygiene); migration preservation/idempotency/rollback подтверждены прямым чтением кода + fresh test evidence; version-gating/no-fallback/ownership/file_url contracts подтверждены прямым чтением кода по наиболее рискованным/легко путаемым точкам; `git diff --check` чист во всех четырёх worktree; feature worktrees clean на момент завершения; Core не изменён; Windows/Python scope отсутствует. Live PBX matrix не пройдена (недоступна инфраструктура) — поэтому не `VERIFIED COMPLETE`.
