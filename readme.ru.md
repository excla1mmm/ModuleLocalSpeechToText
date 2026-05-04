# ModuleLocalSpeechToText

Модуль локальной транскрибации для MikoPBX.

Модуль хранит очередь заданий и результаты в локальной SQLite-БД, предоставляет REST API для внешнего Mac worker на Apple Silicon и минимальный интерфейс администратора для просмотра очереди, воркеров и транскриптов.

Модель Whisper выбирается в веб-интерфейсе MikoPBX. `jobs:getNext` отдаёт worker-у preset и Hugging Face repo выбранной модели, поэтому worker скачивает модель лениво только при первом использовании.

## PBX worker

`Modules\ModuleLocalSpeechToText\Lib\WorkerCreateTranscriptionJobs` регистрируется через `LocalSpeechToTextConf::getModuleWorkers()`.

Он периодически читает завершенные CDR внутри monitor-директории MikoPBX и создает по одному `m_STTJob` на доступный путь записи. Дубликаты предотвращаются проверкой/индексом `file_path`, записи с задержкой появления аудиофайла сохраняются в retry-буфере, а зависшие `processing`-задания возвращаются в очередь после настроенного таймаута.

## API воркера

Базовый путь:

```text
/pbxcore/api/v3/module-local-speech-to-text
```

Эндпоинты:

- `POST /workers:register`
- `GET /jobs:getNext?worker_uid=...`
- `GET /jobs/{job_id}:download?worker_uid=...`
- `POST /jobs/{job_id}:submitResult`
- `POST /jobs/{job_id}:submitError`

Все JSON-эндпоинты используют стандартную обёртку MikoPBX REST v3 `PBXApiResult` и защищены Bearer token или localhost-правилами.
